<?php

declare(strict_types=1);

namespace Drupal\Tests\drupalforge_migrate\Functional;

use Drupal\drupalforge_migrate\Service\ProgressStateStore;
use Drupal\drupalforge_migrate_test_support\Service\TestOverrideKeys;
use Drupal\Tests\BrowserTestBase;

/**
 * @group drupalforge_migrate
 */
final class StatusPageTest extends BrowserTestBase {

  protected $defaultTheme = 'stark';

  protected static $modules = [
    'drupalforge_migrate',
    'drupalforge_migrate_test_support',
  ];

  private function progress(): ProgressStateStore {
    return \Drupal::service('drupalforge_migrate.progress_state_store');
  }

  public function testRouteIsPermissionGated(): void {
    $this->drupalGet('/admin/config/development/drupalforge-migrate/status');
    $this->assertSession()->statusCodeEquals(403);

    $admin = $this->drupalCreateUser(['administer drupalforge migrate']);
    $this->drupalLogin($admin);

    $this->drupalGet('/admin/config/development/drupalforge-migrate/status');
    $this->assertSession()->statusCodeEquals(200);
  }

  public function testRendersSeededErrorNamingTheFailingStep(): void {
    $this->progress()->setLastStatus([
      'backup_id' => 'seeded-backup-1',
      'step' => 'aborted',
      'ok' => FALSE,
      'errors' => ['export (cli): mysqldump/gzip are not available on this host.'],
      'updated' => time(),
    ]);

    $admin = $this->drupalCreateUser(['administer drupalforge migrate']);
    $this->drupalLogin($admin);

    $this->drupalGet('/admin/config/development/drupalforge-migrate/status');
    $this->assertSession()->pageTextContains('mysqldump/gzip are not available on this host');
  }

  public function testRendersResumeActionOnlyWhenAnUploadIsPartial(): void {
    $admin = $this->drupalCreateUser(['administer drupalforge migrate']);
    $this->drupalLogin($admin);

    $this->drupalGet('/admin/config/development/drupalforge-migrate/status');
    $this->assertSession()->linkNotExists('Resume backup');

    $this->progress()->setCurrentBackupId('seeded-backup-2');
    $this->progress()->setUploadState('seeded-backup-2', 'code', [
      'mode' => 'multipart',
      'parts_done' => [1 => 'e1', 2 => 'e2'],
      'total_parts' => 5,
    ]);

    $this->drupalGet('/admin/config/development/drupalforge-migrate/status');
    $this->assertSession()->linkExists('Resume backup');
  }

  public function testListsEachBackupWithPlainLanguageStatus(): void {
    $now = time();
    \Drupal::state()->set(TestOverrideKeys::CONNECT_RESPONSE, ['backups' => [
      [
        'group' => 'aaaaaaaa-1111-4111-8111-111111111111',
        'ready' => TRUE,
        'expires' => $now + 3600,
        'artifacts' => [
          'code' => ['status' => 'ready', 'size' => 31457280, 'ready_at' => $now - 60],
          'db' => ['status' => 'ready', 'size' => 2048, 'ready_at' => $now - 60],
        ],
      ],
      [
        'group' => 'bbbbbbbb-2222-4222-8222-222222222222',
        'ready' => TRUE,
        'expires' => $now + 7200,
        'artifacts' => [
          'code' => ['status' => 'ready', 'size' => 31457280, 'ready_at' => $now - 7200],
          'db' => ['status' => 'ready', 'size' => 2048, 'ready_at' => $now - 7200],
        ],
      ],
      [
        'group' => 'cccccccc-3333-4333-8333-333333333333',
        'ready' => FALSE,
        'expires' => NULL,
        'artifacts' => [
          'code' => ['status' => 'uploading', 'size' => 0],
          'db' => ['status' => 'reserved', 'size' => 0],
        ],
      ],
    ]]);

    $admin = $this->drupalCreateUser(['administer drupalforge migrate']);
    $this->drupalLogin($admin);
    $this->drupalGet('/admin/config/development/drupalforge-migrate/status');

    $session = $this->assertSession();
    $session->pageTextContains('Your latest backup is ready');
    $session->pageTextContains('Ready (latest)');
    // Every backup is listed, identified by a short reference.
    $session->pageTextContains('aaaaaaaa');
    $session->pageTextContains('bbbbbbbb');
    $session->pageTextContains('cccccccc');
    $session->pageTextContains('Code 30 MB');
    $session->pageTextContains('Incomplete');
    $session->pageTextContains('How backups work');
  }

  public function testExplainsWhenNoBackupExistsYet(): void {
    $admin = $this->drupalCreateUser(['administer drupalforge migrate']);
    $this->drupalLogin($admin);
    $this->drupalGet('/admin/config/development/drupalforge-migrate/status');

    $this->assertSession()->pageTextContains('No backup has been made yet');
    $this->assertSession()->linkExists('Start backup');
  }

  public function testFailureBannerReassuresThatEarlierBackupsAreUnaffected(): void {
    $this->progress()->setLastStatus([
      'backup_id' => 'seeded-backup-3',
      'step' => 'aborted',
      'ok' => FALSE,
      'errors' => ['upload (code): boom'],
      'updated' => time(),
    ]);
    $admin = $this->drupalCreateUser(['administer drupalforge migrate']);
    $this->drupalLogin($admin);
    $this->drupalGet('/admin/config/development/drupalforge-migrate/status');

    $this->assertSession()->pageTextContains('nothing was saved from it');
    $this->assertSession()->pageTextContains('Technical details of the failed attempt');
    $this->assertSession()->pageTextContains('upload (code): boom');
  }

}
