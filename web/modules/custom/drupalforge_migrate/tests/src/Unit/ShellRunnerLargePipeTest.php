<?php

declare(strict_types=1);

namespace Drupal\Tests\drupalforge_migrate\Unit;

use Drupal\drupalforge_migrate\Service\ShellRunner;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\drupalforge_migrate\Service\ShellRunner
 * @group drupalforge_migrate
 *
 * Regression test: a naive runPiped() that fwrite()s the first command's
 * entire (large) stdout into the second command's stdin pipe before ever
 * reading that second command's stdout deadlocks once the data exceeds the
 * OS pipe buffer (both processes end up blocked - the writer waiting for
 * room, the reader waiting for a consumer of its own output). This is
 * exactly the `tar | gzip` shape CodeArchiver/DatabaseExporter use for any
 * realistically-sized site, so it must be exercised with data far larger
 * than a single pipe buffer (~64KB on Linux).
 */
final class ShellRunnerLargePipeTest extends TestCase {

  /**
   * @covers ::runPiped
   */
  public function testRunPipedDoesNotDeadlockOnLargeStdin(): void {
    $runner = new ShellRunner();
    // 8MB - comfortably larger than any pipe buffer, small enough to keep
    // the test itself fast once the deadlock is fixed.
    $size = 8 * 1024 * 1024;

    // /dev/urandom (not /dev/zero): the data must be incompressible so
    // gzip's OUTPUT is also large enough to fill its own stdout pipe buffer
    // - that is the second half of the deadlock (gzip blocked writing
    // output with nothing reading it yet). Highly-compressible input like
    // /dev/zero produces a tiny output and would never reproduce this bug.
    $start = microtime(TRUE);
    $result = $runner->runPiped([
      ['head', ['-c', (string) $size, '/dev/urandom']],
      ['gzip', ['-c']],
    ]);
    $elapsed = microtime(TRUE) - $start;

    self::assertTrue($result->isSuccessful(), 'runPiped failed: ' . $result->getErrorOutput());
    self::assertLessThan(30.0, $elapsed, 'runPiped took too long - likely deadlocked and only completed via internal fallback/timeout.');

    $decompressed = gzdecode($result->getOutput());
    self::assertIsString($decompressed);
    self::assertSame($size, \strlen($decompressed));
  }

}
