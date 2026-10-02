<?php

declare(strict_types=1);

namespace Drupal\drupalforge_migrate\Exception;

/**
 * Thrown when this module's own configuration (not user input from a form -
 * the DrupalForge URL is no longer form-editable - but a settings.php
 * override, or any other config the module trusts implicitly) is invalid.
 *
 * Distinct from DrupalForgeApiException: this fires before any request is
 * sent, for a problem this site's operator/developer can fix locally, not a
 * failure of the remote DrupalForge API itself.
 */
final class InvalidConfigurationException extends \RuntimeException {}
