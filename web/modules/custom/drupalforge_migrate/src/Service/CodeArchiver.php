<?php

declare(strict_types=1);

namespace Drupal\drupalforge_migrate\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\drupalforge_migrate\Exception\ArchiveFailedException;
use Drupal\drupalforge_migrate\Value\ArtifactDescriptor;
use Psr\Log\LoggerInterface;

/**
 * Produces the code archive artifact (tar.gz). The BackupManifest
 * describing it is built separately by BackupOrchestrator and sent
 * directly in the `complete` request body - never persisted to disk.
 *
 * Prefers a CLI `tar | gzip` pipeline (via ShellRunner) when both binaries
 * are available; otherwise falls back to PHP's PharData, but only when the
 * pre-measured code size is under a configurable ceiling - a site that
 * exceeds it with no CLI tools available is reported as unsupported rather
 * than silently attempting a huge in-PHP archive.
 */
final class CodeArchiver implements CodeArchiverInterface {

  /**
   * Default PharData fallback ceiling: 500 MB.
   */
  private const DEFAULT_PHAR_SIZE_CEILING = 500 * 1024 * 1024;

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly ShellRunnerInterface $shellRunner,
    private readonly LoggerInterface $logger,
    private readonly int $pharSizeCeiling = self::DEFAULT_PHAR_SIZE_CEILING,
  ) {}

  /**
   * @param string[] $siteDirs
   *   Site directory names under sites/, e.g. ['default'].
   *
   * @return array{tar_excludes: string[], settings_files: string[]}
   */
  public function buildExclusionList(string $projectRoot, string $docroot, array $siteDirs, ?string $privateFilesPath): array {
    $tarExcludes = ['.git', 'node_modules'];
    $settingsFiles = [];

    foreach ($siteDirs as $siteDir) {
      $tarExcludes[] = "sites/{$siteDir}/files";
      $settingsPhp = "sites/{$siteDir}/settings.php";
      $tarExcludes[] = $settingsPhp;
      $tarExcludes[] = "sites/{$siteDir}/settings.*.php";
      $settingsFiles[] = $settingsPhp;
    }

    if ($privateFilesPath !== NULL && $privateFilesPath !== '') {
      $tarExcludes[] = $privateFilesPath;
    }

    if (!$this->hasComposerLock($projectRoot)) {
      $tarExcludes[] = 'vendor';
    }

    return [
      'tar_excludes' => $tarExcludes,
      'settings_files' => $settingsFiles,
    ];
  }

  public function hasComposerLock(string $projectRoot): bool {
    return is_file(rtrim($projectRoot, '/') . '/composer.lock');
  }

  /**
   * @param string[] $siteDirs
   */
  public function archive(string $projectRoot, string $docroot, string $destinationTarGz, array $siteDirs, ?string $privateFilesPath): ArtifactDescriptor {
    $exclusions = $this->buildExclusionList($projectRoot, $docroot, $siteDirs, $privateFilesPath);

    if ($this->shellRunner->isBinaryAvailable('tar') && $this->shellRunner->isBinaryAvailable('gzip')) {
      $this->archiveViaCli($projectRoot, $exclusions['tar_excludes'], $destinationTarGz);
    }
    else {
      $codeSize = $this->measureDirectorySize($projectRoot);
      if ($codeSize > $this->pharSizeCeiling) {
        throw new ArchiveFailedException(sprintf(
          'Code archive failed: tar/gzip unavailable and the site (%d bytes) exceeds the %d-byte PHP-archiver limit.',
          $codeSize,
          $this->pharSizeCeiling,
        ));
      }
      $this->archiveViaPharData($projectRoot, $exclusions['tar_excludes'], $destinationTarGz);
    }

    if (!is_file($destinationTarGz)) {
      throw new ArchiveFailedException('Code archive failed: no archive file was produced.');
    }

    $this->verifyArchiveIntegrity($destinationTarGz);

    $checksum = @hash_file('sha256', $destinationTarGz);
    if ($checksum === FALSE) {
      // A read failure here (permission race, the file being removed/
      // rotated out from under it) must abort the backup - shipping an
      // empty checksum would look like a valid, verifiable artifact.
      throw new ArchiveFailedException("Code archive failed: could not compute a checksum for {$destinationTarGz}.");
    }

    return new ArtifactDescriptor(
      'code',
      $destinationTarGz,
      (int) filesize($destinationTarGz),
      $checksum,
    );
  }

  /**
   * @param string[] $excludes
   */
  private function archiveViaCli(string $projectRoot, array $excludes, string $destinationTarGz): void {
    $args = [];
    foreach ($excludes as $exclude) {
      $args[] = '--exclude=' . $exclude;
    }
    $args[] = '-C';
    $args[] = $projectRoot;
    $args[] = '-cf';
    $args[] = '-';
    $args[] = '.';

    $result = $this->shellRunner->runPipedToFile([
      ['tar', $args],
      ['gzip', ['-c']],
    ], $destinationTarGz);

    if (!$result->isSuccessful()) {
      $this->logger->error('Code archive (CLI) failed: @error', ['@error' => $result->getErrorOutput()]);
      throw new ArchiveFailedException('Code archive failed: tar/gzip pipeline exited with an error. ' . $result->getErrorOutput());
    }
  }

  /**
   * @param string[] $excludes
   */
  private function archiveViaPharData(string $projectRoot, array $excludes, string $destinationTarGz): void {
    $tarPath = preg_replace('/\.gz$/', '', $destinationTarGz) ?? ($destinationTarGz . '.tar');

    try {
      if (is_file($tarPath)) {
        unlink($tarPath);
      }
      $phar = new \PharData($tarPath);
      $phar->buildFromDirectory($projectRoot, $this->exclusionRegex($excludes));
      $phar->compress(\Phar::GZ);
      if (is_file($tarPath . '.gz')) {
        rename($tarPath . '.gz', $destinationTarGz);
      }
      unlink($tarPath);
    }
    catch (\Throwable $e) {
      throw new ArchiveFailedException('Code archive failed: PharData fallback error: ' . $e->getMessage(), 0, $e);
    }
  }

  /**
   * @param string[] $excludes
   *
   * Uses '#' as the PCRE delimiter, not '/': the glob->regex translation
   * below turns an escaped `\*` into the wildcard `[^/]*`, which contains
   * a literal, unescaped '/'. With '/' as the delimiter, PCRE reads that
   * '/' as the pattern's closing delimiter, truncating it mid-pattern and
   * misparsing everything after it as modifier flags (this previously
   * threw "RegexIterator::__construct(): Unknown modifier ']'" the moment
   * PharData::buildFromDirectory() actually ran). Exclude patterns are
   * file paths, so they routinely contain '/' - '#' does not appear in
   * any of this module's exclusion patterns, so it's a safe delimiter.
   */
  private function exclusionRegex(array $excludes): string {
    $patterns = array_map(
      static fn (string $exclude): string => str_replace('\*', '[^/]*', preg_quote($exclude, '#')),
      $excludes,
    );
    return '#^(?!.*(' . implode('|', $patterns) . ')).*$#';
  }

  /**
   * @throws \Drupal\drupalforge_migrate\Exception\ArchiveFailedException
   *   If the walk fails partway through. A partial total must never be
   *   treated as the true size - silently under-reporting here would let
   *   an oversized site slip past the PharData ceiling this measurement
   *   exists to enforce.
   */
  /**
   * A CLI pipeline (or the PharData fallback) that reports success but
   * produces a corrupt/incomplete archive must still be caught: this is
   * the only check that verifies the artifact is actually a well-formed
   * gzip/tar stream, rather than just "a file exists at this path."
   *
   * @throws \Drupal\drupalforge_migrate\Exception\ArchiveFailedException
   */
  private function verifyArchiveIntegrity(string $path): void {
    if ($this->shellRunner->isBinaryAvailable('tar')) {
      $result = $this->shellRunner->run('tar', ['-tzf', $path]);
      if (!$result->isSuccessful()) {
        throw new ArchiveFailedException(sprintf('Code archive failed: integrity check failed for %s: %s', $path, $result->getErrorOutput()));
      }
      return;
    }

    // No CLI tools available - fall back to a PHP-native "can this be
    // decompressed at all" check.
    $handle = @gzopen($path, 'rb');
    if ($handle === FALSE) {
      throw new ArchiveFailedException(sprintf('Code archive failed: integrity check failed for %s: could not open as a gzip stream.', $path));
    }
    $chunk = @gzread($handle, 65536);
    $failed = $chunk === FALSE;
    gzclose($handle);
    if ($failed) {
      throw new ArchiveFailedException(sprintf('Code archive failed: integrity check failed for %s: could not decompress.', $path));
    }
  }

  private function measureDirectorySize(string $root): int {
    if (!is_dir($root)) {
      return 0;
    }
    $total = 0;
    try {
      $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::LEAVES_ONLY,
      );
      foreach ($iterator as $file) {
        /** @var \SplFileInfo $file */
        if ($file->isFile()) {
          $total += $file->getSize();
        }
      }
    }
    catch (\Throwable $e) {
      throw new ArchiveFailedException(sprintf('Code archive failed: could not measure code size to check the PHP-archiver ceiling: %s', $e->getMessage()), 0, $e);
    }
    return $total;
  }

}
