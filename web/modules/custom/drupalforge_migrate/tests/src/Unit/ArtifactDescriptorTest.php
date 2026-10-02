<?php

declare(strict_types=1);

namespace Drupal\Tests\drupalforge_migrate\Unit;

use Drupal\drupalforge_migrate\Value\ArtifactDescriptor;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\drupalforge_migrate\Value\ArtifactDescriptor
 * @group drupalforge_migrate
 */
final class ArtifactDescriptorTest extends TestCase {

  /**
   * @covers ::__construct
   */
  public function testValidCodeAndDbArtifactsConstructFine(): void {
    $code = new ArtifactDescriptor('code', '/tmp/code.tar.gz', 100, str_repeat('a', 64));
    $db = new ArtifactDescriptor('db', '/tmp/db.sql.gz', 200, str_repeat('b', 64));

    self::assertSame('code', $code->getArtifact());
    self::assertSame('db', $db->getArtifact());
  }

  /**
   * @covers ::__construct
   */
  public function testUnrecognizedArtifactTypeThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/artifact type/');
    new ArtifactDescriptor('files', '/tmp/x', 100, str_repeat('a', 64));
  }

  /**
   * @covers ::__construct
   *
   * Regression test: BackupOrchestrator::getArtifactPath() used to map any
   * non-'code' artifact string silently to the db path rather than
   * rejecting it - a typo'd artifact type must fail loudly here, at
   * construction, not resolve to the wrong local file downstream.
   */
  public function testZeroSizeThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/size/');
    new ArtifactDescriptor('code', '/tmp/x', 0, str_repeat('a', 64));
  }

  /**
   * @covers ::__construct
   */
  public function testNegativeSizeThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    new ArtifactDescriptor('code', '/tmp/x', -1, str_repeat('a', 64));
  }

  /**
   * @covers ::__construct
   */
  public function testEmptySha256Throws(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessageMatches('/sha256/');
    new ArtifactDescriptor('code', '/tmp/x', 100, '');
  }

  /**
   * @covers ::__construct
   */
  public function testMalformedSha256Throws(): void {
    $this->expectException(\InvalidArgumentException::class);
    // 63 chars, one short, and contains a non-hex character.
    new ArtifactDescriptor('code', '/tmp/x', 100, str_repeat('g', 63));
  }

  /**
   * @covers ::toArray
   * @covers ::fromArray
   *
   * The round-trip through a keyed array (as BackupBatch now persists a
   * descriptor across Batch API operations) must preserve every field -
   * unlike the old positional-tuple format, a field-order mistake here
   * would be caught by a missing/wrong array key, not silently accepted.
   */
  public function testToArrayFromArrayRoundTripPreservesAllFields(): void {
    $original = new ArtifactDescriptor('db', '/tmp/db.sql.gz', 4096, str_repeat('c', 64));

    $roundTripped = ArtifactDescriptor::fromArray($original->toArray());

    self::assertSame($original->getArtifact(), $roundTripped->getArtifact());
    self::assertSame($original->getPath(), $roundTripped->getPath());
    self::assertSame($original->getSize(), $roundTripped->getSize());
    self::assertSame($original->getSha256(), $roundTripped->getSha256());
  }

  /**
   * @covers ::toArray
   */
  public function testToArrayIsKeyedNotPositional(): void {
    $descriptor = new ArtifactDescriptor('code', '/tmp/code.tar.gz', 100, str_repeat('a', 64));

    self::assertSame([
      'artifact' => 'code',
      'path' => '/tmp/code.tar.gz',
      'size' => 100,
      'sha256' => str_repeat('a', 64),
    ], $descriptor->toArray());
  }

  /**
   * @covers ::fromArray
   *
   * A malformed serialized descriptor (e.g. from a swapped field order bug)
   * must be rejected by the same constructor validation, not silently
   * accepted.
   */
  public function testFromArrayWithSwappedArtifactAndPathThrows(): void {
    $this->expectException(\InvalidArgumentException::class);
    ArtifactDescriptor::fromArray([
      'artifact' => '/tmp/code.tar.gz',
      'path' => 'code',
      'size' => 100,
      'sha256' => str_repeat('a', 64),
    ]);
  }

}
