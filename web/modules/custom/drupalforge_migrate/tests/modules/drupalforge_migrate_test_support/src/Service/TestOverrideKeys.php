<?php

declare(strict_types=1);

namespace Drupal\drupalforge_migrate_test_support\Service;

/**
 * State keys used to drive DrupalForgeClientOverride from a test.
 */
final class TestOverrideKeys {

  public const CONNECT_RESPONSE = 'drupalforge_migrate_test_support.connect_response';
  public const CONNECT_SHOULD_THROW = 'drupalforge_migrate_test_support.connect_should_throw';
  public const BASE_URL = 'drupalforge_migrate_test_support.base_url';

}
