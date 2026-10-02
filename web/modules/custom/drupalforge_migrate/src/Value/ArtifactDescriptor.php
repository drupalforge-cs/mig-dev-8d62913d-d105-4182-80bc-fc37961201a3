<?php

declare(strict_types=1);

namespace Drupal\drupalforge_migrate\Value;

/**
 * Describes one backup artifact (the code archive or the database dump).
 *
 * Validated at construction - artifact type, size, and sha256 are all
 * checked here - so malformed data (e.g. from a hashing failure elsewhere
 * that would otherwise coerce to an empty checksum, or a swapped field in a
 * serialize/deserialize round-trip - see toArray()/fromArray()) fails
 * loudly at the boundary where it enters this module, rather than
 * propagating silently into a request sent to DrupalForge.
 */
final class ArtifactDescriptor {

  private const VALID_ARTIFACTS = ['code', 'db'];

  public function __construct(
    private readonly string $artifact,
    private readonly string $path,
    private readonly int $size,
    private readonly string $sha256,
  ) {
    if (!\in_array($artifact, self::VALID_ARTIFACTS, TRUE)) {
      throw new \InvalidArgumentException(sprintf(
        "ArtifactDescriptor: unrecognized artifact type '%s' (must be 'code' or 'db').",
        $artifact,
      ));
    }
    if ($size <= 0) {
      throw new \InvalidArgumentException(sprintf(
        'ArtifactDescriptor: size must be positive, got %d for artifact %s.',
        $size,
        $artifact,
      ));
    }
    if (!preg_match('/^[0-9a-f]{64}$/', $sha256)) {
      throw new \InvalidArgumentException(sprintf(
        "ArtifactDescriptor: sha256 must be exactly 64 lowercase hex characters, got '%s' for artifact %s.",
        $sha256,
        $artifact,
      ));
    }
  }

  public function getArtifact(): string {
    return $this->artifact;
  }

  public function getPath(): string {
    return $this->path;
  }

  public function getSize(): int {
    return $this->size;
  }

  public function getSha256(): string {
    return $this->sha256;
  }

  /**
   * @return array{artifact: string, size: int, sha256: string, filename: string}
   */
  public function toRequestBody(): array {
    return [
      'artifact' => $this->artifact,
      'size' => $this->size,
      'sha256' => $this->sha256,
      'filename' => basename($this->path),
    ];
  }

  /**
   * A keyed (not positional) serialization for round-tripping a descriptor
   * across a boundary that cannot carry a PHP object directly - e.g.
   * BackupBatch persisting it in Drupal's Batch API $context between
   * operation invocations. Field access by name means a future
   * reorder/typo is caught by a missing/wrong key (and by this class's own
   * constructor validation on the way back in via fromArray()), not
   * silently accepted the way a bare [artifact, path, size, sha256] tuple
   * would be.
   *
   * @return array{artifact: string, path: string, size: int, sha256: string}
   */
  public function toArray(): array {
    return [
      'artifact' => $this->artifact,
      'path' => $this->path,
      'size' => $this->size,
      'sha256' => $this->sha256,
    ];
  }

  /**
   * @param array{artifact: string, path: string, size: int, sha256: string} $data
   */
  public static function fromArray(array $data): self {
    return new self(
      (string) ($data['artifact'] ?? ''),
      (string) ($data['path'] ?? ''),
      (int) ($data['size'] ?? 0),
      (string) ($data['sha256'] ?? ''),
    );
  }

}
