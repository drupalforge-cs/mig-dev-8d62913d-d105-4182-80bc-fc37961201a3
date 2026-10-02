<?php

declare(strict_types=1);

namespace Drupal\Tests\drupalforge_migrate\Unit;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\drupalforge_migrate\Service\PreflightService;
use Drupal\drupalforge_migrate\Service\ShellRunnerInterface;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\drupalforge_migrate\Service\PreflightService
 * @group drupalforge_migrate
 */
final class PreflightServiceTest extends TestCase {

  private function mockDatabase(string $driver, int|false $dbSize = 12345): Connection {
    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn($dbSize);

    $database = $this->createMock(Connection::class);
    $database->method('driver')->willReturn($driver);
    $database->method('query')->willReturn($statement);

    return $database;
  }

  private function mockFileSystem(string $tempDir, string $realpath): FileSystemInterface {
    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('getTempDirectory')->willReturn($tempDir);
    $fileSystem->method('realpath')->willReturn($realpath);
    return $fileSystem;
  }

  private function mockShellRunner(array $availableBinaries): ShellRunnerInterface {
    $shellRunner = $this->createMock(ShellRunnerInterface::class);
    $shellRunner->method('isBinaryAvailable')
      ->willReturnCallback(static fn (string $binary): bool => \in_array($binary, $availableBinaries, TRUE));
    return $shellRunner;
  }

  /**
   * @covers ::run
   */
  public function testAllGreenIsSupported(): void {
    $writableTemp = sys_get_temp_dir();
    $service = new PreflightService(
      $this->mockDatabase('mysql'),
      $this->mockFileSystem($writableTemp, $writableTemp),
      $this->mockShellRunner(['mysqldump', 'tar', 'gzip']),
    );

    $result = $service->run();

    self::assertTrue($result->isSupported(), implode('; ', $result->getFailureReasons()));
    self::assertSame([], $result->getFailureReasons());
    self::assertSame('mysql', $result->getChecks()['db_driver']);
  }

  /**
   * @covers ::run
   */
  public function testMissingMysqldumpIsNamedAsTheFailingCheck(): void {
    $writableTemp = sys_get_temp_dir();
    $service = new PreflightService(
      $this->mockDatabase('mysql'),
      $this->mockFileSystem($writableTemp, $writableTemp),
      // No CLI tools available at all, so there is no PHP-fallback-capable
      // path either: mysqldump is missing and proc_open based tar/gzip are
      // also missing.
      $this->mockShellRunner([]),
    );

    $result = $service->run();

    self::assertFalse($result->isSupported());
    $reasons = implode(' ', $result->getFailureReasons());
    self::assertStringContainsString('mysqldump', $reasons);
  }

  /**
   * @covers ::run
   */
  public function testUnwritableTempDirIsUnsupported(): void {
    $service = new PreflightService(
      $this->mockDatabase('mysql'),
      $this->mockFileSystem('/nonexistent-dir-drupalforge-migrate-test', '/nonexistent-dir-drupalforge-migrate-test'),
      $this->mockShellRunner(['mysqldump', 'tar', 'gzip']),
    );

    $result = $service->run();

    self::assertFalse($result->isSupported());
    $reasons = implode(' ', $result->getFailureReasons());
    self::assertStringContainsString('not writable', $reasons);
  }

  /**
   * @covers ::run
   */
  public function testUnknownDbDriverIsUnsupported(): void {
    $writableTemp = sys_get_temp_dir();
    $service = new PreflightService(
      $this->mockDatabase('sqlite'),
      $this->mockFileSystem($writableTemp, $writableTemp),
      $this->mockShellRunner(['mysqldump', 'tar', 'gzip']),
    );

    $result = $service->run();

    self::assertFalse($result->isSupported());
    $reasons = implode(' ', $result->getFailureReasons());
    self::assertStringContainsString('driver', $reasons);
  }

  /**
   * @covers ::run
   *
   * A failed size estimate must fail closed (unsupported, with a named
   * reason), not silently report "0 bytes" - which reads as a perfectly
   * fine, tiny database and would let this check pass unnoticed.
   */
  public function testDbSizeEstimateFailureIsReportedAsUnsupported(): void {
    $writableTemp = sys_get_temp_dir();

    $database = $this->createMock(Connection::class);
    $database->method('driver')->willReturn('mysql');
    $database->method('query')->willThrowException(new \RuntimeException('simulated query failure'));

    $service = new PreflightService(
      $database,
      $this->mockFileSystem($writableTemp, $writableTemp),
      $this->mockShellRunner(['mysqldump', 'tar', 'gzip']),
    );

    $result = $service->run();

    self::assertFalse($result->isSupported());
    $reasons = implode(' ', $result->getFailureReasons());
    self::assertStringContainsString('database size', $reasons);
    self::assertSame(-1, $result->getChecks()['db_size_bytes'], 'A failed estimate must not be silently reported as "0 bytes".');
  }

  /**
   * @covers ::estimateCodeSizeBytes
   *
   * Same "fail closed, not open" requirement as the DB size estimate
   * (testDbSizeEstimateFailureIsReportedAsUnsupported, which also proves
   * run() correctly wires a NULL estimate into "unsupported, with a named
   * reason, checks value -1" - the exact same wiring code shape is used
   * for code size). This test exercises estimateCodeSizeBytes() directly
   * via reflection rather than through run(), because run()'s docroot
   * always resolves to the real site root in this test environment:
   * web/core/tests/bootstrap.php (required unconditionally by this
   * module's own tests/bootstrap.php) defines the DRUPAL_ROOT constant as
   * a side effect, and PreflightService::resolveDocroot() checks
   * DRUPAL_ROOT first - so the injected FileSystemInterface::realpath()
   * mock this class's other tests rely on is silently bypassed for
   * anything that depends on docroot/project_root, with no way to inject
   * a different root through the public API here.
   */
  public function testCodeSizeWalkFailureIsUnsupported(): void {
    $projectRoot = sys_get_temp_dir() . '/dfm_preflight_walk_test_' . uniqid();
    $blockedSubdir = $projectRoot . '/blocked';
    mkdir($blockedSubdir, 0755, TRUE);
    file_put_contents($projectRoot . '/readable.txt', 'ok');
    file_put_contents($blockedSubdir . '/secret.txt', 'nope');
    chmod($blockedSubdir, 0000);

    try {
      $method = new \ReflectionMethod(PreflightService::class, 'estimateCodeSizeBytes');
      $method->setAccessible(TRUE);
      $service = new PreflightService(
        $this->mockDatabase('mysql'),
        $this->mockFileSystem(sys_get_temp_dir(), sys_get_temp_dir()),
        $this->mockShellRunner(['mysqldump', 'tar', 'gzip']),
      );

      self::assertNull($method->invoke($service, $projectRoot), 'A mid-walk failure must return NULL ("could not measure"), not a partial byte count.');
    }
    finally {
      @chmod($blockedSubdir, 0755);
      $this->removeDir($projectRoot);
    }
  }

  /**
   * @covers ::run
   *
   * run() does a full recursive filesystem walk plus an
   * information_schema.tables query - expensive, and unnecessary to redo
   * more than once per request/process, since nothing about docroot/PHP
   * version/DB driver changes mid-request. A single backup currently
   * calls run() from several places (BackupOrchestrator's startBackup(),
   * runCodeArtifact(), buildManifest()); memoizing at this level fixes
   * the redundant recomputation regardless of how many call sites there
   * are.
   */
  public function testRunMemoizesResultAcrossMultipleCalls(): void {
    $writableTemp = sys_get_temp_dir();

    $statement = $this->createMock(StatementInterface::class);
    $statement->method('fetchField')->willReturn(12345);

    $database = $this->createMock(Connection::class);
    $database->expects($this->once())->method('driver')->willReturn('mysql');
    $database->expects($this->once())->method('query')->willReturn($statement);

    $service = new PreflightService(
      $database,
      $this->mockFileSystem($writableTemp, $writableTemp),
      $this->mockShellRunner(['mysqldump', 'tar', 'gzip']),
    );

    $first = $service->run();
    $second = $service->run();

    self::assertSame($first, $second, 'The second call must return the memoized result, not recompute it.');
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

}
