<?php

/**
 * @file
 * Allow autoloading of extension classes.
 */

$extensionRoot = dirname(__DIR__, 2);

require_once $extensionRoot . '/vendor/autoload.php';

// Focused unit tests load the payment class without a full CiviCRM install.
// The production classes supplied by CiviCRM take precedence when available.
if (!class_exists('CRM_Core_Payment')) {

  /**
   * Minimal stand-in for CiviCRM core's payment-processor base class.
   */
  abstract class CRM_Core_Payment {

    /**
     * Matches the real CRM_Core_Payment base class.
     *
     * See CRM/Core/Payment.php, so tests don't trip a "dynamic property"
     * deprecation that wouldn't occur against the real CiviCRM core class.
     *
     * @var string
     */
    // phpcs:ignore Drupal.NamingConventions.ValidVariableName, PSR2.Classes.PropertyDeclaration.Underscore
    protected $_component;

  }
}
if (!class_exists('CRM_Core_Exception')) {

  /**
   * Minimal stand-in for CiviCRM core's exception class.
   */
  class CRM_Core_Exception extends Exception {
  }
}
if (!class_exists('CRM_Square_ExtensionUtil')) {

  /**
   * Minimal stand-in for civix's generated extension-path helper.
   */
  class CRM_Square_ExtensionUtil {

    /**
     * @return string
     */
    public static function path(): string {
      return dirname(__DIR__, 2);
    }

    /**
     * @param string $path
     *
     * @return string
     */
    public static function url(string $path = ''): string {
      return $path;
    }

  }
}
if (!function_exists('ts')) {

  /**
   * Minimal stand-in for CiviCRM's translation function.
   *
   * @param string $message
   * @param array $params
   *
   * @return string
   */
  function ts($message, array $params = []) {
    foreach ($params as $key => $value) {
      $message = str_replace("%{$key}", $value, $message);
    }
    return $message;
  }

}
if (!class_exists('CRM_Contribute_PseudoConstant')) {

  /**
   * Minimal stand-in for CiviCRM core's contribution-status lookup.
   *
   * Kept even though production code no longer calls this directly
   * (CRM_Core_Payment_Square::contributionStatusId() now goes through
   * CRM_Core_PseudoConstant::getKey(), matching CiviCRM's real, current
   * convention) — some tests still exercise it directly as a regression
   * guard against reintroducing the deprecated call.
   */
  class CRM_Contribute_PseudoConstant {

    /**
     * @return array
     */
    public static function contributionStatus(): array {
      return [
        1 => 'Completed',
        2 => 'Pending',
        3 => 'Cancelled',
        4 => 'Failed',
        5 => 'In Progress',
        6 => 'Overdue',
        7 => 'Refunded',
      ];
    }

  }
}
if (!class_exists('CRM_Core_PseudoConstant')) {

  /**
   * Minimal stand-in for CiviCRM core's pseudoconstant lookup.
   *
   * Used by CRM_Core_Payment_Square::pseudoConstantId() for
   * contribution_status_id, payment_instrument_id, and financial_type_id.
   * Values match CiviCRM's own default option groups, seeded on every
   * fresh install, so tests exercise the same IDs the production code
   * assumes elsewhere.
   */
  class CRM_Core_PseudoConstant {

    /**
     * @param string $baoName
     * @param string $fieldName
     * @param string $value
     *
     * @return int|false
     */
    public static function getKey($baoName, $fieldName, $value) {
      $options = [
        'contribution_status_id' => [
          1 => 'Completed',
          2 => 'Pending',
          3 => 'Cancelled',
          4 => 'Failed',
          5 => 'In Progress',
          6 => 'Overdue',
          7 => 'Refunded',
        ],
        'payment_instrument_id' => [
          1 => 'Credit Card',
          2 => 'Debit Card',
          3 => 'Cash',
          4 => 'Check',
          5 => 'EFT',
          6 => 'Money Order',
        ],
        'financial_type_id' => [
          1 => 'Donation',
          2 => 'Member Dues',
          3 => 'Campaign Contribution',
          4 => 'Event Fee',
          5 => 'Gift',
        ],
      ];
      $id = array_search($value, $options[$fieldName] ?? [], TRUE);
      return $id === FALSE ? FALSE : $id;
    }

  }
}

// CiviCRM test bootstrap (if available). Not required for mock-only tests,
// but harmless if present.
$cmsPath = getenv('CIVICRM_BOOTSTRAP_FILE');
if ($cmsPath && file_exists($cmsPath)) {
  require_once $cmsPath;
}
