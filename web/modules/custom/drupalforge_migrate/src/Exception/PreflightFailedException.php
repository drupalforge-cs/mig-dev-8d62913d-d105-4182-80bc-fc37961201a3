<?php

declare(strict_types=1);

namespace Drupal\drupalforge_migrate\Exception;

/**
 * Thrown when a backup is started on an unsupported site.
 */
final class PreflightFailedException extends \RuntimeException {}
