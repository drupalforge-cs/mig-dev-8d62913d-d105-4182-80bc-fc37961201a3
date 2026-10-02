<?php

declare(strict_types=1);

namespace Drupal\Tests\drupalforge_migrate\Unit;

use Drupal\drupalforge_migrate\Service\ShellRunner;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\drupalforge_migrate\Service\ShellRunner
 * @group drupalforge_migrate
 *
 * ShellRunner::readChunk() (private - reached here via reflection, since
 * this exact classification cannot be reliably forced through a real
 * proc_open() pipe: a genuine fread() I/O error, as opposed to a clean
 * end-of-stream, is not something that can be deterministically triggered
 * on a real OS pipe in a portable test. A custom stream wrapper lets us
 * simulate the "fread() returns FALSE" case directly and confirm it is
 * classified as an error, not silently treated the same as end-of-stream.
 */
final class ShellRunnerReadClassificationTest extends TestCase {

  public static function setUpBeforeClass(): void {
    parent::setUpBeforeClass();
    if (!\in_array('dfm-failing-stream-test', stream_get_wrappers(), TRUE)) {
      stream_wrapper_register('dfm-failing-stream-test', FailingStreamWrapperForTest::class);
    }
  }

  public static function tearDownAfterClass(): void {
    if (\in_array('dfm-failing-stream-test', stream_get_wrappers(), TRUE)) {
      stream_wrapper_unregister('dfm-failing-stream-test');
    }
    parent::tearDownAfterClass();
  }

  private function readChunk($stream): array {
    $method = new \ReflectionMethod(ShellRunner::class, 'readChunk');
    $method->setAccessible(TRUE);
    return $method->invoke(new ShellRunner(), $stream);
  }

  /**
   * @covers ::readChunk
   */
  public function testDataIsClassifiedAsData(): void {
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, 'hello');
    rewind($stream);

    [$status, $chunk] = $this->readChunk($stream);

    self::assertSame(0, $status, 'Expected READ_DATA.');
    self::assertSame('hello', $chunk);
    fclose($stream);
  }

  /**
   * @covers ::readChunk
   */
  public function testCleanEndOfStreamIsClassifiedAsEof(): void {
    $stream = fopen('php://memory', 'r+');
    // Already fully read - the next fread() returns '' with feof() true.
    fread($stream, 1);

    [$status, $chunk] = $this->readChunk($stream);

    self::assertSame(1, $status, 'Expected READ_EOF.');
    self::assertSame('', $chunk);
    fclose($stream);
  }

  /**
   * @covers ::readChunk
   *
   * The actual bug this fixes: before, a genuine fread() === FALSE was
   * treated identically to clean EOF, silently truncating whatever was
   * captured so far and reporting success.
   */
  public function testGenuineReadErrorIsClassifiedDifferentlyFromEof(): void {
    $stream = fopen('dfm-failing-stream-test://irrelevant', 'r');
    self::assertIsResource($stream);

    [$status, $chunk] = $this->readChunk($stream);

    self::assertSame(2, $status, 'Expected READ_ERROR, not READ_EOF or READ_DATA.');
    self::assertSame('', $chunk);
    fclose($stream);
  }

}

/**
 * A minimal stream wrapper whose stream_read() always fails, to simulate a
 * genuine I/O error deterministically (unlike a real OS pipe, where forcing
 * fread() to return FALSE rather than a clean EOF is not reliably
 * reproducible in a portable test).
 */
final class FailingStreamWrapperForTest {

  /** @var resource */
  public $context;

  public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool {
    return TRUE;
  }

  public function stream_read(int $count): string|false {
    return FALSE;
  }

  public function stream_eof(): bool {
    return FALSE;
  }

  public function stream_close(): void {}

  public function stream_stat(): array {
    return [];
  }

}
