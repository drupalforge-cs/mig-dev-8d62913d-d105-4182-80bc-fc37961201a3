<?php

declare(strict_types=1);

namespace Drupal\drupalforge_migrate\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\StringTranslation\ByteSizeMarkup;
use Drupal\Core\Url;
use Drupal\drupalforge_migrate\Batch\BackupBatch;
use Drupal\drupalforge_migrate\Service\BackupOrchestrator;
use Drupal\drupalforge_migrate\Service\ProgressStateStore;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * The backup status page: last backup, current step, errors, and
 * start/resume actions.
 */
final class MigrationStatusController extends ControllerBase {

  public function __construct(
    private readonly BackupOrchestrator $orchestrator,
    private readonly ProgressStateStore $progress,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('drupalforge_migrate.backup_orchestrator'),
      $container->get('drupalforge_migrate.progress_state_store'),
      $container->get('date.formatter'),
    );
  }

  /**
   * @return array<string, mixed>
   */
  public function build(): array {
    $status = $this->orchestrator->status();
    // The in-progress backup id (if any) is the authority for "is there
    // something to resume" - last_status's backup_id only reflects the most
    // recent *completed or aborted* backup.
    $currentId = $this->orchestrator->getCurrentBackupId();
    $partial = $this->partialUploadSummary($currentId);
    $backups = \is_array($status['backups'] ?? NULL) ? $status['backups'] : [];
    $latest = $this->latestReadyBackup($backups);
    $failed = array_key_exists('ok', $status) && !$status['ok'];

    $build = [];

    // One plain-language summary of where things stand, most urgent first.
    $messages = [];
    if ($partial !== NULL) {
      $messages['warning'][] = $this->t('A backup was interrupted: @done of @total part(s) of the @artifact upload are on DrupalForge. Choose "Resume backup" to continue; parts already uploaded are not sent again.', [
        '@done' => $partial['done'],
        '@total' => $partial['total'],
        '@artifact' => $partial['artifact'] === 'db' ? $this->t('database') : $this->t('code'),
      ]);
    }
    elseif ($failed) {
      $messages['error'][] = $this->t('The last backup attempt (@when) failed, so nothing was saved from it. Your earlier backups below are not affected. You can start a new backup.', [
        '@when' => $this->whenText($status['updated'] ?? NULL),
      ]);
    }
    if ($latest !== NULL) {
      $messages['status'][] = $this->t('Your latest backup is ready: taken @when, available on DrupalForge until @until.', [
        '@when' => $this->whenText($this->readyAt($latest)),
        '@until' => $this->dateFormatter->format((int) $latest['expires'], 'short'),
      ]);
    }
    elseif ($partial === NULL && !$failed && !isset($status['backups_error'])) {
      $messages['status'][] = $this->t('No backup has been made yet. Choose "Start backup" to archive this site\'s code and database and send them to DrupalForge.');
    }
    if (isset($status['backups_error'])) {
      $messages['warning'][] = $this->t('Could not load the list of backups from DrupalForge. Check the connection on the Settings tab.');
    }
    if ($messages !== []) {
      $build['summary'] = ['#theme' => 'status_messages', '#message_list' => $messages];
    }

    if ($failed && !empty($status['errors'])) {
      // Raw error text is kept (it names the failing step) but tucked away:
      // it is useful for support, not for deciding what to do next.
      $build['error_details'] = [
        '#type' => 'details',
        '#title' => $this->t('Technical details of the failed attempt'),
        'text' => ['#markup' => '<pre>' . implode("\n", array_map(
          static fn ($e): string => htmlspecialchars(mb_substr((string) $e, 0, 600)),
          (array) $status['errors'],
        )) . '</pre>'],
      ];
    }

    if ($partial !== NULL) {
      $build['resume'] = [
        '#type' => 'link',
        '#title' => $this->t('Resume backup'),
        '#url' => Url::fromRoute('drupalforge_migrate.status.resume'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
    }
    else {
      $build['start'] = [
        '#type' => 'link',
        '#title' => $this->t('Start backup'),
        '#url' => Url::fromRoute('drupalforge_migrate.status.start'),
        '#attributes' => ['class' => ['button', 'button--primary']],
      ];
    }

    $build['backups'] = [
      '#type' => 'table',
      '#caption' => $this->t('Backups on DrupalForge'),
      '#header' => [
        $this->t('Taken'),
        $this->t('Status'),
        $this->t('Contents'),
        $this->t('Available until'),
        $this->t('Reference'),
      ],
      '#rows' => array_map(fn (array $backup): array => $this->backupRow($backup, $backup === $latest), $backups),
      '#empty' => $this->t('No backups are stored on DrupalForge.'),
      '#weight' => 10,
    ];

    $build['about'] = [
      '#type' => 'details',
      '#title' => $this->t('How backups work'),
      '#weight' => 20,
      'text' => ['#markup' => '<p>' . $this->t('Each backup is one complete copy of this site: its code (as code.tar.gz) and its database (as db.sql.gz). Both are uploaded to DrupalForge, and a backup only counts as "Ready" once both have arrived and been verified. DrupalForge keeps a limited number of backups, each for a limited time, then removes them automatically. Starting a new backup never changes your existing ones. The "Reference" is DrupalForge\'s id for a backup, useful when contacting support.') . '</p>'],
    ];

    return $build;
  }

  /**
   * One table row describing a backup set as DrupalForge reports it.
   *
   * @param array<string, mixed> $backup
   *   A backup set: `group`, `ready`, `expires`, and `artifacts` keyed by
   *   kind (each with `status`, `size`, `ready_at`, `error`).
   *
   * @return array<int, mixed>
   */
  private function backupRow(array $backup, bool $isLatest): array {
    $artifacts = \is_array($backup['artifacts'] ?? NULL) ? $backup['artifacts'] : [];
    $ready = !empty($backup['ready']);

    if ($ready) {
      $statusText = $isLatest ? $this->t('Ready (latest)') : $this->t('Ready');
    }
    else {
      $statuses = array_unique(array_column($artifacts, 'status'));
      $error = implode(' ', array_filter(array_column($artifacts, 'error')));
      $statusText = \in_array('failed', $statuses, TRUE)
        ? $this->t('Failed@detail', ['@detail' => $error !== '' ? ': ' . mb_substr($error, 0, 200) : ''])
        : $this->t('Incomplete: still uploading, or interrupted before finishing');
    }

    $contents = [];
    foreach (['code' => $this->t('Code'), 'db' => $this->t('Database')] as $kind => $label) {
      $size = (int) ($artifacts[$kind]['size'] ?? 0);
      if ($size > 0) {
        $contents[] = $this->t('@label @size', ['@label' => $label, '@size' => ByteSizeMarkup::create($size)]);
      }
    }

    $expires = $backup['expires'] ?? NULL;
    if (\is_int($expires) && $expires > 0) {
      $until = $expires > time()
        ? $this->t('@date (in @interval)', [
          '@date' => $this->dateFormatter->format($expires, 'short'),
          '@interval' => $this->dateFormatter->formatTimeDiffUntil($expires, ['granularity' => 2]),
        ])
        : $this->t('Expired');
    }
    else {
      $until = '-';
    }

    $group = (string) ($backup['group'] ?? '');
    return [
      $ready ? $this->whenText($this->readyAt($backup)) : '-',
      $statusText,
      $contents === [] ? '-' : implode(', ', array_map('strval', $contents)),
      $until,
      ['data' => ['#markup' => '<code title="' . htmlspecialchars($group) . '">' . htmlspecialchars(substr($group, 0, 8)) . '</code>']],
    ];
  }

  /**
   * The newest backup that is ready (DrupalForge lists newest first).
   *
   * @param array<int, array<string, mixed>> $backups
   *
   * @return array<string, mixed>|null
   */
  private function latestReadyBackup(array $backups): ?array {
    foreach ($backups as $backup) {
      if (!empty($backup['ready']) && \is_int($backup['expires'] ?? NULL)) {
        return $backup;
      }
    }
    return NULL;
  }

  /**
   * When a ready backup was completed (unix time), if known.
   *
   * @param array<string, mixed> $backup
   */
  private function readyAt(array $backup): ?int {
    $times = array_filter(array_column($backup['artifacts'] ?? [], 'ready_at'), 'is_int');
    return $times === [] ? NULL : max($times);
  }

  /**
   * "Oct 1, 2026 02:47 (3 hours ago)"-style text for a unix time.
   */
  private function whenText(mixed $timestamp): string {
    if (!is_numeric($timestamp)) {
      return (string) $this->t('at an unknown time');
    }
    $timestamp = (int) $timestamp;
    return $this->dateFormatter->format($timestamp, 'short') . ' (' . $this->dateFormatter->formatTimeDiffSince($timestamp, ['granularity' => 1]) . ' ago)';
  }

  public function start(): RedirectResponse {
    $batch = [
      'title' => $this->t('Running DrupalForge backup'),
      'operations' => BackupBatch::buildOperations(),
      'finished' => [BackupBatch::class, 'finished'],
    ];
    batch_set($batch);
    return batch_process(Url::fromRoute('drupalforge_migrate.status')->toString());
  }

  public function resume(): RedirectResponse {
    $batch = [
      'title' => $this->t('Resuming DrupalForge backup'),
      'operations' => [[[BackupBatch::class, 'resumeOperation'], []]],
      'finished' => [BackupBatch::class, 'finished'],
    ];
    batch_set($batch);
    return batch_process(Url::fromRoute('drupalforge_migrate.status')->toString());
  }

  /**
   * @return array{artifact: string, done: int, total: int|string}|null
   */
  private function partialUploadSummary(?string $backupId): ?array {
    if ($backupId === NULL) {
      return NULL;
    }
    foreach (['code', 'db'] as $artifact) {
      $state = $this->progress->getUploadState($backupId, $artifact);
      $partsDone = $state['parts_done'] ?? [];
      $totalParts = $state['total_parts'] ?? NULL;
      if ($partsDone === []) {
        continue;
      }
      $done = count($partsDone);
      if ($totalParts !== NULL && $done >= (int) $totalParts) {
        // Fully uploaded already - nothing to resume for this artifact.
        continue;
      }
      return [
        'artifact' => $artifact,
        'done' => $done,
        'total' => $totalParts ?? $this->t('unknown'),
      ];
    }
    return NULL;
  }

}
