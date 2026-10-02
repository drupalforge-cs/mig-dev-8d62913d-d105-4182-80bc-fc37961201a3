<?php

declare(strict_types=1);

namespace Drupal\drupalforge_migrate\Service;

use Drupal\drupalforge_migrate\Value\ArtifactDescriptor;
use Drupal\drupalforge_migrate\Value\BackupManifest;
use Drupal\drupalforge_migrate\Value\PreflightResult;
use Drupal\drupalforge_migrate\Value\UploadPlan;

/**
 * Talks to the DrupalForge migration API.
 *
 * Exists as an interface (rather than depending on the concrete
 * DrupalForgeClient directly) so functional tests can swap in a fake
 * implementation via service decoration - see
 * tests/modules/drupalforge_migrate_test_support.
 */
interface DrupalForgeClientInterface {

  /**
   * The configured DrupalForge base URL (no trailing slash).
   *
   * Hardcoded to production by default; overridable per-environment via
   * `$settings['drupalforge_migrate_url']` in settings.php for dev/stage
   * testing - see DrupalForgeClient's class docblock.
   */
  public function getBaseUrl(): string;

  /**
   * @return array{limits?: array, backups?: array, status?: string}
   */
  public function connect(PreflightResult $preflight): array;

  /**
   * @return array{backups?: array}
   */
  public function listBackups(): array;

  /**
   * @return array{backup_id: string, reserved_until: string}
   *
   * @throws \Drupal\drupalforge_migrate\Exception\QuotaExceededException
   */
  public function reserveBackup(): array;

  public function requestUpload(string $backupId, ArtifactDescriptor $artifact): UploadPlan;

  /**
   * Marks a backup ready on DrupalForge - the only call that can do so.
   *
   * @param array<int, array{artifact: string, sha256: string, parts?: array<int, array{n: int, etag: string}>}> $artifactResults
   *   As returned by Uploader::uploadArtifact() (extra keys, e.g. `size`,
   *   are ignored here - only `artifact`/`sha256`/`parts` are sent).
   */
  public function complete(string $backupId, array $artifactResults, BackupManifest $manifest): void;

  public function abort(string $backupId): void;

}
