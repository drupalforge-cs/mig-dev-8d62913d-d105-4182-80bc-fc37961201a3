<?php

declare(strict_types=1);

namespace Drupal\drupalforge_migrate\Service;

use Drupal\drupalforge_migrate\Value\ArtifactDescriptor;
use Drupal\drupalforge_migrate\Value\UploadPlan;

/**
 * Uploads a produced artifact to DrupalForge (single PUT or resumable
 * multipart), persisting per-part progress via ProgressStateStore.
 *
 * Exists as an interface (mirroring ShellRunnerInterface /
 * DrupalForgeClientInterface / CodeArchiverInterface / DatabaseExporterInterface)
 * so BackupOrchestrator's upload/complete wiring can be tested against a
 * fake, without PHPUnit needing to double the `final` Uploader class.
 */
interface UploaderInterface {

  /**
   * Requests an upload plan and uploads the whole artifact (single PUT, or
   * every remaining multipart part in a blocking loop) - the path used by
   * the Drush/CLI command, which has no per-request time limit.
   *
   * @return array{artifact: string, sha256: string, size: int, parts: array<int, array{n: int, etag: string}>}
   *   Enough to build the DrupalForge `complete` request body (artifact,
   *   sha256, parts) plus `size`, kept for BackupOrchestrator's local
   *   manifest-building - never sent to DrupalForge as-is.
   */
  public function uploadArtifact(string $backupId, ArtifactDescriptor $artifact): array;

  /**
   * Uploads exactly one multipart part and persists its ETag via
   * ProgressStateStore immediately - the method the Batch API drives, one
   * call per operation invocation, so a crash mid-upload loses at most one
   * part's progress.
   */
  public function uploadOnePart(string $backupId, ArtifactDescriptor $artifact, UploadPlan $plan, int $partNumber): string;

  /**
   * The first part number not yet recorded as done for this backup+artifact
   * in ProgressStateStore (1 if nothing has been uploaded yet).
   */
  public function resumeFrom(string $backupId, ArtifactDescriptor $artifact): int;

}
