<?php

declare(strict_types=1);

namespace Drupal\drupalforge_migrate\Value;

/**
 * Immutable outcome of a PreflightService run.
 *
 * Never guesses: any missing or ambiguous check makes isSupported() FALSE
 * with a specific, named reason in getFailureReasons().
 */
final class PreflightResult {

  /**
   * @param array{
   *   docroot?: string,
   *   project_root?: string,
   *   php_version?: string,
   *   drupal_version?: string,
   *   db_driver?: string,
   *   db_size_bytes?: int,
   *   code_size_bytes?: int,
   *   mysqldump?: bool,
   *   tar?: bool,
   *   gzip?: bool,
   *   proc_open?: bool,
   *   max_execution_time?: int,
   *   temp_dir_writable?: bool,
   * } $checks
   *   Populated by PreflightService::run() - see that class for exact key
   *   semantics. Deliberately left as a loosely-typed array rather than a
   *   dedicated value object per key: every consumer (DrupalForgeClient's
   *   connect(), BackupOrchestrator's archive/manifest-building methods)
   *   already defensively falls back with `?? ''`/`?? 'unknown'` on any
   *   given key, so a typo'd key degrades to an empty/placeholder value
   *   rather than fataling. A full redesign into per-key accessors was
   *   judged disproportionate here: this is internal inter-service data,
   *   not data crossing a network/trust boundary the way
   *   ArtifactDescriptor/BackupManifest/UploadPlan do (those three DO get
   *   constructor-enforced validation, since malformed data there would
   *   propagate into a real request sent to DrupalForge).
   * @param string[] $failureReasons
   */
  public function __construct(
    private readonly bool $supported,
    private readonly array $checks,
    private readonly array $failureReasons,
  ) {}

  public function isSupported(): bool {
    return $this->supported;
  }

  /**
   * @return string[]
   */
  public function getFailureReasons(): array {
    return $this->failureReasons;
  }

  /**
   * @return array<string, mixed>
   */
  public function getChecks(): array {
    return $this->checks;
  }

  /**
   * @return array{supported: bool, checks: array<string, mixed>, failure_reasons: string[]}
   */
  public function toArray(): array {
    return [
      'supported' => $this->supported,
      'checks' => $this->checks,
      'failure_reasons' => $this->failureReasons,
    ];
  }

}
