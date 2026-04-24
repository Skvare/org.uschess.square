<?php

use Civi\Api4\Contact;
use Civi\Api4\ContributionRecur;

class CRM_UschessSquare_Ajax_SquareToken {

  /**
   * Exchange a Web Payments SDK nonce for a CiviCRM-safe token.
   *
   * Request POST parameters:
   *  - token (string) Square nonce
   *  - processor_id (int)
   *  - contact_id (int)
   *
   * Response JSON:
   *  - {success: true, civi_token: "..."}  OR
   *  - {error: "message"}
   */
  public static function run() {
    try {
      $nonce = CRM_Utils_Type::validate($_POST['token'] ?? NULL, 'String');
      $processorId = CRM_Utils_Type::validate($_POST['processor_id'] ?? NULL, 'Integer');
      $contactId = CRM_Utils_Type::validate($_POST['contact_id'] ?? NULL, 'Integer');
      if (!$nonce || !$processorId || !$contactId) {
        return self::error("Missing required parameters.");
      }

      try {
        $processor = civicrm_api3('PaymentProcessor', 'getsingle', [
          'id' => $processorId,
        ]);
      } catch (Exception $e) {
        return self::error("Could not retrieve payment processor: " . $e->getMessage());
      }

      $processorClass = new CRM_Core_Payment_Square($processor['is_test'] ? 'test' : 'live', $processor);

      // Prepare billing details
      $billingDetails = [
        /*
        'billing_address' => [
          'street_address' => $_POST['street_address'] ?? '1600 Amphitheatre Parkway',
          'street_address_2' => $_POST['street_address_2'] ?? NULL,
          'city' => $_POST['city'] ?? 'Mountain View',
          'state' => $_POST['state'] ?? 'CA',
          'postal_code' => $_POST['postal_code'] ?? '94043',
          'country' => $_POST['country'] ?? 'US',
        ],
        */
        /*
        'exp_year' => $_POST['exp_year'] ?? 2026,
        'exp_month' => $_POST['exp_month'] ?? 12,
        'last_4' => $_POST['last_4'] ?? '1111',
        'card_brand' => $_POST['card_brand'] ?? 'VISA',
        'card_type' => $_POST['card_type'] ?? 'CREDIT',
        */
      ];

      // Convert nonce → Square “card on file” or payment token.
      $customerId = $processorClass->ensureSquareCustomer(['contactID' => $contactId]);
      $cardId = $processorClass->createCardOnFile($customerId, $nonce, array_filter($billingDetails), $contactId);
      if (!$cardId) {
        return self::error("Unable to convert token.");
      }

      CRM_Utils_JSON::output([
        'success' => TRUE,
        'civi_token' => $cardId,
      ]);
    }
    catch (\Exception $e) {
      return self::error($e->getMessage());
    }
  }

  protected static function error($msg) {
    CRM_Utils_JSON::output([
      'success' => FALSE,
      'error' => $msg,
    ]);
  }
}