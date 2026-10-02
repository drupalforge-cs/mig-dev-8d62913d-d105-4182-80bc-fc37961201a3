<?php

declare(strict_types=1);

namespace Drupal\drupalforge_migrate\Exception;

/**
 * Thrown when DrupalForge rejects a backup reservation (HTTP 409).
 */
final class QuotaExceededException extends DrupalForgeApiException {}
