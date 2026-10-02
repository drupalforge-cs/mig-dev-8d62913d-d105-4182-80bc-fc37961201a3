<?php

declare(strict_types=1);

namespace Drupal\drupalforge_migrate\Service;

/**
 * Safe shell-out primitive shared by every service that invokes CLI tools.
 */
interface ShellRunnerInterface {

  /**
   * Checks whether a binary is available on the PATH.
   */
  public function isBinaryAvailable(string $binary): bool;

  /**
   * Runs a single command with the given arguments.
   *
   * @param string $command
   *   The binary to execute.
   * @param string[] $args
   *   Arguments, passed verbatim (never shell-interpreted).
   * @param string|null $cwd
   *   Working directory, or NULL for the current one.
   */
  public function run(string $command, array $args = [], ?string $cwd = NULL): ShellResult;

  /**
   * Runs a chain of commands, piping each command's stdout into the next's
   * stdin (e.g. `tar ... | gzip` without ever building a shell string).
   *
   * @param array<int, array{0: string, 1: string[]}> $commandChain
   *   A list of [command, args] pairs.
   * @param string|null $cwd
   *   Working directory, or NULL for the current one.
   */
  public function runPiped(array $commandChain, ?string $cwd = NULL): ShellResult;

  /**
   * Like runPiped(), but streams the last command's stdout directly to
   * $destinationPath as it arrives instead of buffering it as a PHP
   * string - use this instead of runPiped() whenever the output could be
   * large (an archive or a database dump), since runPiped() holds each
   * stage's full output in memory.
   *
   * @param array<int, array{0: string, 1: string[]}> $commandChain
   *   A list of [command, args] pairs.
   * @param string $destinationPath
   *   Where to write the final command's stdout.
   * @param string|null $cwd
   *   Working directory, or NULL for the current one.
   */
  public function runPipedToFile(array $commandChain, string $destinationPath, ?string $cwd = NULL): ShellResult;

}
