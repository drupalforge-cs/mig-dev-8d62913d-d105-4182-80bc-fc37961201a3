<?php

/**
 * @file
 * PHPUnit bootstrap for drupalforge_migrate, run against the host site's
 * installed Drupal core (this module is developed in-place, not via its own
 * standalone composer install — see TODO.md).
 */

declare(strict_types=1);

$moduleRoot = dirname(__DIR__);
$siteVendorAutoload = $moduleRoot . '/../../../../vendor/autoload.php';

if (!is_file($siteVendorAutoload)) {
  fwrite(STDERR, "Host site vendor/autoload.php not found at {$siteVendorAutoload}.\n");
  exit(1);
}

/** @var \Composer\Autoload\ClassLoader $loader */
$loader = require $siteVendorAutoload;
$loader->addPsr4('Drupal\\drupalforge_migrate\\', $moduleRoot . '/src');
$loader->addPsr4('Drupal\\Tests\\drupalforge_migrate\\', $moduleRoot . '/tests/src');
$loader->addPsr4('Drupal\\drupalforge_migrate_test_support\\', $moduleRoot . '/tests/modules/drupalforge_migrate_test_support/src');

$webCore = $moduleRoot . '/../../../../web/core';
if (is_dir($webCore)) {
  $loader->addPsr4('Drupal\\Tests\\', $webCore . '/tests/Drupal/Tests');
  $loader->addPsr4('Drupal\\TestTools\\', $webCore . '/tests/Drupal/TestTools');
  $loader->addPsr4('Drupal\\BuildTests\\', $webCore . '/tests/Drupal/BuildTests');
  $loader->addPsr4('Drupal\\KernelTests\\', $webCore . '/tests/Drupal/KernelTests');
  $loader->addPsr4('Drupal\\FunctionalTests\\', $webCore . '/tests/Drupal/FunctionalTests');
  $loader->addPsr4('Drupal\\FunctionalJavascriptTests\\', $webCore . '/tests/Drupal/FunctionalJavascriptTests');

  $bootstrapFile = $webCore . '/tests/bootstrap.php';
  if (is_file($bootstrapFile)) {
    require_once $bootstrapFile;
  }
}
