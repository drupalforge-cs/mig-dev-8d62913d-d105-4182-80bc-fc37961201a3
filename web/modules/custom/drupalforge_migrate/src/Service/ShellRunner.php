<?php

declare(strict_types=1);

namespace Drupal\drupalforge_migrate\Service;

/**
 * Safe shell-out primitive shared by every service that invokes CLI tools.
 *
 * Every invocation uses proc_open()'s array command form, which executes the
 * binary directly (execve-style) instead of going through a shell. That means
 * arguments are never subject to shell interpretation or injection - there is
 * no string concatenation of user-controlled values into a shell command
 * line, which is a stronger guarantee than escaping arguments for a shell.
 */
final class ShellRunner implements ShellRunnerInterface {

  /**
   * fread() returned data.
   */
  private const READ_DATA = 0;

  /**
   * fread() returned '' and feof() is true - the stream closed cleanly.
   */
  private const READ_EOF = 1;

  /**
   * fread() returned FALSE - a genuine I/O error, not end-of-stream. Must
   * never be treated the same as READ_EOF: silently stopping here would
   * mean whatever was captured so far gets reported as a complete,
   * successful result even though the stream was actually cut short by an
   * error.
   */
  private const READ_ERROR = 2;

  /**
   * @var array<string, bool>
   */
  private array $binaryAvailabilityCache = [];

  /**
   * Checks whether a binary is available on the PATH.
   */
  public function isBinaryAvailable(string $binary): bool {
    if (!array_key_exists($binary, $this->binaryAvailabilityCache)) {
      $result = $this->exec(['which', $binary], NULL);
      $this->binaryAvailabilityCache[$binary] = $result->isSuccessful() && trim($result->getOutput()) !== '';
    }
    return $this->binaryAvailabilityCache[$binary];
  }

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
  public function run(string $command, array $args = [], ?string $cwd = NULL): ShellResult {
    return $this->exec([$command, ...$args], $cwd);
  }

  /**
   * Runs a chain of commands, piping each command's stdout into the next's
   * stdin (e.g. `tar ... | gzip` without ever building a shell string).
   *
   * @param array<int, array{0: string, 1: string[]}> $commandChain
   *   A list of [command, args] pairs.
   * @param string|null $cwd
   *   Working directory, or NULL for the current one.
   */
  public function runPiped(array $commandChain, ?string $cwd = NULL): ShellResult {
    if ($commandChain === []) {
      return new ShellResult(0, '', '');
    }

    $stdin = NULL;
    $result = new ShellResult(0, '', '');
    foreach ($commandChain as [$command, $args]) {
      $result = $this->exec([$command, ...$args], $cwd, $stdin);
      if (!$result->isSuccessful()) {
        return $result;
      }
      $stdin = $result->getOutput();
    }
    return $result;
  }

  /**
   * Like runPiped(), but for the LAST command's stdout only: streamed
   * directly to $destinationPath as it arrives, instead of being buffered
   * as a PHP string and returned via ShellResult::getOutput(). Every stage
   * of the chain is also connected to the next via a real OS pipe (an
   * already-open pipe resource handed directly to proc_open() as the next
   * process's stdin descriptor, which proc_open() dup()s into the child) -
   * unlike runPiped(), which fully re-buffers each stage's entire output in
   * PHP before feeding it to the next stage. Neither the uncompressed
   * stream (e.g. tar's stdout) nor the final compressed stream ever sits
   * fully in PHP memory here, which matters for archiving/exporting a
   * realistically large site.
   */
  public function runPipedToFile(array $commandChain, string $destinationPath, ?string $cwd = NULL): ShellResult {
    if ($commandChain === []) {
      return $this->writeEmptyFile($destinationPath);
    }

    $processes = [];
    $stderrPipes = [];
    $upstreamStdout = NULL;

    foreach ($commandChain as [$command, $args]) {
      $descriptorSpec = [
        0 => $upstreamStdout ?? ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
      ];

      try {
        $process = @proc_open([$command, ...$args], $descriptorSpec, $pipes, $cwd ?? NULL);
      }
      catch (\Throwable $e) {
        $this->closeChain($processes, $stderrPipes, $upstreamStdout);
        return new ShellResult(-1, '', $e->getMessage());
      }

      if (!\is_resource($process)) {
        $this->closeChain($processes, $stderrPipes, $upstreamStdout);
        return new ShellResult(-1, '', 'Failed to start process: ' . implode(' ', [$command, ...$args]));
      }

      if ($upstreamStdout !== NULL) {
        // proc_open() duplicated our handle to the prior stage's stdout
        // into this child's stdin descriptor directly (we passed the
        // resource itself, not ['pipe', 'r']) - it never allocates a
        // $pipes[0] entry for a descriptor supplied this way, so there is
        // nothing of our own to close there. Our copy of the upstream
        // pipe is no longer needed, though, so EOF propagates correctly
        // once its writer finishes.
        fclose($upstreamStdout);
      }
      else {
        // The first command in the chain has no upstream stdin to feed -
        // close our write end immediately so the child sees EOF on stdin
        // right away (tar/mysqldump don't read from it).
        fclose($pipes[0]);
      }

      $processes[] = $process;
      $stderrPipes[] = $pipes[2];
      $upstreamStdout = $pipes[1];
    }

    $finalStdout = $upstreamStdout;

    $destinationHandle = @fopen($destinationPath, 'wb');
    if ($destinationHandle === FALSE) {
      $this->closeChain($processes, $stderrPipes, $finalStdout);
      return new ShellResult(-1, '', "Failed to open destination for writing: {$destinationPath}");
    }

    [$writeError, $stderr] = $this->drainToFile($finalStdout, $stderrPipes, $destinationHandle);
    fclose($destinationHandle);

    $exitCode = 0;
    foreach ($processes as $process) {
      $code = proc_close($process);
      if ($code !== 0) {
        $exitCode = $code;
      }
    }

    if ($writeError !== NULL) {
      @unlink($destinationPath);
      return new ShellResult($exitCode !== 0 ? $exitCode : -1, '', trim($stderr . "\n" . $writeError));
    }

    return new ShellResult($exitCode, '', $stderr);
  }

  private function writeEmptyFile(string $destinationPath): ShellResult {
    if (@file_put_contents($destinationPath, '') === FALSE) {
      return new ShellResult(-1, '', "Failed to create empty output file: {$destinationPath}");
    }
    return new ShellResult(0, '', '');
  }

  /**
   * @param resource[] $processes
   * @param resource[] $stderrPipes
   * @param resource|null $finalStdout
   */
  private function closeChain(array $processes, array $stderrPipes, $finalStdout): void {
    if (\is_resource($finalStdout)) {
      fclose($finalStdout);
    }
    foreach ($stderrPipes as $pipe) {
      if (\is_resource($pipe)) {
        fclose($pipe);
      }
    }
    foreach ($processes as $process) {
      if (\is_resource($process)) {
        proc_close($process);
      }
    }
  }

  /**
   * Concurrently drains the final stage's stdout (writing every chunk
   * straight to $destinationHandle) and every stage's stderr (captured in
   * memory - error output is expected to be small, unlike the payload
   * itself) via stream_select(), so nothing blocks waiting on a pipe nobody
   * is reading.
   *
   * @param resource $finalStdout
   * @param resource[] $stderrPipes
   * @param resource $destinationHandle
   *
   * @return array{0: string|null, 1: string}
   *   [error message, or NULL on success; concatenated stderr from every
   *   stage]
   */
  private function drainToFile($finalStdout, array $stderrPipes, $destinationHandle): array {
    stream_set_blocking($finalStdout, FALSE);
    foreach ($stderrPipes as $pipe) {
      stream_set_blocking($pipe, FALSE);
    }

    $stdoutOpen = TRUE;
    $openStderr = $stderrPipes;
    $stderr = '';
    $writeError = NULL;

    while ($stdoutOpen || $openStderr !== []) {
      $read = array_values($openStderr);
      if ($stdoutOpen) {
        $read[] = $finalStdout;
      }
      $write = NULL;
      $except = NULL;

      if ($read === []) {
        break;
      }

      $changed = @stream_select($read, $write, $except, 1);
      if ($changed === FALSE) {
        break;
      }

      if ($stdoutOpen && \in_array($finalStdout, $read, TRUE)) {
        [$status, $chunk] = $this->readChunk($finalStdout);
        if ($status === self::READ_ERROR) {
          $writeError = 'Failed to read command output stream (I/O error, not a clean end-of-stream).';
          fclose($finalStdout);
          $stdoutOpen = FALSE;
        }
        elseif ($status === self::READ_EOF) {
          fclose($finalStdout);
          $stdoutOpen = FALSE;
        }
        else {
          $written = @fwrite($destinationHandle, $chunk);
          if ($written === FALSE || $written < \strlen($chunk)) {
            $writeError = 'Failed to write destination file in full (disk full or I/O error).';
            fclose($finalStdout);
            $stdoutOpen = FALSE;
          }
        }
      }

      foreach ($openStderr as $key => $pipe) {
        if (!\in_array($pipe, $read, TRUE)) {
          continue;
        }
        [$status, $chunk] = $this->readChunk($pipe);
        if ($status === self::READ_DATA) {
          $stderr .= $chunk;
        }
        else {
          fclose($pipe);
          unset($openStderr[$key]);
        }
      }
    }

    return [$writeError, $stderr];
  }

  /**
   * @param string[] $commandParts
   */
  private function exec(array $commandParts, ?string $cwd, ?string $stdin = NULL): ShellResult {
    $descriptors = [
      0 => ['pipe', 'r'],
      1 => ['pipe', 'w'],
      2 => ['pipe', 'w'],
    ];

    try {
      $process = @proc_open($commandParts, $descriptors, $pipes, $cwd ?? NULL);
    }
    catch (\Throwable $e) {
      return new ShellResult(-1, '', $e->getMessage());
    }

    if (!\is_resource($process)) {
      return new ShellResult(-1, '', 'Failed to start process: ' . implode(' ', $commandParts));
    }

    try {
      [$stdout, $stderr, $readError] = $this->pump($pipes, $stdin ?? '');
      $exitCode = proc_close($process);
    }
    catch (\Throwable $e) {
      return new ShellResult(-1, '', $e->getMessage());
    }

    if ($readError) {
      return new ShellResult(-1, $stdout, trim($stderr . "\nFailed to read command output stream (I/O error, not a clean end-of-stream)."));
    }

    return new ShellResult($exitCode, $stdout, $stderr);
  }

  /**
   * Writes $stdin to $pipes[0] and reads $pipes[1]/$pipes[2] concurrently
   * via stream_select(), instead of writing all of stdin before reading any
   * output.
   *
   * A large amount of data (e.g. an uncompressed tar stream piped into
   * gzip) does not fit in a single OS pipe buffer (commonly 64KB on
   * Linux). Writing it all up front with a blocking fwrite() - which
   * itself loops internally until every byte is accepted - stalls once the
   * stdin pipe fills, while the downstream process (gzip) simultaneously
   * blocks trying to write its own output to a stdout pipe nobody is
   * reading yet: a permanent deadlock. Interleaving writes and reads via
   * stream_select() is the standard fix.
   *
   * @param resource[] $pipes
   *
   * @return array{0: string, 1: string, 2: bool}
   *   [stdout, stderr, whether a genuine read error (not clean EOF)
   *   occurred on either pipe - see READ_ERROR]
   */
  private function pump(array $pipes, string $stdin): array {
    foreach ($pipes as $pipe) {
      stream_set_blocking($pipe, FALSE);
    }

    $stdinLength = \strlen($stdin);
    $stdinOffset = 0;
    $stdinClosed = $stdinLength === 0;
    if ($stdinClosed) {
      fclose($pipes[0]);
    }

    $stdout = '';
    $stderr = '';
    $stdoutOpen = TRUE;
    $stderrOpen = TRUE;
    $readError = FALSE;

    while (!$stdinClosed || $stdoutOpen || $stderrOpen) {
      $read = [];
      if ($stdoutOpen) {
        $read[] = $pipes[1];
      }
      if ($stderrOpen) {
        $read[] = $pipes[2];
      }
      $write = $stdinClosed ? [] : [$pipes[0]];
      $except = NULL;

      if ($read === [] && $write === []) {
        break;
      }

      $changed = @stream_select($read, $write, $except, 1);
      if ($changed === FALSE) {
        break;
      }

      if (!$stdinClosed && \in_array($pipes[0], $write, TRUE)) {
        $chunk = substr($stdin, $stdinOffset, 65536);
        $written = @fwrite($pipes[0], $chunk);
        if ($written === FALSE) {
          // The reader went away (e.g. the downstream command exited
          // early/failed) - stop trying to write, but keep draining its
          // output/error pipes below so we still capture its error output.
          fclose($pipes[0]);
          $stdinClosed = TRUE;
        }
        else {
          $stdinOffset += $written;
          if ($stdinOffset >= $stdinLength) {
            fclose($pipes[0]);
            $stdinClosed = TRUE;
          }
        }
      }

      if ($stdoutOpen && \in_array($pipes[1], $read, TRUE)) {
        [$status, $chunk] = $this->readChunk($pipes[1]);
        if ($status === self::READ_DATA) {
          $stdout .= $chunk;
        }
        else {
          if ($status === self::READ_ERROR) {
            $readError = TRUE;
          }
          fclose($pipes[1]);
          $stdoutOpen = FALSE;
        }
      }

      if ($stderrOpen && \in_array($pipes[2], $read, TRUE)) {
        [$status, $chunk] = $this->readChunk($pipes[2]);
        if ($status === self::READ_DATA) {
          $stderr .= $chunk;
        }
        else {
          if ($status === self::READ_ERROR) {
            $readError = TRUE;
          }
          fclose($pipes[2]);
          $stderrOpen = FALSE;
        }
      }
    }

    return [$stdout, $stderr, $readError];
  }

  /**
   * Reads one chunk from $stream and classifies it, so every call site
   * treats a genuine I/O error (fread() === FALSE) differently from a
   * clean end-of-stream (fread() === '' with feof() true) instead of
   * conflating the two - a truncated read due to an error must never be
   * reported the same way as "the stream finished normally."
   *
   * @param resource $stream
   *
   * @return array{0: int, 1: string}
   *   [self::READ_DATA|READ_EOF|READ_ERROR, the chunk read (empty for
   *   EOF/ERROR)]
   */
  private function readChunk($stream): array {
    $chunk = fread($stream, 65536);
    if ($chunk === FALSE) {
      return [self::READ_ERROR, ''];
    }
    if ($chunk === '' && feof($stream)) {
      return [self::READ_EOF, ''];
    }
    return [self::READ_DATA, $chunk];
  }

}
