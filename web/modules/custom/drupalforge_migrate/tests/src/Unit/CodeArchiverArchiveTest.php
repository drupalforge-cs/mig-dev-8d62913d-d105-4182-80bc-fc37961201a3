<?php

declare(strict_types=1);

namespace Drupal\Tests\drupalforge_migrate\Unit;

use Drupal\Core\File\FileSystemInterface;
use Drupal\drupalforge_migrate\Exception\ArchiveFailedException;
use Drupal\drupalforge_migrate\Service\CodeArchiver;
use Drupal\drupalforge_migrate\Service\ShellResult;
use Drupal\drupalforge_migrate\Service\ShellRunner;
use Drupal\drupalforge_migrate\Service\ShellRunnerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @coversDefaultClass \Drupal\drupalforge_migrate\Service\CodeArchiver
 * @group drupalforge_migrate
 */
final class CodeArchiverArchiveTest extends TestCase {

  private string $projectRoot;
  private string $destinationDir;

  protected function setUp(): void {
    parent::setUp();
    $base = sys_get_temp_dir() . '/dfm_archive_test_' . uniqid();
    $this->projectRoot = $base . '/project';
    $this->destinationDir = $base . '/dest';
    mkdir($this->projectRoot . '/web/sites/default/files', 0777, TRUE);
    mkdir($this->destinationDir, 0777, TRUE);
    file_put_contents($this->projectRoot . '/composer.json', '{}');
    file_put_contents($this->projectRoot . '/web/index.php', '<?php // fake');
    file_put_contents($this->projectRoot . '/web/sites/default/settings.php', '<?php // secrets');
    file_put_contents($this->projectRoot . '/web/sites/default/files/should-be-excluded.txt', 'user upload');
  }

  protected function tearDown(): void {
    $this->removeDir(dirname($this->projectRoot));
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

  /**
   * @covers ::archive
   */
  public function testArchiveViaCliExcludesFilesAndSettings(): void {
    $fileSystem = $this->createMock(FileSystemInterface::class);
    $archiver = new CodeArchiver($fileSystem, new ShellRunner(), new NullLogger());

    $destination = $this->destinationDir . '/code.tar.gz';
    $descriptor = $archiver->archive($this->projectRoot, $this->projectRoot . '/web', $destination, ['default'], NULL);

    $this->assertSame('code', $descriptor->getArtifact());
    $this->assertGreaterThan(0, $descriptor->getSize());
    $this->assertSame(64, strlen($descriptor->getSha256()));
    $this->assertFileExists($destination);

    $listing = shell_exec('tar -tzf ' . escapeshellarg($destination));
    $this->assertIsString($listing);
    $this->assertStringContainsString('composer.json', $listing);
    $this->assertStringNotContainsString('settings.php', $listing);
    $this->assertStringNotContainsString('should-be-excluded.txt', $listing);
  }

  /**
   * @covers ::archive
   */
  public function testThrowsWhenNoCliToolsAndSizeExceedsCeiling(): void {
    $shellRunner = $this->createMock(ShellRunnerInterface::class);
    $shellRunner->method('isBinaryAvailable')->willReturn(FALSE);
    $fileSystem = $this->createMock(FileSystemInterface::class);

    // Ceiling of 1 byte guarantees any real fixture exceeds it.
    $archiver = new CodeArchiver($fileSystem, $shellRunner, new NullLogger(), 1);

    $this->expectException(ArchiveFailedException::class);
    $this->expectExceptionMessageMatches('/exceeds the 1-byte PHP-archiver limit/');
    $archiver->archive($this->projectRoot, $this->projectRoot . '/web', $this->destinationDir . '/code.tar.gz', ['default'], NULL);
  }

  /**
   * @covers ::archive
   *
   * Regression test: exclusionRegex() built its pattern with '/' as the
   * PCRE delimiter, but the glob->regex translation for
   * `settings.*.php`-style excludes injects a literal, unescaped '/'
   * inside `[^/]*` - which PCRE reads as an early closing delimiter,
   * corrupting the pattern and making PharData::buildFromDirectory()
   * throw "RegexIterator::__construct(): Unknown modifier ']'" the moment
   * it actually runs. The CLI-available and over-ceiling-refusal tests
   * above never exercise buildFromDirectory() actually executing, so this
   * was never caught.
   */
  public function testArchiveViaPharDataFallbackExcludesFilesAndSettings(): void {
    $shellRunner = $this->createMock(ShellRunnerInterface::class);
    $shellRunner->method('isBinaryAvailable')->willReturn(FALSE);
    $fileSystem = $this->createMock(FileSystemInterface::class);

    $archiver = new CodeArchiver($fileSystem, $shellRunner, new NullLogger());

    $destination = $this->destinationDir . '/code.tar.gz';
    $descriptor = $archiver->archive($this->projectRoot, $this->projectRoot . '/web', $destination, ['default'], NULL);

    $this->assertSame('code', $descriptor->getArtifact());
    $this->assertFileExists($destination);

    $names = [];
    foreach (new \RecursiveIteratorIterator(new \PharData($destination)) as $file) {
      /** @var \SplFileInfo $file */
      $names[] = $file->getPathname();
    }
    $joined = implode("\n", $names);

    $this->assertStringContainsString('composer.json', $joined);
    $this->assertStringNotContainsString('settings.php', $joined);
    $this->assertStringNotContainsString('should-be-excluded.txt', $joined);
  }

  /**
   * @covers ::archive
   *
   * A failed size measurement (used to decide whether the PharData
   * fallback is safe to attempt) must fail closed - refuse the archive -
   * rather than silently under-reporting the size and risking a PHP OOM/
   * timeout on a site that was never actually confirmed to be small
   * enough for the in-PHP archiver.
   */
  public function testArchiveThrowsWhenCodeSizeCannotBeMeasuredForPharDataCeiling(): void {
    $shellRunner = $this->createMock(ShellRunnerInterface::class);
    // No CLI tools -> forces the PharData path, which measures size first
    // to check it against the ceiling.
    $shellRunner->method('isBinaryAvailable')->willReturn(FALSE);
    $fileSystem = $this->createMock(FileSystemInterface::class);

    $blockedSubdir = $this->projectRoot . '/web/blocked';
    mkdir($blockedSubdir, 0755, TRUE);
    file_put_contents($blockedSubdir . '/secret.txt', 'nope');
    chmod($blockedSubdir, 0000);

    $archiver = new CodeArchiver($fileSystem, $shellRunner, new NullLogger());

    try {
      $this->expectException(ArchiveFailedException::class);
      $this->expectExceptionMessageMatches('/could not measure/i');
      $archiver->archive($this->projectRoot, $this->projectRoot . '/web', $this->destinationDir . '/code.tar.gz', ['default'], NULL);
    }
    finally {
      @chmod($blockedSubdir, 0755);
    }
  }

  /**
   * @covers ::archive
   *
   * A CLI pipeline that reports success but produces a corrupt/incomplete
   * archive (e.g. a truncated write masked elsewhere) must still be
   * caught here, by verifying the archive is actually a well-formed
   * gzip/tar stream before treating it as a successfully produced
   * artifact.
   */
  public function testArchiveThrowsWhenProducedFileFailsIntegrityCheck(): void {
    $fileSystem = $this->createMock(FileSystemInterface::class);
    $shellRunner = $this->createMock(ShellRunnerInterface::class);
    $shellRunner->method('isBinaryAvailable')->willReturn(TRUE);

    $destination = $this->destinationDir . '/code.tar.gz';

    $shellRunner->method('runPipedToFile')->willReturnCallback(function () use ($destination): ShellResult {
      file_put_contents($destination, 'this is not a valid gzip/tar stream');
      return new ShellResult(0, '', '');
    });
    // Real `tar -tzf` on genuinely garbage bytes fails - exercise that for
    // real rather than mocking the verdict itself.
    $realShellRunner = new ShellRunner();
    $shellRunner->method('run')->willReturnCallback(
      static fn (string $command, array $args = [], ?string $cwd = NULL): ShellResult => $realShellRunner->run($command, $args, $cwd),
    );

    $archiver = new CodeArchiver($fileSystem, $shellRunner, new NullLogger());

    try {
      $this->expectException(ArchiveFailedException::class);
      $this->expectExceptionMessageMatches('/integrity check failed/i');
      $archiver->archive($this->projectRoot, $this->projectRoot . '/web', $destination, ['default'], NULL);
    }
    finally {
      @unlink($destination);
    }
  }

  /**
   * @covers ::archive
   *
   * A read failure while hashing a just-produced archive (e.g. a
   * permission race, or the file becoming briefly unreadable) must abort
   * the backup, not silently ship an empty checksum that looks valid.
   */
  public function testArchiveThrowsWhenChecksumCannotBeComputed(): void {
    $fileSystem = $this->createMock(FileSystemInterface::class);
    $shellRunner = $this->createMock(ShellRunnerInterface::class);
    $shellRunner->method('isBinaryAvailable')->willReturn(TRUE);

    $destination = $this->destinationDir . '/code.tar.gz';

    // Simulate the CLI pipeline succeeding and producing a real file that
    // then becomes unreadable before archive() gets to hash it. The
    // integrity check (which also shells out via isBinaryAvailable/run())
    // must be allowed to pass so the flow actually reaches hash_file() -
    // this test is specifically about the checksum step, not integrity.
    $shellRunner->method('runPipedToFile')->willReturnCallback(function () use ($destination): ShellResult {
      file_put_contents($destination, 'not a real archive, just bytes');
      chmod($destination, 0000);
      return new ShellResult(0, '', '');
    });
    $shellRunner->method('run')->willReturn(new ShellResult(0, '', ''));

    $archiver = new CodeArchiver($fileSystem, $shellRunner, new NullLogger());

    try {
      $this->expectException(ArchiveFailedException::class);
      $this->expectExceptionMessageMatches('/checksum/i');
      $archiver->archive($this->projectRoot, $this->projectRoot . '/web', $destination, ['default'], NULL);
    }
    finally {
      @chmod($destination, 0644);
      @unlink($destination);
    }
  }

}
