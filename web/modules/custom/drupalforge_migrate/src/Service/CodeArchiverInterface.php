<?php

declare(strict_types=1);

namespace Drupal\drupalforge_migrate\Service;

use Drupal\drupalforge_migrate\Value\ArtifactDescriptor;

/**
 * Produces the code archive artifact.
 *
 * Exists as an interface (mirroring ShellRunnerInterface /
 * DrupalForgeClientInterface) so BackupOrchestrator's failure-handling
 * behavior can be tested against a fake that throws, without PHPUnit needing
 * to double the `final` CodeArchiver class.
 */
interface CodeArchiverInterface {

  /**
   * @param string[] $siteDirs
   *
   * @return array{tar_excludes: string[], settings_files: string[]}
   */
  public function buildExclusionList(string $projectRoot, string $docroot, array $siteDirs, ?string $privateFilesPath): array;

  public function hasComposerLock(string $projectRoot): bool;

  /**
   * @param string[] $siteDirs
   */
  public function archive(string $projectRoot, string $docroot, string $destinationTarGz, array $siteDirs, ?string $privateFilesPath): ArtifactDescriptor;

}
