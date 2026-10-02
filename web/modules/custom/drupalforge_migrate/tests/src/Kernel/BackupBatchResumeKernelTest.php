<?php

declare(strict_types=1);

namespace Drupal\Tests\drupalforge_migrate\Kernel;

use Drupal\drupalforge_migrate\Batch\BackupBatch;
use Drupal\drupalforge_migrate\Exception\UploadFailedException;
use Drupal\drupalforge_migrate\Service\DrupalForgeClientInterface;
use Drupal\drupalforge_migrate\Service\ProgressStateStore;
use Drupal\drupalforge_migrate\Service\UploaderInterface;
use Drupal\drupalforge_migrate\Value\ArtifactDescriptor;
use Drupal\drupalforge_migrate\Value\UploadPlan;
use Drupal\KernelTests\KernelTestBase;

/**
 * @coversDefaultClass \Drupal\drupalforge_migrate\Batch\BackupBatch
 * @group drupalforge_migrate
 */
final class BackupBatchResumeKernelTest extends KernelTestBase {

  protected static $modules = ['drupalforge_migrate'];

  /**
   * @covers ::codeUploadOperation
   */
  public function testUploadOperationResumesFromRecordedPartsAndSkipsFinishedOnes(): void {
    $progress = new ProgressStateStore($this->container->get('state'));
    $backupId = 'batch-test-1';
    $progress->setUploadState($backupId, 'code', [
      'mode' => 'multipart',
      'parts_done' => [1 => 'e1', 2 => 'e2', 3 => 'e3'],
      'total_parts' => 6,
    ]);

    $calledParts = [];
    $uploader = $this->createMock(UploaderInterface::class);
    $uploader->method('resumeFrom')->willReturn(4);
    $uploader->expects($this->exactly(3))->method('uploadOnePart')
      ->willReturnCallback(function (string $bId, ArtifactDescriptor $artifact, UploadPlan $plan, int $partNumber) use (&$calledParts, $progress, $backupId): string {
        $calledParts[] = $partNumber;
        $state = $progress->getUploadState($backupId, 'code');
        $state['parts_done'][$partNumber] = "e{$partNumber}";
        $progress->setUploadState($backupId, 'code', $state);
        return "e{$partNumber}";
      });

    $client = $this->createMock(DrupalForgeClientInterface::class);
    $client->method('requestUpload')->willReturn(UploadPlan::fromResponse([
      'mode' => 'multipart',
      'upload_id' => 'u1',
      'parts' => array_map(static fn (int $n): array => ['n' => $n, 'url' => "https://s3.example.test/part-{$n}"], range(1, 6)),
      'part_size' => 10,
      'expires' => '2026-01-01T00:00:00Z',
    ]));

    $this->container->set('drupalforge_migrate.uploader', $uploader);
    $this->container->set('drupalforge_migrate.drupalforge_client', $client);
    $this->container->set('drupalforge_migrate.progress_state_store', $progress);
    \Drupal::setContainer($this->container);

    $tmp = tempnam(sys_get_temp_dir(), 'dfm_batch_');
    file_put_contents($tmp, str_repeat('x', 60));

    $context = [
      'results' => [
        'backup_id' => $backupId,
        'code_descriptor' => (new ArtifactDescriptor('code', $tmp, 60, hash_file('sha256', $tmp)))->toArray(),
      ],
    ];

    // The Batch API re-invokes the SAME operation repeatedly until
    // $context['finished'] >= 1 - simulate that loop directly, since
    // batch_set()/batch_process() need a full HTTP request cycle.
    $iterations = 0;
    do {
      BackupBatch::codeUploadOperation($context);
      $iterations++;
      self::assertLessThan(20, $iterations, 'codeUploadOperation looped without terminating.');
    } while (($context['finished'] ?? 1) < 1);

    self::assertSame([4, 5, 6], $calledParts, 'Parts 1-3 (already recorded in ProgressStateStore) must not be re-uploaded; upload must resume from part 4.');
    self::assertArrayNotHasKey('error', $context['results']);
    self::assertNotEmpty($context['results']['artifacts'][0]['parts'] ?? NULL);

    unlink($tmp);
  }

  /**
   * @covers ::codeUploadOperation
   *
   * Regression test: uploadOperation() used to call Uploader/
   * DrupalForgeClient directly, bypassing BackupOrchestrator's
   * abort-on-failure contract entirely - a failed upload during the
   * web/Batch path never DELETEd the DrupalForge reservation and never
   * updated ProgressStateStore's last_status, leaving the status page
   * showing stale "OK" state. A failed part upload must now abort exactly
   * like every other guarded step (archive, export, complete).
   */
  public function testUploadOperationFailureAbortsBackupAndRecordsStatus(): void {
    $progress = new ProgressStateStore($this->container->get('state'));
    $backupId = 'batch-test-fail-1';

    $uploader = $this->createMock(UploaderInterface::class);
    $uploader->method('resumeFrom')->willReturn(1);
    $uploader->method('uploadOnePart')->willThrowException(new UploadFailedException('part 1 exceeded retry budget'));

    $abortedIds = [];
    $client = $this->createMock(DrupalForgeClientInterface::class);
    $client->method('requestUpload')->willReturn(UploadPlan::fromResponse([
      'mode' => 'multipart',
      'upload_id' => 'u2',
      'parts' => [['n' => 1, 'url' => 'https://s3.example.test/part-1']],
      'part_size' => 10,
      'expires' => '2026-01-01T00:00:00Z',
    ]));
    $client->expects($this->once())->method('abort')->willReturnCallback(function (string $id) use (&$abortedIds): void {
      $abortedIds[] = $id;
    });

    $this->container->set('drupalforge_migrate.uploader', $uploader);
    $this->container->set('drupalforge_migrate.drupalforge_client', $client);
    $this->container->set('drupalforge_migrate.progress_state_store', $progress);
    \Drupal::setContainer($this->container);

    $tmp = tempnam(sys_get_temp_dir(), 'dfm_batch_fail_');
    file_put_contents($tmp, str_repeat('x', 10));

    $context = [
      'results' => [
        'backup_id' => $backupId,
        'code_descriptor' => (new ArtifactDescriptor('code', $tmp, 10, hash_file('sha256', $tmp)))->toArray(),
      ],
    ];

    BackupBatch::codeUploadOperation($context);

    self::assertSame([$backupId], $abortedIds, 'A failed upload part during the web/Batch path must abort the DrupalForge reservation, not just record a local error.');
    self::assertArrayHasKey('error', $context['results']);
    self::assertStringContainsString('part 1 exceeded retry budget', $context['results']['error']);

    $status = $progress->getLastStatus();
    self::assertSame($backupId, $status['backup_id'] ?? NULL);
    self::assertFalse($status['ok'] ?? TRUE);

    unlink($tmp);
  }

}
