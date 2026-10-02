<?php

declare(strict_types=1);

namespace Drupal\drupalforge_migrate\Service;

use Drupal\Core\State\StateInterface;

/**
 * Namespaced State wrapper for resumable backup progress.
 *
 * Every resumable facet of a backup - the current backup id, the database
 * export cursor, and per-artifact upload state - lives under the
 * `drupalforge_migrate.progress.` key namespace, keyed by backup id (and,
 * for upload state, also by artifact type) so entries never collide and are
 * trivially clearable on completeBackup()/abortBackup().
 */
final class ProgressStateStore {

  private const NAMESPACE = 'drupalforge_migrate.progress.';

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  public function getCurrentBackupId(): ?string {
    $value = $this->state->get(self::NAMESPACE . 'current_backup_id');
    return \is_string($value) && $value !== '' ? $value : NULL;
  }

  public function setCurrentBackupId(?string $backupId): void {
    if ($backupId === NULL) {
      $this->state->delete(self::NAMESPACE . 'current_backup_id');
      return;
    }
    $this->state->set(self::NAMESPACE . 'current_backup_id', $backupId);
  }

  /**
   * @return array<string, mixed>
   */
  public function getExportCursor(string $backupId): array {
    $value = $this->state->get($this->exportCursorKey($backupId));
    return \is_array($value) ? $value : [];
  }

  /**
   * @param array<string, mixed> $cursor
   */
  public function setExportCursor(string $backupId, array $cursor): void {
    $this->state->set($this->exportCursorKey($backupId), $cursor);
  }

  public function clearExportCursor(string $backupId): void {
    $this->state->delete($this->exportCursorKey($backupId));
  }

  /**
   * @return array<string, mixed>
   */
  public function getUploadState(string $backupId, string $artifact): array {
    $value = $this->state->get($this->uploadStateKey($backupId, $artifact));
    return \is_array($value) ? $value : [];
  }

  /**
   * @param array<string, mixed> $state
   */
  public function setUploadState(string $backupId, string $artifact, array $state): void {
    $this->state->set($this->uploadStateKey($backupId, $artifact), $state);
  }

  public function clearUploadState(string $backupId, string $artifact): void {
    $this->state->delete($this->uploadStateKey($backupId, $artifact));
  }

  /**
   * @return array<string, mixed>
   */
  public function getLastStatus(): array {
    $value = $this->state->get(self::NAMESPACE . 'last_status');
    return \is_array($value) ? $value : [];
  }

  /**
   * @param array<string, mixed> $status
   */
  public function setLastStatus(array $status): void {
    $this->state->set(self::NAMESPACE . 'last_status', $status);
  }

  private function exportCursorKey(string $backupId): string {
    return self::NAMESPACE . 'export_cursor.' . $backupId;
  }

  private function uploadStateKey(string $backupId, string $artifact): string {
    return self::NAMESPACE . 'upload.' . $backupId . '.' . $artifact;
  }

}
