<?php

declare(strict_types=1);

namespace Drupal\Tests\drupalforge_migrate\Unit;

use Drupal\Core\State\State;
use Drupal\Core\KeyValueStore\KeyValueMemoryFactory;
use Drupal\Component\Datetime\Time;
use Drupal\Core\Cache\NullBackend;
use Drupal\Core\Lock\NullLockBackend;
use Drupal\drupalforge_migrate\Service\ProgressStateStore;
use PHPUnit\Framework\TestCase;

/**
 * @coversDefaultClass \Drupal\drupalforge_migrate\Service\ProgressStateStore
 * @group drupalforge_migrate
 */
final class ProgressStateStoreTest extends TestCase {

  private function makeStore(): ProgressStateStore {
    $state = new State(new KeyValueMemoryFactory(), new NullBackend('state'), new NullLockBackend());
    return new ProgressStateStore($state);
  }

  /**
   * @covers ::getCurrentBackupId
   * @covers ::setCurrentBackupId
   */
  public function testCurrentBackupIdRoundTrip(): void {
    $store = $this->makeStore();
    $this->assertNull($store->getCurrentBackupId());

    $store->setCurrentBackupId('backup-1');
    $this->assertSame('backup-1', $store->getCurrentBackupId());

    $store->setCurrentBackupId(NULL);
    $this->assertNull($store->getCurrentBackupId());
  }

  /**
   * @covers ::getExportCursor
   * @covers ::setExportCursor
   * @covers ::clearExportCursor
   */
  public function testExportCursorRoundTrip(): void {
    $store = $this->makeStore();
    $this->assertSame([], $store->getExportCursor('backup-1'));

    $store->setExportCursor('backup-1', ['table_index' => 3, 'row_offset' => 200]);
    $this->assertSame(['table_index' => 3, 'row_offset' => 200], $store->getExportCursor('backup-1'));

    // A different backup id must not see backup-1's cursor.
    $this->assertSame([], $store->getExportCursor('backup-2'));

    $store->clearExportCursor('backup-1');
    $this->assertSame([], $store->getExportCursor('backup-1'));
  }

  /**
   * @covers ::getUploadState
   * @covers ::setUploadState
   * @covers ::clearUploadState
   */
  public function testUploadStateRoundTripIsPerArtifact(): void {
    $store = $this->makeStore();
    $this->assertSame([], $store->getUploadState('backup-1', 'code'));

    $store->setUploadState('backup-1', 'code', ['mode' => 'multipart', 'parts_done' => [1 => 'etag1']]);
    $this->assertSame(['mode' => 'multipart', 'parts_done' => [1 => 'etag1']], $store->getUploadState('backup-1', 'code'));

    // Different artifact type, same backup, must be isolated.
    $this->assertSame([], $store->getUploadState('backup-1', 'db'));

    $store->clearUploadState('backup-1', 'code');
    $this->assertSame([], $store->getUploadState('backup-1', 'code'));
  }

  /**
   * @covers ::getLastStatus
   * @covers ::setLastStatus
   */
  public function testLastStatusRoundTrip(): void {
    $store = $this->makeStore();
    $this->assertSame([], $store->getLastStatus());

    $status = ['backup_id' => 'backup-1', 'step' => 'archive', 'ok' => FALSE, 'errors' => ['boom']];
    $store->setLastStatus($status);
    $this->assertSame($status, $store->getLastStatus());
  }

}
