<?php

declare(strict_types=1);

namespace Drupal\drupalforge_migrate\Service;

use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Drupal\drupalforge_migrate\Value\PreflightResult;

/**
 * Detects whether this site is supported for a DrupalForge migration.
 *
 * Never guesses: any missing or ambiguous check makes the result
 * unsupported, with a specific reason naming the failing check.
 */
final class PreflightService {

  private const MIN_PHP_VERSION = '8.1.0';
  private const SUPPORTED_DB_DRIVERS = ['mysql', 'mariadb'];
  private const CODE_SIZE_FILE_CEILING = 200000;

  /**
   * Memoized result of the first run() call this request/process.
   *
   * run() does a full recursive filesystem walk plus a DB query - neither
   * docroot, PHP version, nor DB driver change mid-request, and a single
   * backup calls run() from several places (BackupOrchestrator's
   * startBackup(), runCodeArtifact(), buildManifest()), so recomputing on
   * every call is pure waste. No invalidation is needed for the same
   * reason: nothing this measures can change within one instance's
   * lifetime.
   */
  private ?PreflightResult $cachedResult = NULL;

  public function __construct(
    private readonly Connection $database,
    private readonly FileSystemInterface $fileSystem,
    private readonly ShellRunnerInterface $shellRunner,
  ) {}

  public function run(): PreflightResult {
    if ($this->cachedResult !== NULL) {
      return $this->cachedResult;
    }

    $checks = [];
    $failureReasons = [];

    $checks['php_version'] = PHP_VERSION;
    if (version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '<')) {
      $failureReasons[] = sprintf('PHP version %s is below the required %s.', PHP_VERSION, self::MIN_PHP_VERSION);
    }

    $checks['drupal_version'] = class_exists('\Drupal') ? \Drupal::VERSION : 'unknown';

    $driver = $this->safeDriver();
    $checks['db_driver'] = $driver;
    if (!\in_array($driver, self::SUPPORTED_DB_DRIVERS, TRUE)) {
      $failureReasons[] = sprintf("Unsupported or undetected database driver ('%s'); only MySQL/MariaDB are supported.", $driver ?? 'unknown');
    }

    $dbSize = $this->estimateDbSizeBytes();
    $checks['db_size_bytes'] = $dbSize ?? -1;
    if ($dbSize === NULL) {
      // A failed estimate must fail closed, not report "0 bytes" - which
      // reads as a perfectly fine, tiny database and would let this check
      // pass unnoticed.
      $failureReasons[] = 'Could not estimate database size: a query against information_schema.tables failed.';
    }

    $mysqldumpAvailable = $this->shellRunner->isBinaryAvailable('mysqldump');
    $tarAvailable = $this->shellRunner->isBinaryAvailable('tar');
    $gzipAvailable = $this->shellRunner->isBinaryAvailable('gzip');
    $checks['mysqldump'] = $mysqldumpAvailable;
    $checks['tar'] = $tarAvailable;
    $checks['gzip'] = $gzipAvailable;

    $checks['proc_open'] = \function_exists('proc_open');
    if (!$checks['proc_open']) {
      $failureReasons[] = 'The proc_open() function is disabled; CLI export/archive tools cannot be used.';
    }

    if (!$mysqldumpAvailable) {
      $failureReasons[] = 'mysqldump is not available; database export will need to use the bounded web/batch fallback.';
    }
    if (!$tarAvailable || !$gzipAvailable) {
      $failureReasons[] = 'tar and/or gzip are not available; code archiving will need to use the PHP PharData fallback.';
    }

    $checks['max_execution_time'] = (int) @ini_get('max_execution_time');

    $tempDir = $this->fileSystem->getTempDirectory();
    $tempDirWritable = \is_string($tempDir) && $tempDir !== '' && is_dir($tempDir) && is_writable($tempDir);
    $checks['temp_dir_writable'] = $tempDirWritable;
    if (!$tempDirWritable) {
      $failureReasons[] = sprintf("Temp directory '%s' is not writable.", $tempDir ?: '(unknown)');
    }

    $roots = $this->detectRoots();
    $checks['docroot'] = $roots['docroot'];
    $checks['project_root'] = $roots['project_root'];
    $codeSize = $this->estimateCodeSizeBytes($roots['project_root']);
    $checks['code_size_bytes'] = $codeSize ?? -1;
    if ($codeSize === NULL) {
      // Same fail-closed requirement as the DB size estimate above: a
      // partial walk is not the true size.
      $failureReasons[] = 'Could not fully measure code size: the directory walk failed partway through.';
    }

    return $this->cachedResult = new PreflightResult(empty($failureReasons), $checks, $failureReasons);
  }

  private function safeDriver(): ?string {
    try {
      return $this->database->driver();
    }
    catch (\Throwable) {
      return NULL;
    }
  }

  /**
   * @return int|null
   *   The estimated size in bytes, or NULL if it could not be determined
   *   (the caller must treat this as a failure, not as "0 bytes").
   */
  private function estimateDbSizeBytes(): ?int {
    try {
      $value = $this->database
        ->query('SELECT SUM(data_length + index_length) AS size FROM information_schema.tables WHERE table_schema = DATABASE()')
        ->fetchField();
    }
    catch (\Throwable) {
      return NULL;
    }
    return $value !== FALSE && $value !== NULL ? (int) $value : 0;
  }

  /**
   * @return array{docroot: string, project_root: string}
   */
  private function detectRoots(): array {
    $docroot = $this->resolveDocroot();
    $projectRoot = $docroot;
    $dir = $docroot;

    for ($i = 0; $i < 5; $i++) {
      $parent = \dirname($dir);
      if ($parent === $dir) {
        break;
      }
      if (is_file($parent . '/composer.json') && (is_dir($parent . '/web') || is_dir($parent . '/docroot'))) {
        $projectRoot = $parent;
        break;
      }
      $dir = $parent;
    }

    return ['docroot' => $docroot, 'project_root' => $projectRoot];
  }

  private function resolveDocroot(): string {
    if (\defined('DRUPAL_ROOT')) {
      return DRUPAL_ROOT;
    }
    $realpath = $this->fileSystem->realpath('.');
    return \is_string($realpath) && $realpath !== '' ? $realpath : (string) getcwd();
  }

  /**
   * @return int|null
   *   The measured size in bytes, or NULL if the walk failed partway
   *   through (the caller must treat this as a failure, not as "0 bytes"
   *   or the partial total collected so far).
   */
  private function estimateCodeSizeBytes(string $root): ?int {
    if (!is_dir($root)) {
      return 0;
    }

    $total = 0;
    $count = 0;

    try {
      $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::LEAVES_ONLY,
      );
      foreach ($iterator as $file) {
        /** @var \SplFileInfo $file */
        if ($file->isFile()) {
          $total += $file->getSize();
          if (++$count > self::CODE_SIZE_FILE_CEILING) {
            break;
          }
        }
      }
    }
    catch (\Throwable) {
      return NULL;
    }

    return $total;
  }

}
