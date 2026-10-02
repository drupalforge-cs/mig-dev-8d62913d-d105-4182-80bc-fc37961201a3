<?php

declare(strict_types=1);

namespace Drupal\drupalforge_migrate\Service;

use Drupal\drupalforge_migrate\Exception\UploadFailedException;
use Drupal\drupalforge_migrate\Value\ArtifactDescriptor;
use Drupal\drupalforge_migrate\Value\UploadPlan;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Uploads a produced artifact to DrupalForge.
 *
 * Single-PUT artifacts are uploaded whole; multipart artifacts are uploaded
 * one part at a time via uploadOnePart(), which persists the returned ETag
 * to ProgressStateStore immediately - the seam BackupBatch drives one call
 * per Batch API operation, and the reason a crash mid-upload loses at most
 * one part's progress.
 *
 * Retries (5xx / connection failures only, never 4xx) use exponential
 * backoff with jitter through an injectable sleeper so tests never actually
 * sleep.
 */
final class Uploader implements UploaderInterface {

  private const MAX_ATTEMPTS = 5;
  private const BASE_DELAY_MS = 500;

  /**
   * Total time allowed for one PUT, overriding http_client's 30s default.
   */
  private const PUT_TIMEOUT_SECONDS = 3600;
  private const PUT_CONNECT_TIMEOUT_SECONDS = 30;

  /**
   * @var callable(int): void
   */
  private $sleeper;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly DrupalForgeClientInterface $client,
    private readonly ProgressStateStore $progress,
    private readonly LoggerInterface $logger,
    ?callable $sleeper = NULL,
  ) {
    $this->sleeper = $sleeper ?? static function (int $milliseconds): void {
      usleep($milliseconds * 1000);
    };
  }

  public function uploadArtifact(string $backupId, ArtifactDescriptor $artifact): array {
    $plan = $this->client->requestUpload($backupId, $artifact);

    $parts = $plan->isMultipart()
      ? $this->uploadRemainingParts($backupId, $artifact, $plan)
      : $this->uploadSingle($artifact, $plan);

    return [
      'artifact' => $artifact->getArtifact(),
      'sha256' => $artifact->getSha256(),
      'size' => $artifact->getSize(),
      'parts' => $parts,
    ];
  }

  public function uploadOnePart(string $backupId, ArtifactDescriptor $artifact, UploadPlan $plan, int $partNumber): string {
    $partMeta = NULL;
    foreach ($plan->getParts() as $part) {
      if ((int) $part['n'] === $partNumber) {
        $partMeta = $part;
        break;
      }
    }
    if ($partMeta === NULL) {
      throw new UploadFailedException(sprintf(
        "Upload failed for artifact '%s': no part %d in the upload plan.",
        $artifact->getArtifact(),
        $partNumber,
      ));
    }

    $body = $this->readPart($artifact->getPath(), $partNumber, $plan->getPartSize());
    // DrupalForge verifies multipart uploads against a sha256 for every part,
    // and S3 only records it when the part PUT carries it as a checksum.
    $partSha256 = hash('sha256', $body);
    $etag = $this->putWithRetry($partMeta['url'], $body, $partSha256);

    $state = $this->progress->getUploadState($backupId, $artifact->getArtifact());
    $state['mode'] = UploadPlan::MODE_MULTIPART;
    $state['part_size'] = $plan->getPartSize();
    $state['total_parts'] = count($plan->getParts());
    $state['sha256'] = $artifact->getSha256();
    $state['parts_done'][$partNumber] = $etag;
    $state['parts_sha256'][$partNumber] = $partSha256;
    $this->progress->setUploadState($backupId, $artifact->getArtifact(), $state);

    return $etag;
  }

  public function resumeFrom(string $backupId, ArtifactDescriptor $artifact): int {
    $state = $this->progress->getUploadState($backupId, $artifact->getArtifact());
    $partsDone = $state['parts_done'] ?? [];
    if ($partsDone === []) {
      return 1;
    }
    $doneNumbers = array_map('intval', array_keys($partsDone));
    return max($doneNumbers) + 1;
  }

  /**
   * @return array<int, array{n: int, etag: string, sha256: string}>
   */
  private function uploadRemainingParts(string $backupId, ArtifactDescriptor $artifact, UploadPlan $plan): array {
    $next = $this->resumeFrom($backupId, $artifact);
    $total = count($plan->getParts());
    while ($next <= $total) {
      $this->uploadOnePart($backupId, $artifact, $plan, $next);
      $next++;
    }

    $state = $this->progress->getUploadState($backupId, $artifact->getArtifact());
    return self::partsFromState($state);
  }

  /**
   * @param array<string, mixed> $state
   *   Upload state as stored by ProgressStateStore.
   *
   * @return array<int, array{n: int, etag: string, sha256: string}>
   */
  public static function partsFromState(array $state): array {
    $partsDone = $state['parts_done'] ?? [];
    ksort($partsDone);
    $parts = [];
    foreach ($partsDone as $n => $etag) {
      $parts[] = [
        'n' => (int) $n,
        'etag' => $etag,
        'sha256' => (string) ($state['parts_sha256'][$n] ?? ''),
      ];
    }
    return $parts;
  }

  /**
   * @return array<int, array{n: int, etag: string}>
   *   Always empty: single-PUT mode has no per-part ETags.
   */
  private function uploadSingle(ArtifactDescriptor $artifact, UploadPlan $plan): array {
    $url = $plan->getUrl();
    if ($url === NULL) {
      throw new UploadFailedException(sprintf(
        "Upload failed for artifact '%s': no upload URL returned for single-PUT mode.",
        $artifact->getArtifact(),
      ));
    }

    $path = $artifact->getPath();
    $open = static fn () => @fopen($path, 'rb');
    // Fail fast with a clear message if the file is unreadable; the probe
    // handle is closed straight away since every attempt opens its own.
    $probe = $open();
    if ($probe === FALSE) {
      throw new UploadFailedException(sprintf(
        "Upload failed for artifact '%s': could not open %s for reading.",
        $artifact->getArtifact(),
        $path,
      ));
    }
    fclose($probe);

    // Guzzle closes a stream body when a request ends, so a retry cannot
    // reuse the handle: hand putWithRetry() a factory for a fresh one.
    $this->putWithRetry($url, $open, $artifact->getSha256());

    return [];
  }

  /**
   * Reads exactly one part's byte range from the local artifact file.
   *
   * Only the part itself (bounded by the server-chosen part_size, typically
   * a few dozen MB at most) is loaded into memory - never the whole file.
   */
  private function readPart(string $path, int $partNumber, int $partSize): string {
    $handle = @fopen($path, 'rb');
    if ($handle === FALSE) {
      throw new UploadFailedException("Could not open {$path} for reading part {$partNumber}.");
    }
    try {
      $offset = ($partNumber - 1) * $partSize;
      if (fseek($handle, $offset) !== 0) {
        throw new UploadFailedException("Could not seek to offset {$offset} in {$path} for part {$partNumber}.");
      }
      $data = fread($handle, $partSize);
      return $data === FALSE ? '' : $data;
    }
    finally {
      fclose($handle);
    }
  }

  /**
   * PUTs $body to $url, retrying only on 5xx responses or connection-level
   * failures (never 4xx - those are permanent, e.g. an expired presigned
   * URL). Returns the ETag response header with surrounding quotes
   * stripped, if present.
   *
   * The x-amz-checksum-sha256 header is required by DrupalForge's presigned
   * URLs: without it S3 stores no checksum and DrupalForge cannot verify the
   * upload's integrity when the backup is completed.
   *
   * @param string|callable(): (resource|false) $body
   *   Either the body itself or a factory opening a fresh stream per attempt
   *   (Guzzle closes a stream body once a request ends, so it can't be
   *   rewound and reused).
   * @param string $sha256Hex
   *   Lowercase hex SHA-256 of exactly the bytes in $body.
   */
  private function putWithRetry(string $url, mixed $body, string $sha256Hex): string {
    $headers = ['x-amz-checksum-sha256' => base64_encode((string) hex2bin($sha256Hex))];
    $attempt = 0;
    $lastException = NULL;

    while ($attempt < self::MAX_ATTEMPTS) {
      $attempt++;
      try {
        $attemptBody = is_callable($body) ? $body() : $body;
        if ($attemptBody === FALSE) {
          throw new UploadFailedException(sprintf('Upload PUT to %s failed: could not open the artifact for reading.', $url));
        }
        $response = $this->httpClient->request('PUT', $url, [
          'body' => $attemptBody,
          'headers' => $headers,
          'timeout' => self::PUT_TIMEOUT_SECONDS,
          'connect_timeout' => self::PUT_CONNECT_TIMEOUT_SECONDS,
        ]);
        $etag = trim($response->getHeaderLine('ETag'), '"');
        if ($etag === '') {
          // A 2xx with no ETag (e.g. a proxy/CDN stripped the header) is
          // not proof the part actually uploaded - fail loudly rather than
          // recording it as done with an empty ETag. Not retried: a
          // stripped header is a property of the path the request took,
          // which a retry over the same URL/proxy would not fix.
          throw new UploadFailedException(sprintf(
            'Upload PUT to %s succeeded (HTTP %d) but returned no ETag header - the part cannot be confirmed as uploaded.',
            $url,
            $response->getStatusCode(),
          ));
        }
        return $etag;
      }
      catch (ConnectException $e) {
        $lastException = $e;
      }
      catch (RequestException $e) {
        $response = $e->getResponse();
        if ($response !== NULL && $response->getStatusCode() < 500) {
          // A genuine 4xx response is permanent (e.g. an expired presigned
          // URL) - never retry. A RequestException with NO response at all
          // (a low-level transfer failure after the connection was already
          // established, e.g. a reset mid-upload) falls through to the
          // retry path below, same as ConnectException - it is not the
          // permanent failure this branch guards against.
          throw new UploadFailedException('Upload PUT failed: ' . $e->getMessage(), $response->getStatusCode(), $e);
        }
        $lastException = $e;
      }

      $this->logger->warning('Upload PUT to @url failed on attempt @attempt: @message', [
        '@url' => $url,
        '@attempt' => $attempt,
        '@message' => $lastException->getMessage(),
      ]);

      if ($attempt < self::MAX_ATTEMPTS) {
        $delayMs = self::BASE_DELAY_MS * (2 ** ($attempt - 1)) + random_int(0, 100);
        ($this->sleeper)($delayMs);
      }
    }

    throw new UploadFailedException(
      sprintf('Upload PUT to %s failed after %d attempts: %s', $url, self::MAX_ATTEMPTS, $lastException?->getMessage() ?? 'unknown error'),
      0,
      $lastException,
    );
  }

}
