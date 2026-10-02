<?php

declare(strict_types=1);

namespace Drupal\Tests\drupalforge_migrate\Kernel;

use Drupal\drupalforge_migrate\Service\DatabaseExporter;
use Drupal\drupalforge_migrate\Service\ProgressStateStore;
use Drupal\drupalforge_migrate\Service\ShellRunner;
use Drupal\KernelTests\KernelTestBase;
use Psr\Log\NullLogger;

/**
 * @coversDefaultClass \Drupal\drupalforge_migrate\Service\DatabaseExporter
 * @group drupalforge_migrate
 */
final class DatabaseExporterWebModeKernelTest extends KernelTestBase {

  protected static $modules = ['drupalforge_migrate'];

  private DatabaseExporter $exporter;
  private string $destination;

  protected function setUp(): void {
    parent::setUp();

    $connection = $this->container->get('database');
    $schema = $connection->schema();

    // A cache_* table: structure-only, no row data should be dumped.
    $schema->createTable('cache_dfm_test', [
      'fields' => [
        'cid' => ['type' => 'varchar', 'length' => 255, 'not null' => TRUE],
        'data' => ['type' => 'blob', 'not null' => FALSE],
      ],
      'primary key' => ['cid'],
    ]);
    $connection->insert('cache_dfm_test')->fields(['cid' => 'x', 'data' => 'should-not-appear'])->execute();

    // A content-like table: rows should be dumped.
    $schema->createTable('dfm_test_content', [
      'fields' => [
        'id' => ['type' => 'serial', 'not null' => TRUE],
        'title' => ['type' => 'varchar', 'length' => 255, 'not null' => FALSE],
      ],
      'primary key' => ['id'],
    ]);
    for ($i = 1; $i <= 3; $i++) {
      $connection->insert('dfm_test_content')->fields(['title' => "row-{$i}"])->execute();
    }

    $this->exporter = new DatabaseExporter(
      $connection,
      new ShellRunner(),
      new ProgressStateStore($this->container->get('state')),
      new NullLogger(),
    );
    $this->destination = sys_get_temp_dir() . '/dfm_web_export_test_' . uniqid() . '.sql.gz';
  }

  protected function tearDown(): void {
    @unlink($this->destination);
    parent::tearDown();
  }

  /**
   * @covers ::listTablesOrdered
   */
  public function testListTablesOrderedIncludesFixtureTables(): void {
    $tables = $this->exporter->listTablesOrdered();
    $this->assertContains('cache_dfm_test', $tables);
    $this->assertContains('dfm_test_content', $tables);
  }

  /**
   * @covers ::exportStepWeb
   */
  public function testCursorAdvancesAcrossStepsAndSkipsStructureOnlyRows(): void {
    $backupId = 'kernel-test-backup';
    $result = ['done' => FALSE];
    $iterations = 0;
    while (!$result['done']) {
      $result = $this->exporter->exportStepWeb($backupId, $this->destination);
      $iterations++;
      $this->assertLessThan(200, $iterations, 'exportStepWeb looped without terminating.');
    }

    $this->assertFileExists($this->destination);
    $this->assertContains('dfm_test_content', $this->exporter->listTablesOrdered(), 'The fixture table must still be a real, listed table after export.');

    $sql = (string) gzdecode((string) file_get_contents($this->destination));
    $this->assertStringContainsString('CREATE TABLE', $sql);
    $this->assertStringContainsString('dfm_test_content', $sql);
    $this->assertStringContainsString('row-1', $sql);
    $this->assertStringContainsString('row-2', $sql);
    $this->assertStringContainsString('row-3', $sql);
    // The cache_* table's structure is dumped, but never its row data.
    $this->assertStringContainsString('cache_dfm_test', $sql);
    $this->assertStringNotContainsString('should-not-appear', $sql);
  }

  /**
   * @covers ::quoteValue
   * @covers ::insertStatement
   *
   * Real round-trip through the actual DB connection's quoting (not a
   * mock): a value containing a single quote and a backslash must
   * produce an INSERT statement that is valid SQL and reproduces the
   * value exactly when re-executed - the concrete corruption scenario
   * addslashes() risks under certain multi-byte charsets.
   */
  public function testInsertStatementRoundTripsQuotesAndBackslashesSafely(): void {
    $connection = $this->container->get('database');
    $tricky = "O'Brien's \\ backslash \\ value";
    $connection->insert('dfm_test_content')->fields(['title' => $tricky])->execute();

    $backupId = 'kernel-quote-roundtrip-backup';
    $result = ['done' => FALSE];
    $iterations = 0;
    while (!$result['done']) {
      $result = $this->exporter->exportStepWeb($backupId, $this->destination);
      $iterations++;
      $this->assertLessThan(200, $iterations, 'exportStepWeb looped without terminating.');
    }

    $sql = (string) gzdecode((string) file_get_contents($this->destination));

    preg_match('/INSERT INTO `dfm_test_content`.*?;/s', $sql, $matches);
    $this->assertNotEmpty($matches, 'Could not find the dfm_test_content INSERT statement in the dump.');

    // The dump intentionally uses the *logical* (unprefixed) table name -
    // it targets a fresh, unprefixed restore DB. Re-executing it against
    // this same (test-prefixed) Kernel DB requires the {table} curly-brace
    // form so Drupal's own prefix substitution maps it back to the real
    // physical table.
    $insertAgainstPrefixedTable = str_replace('`dfm_test_content`', '{dfm_test_content}', $matches[0]);

    // Re-execute the generated statement against a truncated copy of the
    // same table and read the value back - proving it is valid SQL that
    // reproduces the original value exactly, not just "doesn't crash".
    $connection->truncate('dfm_test_content')->execute();
    $connection->query($insertAgainstPrefixedTable);

    $roundTripped = $connection->select('dfm_test_content', 't')
      ->fields('t', ['title'])
      ->condition('title', $tricky)
      ->execute()
      ->fetchField();

    $this->assertSame($tricky, $roundTripped);
  }

}
