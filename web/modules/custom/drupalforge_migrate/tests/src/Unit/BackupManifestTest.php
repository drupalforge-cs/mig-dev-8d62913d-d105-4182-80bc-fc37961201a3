<?php

declare(strict_types=1);

namespace Drupal\Tests\drupalforge_migrate\Unit;

use Drupal\drupalforge_migrate\Value\BackupManifest;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\drupalforge_migrate\Value\BackupManifest
 * @group drupalforge_migrate
 */
final class BackupManifestTest extends TestCase {

  private function sampleData(): array {
    return [
      'docroot' => '/var/www/html/web',
      'php_version' => '8.4.18',
      'drupal_version' => '10.6.18',
      'excluded_paths' => ['sites/default/files', '.git', 'node_modules'],
      'file_counts' => ['modules' => 12, 'themes' => 2],
      'sizes' => ['code' => 156568722, 'db' => 15368192],
      'checksums' => ['code' => str_repeat('a', 64), 'db' => str_repeat('b', 64)],
      'excluded_settings_files' => ['sites/default/settings.php'],
    ];
  }

  /**
   * @covers ::toArray
   * @covers ::fromArray
   */
  public function testRoundTrip(): void {
    $data = $this->sampleData();
    $manifest = BackupManifest::fromArray($data);
    $this->assertSame($data, $manifest->toArray());
  }

  /**
   * @covers ::toJson
   */
  public function testToJsonContainsRequiredKeys(): void {
    $manifest = BackupManifest::fromArray($this->sampleData());
    $decoded = json_decode($manifest->toJson(), TRUE);

    foreach (['docroot', 'php_version', 'drupal_version', 'excluded_paths', 'file_counts', 'sizes', 'checksums', 'excluded_settings_files'] as $key) {
      $this->assertArrayHasKey($key, $decoded, "manifest.json is missing required key '$key'");
    }
  }

  public function testConstructorAndGetters(): void {
    $manifest = new BackupManifest(
      '/var/www/html/web',
      '8.4.18',
      '10.6.18',
      ['sites/default/files'],
      ['modules' => 12],
      ['code' => 100, 'db' => 200],
      ['code' => 'x', 'db' => 'y'],
      ['sites/default/settings.php'],
    );

    $this->assertSame('/var/www/html/web', $manifest->getDocroot());
    $this->assertSame('8.4.18', $manifest->getPhpVersion());
    $this->assertSame('10.6.18', $manifest->getDrupalVersion());
    $this->assertSame(['sites/default/files'], $manifest->getExcludedPaths());
    $this->assertSame(['modules' => 12], $manifest->getFileCounts());
    $this->assertSame(['code' => 100, 'db' => 200], $manifest->getSizes());
    $this->assertSame(['code' => 'x', 'db' => 'y'], $manifest->getChecksums());
    $this->assertSame(['sites/default/settings.php'], $manifest->getExcludedSettingsFiles());
  }

  /**
   * @covers ::__construct
   *
   * Legitimately optional/empty-by-design fields (file_counts is never
   * populated per BackupOrchestrator's documented decision;
   * excluded_paths/excluded_settings_files can genuinely be empty for a
   * site excluding nothing unusual) must NOT be required.
   */
  public function testEmptyOptionalArraysAreAllowed(): void {
    $manifest = new BackupManifest(
      '/var/www/html/web',
      '8.4.18',
      '10.6.18',
      [],
      [],
      ['code' => 100],
      ['code' => str_repeat('a', 64)],
      [],
    );
    $this->assertSame([], $manifest->getExcludedPaths());
    $this->assertSame([], $manifest->getFileCounts());
    $this->assertSame([], $manifest->getExcludedSettingsFiles());
  }

  /**
   * @covers ::__construct
   * @covers ::fromArray
   *
   * Regression test: BackupManifest::fromArray() used to default every
   * missing field with `?? ''`/`?? []` instead of throwing - a manifest
   * built from incomplete data would silently ship empty/garbage data in
   * the `complete` request body DrupalForge relies on.
   */
  public function testFromArrayWithMissingDataThrowsInsteadOfDefaulting(): void {
    $this->expectException(\InvalidArgumentException::class);
    BackupManifest::fromArray([]);
  }

  /**
   * @covers ::__construct
   */
  public function testEmptyDocrootThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/docroot/');
    new BackupManifest('', '8.4.18', '10.6.18', [], [], ['code' => 100], ['code' => str_repeat('a', 64)], []);
  }

  /**
   * @covers ::__construct
   *
   * A manifest describing zero artifacts is nonsensical - a real backup
   * always produces at least one (code and/or db).
   */
  public function testEmptySizesThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/artifact/');
    new BackupManifest('/var/www/html/web', '8.4.18', '10.6.18', [], [], [], ['code' => str_repeat('a', 64)], []);
  }

  /**
   * @covers ::__construct
   */
  public function testEmptyChecksumsThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    new BackupManifest('/var/www/html/web', '8.4.18', '10.6.18', [], [], ['code' => 100], [], []);
  }

}
