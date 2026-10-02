<?php

declare(strict_types=1);

namespace Drupal\Tests\drupalforge_migrate\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Database\Connection;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\KeyValueStore\KeyValueMemoryFactory;
use Drupal\Core\Lock\NullLockBackend;
use Drupal\Core\Cache\NullBackend;
use Drupal\Core\State\State;
use Drupal\drupalforge_migrate\Exception\ArchiveFailedException;
use Drupal\drupalforge_migrate\Exception\DrupalForgeApiException;
use Drupal\drupalforge_migrate\Exception\ExportFailedException;
use Drupal\drupalforge_migrate\Exception\PreflightFailedException;
use Drupal\drupalforge_migrate\Exception\UploadFailedException;
use Drupal\drupalforge_migrate\Service\BackupOrchestrator;
use Drupal\drupalforge_migrate\Service\CodeArchiverInterface;
use Drupal\drupalforge_migrate\Service\DatabaseExporterInterface;
use Drupal\drupalforge_migrate\Service\DrupalForgeClientInterface;
use Drupal\drupalforge_migrate\Service\PreflightService;
use Drupal\drupalforge_migrate\Service\ProgressStateStore;
use Drupal\drupalforge_migrate\Service\ShellRunnerInterface;
use Drupal\drupalforge_migrate\Service\UploaderInterface;
use Drupal\drupalforge_migrate\Value\ArtifactDescriptor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @coversDefaultClass \Drupal\drupalforge_migrate\Service\BackupOrchestrator
 * @group drupalforge_migrate
 */
final class BackupOrchestratorTest extends TestCase {

  private function makeProgress(): ProgressStateStore {
    return new ProgressStateStore(new State(new KeyValueMemoryFactory(), new NullBackend('state'), new NullLockBackend()));
  }

  /**
   * A CodeArchiverInterface mock whose buildExclusionList() is
   * pre-configured - required by any test that reaches completeBackup()'s
   * buildManifest() call, which reads 'tar_excludes'/'settings_files' from
   * it regardless of whether a code artifact was actually archived in that
   * test (resumeBackup()'s manifest always includes exclusion metadata).
   */
  private function makeArchiverForManifestBuilding(): CodeArchiverInterface {
    $archiver = $this->createMock(CodeArchiverInterface::class);
    $archiver->method('buildExclusionList')->willReturn(['tar_excludes' => [], 'settings_files' => []]);
    return $archiver;
  }

  private function makeConfigFactory(?string $privatePath = NULL): ConfigFactoryInterface {
    $config = $this->createMock(ImmutableConfig::class);
    $config->method('get')->with('path.private')->willReturn($privatePath);
    $configFactory = $this->createMock(ConfigFactoryInterface::class);
    $configFactory->method('get')->with('system.file')->willReturn($config);
    return $configFactory;
  }

  /**
   * A supported PreflightService: real object, all its dependency doubles
   * report a fully-capable environment.
   */
  private function makeSupportedPreflight(): PreflightService {
    $database = $this->createMock(Connection::class);
    $database->method('driver')->willReturn('mysql');
    $database->method('query')->willReturn($this->createConfiguredMock(\Drupal\Core\Database\StatementInterface::class, ['fetchField' => 0]));
    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('getTempDirectory')->willReturn(sys_get_temp_dir());
    $fileSystem->method('realpath')->willReturn(sys_get_temp_dir());
    $shellRunner = $this->createMock(ShellRunnerInterface::class);
    $shellRunner->method('isBinaryAvailable')->willReturn(TRUE);
    return new PreflightService($database, $fileSystem, $shellRunner);
  }

  /**
   * An unsupported PreflightService: no CLI tools, unknown DB driver.
   */
  private function makeUnsupportedPreflight(): PreflightService {
    $database = $this->createMock(Connection::class);
    $database->method('driver')->willReturn('sqlite');
    $database->method('query')->willReturn($this->createConfiguredMock(\Drupal\Core\Database\StatementInterface::class, ['fetchField' => 0]));
    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('getTempDirectory')->willReturn('/nonexistent-temp-dir-dfm-test');
    $fileSystem->method('realpath')->willReturn(sys_get_temp_dir());
    $shellRunner = $this->createMock(ShellRunnerInterface::class);
    $shellRunner->method('isBinaryAvailable')->willReturn(FALSE);
    return new PreflightService($database, $fileSystem, $shellRunner);
  }

  /**
   * @covers ::getArtifactPath
   *
   * Regression test: getArtifactPath() used to silently map any non-'code'
   * artifact string to the db path (`$artifact === 'code' ? ... : ...`)
   * rather than rejecting an unrecognized value - it takes a bare string,
   * not necessarily always sourced from an already-validated
   * ArtifactDescriptor, so it needs its own defensive check.
   */
  public function testGetArtifactPathRejectsUnrecognizedArtifactType(): void {
    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('getTempDirectory')->willReturn(sys_get_temp_dir());

    $orchestrator = new BackupOrchestrator(
      $this->makeSupportedPreflight(),
      $this->createMock(CodeArchiverInterface::class),
      $this->createMock(DatabaseExporterInterface::class),
      $this->createMock(DrupalForgeClientInterface::class),
      $this->makeProgress(),
      $fileSystem,
      new NullLogger(),
      $this->makeConfigFactory(),
      $this->createMock(UploaderInterface::class),
    );

    $this->expectException(\InvalidArgumentException::class);
    $orchestrator->getArtifactPath('some-backup', 'files');
  }

  /**
   * @covers ::startBackup
   */
  public function testPreflightFailurePreventsReserveBackup(): void {
    $client = $this->createMock(DrupalForgeClientInterface::class);
    $client->expects($this->never())->method('reserveBackup');

    $orchestrator = new BackupOrchestrator(
      $this->makeUnsupportedPreflight(),
      $this->createMock(CodeArchiverInterface::class),
      $this->createMock(DatabaseExporterInterface::class),
      $client,
      $this->makeProgress(),
      $this->createMock(FileSystemInterface::class),
      new NullLogger(),
      $this->makeConfigFactory(),
      $this->createMock(UploaderInterface::class),
    );

    $this->expectException(PreflightFailedException::class);
    $orchestrator->startBackup(codeOnly: TRUE);
  }

  /**
   * @covers ::startBackup
   * @covers ::runCodeArtifact
   */
  public function testSuccessfulStartAndCodeArtifact(): void {
    $client = $this->createMock(DrupalForgeClientInterface::class);
    $client->method('reserveBackup')->willReturn(['backup_id' => 'orc-1', 'reserved_until' => '2026-01-01T00:00:00Z']);

    $descriptor = new ArtifactDescriptor('code', '/tmp/fake.tar.gz', 123, str_repeat('a', 64));
    $archiver = $this->createMock(CodeArchiverInterface::class);
    $archiver->method('archive')->willReturn($descriptor);

    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('getTempDirectory')->willReturn(sys_get_temp_dir());

    $orchestrator = new BackupOrchestrator(
      $this->makeSupportedPreflight(),
      $archiver,
      $this->createMock(DatabaseExporterInterface::class),
      $client,
      $this->makeProgress(),
      $fileSystem,
      new NullLogger(),
      $this->makeConfigFactory(),
      $this->createMock(UploaderInterface::class),
    );

    $backupId = $orchestrator->startBackup(codeOnly: TRUE);
    $this->assertSame('orc-1', $backupId);

    $result = $orchestrator->runCodeArtifact($backupId);
    $this->assertSame($descriptor, $result);
  }

  /**
   * @covers ::runCodeArtifact
   * @covers ::abortBackup
   */
  public function testCodeArchiverFailureTriggersAbortWithNamedReason(): void {
    $abortedIds = [];
    $client = $this->createMock(DrupalForgeClientInterface::class);
    $client->method('reserveBackup')->willReturn(['backup_id' => 'orc-2', 'reserved_until' => '2026-01-01T00:00:00Z']);
    $client->expects($this->once())->method('abort')->willReturnCallback(function (string $id) use (&$abortedIds): void {
      $abortedIds[] = $id;
    });

    $archiver = $this->createMock(CodeArchiverInterface::class);
    $archiver->method('archive')->willThrowException(new ArchiveFailedException('tar/gzip unavailable and site exceeds the PHP-archiver limit'));

    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('getTempDirectory')->willReturn(sys_get_temp_dir());

    $progress = $this->makeProgress();

    $orchestrator = new BackupOrchestrator(
      $this->makeSupportedPreflight(),
      $archiver,
      $this->createMock(DatabaseExporterInterface::class),
      $client,
      $progress,
      $fileSystem,
      new NullLogger(),
      $this->makeConfigFactory(),
      $this->createMock(UploaderInterface::class),
    );

    $backupId = $orchestrator->startBackup(codeOnly: TRUE);

    $this->expectException(ArchiveFailedException::class);
    try {
      $orchestrator->runCodeArtifact($backupId);
    }
    finally {
      $this->assertSame([$backupId], $abortedIds);
      $status = $progress->getLastStatus();
      $this->assertSame($backupId, $status['backup_id'] ?? NULL);
      $this->assertFalse($status['ok'] ?? TRUE);
      $this->assertStringContainsString('tar/gzip unavailable', $status['errors'][0] ?? '');
    }
  }

  /**
   * @covers ::runFullBackup
   * @covers ::completeBackup
   */
  public function testRunFullBackupUploadsBothArtifactsAndCompletesExactlyOnce(): void {
    $client = $this->createMock(DrupalForgeClientInterface::class);
    $client->method('reserveBackup')->willReturn(['backup_id' => 'orc-3', 'reserved_until' => '2026-01-01T00:00:00Z']);
    $client->expects($this->once())->method('complete');
    $client->expects($this->never())->method('abort');

    $codeDescriptor = new ArtifactDescriptor('code', '/tmp/fake-code.tar.gz', 100, str_repeat('a', 64));
    $dbDescriptor = new ArtifactDescriptor('db', '/tmp/fake-db.sql.gz', 200, str_repeat('b', 64));

    $archiver = $this->createMock(CodeArchiverInterface::class);
    $archiver->method('archive')->willReturn($codeDescriptor);
    $archiver->method('buildExclusionList')->willReturn(['tar_excludes' => ['.git'], 'settings_files' => ['sites/default/settings.php']]);

    $exporter = $this->createMock(DatabaseExporterInterface::class);
    $exporter->method('exportViaCli')->willReturn($dbDescriptor);

    $uploader = $this->createMock(UploaderInterface::class);
    $uploader->expects($this->exactly(2))->method('uploadArtifact')->willReturnOnConsecutiveCalls(
      ['artifact' => 'code', 'sha256' => $codeDescriptor->getSha256(), 'size' => 100, 'parts' => []],
      ['artifact' => 'db', 'sha256' => $dbDescriptor->getSha256(), 'size' => 200, 'parts' => []],
    );

    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('getTempDirectory')->willReturn(sys_get_temp_dir());

    $orchestrator = new BackupOrchestrator(
      $this->makeSupportedPreflight(),
      $archiver,
      $exporter,
      $client,
      $this->makeProgress(),
      $fileSystem,
      new NullLogger(),
      $this->makeConfigFactory(),
      $uploader,
    );

    $backupId = $orchestrator->runFullBackup();
    $this->assertSame('orc-3', $backupId);
  }

  /**
   * @covers ::resumeUpload
   *
   * Regression test: resumeUpload() used to do
   * `hash_file('sha256', $path) ?: ''`, silently coercing a hashing
   * failure into an empty checksum instead of failing loudly - an
   * ArtifactDescriptor with sha256='' would then be sent to DrupalForge as
   * if it were a real checksum.
   */
  public function testResumeUploadFailsLoudlyWhenChecksumCannotBeComputed(): void {
    $client = $this->createMock(DrupalForgeClientInterface::class);
    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('getTempDirectory')->willReturn(sys_get_temp_dir());

    $orchestrator = new BackupOrchestrator(
      $this->makeSupportedPreflight(),
      $this->createMock(CodeArchiverInterface::class),
      $this->createMock(DatabaseExporterInterface::class),
      $client,
      $this->makeProgress(),
      $fileSystem,
      new NullLogger(),
      $this->makeConfigFactory(),
      $this->createMock(UploaderInterface::class),
    );

    $backupId = 'orc-hash-fail';
    $path = $orchestrator->getArtifactPath($backupId, 'code');
    file_put_contents($path, 'some content');
    // Unreadable: hash_file() genuinely returns FALSE for this file (tests
    // run as a non-root user - see the module's README for how tests are
    // invoked), simulating the real-world race/permission failure this
    // guards against.
    chmod($path, 0000);

    try {
      $this->expectException(UploadFailedException::class);
      $this->expectExceptionMessageMatches('/checksum/');
      $orchestrator->resumeUpload($backupId, 'code');
    }
    finally {
      chmod($path, 0644);
      @unlink($path);
    }
  }

  /**
   * @covers ::runFullBackup
   * @covers ::abortBackup
   */
  public function testUploadFailurePreventsCompleteAndTriggersAbort(): void {
    $client = $this->createMock(DrupalForgeClientInterface::class);
    $client->method('reserveBackup')->willReturn(['backup_id' => 'orc-4', 'reserved_until' => '2026-01-01T00:00:00Z']);
    $client->expects($this->never())->method('complete');
    $client->expects($this->once())->method('abort')->with('orc-4');

    $codeDescriptor = new ArtifactDescriptor('code', '/tmp/fake-code2.tar.gz', 100, str_repeat('a', 64));
    $archiver = $this->createMock(CodeArchiverInterface::class);
    $archiver->method('archive')->willReturn($codeDescriptor);
    $archiver->method('buildExclusionList')->willReturn(['tar_excludes' => [], 'settings_files' => []]);

    $uploader = $this->createMock(UploaderInterface::class);
    $uploader->method('uploadArtifact')->willThrowException(new UploadFailedException('upload part 2 exceeded retry budget'));

    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('getTempDirectory')->willReturn(sys_get_temp_dir());

    $orchestrator = new BackupOrchestrator(
      $this->makeSupportedPreflight(),
      $archiver,
      $this->createMock(DatabaseExporterInterface::class),
      $client,
      $this->makeProgress(),
      $fileSystem,
      new NullLogger(),
      $this->makeConfigFactory(),
      $uploader,
    );

    $this->expectException(UploadFailedException::class);
    $orchestrator->runFullBackup(codeOnly: TRUE);
  }

  /**
   * @covers ::runDbArtifactCli
   * @covers ::abortBackup
   *
   * The database-export failure path was previously untested at the
   * orchestrator-guard level (only DatabaseExporter's own unit/kernel
   * tests covered ExportFailedException directly) - this confirms
   * runGuarded() aborts and rethrows for export failures exactly like it
   * does for archive/upload failures above.
   */
  public function testDatabaseExportFailureTriggersAbort(): void {
    $client = $this->createMock(DrupalForgeClientInterface::class);
    $client->method('reserveBackup')->willReturn(['backup_id' => 'orc-db-fail', 'reserved_until' => '2026-01-01T00:00:00Z']);
    $client->expects($this->once())->method('abort')->with('orc-db-fail');
    $client->expects($this->never())->method('complete');

    $exporter = $this->createMock(DatabaseExporterInterface::class);
    $exporter->method('exportViaCli')->willThrowException(new ExportFailedException('mysqldump exited non-zero'));

    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('getTempDirectory')->willReturn(sys_get_temp_dir());

    $progress = $this->makeProgress();

    $orchestrator = new BackupOrchestrator(
      $this->makeSupportedPreflight(),
      $this->createMock(CodeArchiverInterface::class),
      $exporter,
      $client,
      $progress,
      $fileSystem,
      new NullLogger(),
      $this->makeConfigFactory(),
      $this->createMock(UploaderInterface::class),
    );

    $this->expectException(ExportFailedException::class);
    try {
      $orchestrator->runFullBackup(dbOnly: TRUE);
    }
    finally {
      $status = $progress->getLastStatus();
      $this->assertFalse($status['ok'] ?? TRUE);
      $this->assertStringContainsString('mysqldump exited non-zero', $status['errors'][0] ?? '');
    }
  }

  /**
   * @covers ::completeBackup
   *
   * The last line of defense: every artifact can upload successfully, but
   * if DrupalForge's complete() call itself fails (a 500, a checksum
   * rejection), the backup must still abort - a bug that cleared progress
   * state / recorded `step => ready` before (rather than after) a
   * successful complete() call would pass every other test in this file.
   */
  public function testCompleteFailureAfterSuccessfulUploadsStillAbortsAndNeverMarksReady(): void {
    $client = $this->createMock(DrupalForgeClientInterface::class);
    $client->method('reserveBackup')->willReturn(['backup_id' => 'orc-complete-fail', 'reserved_until' => '2026-01-01T00:00:00Z']);
    $client->method('complete')->willThrowException(new DrupalForgeApiException('simulated 500 from DrupalForge'));
    $client->expects($this->once())->method('abort')->with('orc-complete-fail');

    $codeDescriptor = new ArtifactDescriptor('code', '/tmp/fake-code3.tar.gz', 100, str_repeat('a', 64));
    $archiver = $this->createMock(CodeArchiverInterface::class);
    $archiver->method('archive')->willReturn($codeDescriptor);
    $archiver->method('buildExclusionList')->willReturn(['tar_excludes' => [], 'settings_files' => []]);

    $uploader = $this->createMock(UploaderInterface::class);
    $uploader->method('uploadArtifact')->willReturn([
      'artifact' => 'code',
      'sha256' => $codeDescriptor->getSha256(),
      'size' => 100,
      'parts' => [],
    ]);

    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('getTempDirectory')->willReturn(sys_get_temp_dir());

    $progress = $this->makeProgress();

    $orchestrator = new BackupOrchestrator(
      $this->makeSupportedPreflight(),
      $archiver,
      $this->createMock(DatabaseExporterInterface::class),
      $client,
      $progress,
      $fileSystem,
      new NullLogger(),
      $this->makeConfigFactory(),
      $uploader,
    );

    $this->expectException(DrupalForgeApiException::class);
    try {
      $orchestrator->runFullBackup(codeOnly: TRUE);
    }
    finally {
      $status = $progress->getLastStatus();
      $this->assertNotSame('ready', $status['step'] ?? NULL);
      $this->assertFalse($status['ok'] ?? TRUE);
    }
  }

  /**
   * @covers ::resumeBackup
   */
  public function testResumeBackupThrowsWhenNoCurrentBackupId(): void {
    $orchestrator = new BackupOrchestrator(
      $this->makeSupportedPreflight(),
      $this->createMock(CodeArchiverInterface::class),
      $this->createMock(DatabaseExporterInterface::class),
      $this->createMock(DrupalForgeClientInterface::class),
      $this->makeProgress(),
      $this->createMock(FileSystemInterface::class),
      new NullLogger(),
      $this->makeConfigFactory(),
      $this->createMock(UploaderInterface::class),
    );

    $this->expectException(UploadFailedException::class);
    $this->expectExceptionMessageMatches('/No in-progress backup/');
    $orchestrator->resumeBackup();
  }

  /**
   * @covers ::resumeBackup
   */
  public function testResumeBackupThrowsWhenNoLocalArtifactsExistOnDisk(): void {
    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('getTempDirectory')->willReturn(sys_get_temp_dir());

    $progress = $this->makeProgress();
    $progress->setCurrentBackupId('orc-resume-empty');

    $orchestrator = new BackupOrchestrator(
      $this->makeSupportedPreflight(),
      $this->createMock(CodeArchiverInterface::class),
      $this->createMock(DatabaseExporterInterface::class),
      $this->createMock(DrupalForgeClientInterface::class),
      $progress,
      $fileSystem,
      new NullLogger(),
      $this->makeConfigFactory(),
      $this->createMock(UploaderInterface::class),
    );

    $this->expectException(UploadFailedException::class);
    $this->expectExceptionMessageMatches('/No local artifacts/');
    $orchestrator->resumeBackup();
  }

  /**
   * @covers ::resumeUpload
   */
  public function testResumeUploadThrowsWhenLocalArtifactFileIsMissing(): void {
    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('getTempDirectory')->willReturn(sys_get_temp_dir());

    $orchestrator = new BackupOrchestrator(
      $this->makeSupportedPreflight(),
      $this->createMock(CodeArchiverInterface::class),
      $this->createMock(DatabaseExporterInterface::class),
      $this->createMock(DrupalForgeClientInterface::class),
      $this->makeProgress(),
      $fileSystem,
      new NullLogger(),
      $this->makeConfigFactory(),
      $this->createMock(UploaderInterface::class),
    );

    $this->expectException(UploadFailedException::class);
    $this->expectExceptionMessageMatches('/local artifact file not found/');
    $orchestrator->resumeUpload('orc-resume-missing-file', 'code');
  }

  /**
   * @covers ::resumeBackup
   *
   * Only the code artifact reached disk before the run was interrupted
   * (the db export never started) - resumeBackup() must resume just that
   * one artifact and still complete successfully, not wait for a db
   * artifact that was never produced.
   */
  public function testResumeBackupWithOnlyCodeArtifactOnDiskCompletesWithCodeOnly(): void {
    $client = $this->createMock(DrupalForgeClientInterface::class);
    $client->expects($this->once())->method('complete')->with(
      $this->anything(),
      $this->callback(static fn (array $results): bool => count($results) === 1 && $results[0]['artifact'] === 'code'),
      $this->anything(),
    );
    $client->expects($this->never())->method('abort');

    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('getTempDirectory')->willReturn(sys_get_temp_dir());

    $progress = $this->makeProgress();
    $backupId = 'orc-resume-code-only';
    $progress->setCurrentBackupId($backupId);

    $uploader = $this->createMock(UploaderInterface::class);
    $uploader->expects($this->once())->method('uploadArtifact')->willReturn([
      'artifact' => 'code',
      'sha256' => str_repeat('a', 64),
      'size' => 5,
      'parts' => [],
    ]);

    $orchestrator = new BackupOrchestrator(
      $this->makeSupportedPreflight(),
      $this->makeArchiverForManifestBuilding(),
      $this->createMock(DatabaseExporterInterface::class),
      $client,
      $progress,
      $fileSystem,
      new NullLogger(),
      $this->makeConfigFactory(),
      $uploader,
    );

    $codePath = $orchestrator->getArtifactPath($backupId, 'code');
    file_put_contents($codePath, 'partial-archive-bytes');

    try {
      $result = $orchestrator->resumeBackup();
      $this->assertSame($backupId, $result);
    }
    finally {
      @unlink($codePath);
    }
  }

  /**
   * @covers ::resumeBackup
   *
   * Both artifacts already reached disk before the interruption (e.g. only
   * the upload itself was cut short) - resumeBackup() must resume/finish
   * both and complete exactly once. (Per-part "don't re-PUT already-done
   * parts" behavior is Uploader's responsibility and is covered by
   * Uploader's own resume tests - BackupOrchestrator only decides *which
   * artifacts* to resume, based on what's on disk, and delegates the rest.)
   */
  public function testResumeBackupWithBothArtifactsOnDiskUploadsBothAndCompletesOnce(): void {
    $client = $this->createMock(DrupalForgeClientInterface::class);
    $client->expects($this->once())->method('complete')->with(
      $this->anything(),
      $this->callback(static fn (array $results): bool => count($results) === 2),
      $this->anything(),
    );
    $client->expects($this->never())->method('abort');

    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('getTempDirectory')->willReturn(sys_get_temp_dir());

    $progress = $this->makeProgress();
    $backupId = 'orc-resume-both';
    $progress->setCurrentBackupId($backupId);

    $uploader = $this->createMock(UploaderInterface::class);
    $uploader->expects($this->exactly(2))->method('uploadArtifact')->willReturnOnConsecutiveCalls(
      ['artifact' => 'code', 'sha256' => str_repeat('a', 64), 'size' => 5, 'parts' => []],
      ['artifact' => 'db', 'sha256' => str_repeat('b', 64), 'size' => 7, 'parts' => []],
    );

    $orchestrator = new BackupOrchestrator(
      $this->makeSupportedPreflight(),
      $this->makeArchiverForManifestBuilding(),
      $this->createMock(DatabaseExporterInterface::class),
      $client,
      $progress,
      $fileSystem,
      new NullLogger(),
      $this->makeConfigFactory(),
      $uploader,
    );

    $codePath = $orchestrator->getArtifactPath($backupId, 'code');
    $dbPath = $orchestrator->getArtifactPath($backupId, 'db');
    file_put_contents($codePath, 'partial-archive-bytes');
    file_put_contents($dbPath, 'partial-dump-bytes');

    try {
      $orchestrator->resumeBackup();
    }
    finally {
      @unlink($codePath);
      @unlink($dbPath);
    }
  }

  /**
   * @covers ::resumeBackup
   *
   * "Double resume": once a resume successfully completes the backup,
   * completeBackup() clears the in-progress backup id - a second
   * resumeBackup() call must cleanly refuse (no in-progress backup) rather
   * than crashing or silently re-uploading/re-completing. The uploader
   * mock's `once()` expectation would itself fail the test if a second
   * resume attempted to upload again.
   */
  public function testResumingAnAlreadyCompletedBackupIsASafeNoOpNotACrash(): void {
    $client = $this->createMock(DrupalForgeClientInterface::class);
    $client->expects($this->once())->method('complete');
    $client->expects($this->never())->method('abort');

    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->method('getTempDirectory')->willReturn(sys_get_temp_dir());

    $progress = $this->makeProgress();
    $backupId = 'orc-double-resume';
    $progress->setCurrentBackupId($backupId);

    $uploader = $this->createMock(UploaderInterface::class);
    $uploader->expects($this->once())->method('uploadArtifact')->willReturn([
      'artifact' => 'code',
      'sha256' => str_repeat('a', 64),
      'size' => 5,
      'parts' => [],
    ]);

    $orchestrator = new BackupOrchestrator(
      $this->makeSupportedPreflight(),
      $this->makeArchiverForManifestBuilding(),
      $this->createMock(DatabaseExporterInterface::class),
      $client,
      $progress,
      $fileSystem,
      new NullLogger(),
      $this->makeConfigFactory(),
      $uploader,
    );

    $codePath = $orchestrator->getArtifactPath($backupId, 'code');
    file_put_contents($codePath, 'partial-archive-bytes');

    try {
      $orchestrator->resumeBackup();

      $this->expectException(UploadFailedException::class);
      $this->expectExceptionMessageMatches('/No in-progress backup/');
      $orchestrator->resumeBackup();
    }
    finally {
      @unlink($codePath);
    }
  }

}
