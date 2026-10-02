<?php

declare(strict_types=1);

namespace Drupal\Tests\drupalforge_migrate\Unit;

use Drupal\Core\KeyValueStore\KeyValueMemoryFactory;
use Drupal\Core\Cache\NullBackend;
use Drupal\Core\Lock\NullLockBackend;
use Drupal\Core\State\State;
use Drupal\drupalforge_migrate\Exception\UploadFailedException;
use Drupal\drupalforge_migrate\Service\DrupalForgeClientInterface;
use Drupal\drupalforge_migrate\Service\ProgressStateStore;
use Drupal\drupalforge_migrate\Service\Uploader;
use Drupal\drupalforge_migrate\Value\ArtifactDescriptor;
use Drupal\drupalforge_migrate\Value\UploadPlan;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @coversDefaultClass \Drupal\drupalforge_migrate\Service\Uploader
 * @group drupalforge_migrate
 */
final class UploaderRetryBackoffTest extends TestCase {

  private function makeProgress(): ProgressStateStore {
    return new ProgressStateStore(new State(new KeyValueMemoryFactory(), new NullBackend('state'), new NullLockBackend()));
  }

  /**
   * @param array<int, mixed> $responses
   * @param int[] $delays
   *   Out-parameter: every millisecond delay the sleeper was called with (so
   *   tests never actually sleep). Passed by reference rather than returned,
   *   since a plain (non-&) list-destructure of a returned array only copies
   *   the snapshot at return time and would never see later appends made by
   *   the closure after the caller has gone on to invoke the uploader.
   */
  private function makeUploader(array $responses, array &$delays = []): Uploader {
    $mock = new MockHandler($responses);
    $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
    $client = $this->createMock(DrupalForgeClientInterface::class);
    $delays = [];
    $sleeper = function (int $milliseconds) use (&$delays): void {
      $delays[] = $milliseconds;
    };
    return new Uploader($httpClient, $client, $this->makeProgress(), new NullLogger(), $sleeper);
  }

  private function singlePlan(string $url = 'https://s3.example.test/put-url'): UploadPlan {
    return UploadPlan::fromResponse([
      'mode' => 'single',
      'url' => $url,
      'part_size' => 0,
      'expires' => '2026-01-01T01:00:00Z',
    ]);
  }

  /**
   * @covers ::uploadOnePart
   */
  public function testRetriesOn500ThenSucceeds(): void {
    $delays = [];
    $uploader = $this->makeUploader([
      new Response(500),
      new Response(500),
      new Response(200, ['ETag' => '"abc123"']),
    ], $delays);

    $tmp = tempnam(sys_get_temp_dir(), 'dfm_upload_');
    file_put_contents($tmp, 'x');
    $artifact = new ArtifactDescriptor('code', $tmp, 1, str_repeat('a', 64));
    $plan = UploadPlan::fromResponse([
      'mode' => 'multipart',
      'upload_id' => 'u1',
      'parts' => [['n' => 1, 'url' => 'https://s3.example.test/part-1']],
      'part_size' => 1,
      'expires' => '2026-01-01T01:00:00Z',
    ]);

    $etag = $uploader->uploadOnePart('b1', $artifact, $plan, 1);

    self::assertSame('abc123', $etag, 'ETag must be returned with surrounding quotes stripped.');
    self::assertCount(2, $delays, 'Exactly two retry delays expected for two failed attempts before success.');
    self::assertGreaterThan($delays[0], $delays[1], 'Backoff must increase between successive retries.');
    unlink($tmp);
  }

  /**
   * @covers ::uploadOnePart
   */
  public function testDoesNotRetryOn403(): void {
    $delays = [];
    $uploader = $this->makeUploader([
      new Response(403, [], 'forbidden'),
    ], $delays);

    $tmp = tempnam(sys_get_temp_dir(), 'dfm_upload_');
    file_put_contents($tmp, 'x');
    $artifact = new ArtifactDescriptor('code', $tmp, 1, str_repeat('a', 64));
    $plan = UploadPlan::fromResponse([
      'mode' => 'multipart',
      'upload_id' => 'u1',
      'parts' => [['n' => 1, 'url' => 'https://s3.example.test/part-1']],
      'part_size' => 1,
      'expires' => '2026-01-01T01:00:00Z',
    ]);

    $this->expectException(UploadFailedException::class);
    try {
      $uploader->uploadOnePart('b1', $artifact, $plan, 1);
    }
    finally {
      self::assertCount(0, $delays, 'A 4xx response must fail immediately, with no retry/backoff.');
      unlink($tmp);
    }
  }

  /**
   * @covers ::uploadOnePart
   */
  public function testRetriesOnConnectException(): void {
    $delays = [];
    $uploader = $this->makeUploader([
      new ConnectException('Connection refused', new Request('PUT', 'https://s3.example.test/part-1')),
      new Response(200, ['ETag' => 'noquotes']),
    ], $delays);

    $tmp = tempnam(sys_get_temp_dir(), 'dfm_upload_');
    file_put_contents($tmp, 'x');
    $artifact = new ArtifactDescriptor('code', $tmp, 1, str_repeat('a', 64));
    $plan = UploadPlan::fromResponse([
      'mode' => 'multipart',
      'upload_id' => 'u1',
      'parts' => [['n' => 1, 'url' => 'https://s3.example.test/part-1']],
      'part_size' => 1,
      'expires' => '2026-01-01T01:00:00Z',
    ]);

    $etag = $uploader->uploadOnePart('b1', $artifact, $plan, 1);

    self::assertSame('noquotes', $etag);
    self::assertCount(1, $delays);
    unlink($tmp);
  }

  /**
   * @covers ::uploadOnePart
   *
   * Regression test: a proxy/CDN in front of the presigned URL can strip
   * the ETag header on an otherwise-successful PUT. That must be treated
   * as a failure of the part (nothing to prove it actually uploaded), not
   * silently recorded as done with etag=''.
   */
  public function testEmptyETagOnSuccessfulResponseIsTreatedAsFailure(): void {
    $delays = [];
    $uploader = $this->makeUploader([
      new Response(200),
    ], $delays);

    $tmp = tempnam(sys_get_temp_dir(), 'dfm_upload_');
    file_put_contents($tmp, 'x');
    $artifact = new ArtifactDescriptor('code', $tmp, 1, str_repeat('a', 64));
    $plan = UploadPlan::fromResponse([
      'mode' => 'multipart',
      'upload_id' => 'u1',
      'parts' => [['n' => 1, 'url' => 'https://s3.example.test/part-1']],
      'part_size' => 1,
      'expires' => '2026-01-01T01:00:00Z',
    ]);

    $this->expectException(UploadFailedException::class);
    $this->expectExceptionMessageMatches('/ETag/');
    try {
      $uploader->uploadOnePart('b1', $artifact, $plan, 1);
    }
    finally {
      unlink($tmp);
    }
  }

  /**
   * @covers ::uploadOnePart
   *
   * Regression test: a RequestException can occur with getResponse()=NULL
   * for a low-level transfer failure after a connection was already
   * established (e.g. a reset mid-upload) - that must retry the same as
   * ConnectException, not be treated as an immediate permanent failure
   * (the old code computed `$status = ... ?? 0` and `0 < 500` was always
   * true, so this case threw immediately instead of retrying).
   */
  public function testRetriesOnRequestExceptionWithNoResponse(): void {
    $delays = [];
    $uploader = $this->makeUploader([
      new RequestException('Connection reset by peer', new Request('PUT', 'https://s3.example.test/part-1')),
      new Response(200, ['ETag' => '"recovered"']),
    ], $delays);

    $tmp = tempnam(sys_get_temp_dir(), 'dfm_upload_');
    file_put_contents($tmp, 'x');
    $artifact = new ArtifactDescriptor('code', $tmp, 1, str_repeat('a', 64));
    $plan = UploadPlan::fromResponse([
      'mode' => 'multipart',
      'upload_id' => 'u1',
      'parts' => [['n' => 1, 'url' => 'https://s3.example.test/part-1']],
      'part_size' => 1,
      'expires' => '2026-01-01T01:00:00Z',
    ]);

    $etag = $uploader->uploadOnePart('b1', $artifact, $plan, 1);

    self::assertSame('recovered', $etag);
    self::assertCount(1, $delays, 'A RequestException with no attached response must retry, not fail immediately.');
    unlink($tmp);
  }

  /**
   * @covers ::uploadArtifact
   */
  public function testUploadArtifactSingleModePutsWholeFile(): void {
    $tmp = tempnam(sys_get_temp_dir(), 'dfm_upload_');
    file_put_contents($tmp, str_repeat('y', 32));
    $sha256 = hash_file('sha256', $tmp) ?: '';
    $artifact = new ArtifactDescriptor('code', $tmp, 32, $sha256);

    $client = $this->createMock(DrupalForgeClientInterface::class);
    $client->method('requestUpload')->willReturn($this->singlePlan());

    $mock = new MockHandler([new Response(200, ['ETag' => '"whole-file-etag"'])]);
    $httpClient = new Client(['handler' => HandlerStack::create($mock)]);
    $uploader = new Uploader($httpClient, $client, $this->makeProgress(), new NullLogger(), function (int $ms): void {});

    $result = $uploader->uploadArtifact('b1', $artifact);

    self::assertSame('code', $result['artifact']);
    self::assertSame($sha256, $result['sha256']);
    self::assertSame(32, $result['size']);
    self::assertSame([], $result['parts'], 'Single-PUT mode has no per-part ETags.');
    unlink($tmp);
  }

  /**
   * @covers ::uploadArtifact
   *
   * Regression test: Guzzle closes a stream body once a request finishes, so a
   * retried single-PUT upload must open a fresh stream per attempt instead of
   * reusing the closed one ("Passing resource (closed) to request option
   * "body" is invalid").
   */
  public function testSingleModeRetryAfter500UsesFreshStream(): void {
    $tmp = tempnam(sys_get_temp_dir(), 'dfm_upload_');
    file_put_contents($tmp, str_repeat('y', 32));
    $sha256 = hash_file('sha256', $tmp) ?: '';
    $artifact = new ArtifactDescriptor('code', $tmp, 32, $sha256);

    $client = $this->createMock(DrupalForgeClientInterface::class);
    $client->method('requestUpload')->willReturn($this->singlePlan());

    $bodies = [];
    // The real curl handler releases the request (and so closes the body
    // stream) when a transfer ends; MockHandler does not, so simulate it.
    $mock = new MockHandler([
      function ($request) use (&$bodies) {
        $bodies[] = (string) $request->getBody();
        $request->getBody()->close();
        return new Response(500);
      },
      function ($request) use (&$bodies) {
        $bodies[] = (string) $request->getBody();
        return new Response(200, ['ETag' => '"whole-file-etag"']);
      },
    ]);
    $stack = HandlerStack::create($mock);
    $uploader = new Uploader(new Client(['handler' => $stack]), $client, $this->makeProgress(), new NullLogger(), function (int $ms): void {});

    $result = $uploader->uploadArtifact('b1', $artifact);

    self::assertSame([], $result['parts']);
    self::assertSame([str_repeat('y', 32), str_repeat('y', 32)], $bodies, 'Both attempts must send the full file.');
    unlink($tmp);
  }

  /**
   * @covers ::uploadArtifact
   *
   * Regression test: Drupal's http_client defaults to a 30s total timeout,
   * which kills any large artifact PUT ("cURL error 28 ... after 30001
   * milliseconds"). The PUT must override it.
   */
  public function testPutOverridesDefaultTotalTimeout(): void {
    $tmp = tempnam(sys_get_temp_dir(), 'dfm_upload_');
    file_put_contents($tmp, 'x');
    $artifact = new ArtifactDescriptor('code', $tmp, 1, hash_file('sha256', $tmp) ?: '');

    $client = $this->createMock(DrupalForgeClientInterface::class);
    $client->method('requestUpload')->willReturn($this->singlePlan());

    $seen = [];
    $mock = new MockHandler([
      function ($request, array $options) use (&$seen) {
        $seen = $options;
        return new Response(200, ['ETag' => '"e"']);
      },
    ]);
    $uploader = new Uploader(new Client(['handler' => HandlerStack::create($mock), 'timeout' => 30]), $client, $this->makeProgress(), new NullLogger(), function (int $ms): void {});
    $uploader->uploadArtifact('b1', $artifact);

    self::assertGreaterThanOrEqual(1800, $seen['timeout'], 'Total timeout must allow large uploads.');
    self::assertGreaterThan(0, $seen['connect_timeout']);
    unlink($tmp);
  }

  /**
   * @covers ::resumeFrom
   * @covers ::uploadOnePart
   */
  public function testResumeFromSkipsAlreadyUploadedParts(): void {
    $progress = $this->makeProgress();
    $progress->setUploadState('b1', 'code', ['parts_done' => [1 => 'etag-1', 2 => 'etag-2'], 'total_parts' => 3]);

    self::assertSame(3, (new Uploader(
      new Client(['handler' => HandlerStack::create(new MockHandler([]))]),
      $this->createMock(DrupalForgeClientInterface::class),
      $progress,
      new NullLogger(),
    ))->resumeFrom('b1', new ArtifactDescriptor('code', '/dev/null', 1, str_repeat('a', 64))));
  }

}
