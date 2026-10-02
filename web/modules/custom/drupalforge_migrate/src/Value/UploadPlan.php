<?php

declare(strict_types=1);

namespace Drupal\drupalforge_migrate\Value;

use Drupal\drupalforge_migrate\Exception\UploadFailedException;

/**
 * The upload plan DrupalForge returns from POST .../backups/{id}/upload.
 *
 * fromResponse() validates cross-field consistency at construction, so a
 * malformed/degenerate plan from DrupalForge is rejected at the boundary
 * where it enters this module rather than silently propagating - e.g. a
 * multipart plan with zero parts would otherwise let Uploader report an
 * upload as "done" having transferred nothing.
 */
final class UploadPlan {

  public const MODE_SINGLE = 'single';
  public const MODE_MULTIPART = 'multipart';

  /**
   * @param array<int, array{n: int, url: string}> $parts
   */
  public function __construct(
    private readonly string $mode,
    private readonly ?string $url,
    private readonly ?string $uploadId,
    private readonly array $parts,
    private readonly int $partSize,
    private readonly string $expires,
  ) {}

  public static function fromResponse(array $data): self {
    $mode = $data['mode'] ?? self::MODE_SINGLE;
    if (!\in_array($mode, [self::MODE_SINGLE, self::MODE_MULTIPART], TRUE)) {
      throw new UploadFailedException(sprintf(
        "UploadPlan: unrecognized mode '%s' from DrupalForge (must be '%s' or '%s').",
        $mode,
        self::MODE_SINGLE,
        self::MODE_MULTIPART,
      ));
    }

    $url = $data['url'] ?? NULL;
    $parts = $data['parts'] ?? [];
    $partSize = (int) ($data['part_size'] ?? 0);

    if ($mode === self::MODE_MULTIPART) {
      if ($parts === []) {
        throw new UploadFailedException('UploadPlan: a multipart upload plan from DrupalForge must include at least one part.');
      }
      if ($partSize <= 0) {
        throw new UploadFailedException('UploadPlan: a multipart upload plan from DrupalForge must include a positive part_size.');
      }
    }
    elseif ($url === NULL || $url === '') {
      throw new UploadFailedException('UploadPlan: a single-PUT upload plan from DrupalForge must include a url.');
    }

    return new self(
      $mode,
      $url,
      $data['upload_id'] ?? NULL,
      $parts,
      $partSize,
      (string) ($data['expires'] ?? ''),
    );
  }

  public function isMultipart(): bool {
    return $this->mode === self::MODE_MULTIPART;
  }

  public function getUrl(): ?string {
    return $this->url;
  }

  public function getUploadId(): ?string {
    return $this->uploadId;
  }

  /**
   * @return array<int, array{n: int, url: string}>
   */
  public function getParts(): array {
    return $this->parts;
  }

  public function getPartSize(): int {
    return $this->partSize;
  }

  public function getExpires(): string {
    return $this->expires;
  }

}
