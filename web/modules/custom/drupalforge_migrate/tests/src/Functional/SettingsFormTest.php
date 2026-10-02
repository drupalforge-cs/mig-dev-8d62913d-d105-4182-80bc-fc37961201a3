<?php

declare(strict_types=1);

namespace Drupal\Tests\drupalforge_migrate\Functional;

use Drupal\drupalforge_migrate_test_support\Service\TestOverrideKeys;
use Drupal\Tests\BrowserTestBase;

/**
 * @group drupalforge_migrate
 */
final class SettingsFormTest extends BrowserTestBase {

  protected $defaultTheme = 'stark';

  protected static $modules = [
    'drupalforge_migrate',
    'drupalforge_migrate_test_support',
  ];

  public function testRouteIsPermissionGated(): void {
    $this->drupalGet('/admin/config/development/drupalforge-migrate');
    $this->assertSession()->statusCodeEquals(403);

    $admin = $this->drupalCreateUser(['administer drupalforge migrate']);
    $this->drupalLogin($admin);

    $this->drupalGet('/admin/config/development/drupalforge-migrate');
    $this->assertSession()->statusCodeEquals(200);
  }

  public function testSubmittingMigrationIdPersistsToConfig(): void {
    $admin = $this->drupalCreateUser(['administer drupalforge migrate']);
    $this->drupalLogin($admin);

    $this->drupalGet('/admin/config/development/drupalforge-migrate');
    $this->assertSession()->fieldNotExists('url');
    $this->submitForm([
      'migration_id' => 'mig-uuid-abc',
    ], 'Save configuration');

    $config = $this->config('drupalforge_migrate.settings');
    self::assertArrayNotHasKey('url', $config->getRawData(), 'The DrupalForge URL is hardcoded in DrupalForgeClient (overridable only via settings.php), never stored as config.');
    self::assertSame('mig-uuid-abc', $config->get('migration_id'));
  }

  public function testSecretIsStoredInStateNotConfig(): void {
    $admin = $this->drupalCreateUser(['administer drupalforge migrate']);
    $this->drupalLogin($admin);

    $this->drupalGet('/admin/config/development/drupalforge-migrate');
    $this->submitForm([
      'migration_id' => 'mig-uuid-abc',
      'secret' => 'top-secret-value',
    ], 'Save configuration');

    self::assertSame('top-secret-value', \Drupal::state()->get('drupalforge_migrate.secret'));

    $exported = $this->config('drupalforge_migrate.settings')->getRawData();
    self::assertArrayNotHasKey('secret', $exported);
    self::assertStringNotContainsString('top-secret-value', (string) json_encode($exported));
  }

  public function testTestConnectionRendersLimitsAndBackupsOnSuccess(): void {
    \Drupal::state()->set(TestOverrideKeys::CONNECT_RESPONSE, [
      'limits' => ['max_backups' => 3],
      'backups' => [['id' => 'b1', 'status' => 'ready']],
      'status' => 'ok',
    ]);

    $admin = $this->drupalCreateUser(['administer drupalforge migrate']);
    $this->drupalLogin($admin);

    $this->drupalGet('/admin/config/development/drupalforge-migrate');
    $this->submitForm([
      'migration_id' => 'mig-uuid-abc',
    ], 'Test connection');

    $this->assertSession()->pageTextContains('Connected to DrupalForge successfully');
    $this->assertSession()->pageTextContains('max_backups: 3');
  }

  public function testTestConnectionRendersErrorOnFailure(): void {
    \Drupal::state()->set(TestOverrideKeys::CONNECT_SHOULD_THROW, 'simulated network failure');

    $admin = $this->drupalCreateUser(['administer drupalforge migrate']);
    $this->drupalLogin($admin);

    $this->drupalGet('/admin/config/development/drupalforge-migrate');
    $this->submitForm([
      'migration_id' => 'mig-uuid-abc',
    ], 'Test connection');

    $this->assertSession()->pageTextContains('Could not connect to DrupalForge');
    $this->assertSession()->pageTextContains('simulated network failure');
  }

}
