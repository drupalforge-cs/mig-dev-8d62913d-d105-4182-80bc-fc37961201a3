<?php

declare(strict_types=1);

namespace Drupal\Tests\drupalforge_migrate\Unit;

use Drupal\drupalforge_migrate\Service\ShellRunner;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\drupalforge_migrate\Service\ShellRunner
 * @group drupalforge_migrate
 *
 * runPiped() (see ShellRunnerLargePipeTest) fully buffers every stage's
 * output as a PHP string before handing it to the next stage or the caller -
 * fine for small payloads, but an uncompressed tar stream or a raw
 * mysqldump stream for a realistically-sized site can be far larger than is
 * safe to hold in memory. runPipedToFile() instead wires each stage's
 * stdout directly into the next stage's stdin as a real OS pipe (via
 * proc_open()'s resource-descriptor support - no PHP-side copy at all
 * between stages) and streams only the final stage's stdout straight to a
 * destination file as it arrives, so at no point does the whole artifact
 * sit in PHP memory.
 */
final class ShellRunnerPipedToFileTest extends TestCase {

  private string $destination;

  protected function setUp(): void {
    parent::setUp();
    $this->destination = sys_get_temp_dir() . '/dfm_piped_to_file_' . uniqid() . '.out';
  }

  protected function tearDown(): void {
    @unlink($this->destination);
    parent::tearDown();
  }

  /**
   * @covers ::runPipedToFile
   */
  public function testWritesChainedOutputToDestinationFile(): void {
    $runner = new ShellRunner();
    $result = $runner->runPipedToFile([
      ['echo', ['hello world']],
      ['tr', ['a-z', 'A-Z']],
    ], $this->destination);

    self::assertTrue($result->isSuccessful(), 'runPipedToFile failed: ' . $result->getErrorOutput());
    self::assertSame('HELLO WORLD', trim((string) file_get_contents($this->destination)));
  }

  /**
   * @covers ::runPipedToFile
   *
   * Same shape and size as ShellRunnerLargePipeTest's deadlock regression,
   * but proving correctness end-to-end through the destination file this
   * time, and that memory stays bounded rather than growing with payload
   * size (the actual defect being fixed: runPiped()+file_put_contents()
   * held the whole compressed+uncompressed payload in memory at once).
   */
  public function testStreamsLargePayloadToFileWithoutDeadlockOrUnboundedMemory(): void {
    $runner = new ShellRunner();
    $size = 8 * 1024 * 1024;

    $memoryBefore = memory_get_usage(TRUE);
    $start = microtime(TRUE);
    $result = $runner->runPipedToFile([
      ['head', ['-c', (string) $size, '/dev/urandom']],
      ['gzip', ['-c']],
    ], $this->destination);
    $elapsed = microtime(TRUE) - $start;
    $memoryAfter = memory_get_usage(TRUE);

    self::assertTrue($result->isSuccessful(), 'runPipedToFile failed: ' . $result->getErrorOutput());
    self::assertLessThan(30.0, $elapsed, 'runPipedToFile took too long - likely deadlocked.');

    // Not a tight bound (PHP's own overhead varies), just proving we did not
    // grow memory anywhere near the 8MB+ that fully buffering the payload
    // (as runPiped()+file_put_contents() does) would require.
    self::assertLessThan(2 * 1024 * 1024, $memoryAfter - $memoryBefore, 'Memory usage grew as if the payload were fully buffered in PHP.');

    self::assertFileExists($this->destination);
    $decompressed = gzdecode((string) file_get_contents($this->destination));
    self::assertIsString($decompressed);
    self::assertSame($size, \strlen($decompressed));
  }

  /**
   * @covers ::runPipedToFile
   */
  public function testFailingCommandInChainIsReportedAndDoesNotLeaveAPartialFileTreatedAsSuccess(): void {
    $runner = new ShellRunner();
    $result = $runner->runPipedToFile([
      ['php', ['-r', 'fwrite(STDOUT, "partial"); fwrite(STDERR, "boom"); exit(7);']],
      ['gzip', ['-c']],
    ], $this->destination);

    self::assertFalse($result->isSuccessful());
    self::assertNotSame(0, $result->getExitCode());
  }

  /**
   * @covers ::runPipedToFile
   */
  public function testEmptyChainWritesEmptyFile(): void {
    $runner = new ShellRunner();
    $result = $runner->runPipedToFile([], $this->destination);

    self::assertTrue($result->isSuccessful());
    self::assertFileExists($this->destination);
    self::assertSame('', file_get_contents($this->destination));
  }

}
