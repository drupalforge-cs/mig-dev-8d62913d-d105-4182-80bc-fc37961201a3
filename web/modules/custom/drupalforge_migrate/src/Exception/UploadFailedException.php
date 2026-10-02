<?php

declare(strict_types=1);

namespace Drupal\drupalforge_migrate\Exception;

/**
 * Thrown when a backup artifact cannot be uploaded to DrupalForge.
 */
final class UploadFailedException extends \RuntimeException {}
