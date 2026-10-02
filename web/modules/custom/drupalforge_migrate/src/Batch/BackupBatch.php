<?php

declare(strict_types=1);

namespace Drupal\drupalforge_migrate\Batch;

use Drupal\drupalforge_migrate\Exception\ExportFailedException;
use Drupal\drupalforge_migrate\Service\BackupOrchestrator;
use Drupal\drupalforge_migrate\Service\ProgressStateStore;
use Drupal\drupalforge_migrate\Service\Uploader;
use Drupal\drupalforge_migrate\Service\UploaderInterface;
use Drupal\drupalforge_migrate\Value\ArtifactDescriptor;

/**
 * Drupal Batch API bridge for the web path.
 *
 * Every operation here is a thin static callback delegating to
 * BackupOrchestrator - including per-part uploads, via its
 * requestUpload()/uploadOnePart()/uploadArtifact() guarded wrappers, so a
 * failed upload aborts the backup exactly like every other step (archive,
 * export, complete). No business logic is duplicated between this class
 * and the Drush commands, per the module's core requirement of one shared
 * service layer.
 *
 * The upload operations use the "single operation, re-invoked repeatedly via
 * $context['finished'] < 1" Batch API pattern rather than one static
 * operation entry per part, since the total number of parts isn't known
 * until after the artifact is produced and a plan is requested. The source
 * of truth for "which parts are done" is always ProgressStateStore (via
 * Uploader::resumeFrom()/uploadOnePart()) - never $context['sandbox'],
 * which is only used to cache the UploadPlan and part-size bookkeeping for
 * the current batch run and is safely rebuilt if a run is abandoned and a
 * new one started from the status page's "Resume backup".
 */
final class BackupBatch {

  /**
   * @return array<int, array{0: array{0: string, 1: string}, 1: array}>
   */
  public static function buildOperations(bool $dbOnly = FALSE, bool $codeOnly = FALSE): array {
    $operations = [
      [[self::class, 'startOperation'], [$dbOnly, $codeOnly]],
    ];

    if (!$dbOnly) {
      $operations[] = [[self::class, 'codeArchiveOperation'], []];
      $operations[] = [[self::class, 'codeUploadOperation'], []];
    }
    if (!$codeOnly) {
      $operations[] = [[self::class, 'dbExportOperation'], []];
      $operations[] = [[self::class, 'dbUploadOperation'], []];
    }

    $operations[] = [[self::class, 'completeOperation'], []];

    return $operations;
  }

  public static function startOperation(bool $dbOnly, bool $codeOnly, array &$context): void {
    if (!empty($context['results']['error'])) {
      return;
    }
    try {
      $backupId = self::orchestrator()->startBackup($dbOnly, $codeOnly);
      $context['results']['backup_id'] = $backupId;
      $context['message'] = t('Backup reserved (@id).', ['@id' => $backupId]);
    }
    catch (\Throwable $e) {
      $context['results']['error'] = $e->getMessage();
    }
  }

  public static function codeArchiveOperation(array &$context): void {
    if (!empty($context['results']['error'])) {
      return;
    }
    $backupId = (string) $context['results']['backup_id'];
    try {
      $descriptor = self::orchestrator()->runCodeArtifact($backupId);
      $context['results']['code_descriptor'] = $descriptor->toArray();
      $context['message'] = t('Code archive produced.');
    }
    catch (\Throwable $e) {
      $context['results']['error'] = 'archive: ' . $e->getMessage();
    }
  }

  public static function codeUploadOperation(array &$context): void {
    self::uploadOperation($context, 'code', 'code_descriptor');
  }

  public static function dbExportOperation(array &$context): void {
    if (!empty($context['results']['error'])) {
      $context['finished'] = 1;
      return;
    }
    $backupId = (string) $context['results']['backup_id'];
    try {
      $step = self::orchestrator()->runDbArtifactWebStep($backupId);
      if ($step['done']) {
        $path = self::orchestrator()->getArtifactPath($backupId, 'db');
        $size = @filesize($path);
        $sha256 = $size === FALSE ? FALSE : @hash_file('sha256', $path);
        if ($size === FALSE || $sha256 === FALSE) {
          // Not `?: ''` - a failure here must abort loudly, not silently
          // ship a descriptor with an empty/zero checksum or size. This
          // happens after runDbArtifactWebStep() already returned
          // successfully (so it's outside that call's own runGuarded()
          // coverage) - abort explicitly here rather than relying on the
          // catch below, which (correctly, for every OTHER failure in this
          // method) does not re-abort since runGuarded() already did.
          $message = "could not compute size/checksum for {$path}";
          self::orchestrator()->abortBackup($backupId, "export (web step): {$message}");
          throw new ExportFailedException($message);
        }
        $context['results']['db_descriptor'] = (new ArtifactDescriptor('db', $path, $size, $sha256))->toArray();
        $context['finished'] = 1;
        $context['message'] = t('Database export complete.');
      }
      else {
        // Batch API treats any value < 1 as "not finished, call again" -
        // the exact fraction only affects the progress bar's percentage.
        $context['finished'] = 0.5;
        $context['message'] = t('Database export in progress (@remaining table(s) remaining).', ['@remaining' => $step['tables_remaining']]);
      }
    }
    catch (\Throwable $e) {
      $context['results']['error'] = 'export (web step): ' . $e->getMessage();
      $context['finished'] = 1;
    }
  }

  public static function dbUploadOperation(array &$context): void {
    self::uploadOperation($context, 'db', 'db_descriptor');
  }

  public static function completeOperation(array &$context): void {
    if (!empty($context['results']['error'])) {
      return;
    }
    $backupId = (string) $context['results']['backup_id'];
    $artifactResults = $context['results']['artifacts'] ?? [];
    try {
      self::orchestrator()->completeFromArtifacts($backupId, $artifactResults);
      $context['message'] = t('Backup complete.');
    }
    catch (\Throwable $e) {
      $context['results']['error'] = 'complete: ' . $e->getMessage();
    }
  }

  /**
   * Single operation used by the "Resume backup" action: resumes whichever
   * local artifacts already exist for the current in-progress backup and
   * completes it. See BackupOrchestrator::resumeBackup().
   */
  public static function resumeOperation(array &$context): void {
    try {
      $backupId = self::orchestrator()->resumeBackup();
      $context['results']['backup_id'] = $backupId;
      $context['message'] = t('Resumed and completed backup @id.', ['@id' => $backupId]);
    }
    catch (\Throwable $e) {
      $context['results']['error'] = $e->getMessage();
    }
  }

  public static function finished(bool $success, array $results, array $operations): void {
    if (!$success || !empty($results['error'])) {
      \Drupal::messenger()->addError(t('Backup failed: @error', ['@error' => $results['error'] ?? 'unknown error']));
      return;
    }
    \Drupal::messenger()->addStatus(t('Backup @id completed and uploaded to DrupalForge.', ['@id' => $results['backup_id'] ?? '']));
  }

  /**
   * Shared per-part upload loop for either artifact. Re-invoked by the
   * Batch API as long as $context['finished'] < 1; $context['sandbox'] is
   * keyed by $sandboxKey so the code and db upload operations never
   * collide even though Drupal's Batch API context is shared across all
   * operations in a run.
   */
  private static function uploadOperation(array &$context, string $artifactType, string $descriptorKey): void {
    if (!empty($context['results']['error'])) {
      $context['finished'] = 1;
      return;
    }

    $backupId = (string) $context['results']['backup_id'];
    $descriptorData = $context['results'][$descriptorKey] ?? NULL;
    if ($descriptorData === NULL) {
      // Nothing to upload (e.g. --db-only skipped the code artifact).
      $context['finished'] = 1;
      return;
    }
    $descriptor = ArtifactDescriptor::fromArray($descriptorData);

    $sandboxKey = 'upload_' . $artifactType;

    try {
      if (empty($context['sandbox'][$sandboxKey]['plan'])) {
        // Guarded: any failure requesting the upload plan aborts the
        // backup on DrupalForge (DELETE reservation + record last_status),
        // exactly like every other step in the CLI path - this is the
        // fix for the web/Batch path previously bypassing that contract
        // entirely by calling the client directly.
        $plan = self::orchestrator()->requestUpload($backupId, $descriptor);
        $context['sandbox'][$sandboxKey] = [
          'plan' => $plan,
          'total_parts' => $plan->isMultipart() ? count($plan->getParts()) : 1,
          // resumeFrom() is a pure ProgressStateStore read, not a network
          // call - nothing to guard/abort on, so it stays a direct
          // Uploader call rather than going through the orchestrator.
          'next_part' => $plan->isMultipart() ? self::uploader()->resumeFrom($backupId, $descriptor) : 1,
        ];
      }

      $sandbox = &$context['sandbox'][$sandboxKey];
      $plan = $sandbox['plan'];

      if ($plan->isMultipart()) {
        if ($sandbox['next_part'] <= $sandbox['total_parts']) {
          // Guarded, same reasoning as requestUpload() above.
          self::orchestrator()->uploadOnePart($backupId, $descriptor, $plan, $sandbox['next_part']);
          $sandbox['next_part']++;
        }
        $remaining = $sandbox['total_parts'] - $sandbox['next_part'] + 1;
        $context['finished'] = $remaining <= 0 ? 1 : 1 - ($remaining / max(1, $sandbox['total_parts']));
        $context['message'] = t('Uploading @artifact (part @done of @total)...', [
          '@artifact' => $artifactType,
          '@done' => min($sandbox['next_part'] - 1, $sandbox['total_parts']),
          '@total' => $sandbox['total_parts'],
        ]);

        if ($context['finished'] >= 1) {
          $context['results']['artifacts'][] = self::finalizeMultipartResult($backupId, $descriptor);
        }
      }
      else {
        // Guarded, same reasoning as requestUpload() above.
        $context['results']['artifacts'][] = self::orchestrator()->uploadArtifact($backupId, $descriptor);
        $context['finished'] = 1;
        $context['message'] = t('Uploaded @artifact.', ['@artifact' => $artifactType]);
      }
    }
    catch (\Throwable $e) {
      $context['results']['error'] = "upload ({$artifactType}): " . $e->getMessage();
      $context['finished'] = 1;
    }
  }

  /**
   * @return array{artifact: string, sha256: string, size: int, parts: array<int, array{n: int, etag: string}>}
   */
  private static function finalizeMultipartResult(string $backupId, ArtifactDescriptor $descriptor): array {
    $state = self::progress()->getUploadState($backupId, $descriptor->getArtifact());
    return [
      'artifact' => $descriptor->getArtifact(),
      'sha256' => $descriptor->getSha256(),
      'size' => $descriptor->getSize(),
      'parts' => Uploader::partsFromState($state),
    ];
  }

  private static function orchestrator(): BackupOrchestrator {
    return \Drupal::service('drupalforge_migrate.backup_orchestrator');
  }

  private static function uploader(): UploaderInterface {
    return \Drupal::service('drupalforge_migrate.uploader');
  }

  private static function progress(): ProgressStateStore {
    return \Drupal::service('drupalforge_migrate.progress_state_store');
  }

}
