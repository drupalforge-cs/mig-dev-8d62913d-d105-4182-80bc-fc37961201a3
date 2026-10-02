<?php

declare(strict_types=1);

namespace Drupal\drupalforge_migrate\Drush\Commands;

use Drupal\Component\DependencyInjection\ContainerInterface;
use Drupal\drupalforge_migrate\Exception\DrupalForgeApiException;
use Drupal\drupalforge_migrate\Exception\PreflightFailedException;
use Drupal\drupalforge_migrate\Service\BackupOrchestrator;
use Drupal\drupalforge_migrate\Service\DrupalForgeClientInterface;
use Drupal\drupalforge_migrate\Service\PreflightService;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

/**
 * Drush commands for drupalforge_migrate.
 *
 * Thin adapters over BackupOrchestrator - the same shared service layer the
 * Batch API path (src/Batch/BackupBatch.php) also calls, so no business
 * logic lives here.
 */
final class DrupalForgeMigrateCommands extends DrushCommands {

  public function __construct(
    private readonly PreflightService $preflightService,
    private readonly BackupOrchestrator $orchestrator,
    private readonly DrupalForgeClientInterface $client,
  ) {
    parent::__construct();
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('drupalforge_migrate.preflight_service'),
      $container->get('drupalforge_migrate.backup_orchestrator'),
      $container->get('drupalforge_migrate.drupalforge_client'),
    );
  }

  /**
   * Reports whether this site is supported for a DrupalForge migration.
   */
  #[CLI\Command(name: 'drupalforge-migrate:preflight', aliases: ['dfm-preflight'])]
  public function preflight(): int {
    $result = $this->preflightService->run();

    $rows = [];
    foreach ($result->getChecks() as $check => $value) {
      $rows[] = [$check, is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value];
    }

    $this->io()->table(['Check', 'Value'], $rows);

    if ($result->isSupported()) {
      $this->io()->success('This site is supported for a DrupalForge migration.');
      return self::EXIT_SUCCESS;
    }

    $this->io()->error('This site is not supported for a DrupalForge migration:');
    foreach ($result->getFailureReasons() as $reason) {
      $this->io()->writeln(' - ' . $reason);
    }
    return self::EXIT_FAILURE;
  }

  /**
   * Archives the code, exports the database, uploads both to DrupalForge,
   * and marks the backup `ready` - end to end, in one blocking call (no
   * per-request time limit, unlike the web/Batch path).
   */
  #[CLI\Command(name: 'drupalforge-migrate:backup', aliases: ['dfm-backup'])]
  #[CLI\Option(name: 'db-only', description: 'Export only the database artifact.')]
  #[CLI\Option(name: 'code-only', description: 'Archive only the code artifact.')]
  #[CLI\Option(name: 'resume', description: 'Resume a previously interrupted backup: finishes any in-progress database export cursor, then resumes/finishes uploading and completes the backup.')]
  public function backup(array $options = ['db-only' => FALSE, 'code-only' => FALSE, 'resume' => FALSE]): int {
    $dbOnly = (bool) $options['db-only'];
    $codeOnly = (bool) $options['code-only'];
    $resume = (bool) $options['resume'];

    try {
      if ($resume) {
        $backupId = $this->orchestrator->getCurrentBackupId();
        if ($backupId === NULL) {
          $this->io()->error('No in-progress backup to resume.');
          return self::EXIT_FAILURE;
        }
        $this->io()->writeln("Resuming backup {$backupId}...");

        // The db.sql.gz artifact isn't complete/uploadable until the web
        // export cursor (only used in PHP-fallback/web mode) reports done -
        // finish it before resuming uploads.
        if ($this->orchestrator->hasPendingExportCursor($backupId)) {
          do {
            $step = $this->orchestrator->runDbArtifactWebStep($backupId);
            $this->io()->writeln(sprintf('Database export: %d table(s) remaining...', $step['tables_remaining']));
          } while (!$step['done']);
        }

        $this->orchestrator->resumeBackup();
      }
      else {
        $backupId = $this->orchestrator->runFullBackup($dbOnly, $codeOnly);
      }
    }
    catch (PreflightFailedException|DrupalForgeApiException $e) {
      $this->io()->error('Could not start backup: ' . $e->getMessage());
      return self::EXIT_FAILURE;
    }
    catch (\Throwable $e) {
      $this->io()->error('Backup failed: ' . $e->getMessage());
      return self::EXIT_FAILURE;
    }

    $this->io()->success("Backup {$backupId} uploaded and marked ready on DrupalForge.");
    return self::EXIT_SUCCESS;
  }

  /**
   * Reports the last recorded backup status and current DrupalForge backups.
   */
  #[CLI\Command(name: 'drupalforge-migrate:status', aliases: ['dfm-status'])]
  public function status(): int {
    $status = $this->orchestrator->status();

    if (empty($status)) {
      $this->io()->writeln('No backup has been started yet.');
      return self::EXIT_SUCCESS;
    }

    $rows = [];
    foreach ($status as $key => $value) {
      if (\is_array($value)) {
        $value = json_encode($value);
      }
      $rows[] = [$key, (string) $value];
    }
    $this->io()->table(['Field', 'Value'], $rows);

    return empty($status['ok']) && \array_key_exists('ok', $status) ? self::EXIT_FAILURE : self::EXIT_SUCCESS;
  }

  /**
   * Lists backups known to DrupalForge for this migration.
   */
  #[CLI\Command(name: 'drupalforge-migrate:list', aliases: ['dfm-list'])]
  public function list(): int {
    try {
      $response = $this->client->listBackups();
    }
    catch (DrupalForgeApiException $e) {
      $this->io()->error('Could not list backups: ' . $e->getMessage());
      return self::EXIT_FAILURE;
    }

    $backups = $response['backups'] ?? [];
    if ($backups === []) {
      $this->io()->writeln('No backups found.');
      return self::EXIT_SUCCESS;
    }

    // Each entry is a backup set as DrupalForge presents it: a `group` id,
    // a `ready` flag, an `expires` unix time (only once ready), and its
    // artifacts keyed by kind, each with its own status.
    $rows = array_map(static function (array $backup): array {
      $statuses = array_unique(array_column($backup['artifacts'] ?? [], 'status'));
      $status = !empty($backup['ready']) ? 'ready' : ($statuses === [] ? '' : implode('/', $statuses));
      $expires = $backup['expires'] ?? NULL;
      return [
        $backup['group'] ?? $backup['id'] ?? '',
        $status,
        \is_int($expires) ? date('Y-m-d H:i:s T', $expires) : '',
      ];
    }, $backups);
    $this->io()->table(['ID', 'Status', 'Expires'], $rows);

    return self::EXIT_SUCCESS;
  }

}
