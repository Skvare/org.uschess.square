<?php

// Allow autoloading of extension classes.
$extensionRoot = dirname(__DIR__, 2);

require_once $extensionRoot . '/vendor/autoload.php';

// CiviCRM test bootstrap (if available). Not required for mock-only tests,
// but harmless if present.
$cmsPath = getenv('CIVICRM_BOOTSTRAP_FILE');
if ($cmsPath && file_exists($cmsPath)) {
  require_once $cmsPath;
}