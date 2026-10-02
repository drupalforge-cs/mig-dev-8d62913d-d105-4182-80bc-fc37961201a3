<?php

declare(strict_types=1);

namespace Drupal\drupalforge_migrate\Service;

use Drupal\Core\Database\Connection;
use Drupal\drupalforge_migrate\Exception\ExportFailedException;
use Drupal\drupalforge_migrate\Value\ArtifactDescriptor;
use Psr\Log\LoggerInterface;

/**
 * Produces the database export artifact.
 *
 * Two modes:
 * - exportViaCli(): a single blocking `mysqldump | gzip` pipeline, used by
 *   Drush (no request time limit).
 * - exportStepWeb(): one bounded step per call (one table, or one row-batch
 *   of a large table), driven by the Batch API; the cursor is persisted via
 *   ProgressStateStore so a page reload or a fresh request can resume.
 */
final class DatabaseExporter implements DatabaseExporterInterface {

  private const STRUCTURE_ONLY_EXACT = ['watchdog', 'sessions', 'queue', 'semaphore', 'flood', 'batch', 'cachetags'];
  private const STRUCTURE_ONLY_PREFIXES = ['cache_'];
  private const ROW_BATCH_SIZE = 200;
  private const DEFAULT_WEB_EXPORT_MAX_BYTES = 200 * 1024 * 1024;

  public function __construct(
    private readonly Connection $database,
    private readonly ShellRunnerInterface $shellRunner,
    private readonly ProgressStateStore $progress,
    private readonly LoggerInterface $logger,
    private readonly int $webExportMaxBytes = self::DEFAULT_WEB_EXPORT_MAX_BYTES,
  ) {}

  /**
   * @return string[]
   */
  public function listTablesOrdered(): array {
    // Schema::findTables() strips the connection's table prefix and returns
    // logical (unprefixed) table names - a raw `SHOW TABLES` would return
    // physically-prefixed names on any site using table prefixing (as
    // Kernel tests always do), breaking both isStructureOnlyTable()'s name
    // matching and the {table} curly-brace substitution used below.
    $tables = array_values($this->database->schema()->findTables('%'));
    sort($tables, \SORT_STRING);
    return $tables;
  }

  public function isStructureOnlyTable(string $table): bool {
    if (\in_array($table, self::STRUCTURE_ONLY_EXACT, TRUE)) {
      return TRUE;
    }
    foreach (self::STRUCTURE_ONLY_PREFIXES as $prefix) {
      if (str_starts_with($table, $prefix)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  public function exportViaCli(string $destinationSqlGz): ArtifactDescriptor {
    if (!$this->shellRunner->isBinaryAvailable('mysqldump') || !$this->shellRunner->isBinaryAvailable('gzip')) {
      throw new ExportFailedException('Database export failed: mysqldump/gzip are not available on this host.');
    }

    $options = $this->database->getConnectionOptions();
    $defaultsFile = tempnam(sys_get_temp_dir(), 'dfm_mysql_');
    if ($defaultsFile === FALSE) {
      throw new ExportFailedException('Database export failed: could not create a temporary credentials file.');
    }

    try {
      // A --defaults-extra-file keeps the password out of argv (and thus
      // out of `ps`/process-list output), unlike a bare -p<password> arg.
      $ini = "[client]\n"
        . 'host=' . ($options['host'] ?? 'localhost') . "\n"
        . 'user=' . ($options['username'] ?? '') . "\n"
        . 'password=' . ($options['password'] ?? '') . "\n";
      if (!empty($options['port'])) {
        $ini .= 'port=' . $options['port'] . "\n";
      }
      file_put_contents($defaultsFile, $ini);
      chmod($defaultsFile, 0600);

      $result = $this->shellRunner->runPipedToFile([
        [
          'mysqldump',
          [
            '--defaults-extra-file=' . $defaultsFile,
            '--single-transaction',
            '--quick',
            (string) ($options['database'] ?? ''),
          ],
        ],
        ['gzip', ['-c']],
      ], $destinationSqlGz);

      if (!$result->isSuccessful()) {
        $this->logger->error('Database export (CLI) failed: @error', ['@error' => $result->getErrorOutput()]);
        throw new ExportFailedException('Database export failed: mysqldump/gzip pipeline exited with an error. ' . $result->getErrorOutput());
      }
    }
    finally {
      @unlink($defaultsFile);
    }

    $this->verifyDumpIntegrity($destinationSqlGz);

    $checksum = @hash_file('sha256', $destinationSqlGz);
    if ($checksum === FALSE) {
      // A read failure here (permission race, the file being removed/
      // rotated out from under it) must abort the backup - shipping an
      // empty checksum would look like a valid, verifiable artifact.
      throw new ExportFailedException("Database export failed: could not compute a checksum for {$destinationSqlGz}.");
    }

    return new ArtifactDescriptor(
      'db',
      $destinationSqlGz,
      (int) filesize($destinationSqlGz),
      $checksum,
    );
  }

  /**
   * Runs one bounded step of the PHP-fallback web/batch export.
   *
   * Writes plain SQL to an uncompressed sibling file across multiple calls
   * (gzip streams cannot be safely reopened/appended across separate
   * requests), then gzip-compresses it into $destinationSqlGz only once the
   * final table is done.
   *
   * @return array{done: bool, tables_remaining: int, bytes_written: int}
   */
  public function exportStepWeb(string $backupId, string $destinationSqlGz): array {
    $tables = $this->listTablesOrdered();
    $cursor = $this->progress->getExportCursor($backupId) + ['table_index' => 0, 'row_offset' => 0];
    $tempSqlPath = $this->tempSqlPath($backupId);

    if ($cursor['table_index'] === 0 && $cursor['row_offset'] === 0 && !is_file($tempSqlPath)) {
      $estimatedSize = $this->estimateSizeBytes();
      if ($estimatedSize > $this->webExportMaxBytes) {
        throw new ExportFailedException(sprintf(
          "Database export failed: estimated size (%d bytes) exceeds the web export limit (%d bytes). Run 'drush drupalforge-migrate:backup --db-only' instead.",
          $estimatedSize,
          $this->webExportMaxBytes,
        ));
      }
    }

    if ($cursor['table_index'] >= \count($tables)) {
      $this->finalize($tempSqlPath, $destinationSqlGz);
      $this->progress->clearExportCursor($backupId);
      return ['done' => TRUE, 'tables_remaining' => 0, 'bytes_written' => (int) @filesize($destinationSqlGz)];
    }

    $table = $tables[$cursor['table_index']];
    $handle = fopen($tempSqlPath, 'a');
    if ($handle === FALSE) {
      throw new ExportFailedException("Database export failed: could not open {$tempSqlPath} for writing.");
    }

    try {
      if ($cursor['row_offset'] === 0) {
        fwrite($handle, $this->createTableStatement($table) . "\n");
      }

      $tableDone = TRUE;
      if (!$this->isStructureOnlyTable($table)) {
        // LIMIT/OFFSET do not accept bound parameters reliably across
        // drivers; the query builder's range() applies them as validated
        // integers directly, which is what a raw query with placeholders
        // was attempting (and failing) to do.
        $rows = $this->database->select($table, 't')
          ->fields('t')
          ->range($cursor['row_offset'], self::ROW_BATCH_SIZE)
          ->execute()
          ->fetchAll(\PDO::FETCH_ASSOC);

        if ($rows !== []) {
          fwrite($handle, $this->insertStatement($table, $rows) . "\n");
        }
        $tableDone = \count($rows) < self::ROW_BATCH_SIZE;
      }
    }
    finally {
      fclose($handle);
    }

    if ($tableDone) {
      $cursor = ['table_index' => $cursor['table_index'] + 1, 'row_offset' => 0];
    }
    else {
      $cursor = ['table_index' => $cursor['table_index'], 'row_offset' => $cursor['row_offset'] + self::ROW_BATCH_SIZE];
    }
    $this->progress->setExportCursor($backupId, $cursor);

    $remaining = \count($tables) - $cursor['table_index'];
    return [
      'done' => FALSE,
      'tables_remaining' => max(0, $remaining),
      'bytes_written' => (int) @filesize($tempSqlPath),
    ];
  }

  private function tempSqlPath(string $backupId): string {
    return sys_get_temp_dir() . '/drupalforge_migrate_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $backupId) . '.sql';
  }

  private function finalize(string $tempSqlPath, string $destinationSqlGz): void {
    if (!is_file($tempSqlPath)) {
      // No tables at all (edge case) - write an empty gzip stream.
      $stream = gzopen($destinationSqlGz, 'wb9');
      if ($stream !== FALSE) {
        gzclose($stream);
      }
      $this->verifyDumpIntegrity($destinationSqlGz);
      return;
    }

    $in = fopen($tempSqlPath, 'rb');
    $out = gzopen($destinationSqlGz, 'wb9');
    if ($in === FALSE || $out === FALSE) {
      throw new ExportFailedException('Database export failed: could not finalize the gzip stream.');
    }
    try {
      while (!feof($in)) {
        $chunk = fread($in, 1024 * 1024);
        if ($chunk === FALSE) {
          throw new ExportFailedException('Database export failed: could not read the uncompressed SQL temp file while finalizing.');
        }
        if ($chunk === '') {
          continue;
        }
        // gzwrite()'s return must be checked explicitly: a short or failed
        // write here (e.g. a disk-full condition) would otherwise silently
        // produce a truncated .sql.gz that still looks like a complete,
        // successful export.
        $written = @gzwrite($out, $chunk);
        if ($written === FALSE || $written < \strlen($chunk)) {
          throw new ExportFailedException('Database export failed: could not write the compressed dump in full (disk full or I/O error).');
        }
      }
    }
    finally {
      fclose($in);
      gzclose($out);
      @unlink($tempSqlPath);
    }

    $this->verifyDumpIntegrity($destinationSqlGz);
  }

  /**
   * A pipeline/compression step that reports success but produces a
   * corrupt/incomplete dump must still be caught: this is the only check
   * that verifies the artifact is actually a well-formed gzip stream,
   * rather than just "a file exists at this path."
   *
   * @throws \Drupal\drupalforge_migrate\Exception\ExportFailedException
   */
  private function verifyDumpIntegrity(string $path): void {
    if ($this->shellRunner->isBinaryAvailable('gzip')) {
      $result = $this->shellRunner->run('gzip', ['-t', $path]);
      if (!$result->isSuccessful()) {
        throw new ExportFailedException(sprintf('Database export failed: integrity check failed for %s: %s', $path, $result->getErrorOutput()));
      }
      return;
    }

    // No CLI tools available - fall back to a PHP-native "can this be
    // decompressed at all" check.
    $handle = @gzopen($path, 'rb');
    if ($handle === FALSE) {
      throw new ExportFailedException(sprintf('Database export failed: integrity check failed for %s: could not open as a gzip stream.', $path));
    }
    $chunk = @gzread($handle, 65536);
    $failed = $chunk === FALSE;
    gzclose($handle);
    if ($failed) {
      throw new ExportFailedException(sprintf('Database export failed: integrity check failed for %s: could not decompress.', $path));
    }
  }

  /**
   * @throws \Drupal\drupalforge_migrate\Exception\ExportFailedException
   *   If the table's DDL cannot be read. This must abort the whole export
   *   rather than degrade to a placeholder comment: a dump that goes on to
   *   write INSERT rows for a table with no matching CREATE TABLE statement
   *   would be corrupt yet still reported as a successful backup.
   */
  private function createTableStatement(string $table): string {
    try {
      $row = $this->database->query('SHOW CREATE TABLE {' . $table . '}')->fetchAssoc();
    }
    catch (\Throwable $e) {
      $this->logger->error('Could not read CREATE TABLE for @table: @message', ['@table' => $table, '@message' => $e->getMessage()]);
      throw new ExportFailedException(sprintf('Database export failed: could not introspect CREATE TABLE for `%s`: %s', $table, $e->getMessage()), 0, $e);
    }
    $ddl = $row['Create Table'] ?? NULL;
    if (!\is_string($ddl)) {
      $this->logger->error('SHOW CREATE TABLE for @table returned no usable DDL.', ['@table' => $table]);
      throw new ExportFailedException(sprintf('Database export failed: SHOW CREATE TABLE for `%s` returned no usable DDL.', $table));
    }
    return $ddl . ";\n";
  }

  /**
   * @param array<int, array<string, mixed>> $rows
   */
  private function insertStatement(string $table, array $rows): string {
    $columns = array_keys($rows[0]);
    $columnList = '`' . implode('`, `', $columns) . '`';

    $valueGroups = [];
    foreach ($rows as $row) {
      $values = array_map([$this, 'quoteValue'], array_values($row));
      $valueGroups[] = '(' . implode(', ', $values) . ')';
    }

    return sprintf('INSERT INTO `%s` (%s) VALUES %s;', $table, $columnList, implode(', ', $valueGroups));
  }

  /**
   * @throws \Drupal\drupalforge_migrate\Exception\ExportFailedException
   *   If the driver does not support quoting (Connection::quote() returns
   *   FALSE) - not expected for the MySQL/MariaDB drivers this module
   *   supports, but must fail loud rather than emit unquoted SQL.
   */
  private function quoteValue(mixed $value): string {
    if ($value === NULL) {
      return 'NULL';
    }
    if (\is_int($value) || \is_float($value)) {
      return (string) $value;
    }
    // Delegates to the connection's own quoting (real PDO::quote(), driver-
    // and charset-aware) rather than addslashes(), which is unsafe for
    // multi-byte charsets whose trailing lead-byte range overlaps 0x5C
    // (GBK, Big5, Shift-JIS) - a crafted sequence could consume the
    // escaping backslash and let a literal quote through, corrupting the
    // generated INSERT statement.
    $quoted = $this->database->quote((string) $value);
    if ($quoted === FALSE) {
      throw new ExportFailedException('Database export failed: the database driver does not support quoting string values.');
    }
    return $quoted;
  }

  /**
   * @throws \Drupal\drupalforge_migrate\Exception\ExportFailedException
   *   If the estimate could not be determined. This gates the web-export
   *   size ceiling in exportStepWeb() - reporting "0 bytes" on failure
   *   would silently let a database of unknown (possibly huge) size
   *   through a check meant to steer it to the CLI/Drush path instead.
   */
  private function estimateSizeBytes(): int {
    try {
      $value = $this->database
        ->query('SELECT SUM(data_length + index_length) AS size FROM information_schema.tables WHERE table_schema = DATABASE()')
        ->fetchField();
    }
    catch (\Throwable $e) {
      throw new ExportFailedException(sprintf('Database export failed: could not estimate database size to check the web export limit: %s', $e->getMessage()), 0, $e);
    }
    return $value !== FALSE && $value !== NULL ? (int) $value : 0;
  }

}
