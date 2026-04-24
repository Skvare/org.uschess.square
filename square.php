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
  // Create custom group and fields for Square integration.
  square_create_custom_group_and_fields();
}

/**
 * Create custom group and fields for Square integration.
 */
function square_create_custom_group_and_fields() {
  // Check if the custom group already exists.
  $group = civicrm_api3('CustomGroup', 'get', [
    'name' => 'square_data',
    'sequential' => 1,
  ]);
  if (!empty($group['count'])) {
    $groupId = $group['values'][0]['id'];
  } else {
    // Create the custom group for contacts.
    $result = civicrm_api3('CustomGroup', 'create', [
      'title' => 'Square Data',
      'name' => 'square_data',
      'extends' => 'Contact',
      'style' => 'Inline',
      'is_active' => 1,
    ]);
    $groupId = $result['id'];
  }

  // Create Square Customer ID field.
  $field = civicrm_api3('CustomField', 'get', [
    'custom_group_id' => $groupId,
    'name' => 'square_customer_id',
    'sequential' => 1,
  ]);
  if (empty($field['count'])) {
    civicrm_api3('CustomField', 'create', [
      'custom_group_id' => $groupId,
      'label' => 'Square Customer ID',
      'name' => 'square_customer_id',
      'data_type' => 'String',
      'html_type' => 'Text',
      'is_active' => 1,
      'is_view' => 1,
      'is_searchable' => 0,
    ]);
  }

  // Create Square Card ID field.
  $field = civicrm_api3('CustomField', 'get', [
    'custom_group_id' => $groupId,
    'name' => 'square_card_id',
    'sequential' => 1,
  ]);
  if (empty($field['count'])) {
    civicrm_api3('CustomField', 'create', [
      'custom_group_id' => $groupId,
      'label' => 'Square Card ID',
      'name' => 'square_card_id',
      'data_type' => 'String',
      'html_type' => 'Text',
      'is_active' => 1,
      'is_view' => 1,
      'is_searchable' => 0,
    ]);
  }
}

/**
 * Save a value to a Square custom field for a contact.
 *
 * @param int $contactId
 * @param string $fieldName
 * @param string $value
 * @return void
 */
function square_save_custom_field($contactId, $fieldName, $value) {
  // Get the custom group ID.
  $group = civicrm_api3('CustomGroup', 'get', [
    'name' => 'square_data',
    'sequential' => 1,
  ]);
  if (empty($group['count'])) {
    return;
  }
  $groupId = $group['values'][0]['id'];
  // Get the custom field ID.
  $field = civicrm_api3('CustomField', 'get', [
    'custom_group_id' => $groupId,
    'name' => $fieldName,
    'sequential' => 1,
  ]);
  if (empty($field['count'])) {
    return;
  }
  $fieldId = $field['values'][0]['id'];
  $fieldCol = 'custom_' . $fieldId;
  // Save the value.
  civicrm_api3('Contact', 'create', [
    'id' => $contactId,
    $fieldCol => $value,
  ]);
}

/**
 * Retrieve a value from a Square custom field for a contact.
 *
 * @param int $contactId
 * @param string $fieldName
 * @return string|null
 */
function square_get_custom_field($contactId, $fieldName) {
  // Get the custom group ID.
  $group = civicrm_api3('CustomGroup', 'get', [
    'name' => 'square_data',
    'sequential' => 1,
  ]);
  if (empty($group['count'])) {
    return null;
  }
  $groupId = $group['values'][0]['id'];
  // Get the custom field ID.
  $field = civicrm_api3('CustomField', 'get', [
    'custom_group_id' => $groupId,
    'name' => $fieldName,
    'sequential' => 1,
  ]);
  if (empty($field['count'])) {
    return null;
  }
  $fieldId = $field['values'][0]['id'];
  $fieldCol = 'custom_' . $fieldId;
  // Retrieve the value.
  $contact = civicrm_api3('Contact', 'getsingle', [
    'id' => $contactId,
    'return' => [$fieldCol],
  ]);
  return $contact[$fieldCol] ?? null;
}

/**
 * Implements hook_civicrm_enable().
 */
function square_civicrm_enable(): void {
  _square_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_post().
 * Used for storing Square tokens, subscription mapping, etc.
 */
function square_civicrm_post($op, $objectName, $objectId, &$objectRef): void {
  // Placeholder: Token storage, card mapping, recur mapping.
}

/**
 * Implements hook_civicrm_pageRun().
 * Used for injecting JS into contribution pages (if needed).
 */
function square_civicrm_pageRun(&$page): void {
  // Placeholder: Optional page-level JS injection.
}

/**
 * Implements hook_civicrm_buildForm().
 * Inject Square Web Payments SDK + JS + card container into contribution forms.
 */
function square_civicrm_buildForm($formName, &$form): void {
  // Only act on contribution and event registration forms.
  if (!in_array($formName, ['CRM_Contribute_Form_Contribution', 'CRM_Event_Form_Registration'], TRUE)) {
    return;
  }
  return;
  // Get payment processor currently in use.
  $processor = $form->getVar('_paymentProcessor');
  if (empty($processor)) {
    return;
  }

  // Only if this is the Square processor.
  $className = $processor['class_name'] ?? '';
  if ($className !== 'Payment_Square' && $className !== 'CRM_Core_Payment_Square') {
    return;
  }

  // Hidden field where the JS will store the card token.
  if (!$form->elementExists('square_payment_token')) {
    $form->add('hidden', 'square_payment_token', '', ['id' => 'square_payment_token']);
  }

  // Inject the container where Square will mount the card fields + error box.
  $markup = '
    <div id="square-card-container"></div>
    <div id="square-card-errors" class="messages error" style="display:none"></div>
  ';

  // Attach this to the billing block region so it appears in the right place.
  \CRM_Core_Region::instance('billing-block')->add([
    'markup' => $markup,
  ]);

  // Decide sandbox vs live SDK URL.
  $isSandbox = !empty($processor['is_test']);
  $sdkUrl = $isSandbox
    ? 'https://sandbox.web.squarecdn.com/v1/square.js'
    : 'https://web.squarecdn.com/v1/square.js';

  $resources = \CRM_Core_Resources::singleton();

  // Load Square's JS SDK.
  $resources->addScriptUrl($sdkUrl, 0, 'html-header');

  // Load our own integration JS from the extension.
  $resources->addScriptFile('org.uschess.square', 'js/square.js', 10, 'html-header');

  // Pass settings to JS via window variables (more reliable than CRM.vars)
  $inlineScript = "
    window.squareApplicationId = '" . addslashes($processor['user_name'] ?? '') . "';
    window.squareLocationId = '" . addslashes($processor['signature'] ?? ($processor['password'] ?? '')) . "';
    window.squareIsSandbox = " . ($isSandbox ? 'true' : 'false') . ";
  ";
  $resources->addScript($inlineScript, 'html-header');

  // Also pass settings to JS via CRM.vars for compatibility.
  $settings = [
    'applicationId' => $processor['user_name'] ?? '',
    'locationId'    => $processor['signature'] ?? ($processor['password'] ?? ''),
    'isSandbox'     => $isSandbox,
  ];

  $resources->addSetting([
    'orgUschessSquare' => $settings,
  ]);
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
 * Implements hook_civicrm_tabs().
 *
 * Adds the "Square Tokens" tab to the Contact Summary.
 */
function square_civicrm_tabs(&$tabs, $contactID) {

  // URL of our page controller (which we will create next).
  $url = CRM_Utils_System::url(
    'civicrm/square/tokens',
    "reset=1&cid={$contactID}"
  );

  // Add the tab.
  $tabs[] = [
    'id' => 'square_tokens',
    'url' => $url,
    'title' => ts('Square Tokens', ['domain' => 'org.uschess.square']),
    'weight' => 55,        // After Contributions / before Relationships
    'count' => NULL,       // Can update later if we want
    'class' => 'square-tokens-tab',
  ];
}
