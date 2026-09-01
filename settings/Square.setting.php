<?php

/**
 * @file
 * Settings metadata for the Square payment processor extension.
 */

return [
  'square_ipn_debug_logging' => [
    'name' => 'square_ipn_debug_logging',
    'type' => 'Boolean',
    'html_type' => 'checkbox',
    'quick_form_type' => 'YesNo',
    'default' => FALSE,
    'add' => '1.0.3',
    'is_domain' => 1,
    'is_contact' => 0,
    'title' => \CRM_Square_ExtensionUtil::ts('Square IPN Debug Logging'),
    'description' => \CRM_Square_ExtensionUtil::ts('When enabled, verbose Square webhook processing details (event dispatch, record lookups, created/updated records) are written to the CiviCRM debug log (ConfigAndLog). Leave disabled in normal operation.'),
    'help_text' => NULL,
    'settings_pages' => ['square' => ['weight' => 10]],
  ],
];
