<?php

use CRM_Core_Form;
use CRM_Utils_System;
use CRM_Core_Error;
use CRM_UschessSquare_Webhook;
use CRM_Core_Payment_Square;
use Exception;

/**
 * Implementation of hook_civicrm_pageRun().
 *
 * This creates a public-facing URL:
 *   /civicrm/square/webhook
 *
 * Square will POST webhook events to that URL.
 */
function org_uschess_square_civicrm_pageRun(&$page) {
  $path = trim(CRM_Utils_System::currentPath(), '/');

  if ($path === 'civicrm/square/webhook') {

    // Load the Square payment processor instance.
    try {
      $pp = civicrm_api3('PaymentProcessor', 'getsingle', [
        'payment_processor_type_id:name' => 'Square',
      ]);
    }
    catch (Exception $e) {
      CRM_Core_Error::debug_log_message("Square Webhook: cannot load payment processor: " . $e->getMessage());
      CRM_Utils_System::civiExit();
    }

    // Determine mode from processor config
    $mode = !empty($pp['is_test']) ? 'test' : 'live';

    // Instantiate processor class with correct mode
    $processor = new CRM_Core_Payment_Square($mode, $pp);

    // Create webhook handler
    $handler = new CRM_UschessSquare_Webhook($processor);

    // Process webhook
    $handler->handle();

    CRM_Utils_System::civiExit();
  }
}

/**
 * Implementation of hook_civicrm_config().
 * Required to autoload CRM/UschessSquare classes.
 */
function org_uschess_square_civicrm_config(&$config) {
  _org_uschess_square_civix_civicrm_config($config);
}

/**
 * Implementation of hook_civicrm_xmlMenu().
 */
function org_uschess_square_civicrm_xmlMenu(&$files) {
  _org_uschess_square_civix_civicrm_xmlMenu($files);
}

/**
 * Implementation of hook_civicrm_install().
 */
function org_uschess_square_civicrm_install() {
  _org_uschess_square_civix_civicrm_install();
  org_uschess_square_create_custom_fields();
  org_uschess_square_create_webhook_tables();
}

/**
 * Create custom fields for Square integration on install.
 */
function org_uschess_square_create_custom_fields() {
  try {
    // Check if custom group exists
    $group = civicrm_api3('CustomGroup', 'get', [
      'name' => 'square_data',
      'sequential' => 1,
    ]);

    if (!empty($group['count'])) {
      $groupId = $group['values'][0]['id'];
    }
    else {
      // Create custom group
      $result = civicrm_api3('CustomGroup', 'create', [
        'title' => 'Square Data',
        'name' => 'square_data',
        'extends' => 'Contact',
        'style' => 'Inline',
        'is_active' => 1,
      ]);
      $groupId = $result['id'];
    }

    // Create Square Customer ID field
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

    // Create Square Card ID field
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
  catch (Exception $e) {
    CRM_Core_Error::debug_log_message('Square: Error creating custom fields: ' . $e->getMessage());
  }
}

/**
 * Implementation of hook_civicrm_uninstall().
 */
function org_uschess_square_civicrm_uninstall() {
  _org_uschess_square_civix_civicrm_uninstall();
}

/**
 * Implementation of hook_civicrm_enable().
 */
function org_uschess_square_civicrm_enable() {
  _org_uschess_square_civix_civicrm_enable();
  org_uschess_square_ensureOnSiteBillingMode(TRUE);
}

/**
 * Ensure Square payment processors are configured as on-site (billing_mode=1).
 *
 * Webform CiviCRM checks the payment processor instance's billing_mode; if it's
 * off-site it will use an IPN/confirm flow which does not preserve our token
 * field, leading to "Missing Square payment token".
 *
 * @param bool $force
 *   If TRUE, run even if previously marked as fixed.
 */
function org_uschess_square_ensureOnSiteBillingMode($force = FALSE) {
  try {
    if (!$force && class_exists('\\Civi') && \Civi::settings()->get('org_uschess_square_billing_mode_fixed')) {
      return;
    }

    // Prefer API4.
    if (class_exists('\\Civi\\Api4\\PaymentProcessor')) {
      $rows = \Civi\Api4\PaymentProcessor::get(FALSE)
        ->addSelect('id', 'class_name', 'billing_mode', 'payment_processor_type_id:label')
        ->execute();

      $toUpdate = [];
      foreach ($rows as $row) {
        $label = $row['payment_processor_type_id:label'] ?? '';
        $isSquare = (
          (!empty($row['class_name']) && $row['class_name'] === 'Payment_Square') ||
          (!empty($label) && stripos($label, 'square') !== FALSE)
        );
        if ($isSquare && ((int) ($row['billing_mode'] ?? 0) !== 1)) {
          $toUpdate[] = (int) $row['id'];
        }
      }

      if (!empty($toUpdate)) {
        foreach ($toUpdate as $id) {
          \Civi\Api4\PaymentProcessor::update(FALSE)
            ->addWhere('id', '=', $id)
            ->addValue('billing_mode', 1)
            ->execute();
        }
      }
    }
    else {
      // Fallback to API3 if API4 isn't available.
      $result = civicrm_api3('PaymentProcessor', 'get', [
        'options' => ['limit' => 0],
      ]);
      foreach (($result['values'] ?? []) as $pp) {
        $label = $pp['payment_processor_type_id:label'] ?? '';
        $isSquare = (
          (!empty($pp['class_name']) && $pp['class_name'] === 'Payment_Square') ||
          (!empty($label) && stripos($label, 'square') !== FALSE)
        );
        if ($isSquare && ((int) ($pp['billing_mode'] ?? 0) !== 1)) {
          civicrm_api3('PaymentProcessor', 'create', [
            'id' => $pp['id'],
            'billing_mode' => 1,
          ]);
        }
      }
    }

    if (class_exists('\\Civi')) {
      \Civi::settings()->set('org_uschess_square_billing_mode_fixed', 1);
    }
  }
  catch (Exception $e) {
    // Non-fatal: if we can't auto-fix, the admin can update billing_mode manually.
    CRM_Core_Error::debug_log_message('Square: Unable to enforce on-site billing_mode: ' . $e->getMessage());
  }
}

/**
 * Implementation of hook_civicrm_disable().
 */
function org_uschess_square_civicrm_disable() {
  _org_uschess_square_civix_civicrm_disable();
}

/**
 * Implementation of hook_civicrm_upgrade().
 */
function org_uschess_square_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _org_uschess_square_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implementation of hook_civicrm_managed().
 */
function org_uschess_square_civicrm_managed(&$entities) {
  _org_uschess_square_civix_civicrm_managed($entities);
}

/**
 * Inject Square Web Payments SDK + JS + card container into contribution forms.
 *
 * @param string $formName
 * @param CRM_Core_Form $form
 */
function org_uschess_square_civicrm_buildForm($formName, &$form) {
  // Debug: Log all forms to see what's being called
  CRM_Core_Error::debug_log_message('Square buildForm: Form name = ' . $formName);
  
  // Only act on contribution and event registration forms.
  if (!in_array($formName, ['CRM_Contribute_Form_Contribution', 'CRM_Event_Form_Registration'], TRUE)) {
    return;
  }

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
  CRM_Core_Region::instance('billing-block')->add([
    'markup' => $markup,
  ]);

  // Decide sandbox vs live SDK URL.
  $isSandbox = !empty($processor['is_test']);
  $sdkUrl = $isSandbox
    ? 'https://sandbox.web.squarecdn.com/v1/square.js'
    : 'https://web.squarecdn.com/v1/square.js';

  $resources = CRM_Core_Resources::singleton();

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
    // Prefer signature as Location ID (per config labels), then password as fallback.
    'locationId'    => $processor['signature'] ?? ($processor['password'] ?? ''),
    'isSandbox'     => $isSandbox,
    // Generic custom AJAX endpoint in Civi that will call our static handler.
    'ajaxUrl'       => CRM_Utils_System::url(
      'civicrm/ajax/custom',
      NULL,
      TRUE,  // absolute
      NULL,
      FALSE,
      TRUE   // frontend
    ),
  ];

  $resources->addSetting([
    'orgUschessSquare' => $settings,
  ]);
}

/**
 * Implementation of hook_civicrm_post().
 *
 * Handles edits and cancellations to recurring contributions that
 * are processed by the Square payment processor.
 */
function org_uschess_square_civicrm_post($op, $objectName, $objectId, &$objectRef) {
  // We only care about edits and deletes to recurring contribution records.
  if ($objectName !== 'ContributionRecur' || !in_array($op, ['edit', 'delete'], TRUE)) {
    return;
  }

  // Ensure API4 is available.
  if (!class_exists('\\Civi\\Api4\\ContributionRecur')) {
    CRM_Core_Error::debug_log_message('Square: API4 ContributionRecur class not available in civicrm_post.');
    return;
  }

  try {
    // Load the recurring contribution record.
    $recur = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addSelect('id', 'payment_processor_id', 'amount', 'contribution_status_id', 'frequency_interval', 'frequency_unit', 'processor_id')
      ->addWhere('id', '=', (int) $objectId)
      ->execute()
      ->first();

    if (empty($recur) || empty($recur['payment_processor_id'])) {
      return;
    }

    // Load the payment processor to see if it is a Square processor.
    $processor = \Civi\Api4\PaymentProcessor::get(FALSE)
      ->addSelect('id', 'name', 'class_name', 'payment_processor_type_id:label', 'is_test')
      ->addWhere('id', '=', (int) $recur['payment_processor_id'])
      ->execute()
      ->first();

    if (empty($processor)) {
      return;
    }

    // We treat this as a Square-backed recurring contribution either if the
    // class_name is Payment_Square or the type label contains 'Square'.
    $isSquare = (
      (!empty($processor['class_name']) && $processor['class_name'] === 'Payment_Square') ||
      (!empty($processor['payment_processor_type_id:label']) && stripos($processor['payment_processor_type_id:label'], 'square') !== FALSE)
    );

    if (!$isSquare) {
      return;
    }

    // Handle cancellation (status_id = 3 is Cancelled)
    if ($op === 'edit' && !empty($recur['processor_id'])) {
      $oldStatus = $objectRef->contribution_status_id ?? NULL;
      $newStatus = $recur['contribution_status_id'] ?? NULL;

      // Check if status changed to Cancelled (3)
      if ($newStatus == 3 && $oldStatus != 3) {
        // Cancel the Square subscription
        try {
          $mode = !empty($processor['is_test']) ? 'test' : 'live';
          $squareProcessor = new CRM_Core_Payment_Square($mode, $processor);
          $squareProcessor->cancelSubscription($recur['processor_id']);
          CRM_Core_Error::debug_log_message(sprintf(
            'Square: Cancelled subscription %s for recurring contribution #%d',
            $recur['processor_id'],
            $recur['id']
          ));
        }
        catch (Exception $e) {
          CRM_Core_Error::debug_log_message('Square: Error cancelling subscription: ' . $e->getMessage());
        }
      }
      // Handle amount changes
      elseif (!empty($recur['processor_id']) && !empty($objectRef->amount)) {
        $oldAmount = $objectRef->amount ?? NULL;
        $newAmount = $recur['amount'] ?? NULL;

        if ($oldAmount != $newAmount && $newAmount > 0) {
          try {
            $mode = !empty($processor['is_test']) ? 'test' : 'live';
            $squareProcessor = new CRM_Core_Payment_Square($mode, $processor);
            $currency = $recur['currency'] ?? 'USD';
            $squareProcessor->updateSubscriptionAmount($recur['processor_id'], $newAmount, $currency);
            CRM_Core_Error::debug_log_message(sprintf(
              'Square: Updated subscription %s amount from %s to %s for recurring contribution #%d',
              $recur['processor_id'],
              $oldAmount,
              $newAmount,
              $recur['id']
            ));
          }
          catch (Exception $e) {
            CRM_Core_Error::debug_log_message('Square: Error updating subscription amount: ' . $e->getMessage());
          }
        }
      }
    }

    // Log all edits for debugging
    CRM_Core_Error::debug_log_message(sprintf(
      'Square: ContributionRecur #%d %s (amount=%s, status_id=%s, freq=%s %s, processor_id=%s).',
      $recur['id'],
      $op,
      $recur['amount'] ?? 'n/a',
      $recur['contribution_status_id'] ?? 'n/a',
      $recur['frequency_interval'] ?? 'n/a',
      $recur['frequency_unit'] ?? 'n/a',
      $recur['processor_id'] ?? 'n/a'
    ));
  }
  catch (Exception $e) {
    CRM_Core_Error::debug_log_message('Square: Error in civicrm_post ContributionRecur handler: ' . $e->getMessage());
  }
}

/**
 * Create webhook tracking tables on install.
 */
function org_uschess_square_create_webhook_tables() {
  try {
    $db = CRM_Core_DAO::getDatabaseConnection();

    // Create webhook event tracking table
    $sql = "
      CREATE TABLE IF NOT EXISTS civicrm_square_webhook_event (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        event_id VARCHAR(255) NOT NULL UNIQUE,
        processed_at DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_event_id (event_id),
        INDEX idx_processed_at (processed_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    $db->query($sql);

    // Create webhook delivery log table
    $sql = "
      CREATE TABLE IF NOT EXISTS civicrm_square_webhook_delivery (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        event_id VARCHAR(255),
        event_type VARCHAR(100) NOT NULL,
        message TEXT,
        http_status INT,
        delivered_at DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_event_id (event_id),
        INDEX idx_event_type (event_type),
        INDEX idx_delivered_at (delivered_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ";
    $db->query($sql);

    CRM_Core_Error::debug_log_message('Square: Webhook tables created successfully');
  }
  catch (Exception $e) {
    CRM_Core_Error::debug_log_message('Square: Error creating webhook tables: ' . $e->getMessage());
  }
}

