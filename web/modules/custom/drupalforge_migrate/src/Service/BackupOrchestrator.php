<?php

declare(strict_types=1);

namespace Drupal\drupalforge_migrate\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\drupalforge_migrate\Exception\PreflightFailedException;
use Drupal\drupalforge_migrate\Exception\UploadFailedException;
use Drupal\drupalforge_migrate\Value\ArtifactDescriptor;
use Drupal\drupalforge_migrate\Value\BackupManifest;
use Drupal\drupalforge_migrate\Value\PreflightResult;
use Drupal\drupalforge_migrate\Value\UploadPlan;
use Psr\Log\LoggerInterface;

/**
 * The single shared entry point both Drush commands and Batch API
 * operations call - so archiving/exporting/uploading logic exists exactly
 * once regardless of caller.
 *
 * Deviation from the original service list in DESIGN.md: this class also
 * takes a ConfigFactoryInterface (to read system.file's private path),
 * since something has to, and CodeArchiver itself is deliberately kept free
 * of Drupal config-system knowledge so its exclusion-list logic stays a
 * pure, easily unit-tested function of its explicit parameters.
 */
final class BackupOrchestrator {

  /**
   * Multisite support (multiple sites/*\/ directories) is not currently
   * supported; only the default site is archived/exported.
   */
  private const SITE_DIRS = ['default'];

  public function __construct(
    private readonly PreflightService $preflightService,
    private readonly CodeArchiverInterface $archiver,
    private readonly DatabaseExporterInterface $exporter,
    private readonly DrupalForgeClientInterface $client,
    private readonly ProgressStateStore $progress,
    private readonly FileSystemInterface $fileSystem,
    private readonly LoggerInterface $logger,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly UploaderInterface $uploader,
  ) {}

  public function preflight(): PreflightResult {
    return $this->preflightService->run();
  }

  public function getCurrentBackupId(): ?string {
    return $this->progress->getCurrentBackupId();
  }

  /**
   * The deterministic on-disk path for a backup's artifact, so callers
   * (Drush commands, the status page) can report/locate it without needing
   * to know workingDir()'s internal layout.
   */
  public function getArtifactPath(string $backupId, string $artifact): string {
    $filename = match ($artifact) {
      'code' => 'code.tar.gz',
      'db' => 'db.sql.gz',
      default => throw new \InvalidArgumentException("Unrecognized artifact type '{$artifact}' (must be 'code' or 'db')."),
    };
    return $this->workingDir($backupId) . '/' . $filename;
  }

  /**
   * @throws \Drupal\drupalforge_migrate\Exception\PreflightFailedException
   *   When the site is not supported for a migration.
   * @throws \Drupal\drupalforge_migrate\Exception\QuotaExceededException
   *   When DrupalForge rejects the reservation.
   */
  public function startBackup(bool $dbOnly = FALSE, bool $codeOnly = FALSE): string {
    $result = $this->preflightService->run();
    if (!$result->isSupported()) {
      throw new PreflightFailedException(
        'This site is not supported for a DrupalForge migration: ' . implode('; ', $result->getFailureReasons()),
      );
    }

    $reservation = $this->client->reserveBackup();
    $backupId = (string) $reservation['backup_id'];
    $this->progress->setCurrentBackupId($backupId);
    $this->progress->setLastStatus([
      'backup_id' => $backupId,
      'step' => 'reserved',
      'ok' => TRUE,
      'errors' => [],
      'updated' => time(),
    ]);

    return $backupId;
  }

  public function runCodeArtifact(string $backupId): ArtifactDescriptor {
    return $this->runGuarded($backupId, 'archive', function () use ($backupId): ArtifactDescriptor {
      $checks = $this->preflightService->run()->getChecks();
      $projectRoot = (string) ($checks['project_root'] ?? '');
      $docroot = (string) ($checks['docroot'] ?? '');
      $privatePath = $this->configFactory->get('system.file')->get('path.private');

      $destinationDir = $this->workingDir($backupId);
      $destination = $destinationDir . '/code.tar.gz';

      $descriptor = $this->archiver->archive($projectRoot, $docroot, $destination, self::SITE_DIRS, is_string($privatePath) ? $privatePath : NULL);
      return $descriptor;
    });
  }

  public function runDbArtifactCli(string $backupId): ArtifactDescriptor {
    return $this->runGuarded($backupId, 'export (cli)', function () use ($backupId): ArtifactDescriptor {
      $destination = $this->workingDir($backupId) . '/db.sql.gz';
      return $this->exporter->exportViaCli($destination);
    });
  }

  /**
   * @return array{done: bool, tables_remaining: int, bytes_written: int}
   */
  public function runDbArtifactWebStep(string $backupId): array {
    return $this->runGuarded($backupId, 'export (web step)', function () use ($backupId): array {
      $destination = $this->workingDir($backupId) . '/db.sql.gz';
      return $this->exporter->exportStepWeb($backupId, $destination);
    });
  }

  /**
   * Guarded wrapper around DrupalForgeClientInterface::requestUpload(), for
   * BackupBatch's per-part upload loop (which cannot go through
   * uploadArtifact() below since it drives the upload one Batch API
   * operation - one part - at a time, not the whole artifact in one call).
   * Any failure aborts the backup exactly like every other guarded step -
   * this is what closes the gap where the web/Batch upload path used to
   * call the client directly and bypass the abort-on-failure contract.
   */
  public function requestUpload(string $backupId, ArtifactDescriptor $descriptor): UploadPlan {
    return $this->runGuarded($backupId, sprintf('upload (%s): request plan', $descriptor->getArtifact()), function () use ($backupId, $descriptor): UploadPlan {
      return $this->client->requestUpload($backupId, $descriptor);
    });
  }

  /**
   * Guarded wrapper around Uploader::uploadOnePart(), for the same reason
   * as requestUpload() above.
   */
  public function uploadOnePart(string $backupId, ArtifactDescriptor $descriptor, UploadPlan $plan, int $partNumber): string {
    return $this->runGuarded($backupId, sprintf('upload (%s): part %d', $descriptor->getArtifact(), $partNumber), function () use ($backupId, $descriptor, $plan, $partNumber): string {
      return $this->uploader->uploadOnePart($backupId, $descriptor, $plan, $partNumber);
    });
  }

  /**
   * Guarded wrapper around Uploader::uploadArtifact() (single-PUT mode),
   * for BackupBatch's non-multipart upload path. Public alias of the
   * private uploadArtifactGuarded() used internally by runFullBackup().
   *
   * @return array{artifact: string, sha256: string, size: int, parts: array<int, array{n: int, etag: string}>}
   */
  public function uploadArtifact(string $backupId, ArtifactDescriptor $descriptor): array {
    return $this->uploadArtifactGuarded($backupId, $descriptor, sprintf('upload (%s)', $descriptor->getArtifact()));
  }

  /**
   * Runs preflight, archives/exports/uploads whichever artifacts are
   * requested, and marks the backup `ready` on DrupalForge - the CLI/Drush
   * path, which has no per-request time limit so it runs everything to
   * completion in one call.
   *
   * @throws \Drupal\drupalforge_migrate\Exception\PreflightFailedException
   * @throws \Drupal\drupalforge_migrate\Exception\QuotaExceededException
   * @throws \Drupal\drupalforge_migrate\Exception\ArchiveFailedException
   * @throws \Drupal\drupalforge_migrate\Exception\ExportFailedException
   * @throws \Drupal\drupalforge_migrate\Exception\UploadFailedException
   */
  public function runFullBackup(bool $dbOnly = FALSE, bool $codeOnly = FALSE): string {
    $backupId = $this->startBackup($dbOnly, $codeOnly);

    $artifactResults = [];
    $sizes = [];
    $checksums = [];

    if (!$dbOnly) {
      $descriptor = $this->runCodeArtifact($backupId);
      $artifactResults[] = $this->uploadArtifactGuarded($backupId, $descriptor, 'upload (code)');
      $sizes['code'] = $descriptor->getSize();
      $checksums['code'] = $descriptor->getSha256();
    }

    if (!$codeOnly) {
      $descriptor = $this->runDbArtifactCli($backupId);
      $artifactResults[] = $this->uploadArtifactGuarded($backupId, $descriptor, 'upload (db)');
      $sizes['db'] = $descriptor->getSize();
      $checksums['db'] = $descriptor->getSha256();
    }

    $this->completeBackup($backupId, $artifactResults, $this->buildManifest($sizes, $checksums));
    return $backupId;
  }

  /**
   * Resumes a single artifact's upload from its last recorded part (via
   * Uploader::resumeFrom()), re-reading the artifact already on disk from an
   * interrupted run.
   *
   * @return array{artifact: string, sha256: string, size: int, parts: array<int, array{n: int, etag: string}>}
   *   Deliberately returns the upload result (rather than void) so
   *   resumeBackup() can build the `complete` manifest without re-uploading
   *   an artifact whose upload already finished.
   *
   * @throws \Drupal\drupalforge_migrate\Exception\UploadFailedException
   *   When the local artifact file is missing (the interrupted run never
   *   got far enough to produce it - a full re-run is needed instead).
   */
  public function resumeUpload(string $backupId, string $artifact): array {
    $path = $this->getArtifactPath($backupId, $artifact);
    if (!is_file($path)) {
      throw new UploadFailedException("Cannot resume upload for artifact '{$artifact}': local artifact file not found at {$path}. Run the backup from the start instead.");
    }

    $size = @filesize($path);
    if ($size === FALSE) {
      throw new UploadFailedException("Cannot resume upload for artifact '{$artifact}': could not determine the size of {$path}.");
    }
    $sha256 = @hash_file('sha256', $path);
    if ($sha256 === FALSE) {
      // Not `?: ''` - a hashing failure (permission error, the file
      // vanishing under us) must abort loudly, not silently ship an
      // ArtifactDescriptor with an empty checksum to DrupalForge.
      throw new UploadFailedException("Cannot resume upload for artifact '{$artifact}': could not compute its checksum at {$path}.");
    }

    $descriptor = new ArtifactDescriptor($artifact, $path, $size, $sha256);
    return $this->uploadArtifactGuarded($backupId, $descriptor, "upload resume ({$artifact})");
  }

  /**
   * Resumes the current in-progress backup end to end: finishes uploading
   * whichever local artifacts (code.tar.gz / db.sql.gz) already exist on
   * disk from the interrupted run, then completes the backup.
   *
   * Uploader::uploadArtifact()/uploadOnePart() already skip parts recorded
   * as done in ProgressStateStore, so a fully-uploaded artifact is a cheap
   * no-op here (a single-PUT artifact has no partial-resume concept and is
   * simply re-uploaded whole - acceptable since single-PUT implies small).
   *
   * @throws \Drupal\drupalforge_migrate\Exception\UploadFailedException
   *   When there is no in-progress backup, or no local artifacts to resume.
   */
  public function resumeBackup(): string {
    $backupId = $this->progress->getCurrentBackupId();
    if ($backupId === NULL) {
      throw new UploadFailedException('No in-progress backup to resume.');
    }

    $artifactResults = [];
    $sizes = [];
    $checksums = [];

    foreach (['code', 'db'] as $artifactType) {
      if (!is_file($this->getArtifactPath($backupId, $artifactType))) {
        continue;
      }
      $result = $this->resumeUpload($backupId, $artifactType);
      $artifactResults[] = $result;
      $sizes[$artifactType] = $result['size'];
      $checksums[$artifactType] = $result['sha256'];
    }

    if ($artifactResults === []) {
      throw new UploadFailedException("No local artifacts found for backup {$backupId} to resume - run the backup from the start instead.");
    }

    $this->completeBackup($backupId, $artifactResults, $this->buildManifest($sizes, $checksums));
    return $backupId;
  }

  public function hasPendingExportCursor(string $backupId): bool {
    return $this->progress->getExportCursor($backupId) !== [];
  }

  /**
   * Builds the manifest from already-produced artifact results (used by the
   * Batch API path, where BackupBatch's operations - not this class - drove
   * archiving/exporting/uploading, so there is no local ArtifactDescriptor
   * to read sizes/checksums from directly).
   *
   * @param array<int, array{artifact: string, sha256: string, size?: int, parts?: array}> $artifactResults
   */
  public function completeFromArtifacts(string $backupId, array $artifactResults): void {
    $sizes = [];
    $checksums = [];
    foreach ($artifactResults as $result) {
      $sizes[$result['artifact']] = $result['size'] ?? 0;
      $checksums[$result['artifact']] = $result['sha256'] ?? '';
    }
    $this->completeBackup($backupId, $artifactResults, $this->buildManifest($sizes, $checksums));
  }

  /**
   * Marks a backup `ready` on DrupalForge - the only method that can. Only
   * reachable once every requested artifact has uploaded successfully:
   * runFullBackup()/resumeBackup() only call this after every
   * uploadArtifactGuarded() call above has returned without throwing, and
   * runGuarded() aborts (not completes) on any failure - so a partially
   * uploaded backup can never reach `ready`.
   */
  public function completeBackup(string $backupId, array $artifactResults, BackupManifest $manifest): void {
    $this->runGuarded($backupId, 'complete', function () use ($backupId, $artifactResults, $manifest): void {
      $this->client->complete($backupId, $artifactResults, $manifest);
    });

    $this->progress->clearExportCursor($backupId);
    foreach ($artifactResults as $result) {
      $this->progress->clearUploadState($backupId, $result['artifact']);
    }
    $this->progress->setCurrentBackupId(NULL);
    $this->progress->setLastStatus([
      'backup_id' => $backupId,
      'step' => 'ready',
      'ok' => TRUE,
      'errors' => [],
      'updated' => time(),
    ]);
  }

  public function abortBackup(string $backupId, string $reason): void {
    try {
      $this->client->abort($backupId);
    }
    catch (\Throwable $e) {
      // The abort call itself failing is not fatal: the reservation will
      // still expire passively via DrupalForge's reserved_until TTL. Never
      // let a failed abort mask the original failure reason.
      $this->logger->warning('Could not abort backup @id on DrupalForge (it will expire passively): @message', [
        '@id' => $backupId,
        '@message' => $e->getMessage(),
      ]);
    }

    $this->logger->error('Backup @id failed: @reason', ['@id' => $backupId, '@reason' => $reason]);
    $this->progress->setLastStatus([
      'backup_id' => $backupId,
      'step' => 'aborted',
      'ok' => FALSE,
      'errors' => [$reason],
      'updated' => time(),
    ]);
  }

  /**
   * @return array<string, mixed>
   */
  public function status(): array {
    $status = $this->progress->getLastStatus();
    try {
      $status['backups'] = $this->client->listBackups()['backups'] ?? [];
    }
    catch (\Throwable $e) {
      $status['backups_error'] = $e->getMessage();
    }
    return $status;
  }

  /**
   * Runs $fn, and on any failure aborts the backup with the thrown
   * exception's message as the named reason, then rethrows - so both the
   * Drush and web callers see the same specific, actionable message.
   */
  private function runGuarded(string $backupId, string $step, callable $fn): mixed {
    try {
      return $fn();
    }
    catch (\Throwable $e) {
      $this->abortBackup($backupId, sprintf('%s: %s', $step, $e->getMessage()));
      throw $e;
    }
  }

  /**
   * @return array{artifact: string, sha256: string, size: int, parts: array<int, array{n: int, etag: string}>}
   */
  private function uploadArtifactGuarded(string $backupId, ArtifactDescriptor $descriptor, string $step): array {
    return $this->runGuarded($backupId, $step, function () use ($backupId, $descriptor): array {
      return $this->uploader->uploadArtifact($backupId, $descriptor);
    });
  }

  /**
   * @param array{code?: int, db?: int} $sizes
   * @param array{code?: string, db?: string} $checksums
   */
  private function buildManifest(array $sizes, array $checksums): BackupManifest {
    $checks = $this->preflightService->run()->getChecks();
    $docroot = (string) ($checks['docroot'] ?? '');
    $projectRoot = (string) ($checks['project_root'] ?? '');
    $privatePath = $this->configFactory->get('system.file')->get('path.private');

    $exclusions = $this->archiver->buildExclusionList($projectRoot, $docroot, self::SITE_DIRS, is_string($privatePath) ? $privatePath : NULL);

    return new BackupManifest(
      $docroot,
      PHP_VERSION,
      (string) ($checks['drupal_version'] ?? ''),
      $exclusions['tar_excludes'],
      // File counts are not computed here: an accurate per-directory count
      // would need a second full filesystem walk purely for informational
      // manifest metadata, which isn't required by the `complete` contract
      // or the error-abort guarantee.
      [],
      $sizes,
      $checksums,
      $exclusions['settings_files'],
    );
  }

  private function workingDir(string $backupId): string {
    $dir = rtrim($this->fileSystem->getTempDirectory(), '/') . '/drupalforge_migrate/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $backupId);
    if (!is_dir($dir)) {
      mkdir($dir, 0700, TRUE);
    }
    return $dir;
  }

}
