<?php

// Allow autoloading of extension classes.
$extensionRoot = dirname(__DIR__, 2);

require_once $extensionRoot . '/vendor/autoload.php';

// Focused unit tests load the payment class without a full CiviCRM install.
// The production classes supplied by CiviCRM take precedence when available.
if (!class_exists('CRM_Core_Payment')) {
  abstract class CRM_Core_Payment {
  }
}
if (!class_exists('CRM_Core_Exception')) {
  class CRM_Core_Exception extends Exception {
  }
}
if (!class_exists('CRM_Square_ExtensionUtil')) {
  class CRM_Square_ExtensionUtil {
    public static function path(): string {
      return dirname(__DIR__, 2);
    }

    public static function url(string $path = ''): string {
      return $path;
    }
  }
}
if (!function_exists('ts')) {
  function ts($message, array $params = []) {
    foreach ($params as $key => $value) {
      $message = str_replace("%{$key}", $value, $message);
    }
    return $message;
  }
}

// CiviCRM test bootstrap (if available). Not required for mock-only tests,
// but harmless if present.
$cmsPath = getenv('CIVICRM_BOOTSTRAP_FILE');
if ($cmsPath && file_exists($cmsPath)) {
  require_once $cmsPath;
}
