<?php

declare(strict_types=1);

namespace Drupal\drupalforge_migrate\Service;

use Drupal\drupalforge_migrate\Value\ArtifactDescriptor;

/**
 * Produces the database export artifact.
 *
 * Exists as an interface (mirroring ShellRunnerInterface /
 * DrupalForgeClientInterface / CodeArchiverInterface) so
 * BackupOrchestrator's failure-handling behavior can be tested against a
 * fake that throws, without PHPUnit needing to double the `final`
 * DatabaseExporter class.
 */
interface DatabaseExporterInterface {

  /**
   * @return string[]
   */
  public function listTablesOrdered(): array;

  public function isStructureOnlyTable(string $table): bool;

  public function exportViaCli(string $destinationSqlGz): ArtifactDescriptor;

  /**
   * @return array{done: bool, tables_remaining: int, bytes_written: int}
   */
  public function exportStepWeb(string $backupId, string $destinationSqlGz): array;

}
