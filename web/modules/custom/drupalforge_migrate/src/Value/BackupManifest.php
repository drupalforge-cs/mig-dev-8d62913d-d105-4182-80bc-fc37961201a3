<?php

declare(strict_types=1);

namespace Drupal\drupalforge_migrate\Value;

/**
 * The manifest describing one backup's artifacts and what was excluded.
 *
 * Built by BackupOrchestrator and sent verbatim in the `complete` request
 * body - it is never persisted to disk as a manifest.json file.
 */
final class BackupManifest {

  /**
   * @param string[] $excludedPaths
   *   Legitimately optional/empty - a site excluding nothing unusual beyond
   *   the always-applied defaults is valid.
   * @param array<string, int> $fileCounts
   *   Legitimately optional/empty - never populated today, per
   *   BackupOrchestrator's documented decision not to walk the filesystem a
   *   second time purely for informational manifest metadata.
   * @param array{code?: int, db?: int} $sizes
   *   Required non-empty: a manifest describing zero artifacts is invalid -
   *   a real backup always produces at least one of code/db.
   * @param array{code?: string, db?: string} $checksums
   *   Required non-empty, same reasoning as $sizes.
   * @param string[] $excludedSettingsFiles
   *   Legitimately optional/empty, same reasoning as $excludedPaths.
   */
  public function __construct(
    private readonly string $docroot,
    private readonly string $phpVersion,
    private readonly string $drupalVersion,
    private readonly array $excludedPaths,
    private readonly array $fileCounts,
    private readonly array $sizes,
    private readonly array $checksums,
    private readonly array $excludedSettingsFiles,
  ) {
    // docroot/phpVersion/drupalVersion identify which site this manifest
    // describes - an empty value here means the manifest describes
    // nothing meaningfully, so it must fail construction rather than be
    // sent to DrupalForge as if valid.
    foreach (['docroot' => $docroot, 'phpVersion' => $phpVersion, 'drupalVersion' => $drupalVersion] as $field => $value) {
      if ($value === '') {
        throw new \InvalidArgumentException("BackupManifest requires a non-empty {$field}.");
      }
    }
    if ($sizes === [] || $checksums === []) {
      throw new \InvalidArgumentException('BackupManifest requires at least one artifact size and checksum (a manifest describing zero artifacts is invalid).');
    }
  }

  public function getDocroot(): string {
    return $this->docroot;
  }

  public function getPhpVersion(): string {
    return $this->phpVersion;
  }

  public function getDrupalVersion(): string {
    return $this->drupalVersion;
  }

  /**
   * @return string[]
   */
  public function getExcludedPaths(): array {
    return $this->excludedPaths;
  }

  /**
   * @return array<string, int>
   */
  public function getFileCounts(): array {
    return $this->fileCounts;
  }

  /**
   * @return array{code?: int, db?: int}
   */
  public function getSizes(): array {
    return $this->sizes;
  }

  /**
   * @return array{code?: string, db?: string}
   */
  public function getChecksums(): array {
    return $this->checksums;
  }

  /**
   * @return string[]
   */
  public function getExcludedSettingsFiles(): array {
    return $this->excludedSettingsFiles;
  }

  /**
   * @return array{docroot: string, php_version: string, drupal_version: string, excluded_paths: string[], file_counts: array<string, int>, sizes: array, checksums: array, excluded_settings_files: string[]}
   */
  public function toArray(): array {
    return [
      'docroot' => $this->docroot,
      'php_version' => $this->phpVersion,
      'drupal_version' => $this->drupalVersion,
      'excluded_paths' => $this->excludedPaths,
      'file_counts' => $this->fileCounts,
      'sizes' => $this->sizes,
      'checksums' => $this->checksums,
      'excluded_settings_files' => $this->excludedSettingsFiles,
    ];
  }

  public function toJson(): string {
    return (string) json_encode($this->toArray(), \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);
  }

  public static function fromArray(array $data): self {
    return new self(
      (string) ($data['docroot'] ?? ''),
      (string) ($data['php_version'] ?? ''),
      (string) ($data['drupal_version'] ?? ''),
      $data['excluded_paths'] ?? [],
      $data['file_counts'] ?? [],
      $data['sizes'] ?? [],
      $data['checksums'] ?? [],
      $data['excluded_settings_files'] ?? [],
    );
  }

}
