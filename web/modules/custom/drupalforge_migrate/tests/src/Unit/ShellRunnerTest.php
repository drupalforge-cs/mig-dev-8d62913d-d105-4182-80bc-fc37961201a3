<?php

declare(strict_types=1);

namespace Drupal\Tests\drupalforge_migrate\Unit;

use Drupal\drupalforge_migrate\Service\ShellRunner;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\drupalforge_migrate\Service\ShellRunner
 * @group drupalforge_migrate
 */
final class ShellRunnerTest extends TestCase {

  /**
   * @covers ::isBinaryAvailable
   */
  public function testIsBinaryAvailableFindsRealBinary(): void {
    $runner = new ShellRunner();
    self::assertTrue($runner->isBinaryAvailable('php'));
  }

  /**
   * @covers ::isBinaryAvailable
   */
  public function testIsBinaryAvailableRejectsUnknownBinary(): void {
    $runner = new ShellRunner();
    self::assertFalse($runner->isBinaryAvailable('this-binary-does-not-exist-xyz'));
  }

  /**
   * @covers ::run
   */
  public function testRunCapturesStdout(): void {
    $runner = new ShellRunner();
    $result = $runner->run('echo', ['hello world']);
    self::assertTrue($result->isSuccessful());
    self::assertSame('hello world', trim($result->getOutput()));
  }

  /**
   * @covers ::run
   */
  public function testRunEscapesArgumentsSoInjectionFails(): void {
    $runner = new ShellRunner();
    // If arguments were not escaped, this would execute `; echo pwned` as a
    // second command instead of being treated as a literal string argument.
    $result = $runner->run('echo', ['hello; echo pwned']);
    self::assertSame('hello; echo pwned', trim($result->getOutput()));
  }

  /**
   * @covers ::run
   */
  public function testRunReportsFailureForNonZeroExit(): void {
    $runner = new ShellRunner();
    $result = $runner->run('php', ['-r', 'exit(3);']);
    self::assertFalse($result->isSuccessful());
    self::assertSame(3, $result->getExitCode());
  }

  /**
   * @covers ::runPiped
   */
  public function testRunPipedChainsCommands(): void {
    $runner = new ShellRunner();
    $result = $runner->runPiped([
      ['echo', ['hello world']],
      ['tr', ['a-z', 'A-Z']],
    ]);
    self::assertTrue($result->isSuccessful());
    self::assertSame('HELLO WORLD', trim($result->getOutput()));
  }

}
