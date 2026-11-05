<?php

/**
 * @file
 * Drupal site-specific configuration file.
 *
 * Example:
 * @code
 * $databases['default']['default'] = array (
 *   'database' => 'databasename',
 *   'username' => 'sqlusername',
 *   'password' => 'sqlpassword',
 *   'host' => 'localhost',
 *   'port' => '3306',
 *   'driver' => 'mysql',
 *   'prefix' => '',
 * );
 * @endcode
 */

$databases = [];

// $databases['default']['default'] = array('database' => 'commented-out', 'host' => 'nope');

$settings['hash_salt'] = 'abc';
$settings['trusted_host_patterns'] = ['^drupal\.pl$'];

$databases['default']['default'] = array (
  'database' => 'acc_app',
  'username' => 'acc_app',
  'password' => 'appPass',
  'prefix' => '',
  'host' => 'mysql57',
  'namespace' => 'Drupal\\Core\\Database\\Driver\\mysql',
  'driver' => 'mysql',
);
$settings['config_sync_directory'] = '../config/sync';
