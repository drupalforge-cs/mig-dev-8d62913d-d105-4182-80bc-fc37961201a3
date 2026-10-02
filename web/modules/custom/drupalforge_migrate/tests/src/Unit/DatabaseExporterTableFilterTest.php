<?php

declare(strict_types=1);

namespace Drupal\Tests\drupalforge_migrate\Unit;

use Drupal\Component\Datetime\Time;
use Drupal\Core\Cache\NullBackend;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Schema;
use Drupal\Core\Database\StatementInterface;
use Drupal\Core\KeyValueStore\KeyValueMemoryFactory;
use Drupal\Core\Lock\NullLockBackend;
use Drupal\Core\State\State;
use Drupal\drupalforge_migrate\Exception\ExportFailedException;
use Drupal\drupalforge_migrate\Service\DatabaseExporter;
use Drupal\drupalforge_migrate\Service\ProgressStateStore;
use Drupal\drupalforge_migrate\Service\ShellResult;
use Drupal\drupalforge_migrate\Service\ShellRunner;
use Drupal\drupalforge_migrate\Service\ShellRunnerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @coversDefaultClass \Drupal\drupalforge_migrate\Service\DatabaseExporter
 * @group drupalforge_migrate
 */
final class DatabaseExporterTableFilterTest extends TestCase {

  private function makeExporter(): DatabaseExporter {
    $database = $this->createMock(Connection::class);
    $shellRunner = $this->createMock(ShellRunnerInterface::class);
    // ProgressStateStore is `final` (like ShellRunner/DrupalForgeClient) so
    // it cannot be mocked - construct a real one against in-memory State.
    $state = new State(new KeyValueMemoryFactory(), new NullBackend('state'), new NullLockBackend());
    $progress = new ProgressStateStore($state);
    return new DatabaseExporter($database, $shellRunner, $progress, new NullLogger());
  }

  /**
   * @covers ::isStructureOnlyTable
   * @dataProvider structureOnlyProvider
   */
  public function testIsStructureOnlyTable(string $table, bool $expected): void {
    $exporter = $this->makeExporter();
    $this->assertSame($expected, $exporter->isStructureOnlyTable($table));
  }

  public static function structureOnlyProvider(): array {
    return [
      'cache_default' => ['cache_default', TRUE],
      'cache_render' => ['cache_render', TRUE],
      'cachetags' => ['cachetags', TRUE],
      'watchdog' => ['watchdog', TRUE],
      'sessions' => ['sessions', TRUE],
      'queue' => ['queue', TRUE],
      'semaphore' => ['semaphore', TRUE],
      'flood' => ['flood', TRUE],
      'batch' => ['batch', TRUE],
      'node' => ['node', FALSE],
      'node_field_data' => ['node_field_data', FALSE],
      'users' => ['users', FALSE],
      'cache_like_but_not' => ['cacheish_thing', FALSE],
    ];
  }

  /**
   * @covers ::exportStepWeb
   *
   * A failed SHOW CREATE TABLE must abort the whole export, not degrade to
   * a placeholder comment - otherwise the dump would contain INSERT rows
   * with no matching CREATE TABLE statement and still be reported as a
   * successful export.
   */
  public function testExportStepWebThrowsWhenShowCreateTableFails(): void {
    $database = $this->createMock(Connection::class);

    $schema = $this->createMock(Schema::class);
    $schema->method('findTables')->willReturn(['broken_table']);
    $database->method('schema')->willReturn($schema);

    $sizeStatement = $this->createMock(StatementInterface::class);
    $sizeStatement->method('fetchField')->willReturn(10);

    $database->method('query')->willReturnCallback(function (string $sql) use ($sizeStatement) {
      if (str_contains($sql, 'information_schema.tables')) {
        return $sizeStatement;
      }
      throw new \RuntimeException('simulated SHOW CREATE TABLE failure');
    });

    $shellRunner = $this->createMock(ShellRunnerInterface::class);
    $state = new State(new KeyValueMemoryFactory(), new NullBackend('state'), new NullLockBackend());
    $progress = new ProgressStateStore($state);
    $exporter = new DatabaseExporter($database, $shellRunner, $progress, new NullLogger());

    $destination = sys_get_temp_dir() . '/dfm_should_not_be_created_' . uniqid() . '.sql.gz';

    try {
      $this->expectException(ExportFailedException::class);
      $this->expectExceptionMessageMatches('/broken_table/');
      $exporter->exportStepWeb('backup-broken-ddl', $destination);
    }
    finally {
      $this->assertFileDoesNotExist($destination, 'A dump must never be finalized past a table whose DDL could not be read.');
      @unlink($destination);
      @unlink(sys_get_temp_dir() . '/drupalforge_migrate_backup-broken-ddl.sql');
    }
  }

  /**
   * @covers ::quoteValue
   *
   * addslashes() is unsafe for multi-byte charsets whose trailing
   * lead-byte range overlaps 0x5C (GBK, Big5, Shift-JIS) - a crafted
   * sequence can consume the escaping backslash and let a literal quote
   * through, corrupting the generated INSERT. Delegating to the
   * connection's own quote() (real PDO::quote(), charset-aware) is the
   * fix; this proves the wiring actually uses it rather than a
   * reimplementation.
   */
  public function testQuoteValueDelegatesToConnectionQuote(): void {
    $database = $this->createMock(Connection::class);
    $database->method('quote')
      ->with("it's got a backslash: \\")
      ->willReturn("'it''s got a backslash: \\\\'");

    $shellRunner = $this->createMock(ShellRunnerInterface::class);
    $state = new State(new KeyValueMemoryFactory(), new NullBackend('state'), new NullLockBackend());
    $progress = new ProgressStateStore($state);
    $exporter = new DatabaseExporter($database, $shellRunner, $progress, new NullLogger());

    $method = new \ReflectionMethod(DatabaseExporter::class, 'quoteValue');
    $method->setAccessible(TRUE);

    self::assertSame("'it''s got a backslash: \\\\'", $method->invoke($exporter, "it's got a backslash: \\"));
    self::assertSame('NULL', $method->invoke($exporter, NULL));
    self::assertSame('42', $method->invoke($exporter, 42));
    self::assertSame('3.5', $method->invoke($exporter, 3.5));
  }

  /**
   * @covers ::exportStepWeb
   *
   * A failed size estimate (used to decide whether the PHP web/batch
   * fallback is safe to attempt) must fail closed - refuse the export -
   * rather than silently reporting "0 bytes" and letting a database of
   * unknown (possibly huge) size through a ceiling meant to steer it to
   * the CLI/Drush path instead.
   */
  public function testExportStepWebThrowsWhenSizeEstimateFails(): void {
    $schema = $this->createMock(Schema::class);
    $schema->method('findTables')->willReturn([]);

    $database = $this->createMock(Connection::class);
    $database->method('schema')->willReturn($schema);
    $database->method('query')->willThrowException(new \RuntimeException('simulated size estimate failure'));

    $shellRunner = $this->createMock(ShellRunnerInterface::class);
    $state = new State(new KeyValueMemoryFactory(), new NullBackend('state'), new NullLockBackend());
    $progress = new ProgressStateStore($state);
    $exporter = new DatabaseExporter($database, $shellRunner, $progress, new NullLogger());

    $destination = sys_get_temp_dir() . '/dfm_should_not_be_created_' . uniqid() . '.sql.gz';

    try {
      $this->expectException(ExportFailedException::class);
      $this->expectExceptionMessageMatches('/could not estimate/i');
      $exporter->exportStepWeb('backup-size-estimate-failure', $destination);
    }
    finally {
      $this->assertFileDoesNotExist($destination);
      @unlink($destination);
      @unlink(sys_get_temp_dir() . '/drupalforge_migrate_backup-size-estimate-failure.sql');
    }
  }

  /**
   * @covers ::finalize
   *
   * A short/failed gzwrite() (e.g. a disk-full condition mid-compression)
   * must abort, not silently produce a truncated .sql.gz that looks like
   * a complete, valid export. Reached via reflection (finalize() is
   * private): /dev/full is a real Linux device that unconditionally
   * fails writes, giving a deterministic, portable way to trigger a
   * genuine short gzwrite() without needing to actually fill a disk.
   */
  public function testFinalizeThrowsOnShortGzwrite(): void {
    if (!file_exists('/dev/full')) {
      self::markTestSkipped('/dev/full is not available in this environment.');
    }

    // zlib buffers internally before the underlying write() syscall (and
    // thus the ENOSPC failure) actually surfaces - empirically ~8MB of
    // highly-compressible input, so use incompressible-ish data and a
    // healthy margin to reach a short write deterministically.
    $tempSqlPath = sys_get_temp_dir() . '/dfm_finalize_test_' . uniqid() . '.sql';
    $handle = fopen($tempSqlPath, 'wb');
    for ($i = 0; $i < 20; $i++) {
      fwrite($handle, bin2hex(random_bytes(1024 * 1024)));
    }
    fclose($handle);

    $exporter = $this->makeExporter();
    $method = new \ReflectionMethod(DatabaseExporter::class, 'finalize');
    $method->setAccessible(TRUE);

    try {
      $this->expectException(ExportFailedException::class);
      $this->expectExceptionMessageMatches('/write/i');
      $method->invoke($exporter, $tempSqlPath, '/dev/full');
    }
    finally {
      @unlink($tempSqlPath);
    }
  }

  /**
   * @covers ::exportViaCli
   *
   * A mysqldump/gzip pipeline that reports success but produces a
   * corrupt/incomplete dump must still be caught here, by verifying the
   * dump is actually a well-formed gzip stream before treating it as a
   * successfully produced artifact.
   */
  public function testExportViaCliThrowsWhenProducedFileFailsIntegrityCheck(): void {
    $database = $this->createMock(Connection::class);
    $database->method('getConnectionOptions')->willReturn([
      'host' => 'db',
      'username' => 'db',
      'password' => 'db',
      'database' => 'db',
    ]);

    $shellRunner = $this->createMock(ShellRunnerInterface::class);
    $shellRunner->method('isBinaryAvailable')->willReturn(TRUE);

    $destination = sys_get_temp_dir() . '/dfm_export_corrupt_' . uniqid() . '.sql.gz';

    $shellRunner->method('runPipedToFile')->willReturnCallback(function () use ($destination): ShellResult {
      file_put_contents($destination, 'this is not a valid gzip stream');
      return new ShellResult(0, '', '');
    });
    // Real `gzip -t` on genuinely garbage bytes fails - exercise that for
    // real rather than mocking the verdict itself.
    $realShellRunner = new ShellRunner();
    $shellRunner->method('run')->willReturnCallback(
      static fn (string $command, array $args = [], ?string $cwd = NULL): ShellResult => $realShellRunner->run($command, $args, $cwd),
    );

    $state = new State(new KeyValueMemoryFactory(), new NullBackend('state'), new NullLockBackend());
    $progress = new ProgressStateStore($state);
    $exporter = new DatabaseExporter($database, $shellRunner, $progress, new NullLogger());

    try {
      $this->expectException(ExportFailedException::class);
      $this->expectExceptionMessageMatches('/integrity check failed/i');
      $exporter->exportViaCli($destination);
    }
    finally {
      @unlink($destination);
    }
  }

  /**
   * @covers ::exportViaCli
   *
   * A read failure while hashing a just-produced dump (e.g. a permission
   * race) must abort the backup, not silently ship an empty checksum that
   * looks valid.
   */
  public function testExportViaCliThrowsWhenChecksumCannotBeComputed(): void {
    $database = $this->createMock(Connection::class);
    $database->method('getConnectionOptions')->willReturn([
      'host' => 'db',
      'username' => 'db',
      'password' => 'db',
      'database' => 'db',
    ]);

    $shellRunner = $this->createMock(ShellRunnerInterface::class);
    $shellRunner->method('isBinaryAvailable')->willReturn(TRUE);

    $destination = sys_get_temp_dir() . '/dfm_export_unreadable_' . uniqid() . '.sql.gz';

    // Simulate the mysqldump/gzip pipeline succeeding and producing a real
    // file that then becomes unreadable before exportViaCli() hashes it.
    // The integrity check (which also shells out via
    // isBinaryAvailable/run()) must be allowed to pass so the flow
    // actually reaches hash_file() - this test is specifically about the
    // checksum step, not integrity.
    $shellRunner->method('runPipedToFile')->willReturnCallback(function () use ($destination): ShellResult {
      file_put_contents($destination, 'not a real dump, just bytes');
      chmod($destination, 0000);
      return new ShellResult(0, '', '');
    });
    $shellRunner->method('run')->willReturn(new ShellResult(0, '', ''));

    $state = new State(new KeyValueMemoryFactory(), new NullBackend('state'), new NullLockBackend());
    $progress = new ProgressStateStore($state);
    $exporter = new DatabaseExporter($database, $shellRunner, $progress, new NullLogger());

    try {
      $this->expectException(ExportFailedException::class);
      $this->expectExceptionMessageMatches('/checksum/i');
      $exporter->exportViaCli($destination);
    }
    finally {
      @chmod($destination, 0644);
      @unlink($destination);
    }
  }

}
