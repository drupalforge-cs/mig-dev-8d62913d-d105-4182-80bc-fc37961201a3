<?php

declare(strict_types=1);

namespace Drupal\Tests\drupalforge_migrate\Unit;

use Drupal\drupalforge_migrate\Exception\UploadFailedException;
use Drupal\drupalforge_migrate\Value\UploadPlan;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\drupalforge_migrate\Value\UploadPlan
 * @group drupalforge_migrate
 */
final class UploadPlanTest extends TestCase {

  /**
   * @covers ::fromResponse
   */
  public function testValidSingleModeConstructsFine(): void {
    $plan = UploadPlan::fromResponse([
      'mode' => 'single',
      'url' => 'https://s3.example.test/put-url',
      'part_size' => 0,
      'expires' => '2026-01-01T00:00:00Z',
    ]);

    self::assertFalse($plan->isMultipart());
    self::assertSame('https://s3.example.test/put-url', $plan->getUrl());
  }

  /**
   * @covers ::fromResponse
   */
  public function testValidMultipartModeConstructsFine(): void {
    $plan = UploadPlan::fromResponse([
      'mode' => 'multipart',
      'upload_id' => 'u1',
      'parts' => [['n' => 1, 'url' => 'https://s3.example.test/part-1']],
      'part_size' => 10,
      'expires' => '2026-01-01T00:00:00Z',
    ]);

    self::assertTrue($plan->isMultipart());
    self::assertCount(1, $plan->getParts());
    self::assertSame(10, $plan->getPartSize());
  }

  /**
   * @covers ::fromResponse
   *
   * Regression test: a degenerate multipart plan with zero parts must be
   * rejected at the boundary, not silently accepted - otherwise
   * Uploader::uploadRemainingParts() computes total=0, its loop never runs,
   * and it returns as if the upload succeeded, letting BackupOrchestrator
   * mark the backup ready with zero bytes actually transferred.
   */
  public function testMultipartWithEmptyPartsThrows(): void {
    $this->expectException(UploadFailedException::class);
    $this->expectExceptionMessageMatches('/at least one part/');
    UploadPlan::fromResponse([
      'mode' => 'multipart',
      'parts' => [],
      'part_size' => 10,
      'expires' => '2026-01-01T00:00:00Z',
    ]);
  }

  /**
   * @covers ::fromResponse
   */
  public function testMultipartWithZeroPartSizeThrows(): void {
    $this->expectException(UploadFailedException::class);
    $this->expectExceptionMessageMatches('/part_size/');
    UploadPlan::fromResponse([
      'mode' => 'multipart',
      'parts' => [['n' => 1, 'url' => 'https://s3.example.test/part-1']],
      'part_size' => 0,
      'expires' => '2026-01-01T00:00:00Z',
    ]);
  }

  /**
   * @covers ::fromResponse
   */
  public function testSingleWithNullUrlThrows(): void {
    $this->expectException(UploadFailedException::class);
    $this->expectExceptionMessageMatches('/url/');
    UploadPlan::fromResponse([
      'mode' => 'single',
      'part_size' => 0,
      'expires' => '2026-01-01T00:00:00Z',
    ]);
  }

  /**
   * @covers ::fromResponse
   */
  public function testUnrecognizedModeThrowsInsteadOfSilentlyFallingThroughToSingle(): void {
    $this->expectException(UploadFailedException::class);
    $this->expectExceptionMessageMatches('/chunked/');
    UploadPlan::fromResponse([
      'mode' => 'chunked',
      'url' => 'https://s3.example.test/put-url',
      'part_size' => 0,
      'expires' => '2026-01-01T00:00:00Z',
    ]);
  }

}
