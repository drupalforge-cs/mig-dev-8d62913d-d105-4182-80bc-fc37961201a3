<?php

declare(strict_types=1);

namespace Drupal\drupalforge_migrate\Service;

/**
 * Immutable result of a ShellRunner invocation.
 */
final class ShellResult {

  public function __construct(
    private readonly int $exitCode,
    private readonly string $output,
    private readonly string $errorOutput,
  ) {}

  public function isSuccessful(): bool {
    return $this->exitCode === 0;
  }

  public function getExitCode(): int {
    return $this->exitCode;
  }

  public function getOutput(): string {
    return $this->output;
  }

  public function getErrorOutput(): string {
    return $this->errorOutput;
  }

}
