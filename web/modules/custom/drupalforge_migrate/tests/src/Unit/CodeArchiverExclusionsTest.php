<?php

declare(strict_types=1);

namespace Drupal\Tests\drupalforge_migrate\Unit;

use Drupal\Core\File\FileSystemInterface;
use Drupal\drupalforge_migrate\Service\CodeArchiver;
use Drupal\drupalforge_migrate\Service\ShellRunnerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @coversDefaultClass \Drupal\drupalforge_migrate\Service\CodeArchiver
 * @group drupalforge_migrate
 */
final class CodeArchiverExclusionsTest extends TestCase {

  private string $tempDir;

  protected function setUp(): void {
    parent::setUp();
    $this->tempDir = sys_get_temp_dir() . '/dfm_archiver_test_' . uniqid();
    mkdir($this->tempDir, 0777, TRUE);
  }

  protected function tearDown(): void {
    $this->removeDir($this->tempDir);
    parent::tearDown();
  }

  private function removeDir(string $dir): void {
    if (!is_dir($dir)) {
      return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
      if ($entry === '.' || $entry === '..') {
        continue;
      }
      $path = $dir . '/' . $entry;
      is_dir($path) ? $this->removeDir($path) : unlink($path);
    }
    rmdir($dir);
  }

  private function makeArchiver(): CodeArchiver {
    $fileSystem = $this->createMock(FileSystemInterface::class);
    $shellRunner = $this->createMock(ShellRunnerInterface::class);
    return new CodeArchiver($fileSystem, $shellRunner, new NullLogger());
  }

  /**
   * @covers ::buildExclusionList
   */
  public function testAlwaysExcludesCoreDirectoriesAndSettingsFiles(): void {
    $archiver = $this->makeArchiver();
    $result = $archiver->buildExclusionList($this->tempDir, $this->tempDir . '/web', ['default'], NULL);

    $this->assertContains('sites/default/files', $result['tar_excludes']);
    $this->assertContains('.git', $result['tar_excludes']);
    $this->assertContains('node_modules', $result['tar_excludes']);
    $this->assertContains('sites/default/settings.php', $result['tar_excludes']);
    $this->assertContains('sites/default/settings.*.php', $result['tar_excludes']);
    $this->assertContains('sites/default/settings.php', $result['settings_files']);
  }

  /**
   * @covers ::buildExclusionList
   */
  public function testExcludesConfiguredPrivateFilesPath(): void {
    $archiver = $this->makeArchiver();
    $result = $archiver->buildExclusionList($this->tempDir, $this->tempDir . '/web', ['default'], 'private-files');

    $this->assertContains('private-files', $result['tar_excludes']);
  }

  /**
   * @covers ::buildExclusionList
   * @covers ::hasComposerLock
   */
  public function testExcludesVendorOnlyWhenNoComposerLock(): void {
    $archiver = $this->makeArchiver();

    $withoutLock = $archiver->buildExclusionList($this->tempDir, $this->tempDir . '/web', ['default'], NULL);
    $this->assertContains('vendor', $withoutLock['tar_excludes'], 'vendor should be excluded when there is no composer.lock (image cannot composer install).');

    touch($this->tempDir . '/composer.lock');
    $withLock = $archiver->buildExclusionList($this->tempDir, $this->tempDir . '/web', ['default'], NULL);
    $this->assertNotContains('vendor', $withLock['tar_excludes'], 'vendor should be INCLUDED when composer.lock exists.');
  }

  /**
   * @covers ::buildExclusionList
   */
  public function testMultipleSiteDirsAllExcluded(): void {
    $archiver = $this->makeArchiver();
    $result = $archiver->buildExclusionList($this->tempDir, $this->tempDir . '/web', ['default', 'example.com'], NULL);

    $this->assertContains('sites/default/files', $result['tar_excludes']);
    $this->assertContains('sites/example.com/files', $result['tar_excludes']);
    $this->assertContains('sites/default/settings.php', $result['settings_files']);
    $this->assertContains('sites/example.com/settings.php', $result['settings_files']);
  }

  /**
   * @covers ::hasComposerLock
   */
  public function testHasComposerLock(): void {
    $archiver = $this->makeArchiver();
    $this->assertFalse($archiver->hasComposerLock($this->tempDir));

    touch($this->tempDir . '/composer.lock');
    $this->assertTrue($archiver->hasComposerLock($this->tempDir));
  }

}
