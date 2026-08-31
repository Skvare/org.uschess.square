<?php
declare(strict_types = 1);

// phpcs:disable PSR1.Files.SideEffects
require_once 'square.civix.php';
// phpcs:enable

use CRM_Square_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 */
function square_civicrm_config(\CRM_Core_Config $config): void {
  _square_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 */
function square_civicrm_install(): void {
  _square_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 */
function square_civicrm_enable(): void {
  _square_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_managed().
 * Ensure custom fields (Square Customer ID) and payment processor type are created.
 */
function square_civicrm_managed(&$entities): void {
  _square_civix_civicrm_managed($entities);

  // Placeholder: Additional custom-field declarations if not handled by mgd.
}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * Adds "Square Settings" under Administer > System Settings.
 */
function square_civicrm_navigationMenu(&$menu): void {
  _square_civix_insert_navigation_menu($menu, 'Administer/System Settings', [
    'label' => E::ts('Square Settings'),
    'name' => 'square_settings',
    'url' => 'civicrm/admin/setting/square?reset=1',
    'permission' => 'administer CiviCRM',
    'operator' => 'OR',
    'separator' => 0,
  ]);
  _square_civix_navigationMenu($menu);
}
