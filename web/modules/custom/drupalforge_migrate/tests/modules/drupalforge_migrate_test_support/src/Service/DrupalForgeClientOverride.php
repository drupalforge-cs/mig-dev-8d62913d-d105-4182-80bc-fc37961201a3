<?php

declare(strict_types=1);

namespace Drupal\drupalforge_migrate_test_support\Service;

use Drupal\Core\State\StateInterface;
use Drupal\drupalforge_migrate\Exception\DrupalForgeApiException;
use Drupal\drupalforge_migrate\Service\DrupalForgeClientInterface;
use Drupal\drupalforge_migrate\Value\ArtifactDescriptor;
use Drupal\drupalforge_migrate\Value\BackupManifest;
use Drupal\drupalforge_migrate\Value\PreflightResult;
use Drupal\drupalforge_migrate\Value\UploadPlan;

/**
 * Decorates drupalforge_migrate.drupalforge_client for functional tests.
 *
 * Never touches the network: every method reads a canned response/failure
 * from State (set by the test via TestOverrideKeys), so tests can exercise
 * both success and failure paths deterministically.
 */
final class DrupalForgeClientOverride implements DrupalForgeClientInterface {

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  public function getBaseUrl(): string {
    return (string) $this->state->get(TestOverrideKeys::BASE_URL, 'https://forge.example.test');
  }

  public function connect(PreflightResult $preflight): array {
    if ($this->state->get(TestOverrideKeys::CONNECT_SHOULD_THROW, FALSE)) {
      throw new DrupalForgeApiException((string) $this->state->get(TestOverrideKeys::CONNECT_SHOULD_THROW));
    }
    return $this->state->get(TestOverrideKeys::CONNECT_RESPONSE, [
      'limits' => ['max_backups' => 3],
      'backups' => [],
      'status' => 'ok',
    ]);
  }

  public function listBackups(): array {
    return $this->state->get(TestOverrideKeys::CONNECT_RESPONSE, ['backups' => []]);
  }

  public function reserveBackup(): array {
    return ['backup_id' => 'fake-backup', 'reserved_until' => '2026-01-01T00:00:00Z'];
  }

  public function requestUpload(string $backupId, ArtifactDescriptor $artifact): UploadPlan {
    return UploadPlan::fromResponse([
      'mode' => 'single',
      'url' => 'https://example.test/fake-put-url',
      'part_size' => 0,
      'expires' => '2026-01-01T00:00:00Z',
    ]);
  }

  public function complete(string $backupId, array $artifactResults, BackupManifest $manifest): void {
    // No-op: nothing to mark ready against a fake backend.
  }

  public function abort(string $backupId): void {
    // No-op: nothing to abort against a fake backend.
  }

}
