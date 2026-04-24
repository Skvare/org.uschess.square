<?php
use CRM_Square_ExtensionUtil as E;
use Civi\Api4\Contact;
use Civi\Api4\ContributionRecur;
use Square\SquareClient;
use Square\Customers\Requests\ListCustomersRequest;
use Square\Environments;

require_once E::path() . '/vendor/autoload.php';
/**
 * Square Payment Processor for CiviCRM.
 *
 * This processor supports:
 *  - One-off (non-recurring) card payments via Square Payments API
 *  - Recurring contributions via Square Subscriptions API
 *
 * Card details are never handled by CiviCRM directly. Instead, the
 * Square Web Payments SDK is used in the browser to tokenize the card
 * and pass a token/nonce back to this class via $params.
 */
class CRM_Core_Payment_Square extends CRM_Core_Payment {

  /**
   * Singleton instances keyed by processor name + mode.
   *
   * @var array
   */
  protected static $_singleton = [];

  /**
   * @var string
   */
  protected string $_mode;

  /**
   * @var bool
   */
  protected bool $_isTest;

  /**
   * Square-supported cadence definitions.
   */
  protected const SQUARE_CADENCES = [
    'DAILY' => [
      'label' => 'Daily',
      'unit'  => 'day',
      'step'  => 1,
    ],
    'WEEKLY' => [
      'label' => 'Weekly',
      'unit'  => 'week',
      'step'  => 1,
    ],
    'EVERY_TWO_WEEKS' => [
      'label' => 'Every 2 Weeks',
      'unit'  => 'week',
      'step'  => 2,
    ],
    'MONTHLY' => [
      'label' => 'Monthly',
      'unit'  => 'month',
      'step'  => 1,
    ],
    'EVERY_TWO_MONTHS' => [
      'label' => 'Every 2 Months',
      'unit'  => 'month',
      'step'  => 2,
    ],
    'QUARTERLY' => [
      'label' => 'Quarterly',
      'unit'  => 'month',
      'step'  => 3,
    ],
    'EVERY_SIX_MONTHS' => [
      'label' => 'Every 6 Months',
      'unit'  => 'month',
      'step'  => 6,
    ],
    'ANNUAL' => [
      'label' => 'Annual',
      'unit'  => 'year',
      'step'  => 1,
    ],
  ];



  /**
   * Constructor.
   *
   * @param string $mode
   *   'test' or 'live'.
   * @param array $paymentProcessor
   *   Row from civicrm_payment_processor.
   */
  public function __construct($mode, &$paymentProcessor) {
    // Store processor config
    $this->_paymentProcessor = $paymentProcessor;

    // CiviCRM typically passes 'live' or 'test' here, but we also honour the DB flag.
    $this->_mode = $mode ?: 'live';

    // Test mode if either the mode is explicitly 'test' or the processor is flagged is_test.
    $this->_isTest = !empty($paymentProcessor['is_test']) ||
      strtolower((string) $this->_mode) === 'test';
  }

  /**
   * Return a singleton instance of this payment processor.
   *
   * @param string $mode
   * @param array $paymentProcessor
   *
   * @return self
   */
  public static function &singleton($mode, &$paymentProcessor) {
    $processorName = $paymentProcessor['name'] ?? 'Square';
    $cacheKey = $processorName . '_' . $mode;
    if (!isset(self::$_singleton[$cacheKey])) {
      self::$_singleton[$cacheKey] = new self($mode, $paymentProcessor);
    }
    return self::$_singleton[$cacheKey];
  }

  /**
   * Whether this processor is in test/sandbox mode.
   *
   * @return bool
   */
  protected function isTestMode() {
    return !empty($this->_isTest);
  }

  /**
   * Build a SquareClient configured for this processor (2025 SDK style).
   *
   * Uses the password field as access token and honours is_test.
   *
   * @return \Square\SquareClient
   * @throws \CRM_Core_Exception
   */
  protected function buildSquareClient(): SquareClient {
    $token = $this->getAccessToken();

    $baseUrl = $this->_isTest
      ? 'https://connect.squareupsandbox.com'
      : 'https://connect.squareup.com';

    return new SquareClient(
      token: $token,
      options: ['baseUrl' => $baseUrl],
    );
  }

  /**
   * Get the Square access token from processor config.
   *
   * @return string
   *
   * @throws \CRM_Core_Exception
   */
  protected function getAccessToken() {
    if ($this->isTestMode()) {
      $token = $this->_paymentProcessor['password'] ?? '';
      if (empty($token)) {
        throw new CRM_Core_Exception('Square sandbox access token (test_password) is not configured.');
      }
    }
    else {
      $token = $this->_paymentProcessor['password'] ?? '';
      if (empty($token)) {
        throw new CRM_Core_Exception('Square live access token (password) is not configured.');
      }
    }
    return $token;
  }

  /**
   * Get the Square Location ID from processor config.
   *
   * @return string
   *
   * @throws \CRM_Core_Exception
   */
  protected function getLocationId() {
    if ($this->isTestMode()) {
      $loc = $this->_paymentProcessor['test_signature'] ?? '';
      if (empty($loc)) {
        $loc = $this->_paymentProcessor['signature'] ?? '';
      }
    }
    else {
      $loc = $this->_paymentProcessor['signature'] ?? '';
    }

    if (empty($loc)) {
      throw new CRM_Core_Exception('Square location ID is not configured on this payment processor.');
    }
    return $loc;
  }

  /**
   * Base URL for Square API, depending on mode and config.
   *
   * @return string
   */
  protected function getApiBaseUrl() {
    // Allow overriding via processor config if provided.
    if (!empty($this->_paymentProcessor['url_api'])) {
      return rtrim($this->_paymentProcessor['url_api'], '/');
    }

    // Fallback: use sensible defaults based on test/live.
    if ($this->isTestMode()) {
      return 'https://connect.squareupsandbox.com';
    }

    return 'https://connect.squareup.com';
  }

  /**
   * Validate Square webhook signatures (shared logic).
   *
   * @param string $raw
   *   Raw request body
   * @param array $headers
   *   HTTP headers
   * @param string $url
   *   Full callback URL used by Square
   *
   * @return bool
   */
  protected function validateSquareWebhookSignature($raw, $headers, $url) {
    $key = $this->_paymentProcessor['subject'] ?? NULL;
    if (!$key) {
     // Civi::log()->debug("Square Webhook: Missing signature key");
      return FALSE;
    }

    $provided = $headers['X-Square-Signature']
      ?? $headers['x-square-signature']
      ?? NULL;

    if (!$provided) {
     // Civi::log()->debug("Square Webhook: Missing X-Square-Signature header");
      return FALSE;
    }

    $message = $url . $raw;
    $expected = base64_encode(hash_hmac('sha256', $message, $key, TRUE));

    return hash_equals($expected, $provided);
  }

  /**
   * Validate Square configuration by making a real SDK call.
   *
   * @return string|null
   */
  public function checkConfig() {
    try {
      $client = $this->buildSquareClient();
      $resp = $client->customers->list(new ListCustomersRequest([]));
      return NULL;
    }
    catch (\Exception $e) {
      $msg = "Square checkConfig failure (" . ($this->_isTest ? 'SANDBOX' : 'PRODUCTION') . "): " . $e->getMessage();
     // Civi::log()->debug($msg);
      return $msg;
    }
  }

  /**
   * Sync a Square payment (from webhook payload) into CiviCRM.
   *
   * @param array $payment
   *   Payment object from Square webhooks.
   */
  public function syncPaymentFromSquare(array $payment) {
    $paymentId = $payment['id'] ?? NULL;
    if (!$paymentId) {
     // Civi::log()->debug('Square syncPaymentFromSquare(): missing payment ID.');
      return;
    }

    $status = $payment['status'] ?? 'UNKNOWN';

    // Determine amount/currency.
    $money = $payment['amount_money'] ?? NULL;
    $amount = $money && isset($money['amount']) ? ($money['amount'] / 100) : NULL;
    $currency = $money['currency'] ?? 'USD';

    // 1. Try to find existing contribution.
    $existing = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('id')
      ->addWhere('trxn_id', '=', $paymentId)
      ->execute()
      ->first();

    if ($existing) {
      \Civi\Api4\Contribution::update(FALSE)
        ->addWhere('id', '=', $existing['id'])
        ->addValue('total_amount', $amount)
        ->addValue('currency', $currency)
        ->addValue('contribution_status_id', $this->mapPaymentStatus($status))
        ->execute();
      return;
    }

    // If no contribution exists, try mapping by reference_id → contact or contribution.
    $referenceId = $payment['reference_id'] ?? NULL;
    $contactId = NULL;

    if ($referenceId && ctype_digit((string) $referenceId)) {
      $refContribution = \Civi\Api4\Contribution::get(FALSE)
        ->addSelect('id', 'contact_id')
        ->addWhere('id', '=', (int) $referenceId)
        ->execute()
        ->first();

      if ($refContribution) {
        $contactId = (int) $refContribution['contact_id'];
      }
    }

    if (!$contactId) {
      $contactId = $this->findContactIdForPayment($payment);
    }

    if (!$contactId) {
     // Civi::log()->debug("Square syncPaymentFromSquare(): cannot resolve contact for payment {$paymentId}");
      return;
    }

    // Default financial type is Donation (ID=1) unless better mapping is added later.
    $financialTypeId = 1;

    \Civi\Api4\Contribution::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('financial_type_id', $financialTypeId)
      ->addValue('total_amount', $amount)
      ->addValue('currency', $currency)
      ->addValue('contribution_status_id', $this->mapPaymentStatus($status))
      ->addValue('trxn_id', $paymentId)
      ->addValue('source', 'Square Payment (Webhook)')
      ->execute();
  }

  /**
   * Sync a Square refund into CiviCRM.
   *
   * @param array $refund
   */
  public function syncRefundFromSquare(array $refund) {
    $paymentId = $refund['payment_id'] ?? NULL;
    $refundId = $refund['id'] ?? NULL;
    if (!$paymentId || !$refundId) {
     // Civi::log()->debug('Square syncRefundFromSquare(): missing data.');
      return;
    }

    // Find contribution by trxn_id.
    $contribution = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('id')
      ->addWhere('trxn_id', '=', $paymentId)
      ->execute()
      ->first();

    if (!$contribution) {
     // Civi::log()->debug("Square syncRefundFromSquare(): no contribution for payment {$paymentId}");
      return;
    }

    \Civi\Api4\Contribution::update(FALSE)
      ->addWhere('id', '=', $contribution['id'])
      ->addValue('contribution_status_id', 'Refunded')
      ->addValue('refund_trxn_id', $refundId)
      ->execute();
  }

  /**
   * Sync a Square subscription update into CiviCRM.
   *
   * @param array $subscription
   */
  public function syncSubscriptionFromWebhook(array $subscription) {
    $id = $subscription['id'] ?? NULL;
    if (!$id) {
     // Civi::log()->debug('Square syncSubscriptionFromWebhook(): missing subscription ID.');
      return;
    }

    $status = $subscription['status'] ?? 'UNKNOWN';
    $amount = $this->extractSubscriptionAmount($subscription);

    $recur = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addSelect('id', 'amount', 'contribution_status_id')
      ->addWhere('processor_id', '=', $id)
      ->execute()
      ->first();

    if (!$recur) {
     // Civi::log()->debug("Square syncSubscriptionFromWebhook(): no matching recur for {$id}");
      return;
    }

    $updates = [];
    $mappedStatus = $this->mapSquareSubscriptionStatusToCivi($status);

    if ($mappedStatus !== NULL) {
      $updates['contribution_status_id'] = $mappedStatus;
    }
    if ($amount !== NULL && (float) $amount !== (float) $recur['amount']) {
      $updates['amount'] = (float) $amount;
    }

    if ($updates) {
      \Civi\Api4\ContributionRecur::update(FALSE)
        ->addWhere('id', '=', $recur['id'])
        ->addValues($updates)
        ->execute();
    }
  }

  /**
   * Sync a Square invoice (recurring payment) into CiviCRM.
   *
   * @param array $invoice
   */
  public function syncInvoiceFromSquare(array $invoice) {
    $invoiceId = $invoice['id'] ?? NULL;
    if (!$invoiceId) {
     // Civi::log()->debug('Square syncInvoiceFromSquare(): missing invoice ID.');
      return;
    }

    $subscriptionId = $invoice['subscription_id'] ?? NULL;
    if (!$subscriptionId) {
     // Civi::log()->debug("Square syncInvoiceFromSquare(): invoice {$invoiceId} has no subscription_id.");
      return;
    }

    // Find recur.
    $recur = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addSelect('id', 'contact_id', 'financial_type_id', 'currency')
      ->addWhere('processor_id', '=', $subscriptionId)
      ->execute()
      ->first();

    if (!$recur) {
     // Civi::log()->debug("Square syncInvoiceFromSquare(): no recur for subscription {$subscriptionId}");
      return;
    }

    // Prevent duplicates.
    $existing = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('id')
      ->addWhere('invoice_id', '=', $invoiceId)
      ->execute()
      ->first();

    if ($existing) {
      return;
    }

    $money = $invoice['payment_requests'][0]['computed_amount_money'] ?? NULL;
    $amount = $money ? ($money['amount'] / 100) : NULL;
    $currency = $money['currency'] ?? $recur['currency'] ?? 'USD';

    \Civi\Api4\Contribution::create(FALSE)
      ->addValue('contact_id', $recur['contact_id'])
      ->addValue('contribution_recur_id', $recur['id'])
      ->addValue('financial_type_id', $recur['financial_type_id'])
      ->addValue('total_amount', $amount)
      ->addValue('currency', $currency)
      ->addValue('contribution_status_id', 1)
      ->addValue('invoice_id', $invoiceId)
      ->addValue('source', 'Square Invoice (Webhook)')
      ->execute();
  }

  /**
   * Sync a Square subscription with the corresponding CiviCRM recurring contribution.
   *
   * This is used when Square sends a webhook (subscription.updated or subscription.canceled)
   * AND also may be triggered manually by scheduled jobs.
   *
   * @param string $squareSubscriptionId
   *   The subscription ID from Square.
   *
   * @throws \CRM_Core_Exception
   */
  public function syncSubscriptionFromSquare(string $squareSubscriptionId) {
    if (empty($squareSubscriptionId)) {
      throw new CRM_Core_Exception('Missing Square subscription ID for sync.');
    }

    // 1. Look up the subscription in Square
    $resp = $this->squareRequest('GET', "/v2/subscriptions/{$squareSubscriptionId}");

    if (empty($resp['subscription'])) {
      throw new CRM_Core_Exception("Square subscription {$squareSubscriptionId} not found.");
    }

    $sub = $resp['subscription'];
    $status = $sub['status'] ?? 'UNKNOWN';
    $amount = $this->extractSubscriptionAmount($sub);

    // 2. Find local CiviCRM recurring contribution
    $recur = ContributionRecur::get(FALSE)
      ->addWhere('processor_id', '=', $squareSubscriptionId)
      ->addSelect('id', 'amount', 'currency', 'contribution_status_id')
      ->execute()
      ->first();

    if (empty($recur)) {
      // No such recurring record exists — log and stop
     // Civi::log()->debug("Square sync: No local contribution_recur record found for subscription {$squareSubscriptionId}");
      return;
    }

    $recurId = (int) $recur['id'];

    // 3. Map Square → CiviCRM status
    $mappedStatus = $this->mapSquareSubscriptionStatusToCivi($status);

    // 4. Update recurring amount if changed
    $updates = [];
    if (!empty($amount) && (float) $amount !== (float) $recur['amount']) {
      $updates['amount'] = (float) $amount;
    }

    // 5. Update contribution_status_id if needed
    if ($mappedStatus !== NULL && $mappedStatus !== (int) $recur['contribution_status_id']) {
      $updates['contribution_status_id'] = $mappedStatus;
    }

    // 6. Apply updates
    if (!empty($updates)) {
      ContributionRecur::update(FALSE)
        ->addWhere('id', '=', $recurId)
        ->addValues($updates)
        ->execute();

     // Civi::log()->debug("Square sync: Updated recurring contribution {$recurId} from subscription {$squareSubscriptionId}");
    }
  }

  /**
   * Map Square subscription statuses to CiviCRM contribution_status_id.
   *
   * @param string $squareStatus
   * @return int|null
   */
  protected function mapSquareSubscriptionStatusToCivi($squareStatus) {
    $squareStatus = strtoupper(trim($squareStatus));

    // CiviCRM recurring statuses:
    // 1 = Active, 2 = Pending, 3 = Cancelled, 4 = Failed
    switch ($squareStatus) {
      case 'ACTIVE':
      case 'PENDING':
      case 'CANCELED':
      case 'SUSPENDED':
      case 'DEACTIVATED':
        // Map Square semantics:
        if ($squareStatus === 'ACTIVE') {
          return 1; // Active
        }
        if ($squareStatus === 'PENDING') {
          return 2; // Pending
        }
        if ($squareStatus === 'CANCELED' || $squareStatus === 'DEACTIVATED') {
          return 3; // Cancelled
        }
        if ($squareStatus === 'SUSPENDED') {
          return 4; // Failed / On Hold
        }
        break;
    }

    // If unknown, don't change local status.
    return NULL;
  }

  /**
   * Extract override amount from Square subscription.
   *
   * @param array $subscription
   * @return float|null
   */
  protected function extractSubscriptionAmount(array $subscription) {
    if (!empty($subscription['price_override_money']['amount'])) {
      return ((float) $subscription['price_override_money']['amount']) / 100;
    }

    // If no override, fall back to catalog plan pricing (unavailable via subscription API alone).
    return NULL;
  }

  /**
   * Determine financial_type_id for contributions created by Square.
   *
   * Priority:
   *  1. Contribution params (financialTypeID / financial_type_id)
   *  2. Recurring template on contribution_recur
   *  3. System default (Donation = 1)
   *
   * @param array $params
   * @return int
   */
  protected function getFinancialTypeId(array $params) {
    // 1. Direct param from contribution form
    if (!empty($params['financialTypeID'])) {
      return (int) $params['financialTypeID'];
    }
    if (!empty($params['financial_type_id'])) {
      return (int) $params['financial_type_id'];
    }

    // 2. Check recurring template if recurID provided
    if (!empty($params['contributionRecurID'])) {
      $recur = \Civi\Api4\ContributionRecur::get(FALSE)
        ->addWhere('id', '=', (int) $params['contributionRecurID'])
        ->addSelect('financial_type_id')
        ->execute()
        ->first();

      if (!empty($recur['financial_type_id'])) {
        return (int) $recur['financial_type_id'];
      }
    }

    // 3. Fallback to Donation (ID=1)
    return 1;
  }

  /**
   * Process Square invoice.payment_made webhook event.
   *
   * @param array $payload
   *   Full decoded JSON from Square webhook.
   */
  public function handleInvoicePaymentCreated(array $payload) {
    if (empty($payload['data']['object']['invoice'])) {
      Civi::log()->debug('Square webhook: invoice.payment_made missing invoice object.');
      return;
    }

    $invoice = $payload['data']['object']['invoice'];
    $invoiceId = $invoice['id'] ?? NULL;
    $subscriptionId = $invoice['subscription_id'] ?? NULL;

    if (!$invoiceId) {
      Civi::log()->debug('Square webhook: invoice missing ID.');
      return;
    }

    // Load subscription payment info
    $total = $invoice['payment_requests'][0]['computed_amount_money']['amount'] ?? NULL;
    $currency = $invoice['payment_requests'][0]['computed_amount_money']['currency'] ?? 'USD';

    if ($total === NULL) {
      Civi::log()->debug("Square webhook: invoice {$invoiceId} missing payment amount.");
      return;
    }

    $amount = ((float) $total) / 100;

    // Find matching Civi recurring record
    $recur = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addWhere('processor_id', '=', $subscriptionId)
      ->addSelect('id', 'contact_id')
      ->execute()
      ->first();

    if (empty($recur)) {
      Civi::log()->debug("Square webhook: No matching contribution_recur for subscription {$subscriptionId}.");
      return;
    }

    $contactId = (int) $recur['contact_id'];
    $recurId = (int) $recur['id'];

    // Check for duplicate contribution by invoice ID
    $existing = \Civi\Api4\Contribution::get(FALSE)
      ->addWhere('invoice_id', '=', $invoiceId)
      ->addSelect('id')
      ->execute()
      ->first();

    if (!empty($existing)) {
      Civi::log()->debug("Square webhook: Invoice {$invoiceId} already mapped to contribution {$existing['id']}.");
      return;
    }

    // Determine financial type ID
    $financialTypeId = $this->getFinancialTypeId([
      'contributionRecurID' => $recurId,
    ]);

    // Create new completed contribution
    \Civi\Api4\Contribution::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('financial_type_id', $financialTypeId)
      ->addValue('total_amount', $amount)
      ->addValue('net_amount', $amount)
      ->addValue('currency', $currency)
      ->addValue('contribution_recur_id', $recurId)
      ->addValue('contribution_status_id', 1)
      ->addValue('invoice_id', $invoiceId)
      ->addValue('source', 'Square Recurring Payment')
      ->execute();

    Civi::log()->debug("Square webhook: Created contribution for invoice {$invoiceId} (subscription {$subscriptionId}).");
  }

  /**
   * Handle a subscription cancellation event coming from Square.
   *
   * Triggered by webhook event: subscription.canceled
   *
   * @param array $payload
   *   Full decoded JSON body from Square webhook.
   */
  public function handleSubscriptionCancelled(array $payload) {
    if (empty($payload['data']['object']['subscription']['id'])) {
      // Civi::log()->debug('Square webhook: subscription.canceled missing subscription ID.');
      return;
    }

    $subscriptionId = $payload['data']['object']['subscription']['id'];

    // Find associated recurring contribution.
    $recur = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addWhere('processor_id', '=', $subscriptionId)
      ->addSelect('id')
      ->execute()
      ->first();

    if (empty($recur)) {
      // Civi::log()->debug("Square webhook: No matching contribution_recur found for cancelled subscription {$subscriptionId}.");
      return;
    }

    $recurId = (int) $recur['id'];

    // Update recurring record to Cancelled (3).
    \Civi\Api4\ContributionRecur::update(FALSE)
      ->addWhere('id', '=', $recurId)
      ->addValue('contribution_status_id', 3)
      ->execute();

    // Civi::log()->debug("Square webhook: Marked recurring contribution {$recurId} as Cancelled for subscription {$subscriptionId}.");
  }

  /**
   * Legacy entry point for on-site CC payments.
   *
   * CiviCRM still calls doDirectPayment for front-end payments.
   *
   * @param array $params
   *   Contribution / event params.
   *
   * @return array
   *
   * @throws \CRM_Core_Exception
   */
  public function doDirectPayment(&$params) {
    return $this->doPayment($params);
  }

  /**
   * Modern CiviCRM entry point for submitting payments (one-time or recurring).
   *
   * @param array $params
   *   Contribution or event payment parameters.
   * @param string $component
   *   Component name (e.g. 'contribute' or 'event'). Default 'contribute'.
   *
   * @return array
   *   Updated $params array.
   *
   * @throws \CRM_Core_Exception
   */
  public function doPayment(&$params, $component = 'contribute') {
    $this->_component = $component;
    // Determine if this is a recurring payment.
    if (!empty($params['is_recur']) || !empty($params['contributionRecurID'])) {
      return $this->doRecurPayment($params);
    }
    return $this->doOneTimePayment($params);
  }

  /**
   * Setup a recurring payment (called by CiviCRM for initial setup).
   *
   * This is called when a recurring contribution is first created.
   * It initializes the recurring payment but doesn't charge yet.
   *
   * @param array $params
   *   Recurring contribution parameters.
   *
   * @return array
   *   Updated params with subscription info.
   *
   * @throws \CRM_Core_Exception
   */
  public function doSetupRecurring(&$params) {
    // For Square, setup is handled in doRecurPayment()
    // This method is called by CiviCRM but we delegate to doRecurPayment
    return $this->doRecurPayment($params);
  }

  /**
   * Handle one-time Square payments.
   *
   * @param array $params
   * @return array
   * @throws \CRM_Core_Exception
   */
  protected function doOneTimePayment(&$params) {
    // 1. Extract Web Payments SDK token.
    //    Webform CiviCRM's confirm-form path does not always merge $_POST
    //    values into payment params, so we fall back to the request globals.
    $token = $params['square_payment_token']
      ?? $params['payment_token']
      ?? $params['token']
      ?? $_POST['square_payment_token']
      ?? $_REQUEST['square_payment_token']
      ?? NULL;

    if (!$token) {
      throw new \CRM_Core_Exception('Missing Square payment token.');
    }

    // Persist it back into $params so downstream code can see it.
    $params['square_payment_token'] = $token;

    // 2. Determine amount and currency.
    $amount = $params['amount'] ?? $params['total_amount'] ?? NULL;
    if (!$amount) {
      throw new \CRM_Core_Exception('Missing contribution amount.');
    }

    $amountCents = (int) round(((float) $amount) * 100);
    $currency = $params['currency'] ?? $params['currencyID'] ?? 'USD';

    // 3. Idempotency key.
    $idempotencyKey = 'civi_onetime_' . md5(json_encode($params) . microtime(TRUE));

    // 4. Build payload for /v2/payments.
    $body = [
      'idempotency_key' => $idempotencyKey,
      'source_id' => $token,
      'amount_money' => [
        'amount' => $amountCents,
        'currency' => $currency,
      ],
      'location_id' => $this->getLocationId(),
    ];

    // Add customer ID if available (optional for one-off payments)
    $contactId = $params['contactID'] ?? $params['contact_id'] ?? NULL;
    if ($contactId) {
      $customerId = $this->getSquareCustomerId($contactId);
      if ($customerId) {
        $body['customer_id'] = $customerId;
      }
    }

    // Optional reference
    if (!empty($params['invoiceID'])) {
      $body['reference_id'] = (string) $params['invoiceID'];
    }

    // 5. Send request.
    $resp = $this->squareRequest('POST', '/v2/payments', $body);
    if (empty($resp['payment']['id'])) {
      throw new \CRM_Core_Exception('Square payment failed: Missing payment ID.');
    }

    $payment = $resp['payment'];
    $trxnId = $payment['id'];

    // 6. Set required CiviCRM transaction fields.
    $params['trxn_id'] = $trxnId;
    $params['payment_status_id'] = 1;        // Completed
    $params['contribution_status_id'] = 1;   // Completed

    return $params;
  }

  /**
   * Main payment call for one-off payments.
   *
   * - Expects a Web Payments SDK token in:
   *     - $params['square_payment_token'] or
   *     - $params['payment_token'] or
   *     - $params['token']
   * - Creates a Square Payment via /v2/payments.
   * - On success, sets trxn_id and returns $params.
   *
   * @param array $params
   *   Contribution / participant params.
   * @param string $component
   *   Component name (e.g. 'contribute' or 'event').
   *
   * @return array
   *   Updated params.
   *
   * @throws \CRM_Core_Exception
   */
  public function doRecurPayment(&$params) {
    // 1. Extract token from Web Payments SDK.
    //    Webform CiviCRM's confirm-form path does not always merge $_POST
    //    values into payment params, so we fall back to the request globals.
    $token = $params['square_payment_token']
      ?? $params['payment_token']
      ?? $params['token']
      ?? $_POST['square_payment_token']
      ?? $_REQUEST['square_payment_token']
      ?? NULL;

    if (!$token) {
      throw new CRM_Core_Exception('Missing Square card token for recurring payments.');
    }

    $params['square_payment_token'] = $token;

    // 2. Ensure we have a valid Recurring Contribution ID from CiviCRM.
    $recurId = $params['contributionRecurID'] ?? NULL;
    if (!$recurId) {
      throw new CRM_Core_Exception('Missing contributionRecurID for Square recurring payments.');
    }

    // 3. Ensure customer exists / or create one
    $customerId = $this->ensureSquareCustomer($params);
    if ($this->findSquareCustomerById($customerId)) {
      // Civi::log()->debug("Square doRecurPayment: Found existing Square customer ID {$customerId} for CiviCRM recur ID {$recurId}");
      $this->updateSquareCustomerDetails($customerId, $params);
    }
    else {
      throw new CRM_Core_Exception("Failed to find or create Square customer for CiviCRM recur ID {$recurId}");
    }

    // 4. Convert card nonce → persistent card_id
    if (empty($params['square_payment_token'])) {
      $cardId = $this->createCardOnFile($customerId, $token);
    }
    else {
      // If using 'square_payment_token', we assume it's already a card on file ID.
      $cardId = $token;
    }

    // 5. Determine plan ID
    $planVariationId = $this->getPlanVariationIdForParams($params);
    Civi::log()->debug("Square doRecurPayment: Using plan variation ID {$planVariationId} for CiviCRM recur ID {$recurId}");
    // 6. Determine start date (Square-safe)
    // Square rejects same-day start if UTC has rolled to next day.
    // To avoid timezone hell: always start tomorrow.
    $startDate = (new DateTime('tomorrow'))->format('Y-m-d');

    // 7. Generate idempotency key tied to the recurring record so re-posts don't duplicate.
    $idempotencyKey = "recur_{$recurId}_" . md5($customerId . $cardId . microtime(TRUE));
    $source = [
      'contact_id' => (string) ($params['contactID'] ?? $params['contact_id'] ?? ''),
      'recur_id' => (string) $recurId,
    ];
    // implode key and value of source
    // convert array to string with maintain key value
    $note = json_encode($source, JSON_UNESCAPED_SLASHES);


    // 8. Build subscription payload
    $body = [
      'idempotency_key' => $idempotencyKey,
      'location_id' => $this->getLocationId(),
      'plan_variation_id' => $planVariationId,
      'customer_id' => $customerId,
      'card_id' => $cardId,
      'start_date' => $startDate,
      'source' => [
        'name' => $note,
      ],
    ];

    // 9. Send subscription create request
    $resp = $this->squareRequest('POST', '/v2/subscriptions', $body);

    if (empty($resp['subscription']['id'])) {
      throw new CRM_Core_Exception('Failed to create Square subscription.');
    }
    $subscriptionId = $resp['subscription']['id'];
     Civi::log()->debug('Square subscription created: ' . json_encode([
      'subscription_id' => $subscriptionId,
      'recur_id' => $recurId,
    ]));

    // 10. Handle initial payment (if needed)
    // Square subscriptions don't charge on creation, they start on start_date.
    // If the user wants to charge immediately, we need to make a one-time payment.
    $initialPaymentNeeded = !empty($params['send_receipt']) || !empty($params['is_recur']);
    
    if ($initialPaymentNeeded) {
      try {
        // Make an initial one-time payment for the first billing cycle
        $initialPaymentBody = [
          'idempotency_key' => 'initial_' . $idempotencyKey,
          'source_id' => $cardId,
          'amount_money' => [
            'amount' => $amountCents,
            'currency' => $currency,
          ],
          'location_id' => $this->getLocationId(),
          'customer_id' => $customerId,
          'reference_id' => (string) $recurId,
        ];

        $paymentResp = $this->squareRequest('POST', '/v2/payments', $initialPaymentBody);
        if (!empty($paymentResp['payment']['id'])) {

        }
      }
      catch (Exception $e) {
        // Log but don't fail - subscription is already created
        Civi::log()->debug('Square initial payment failed (non-fatal): ' . $e->getMessage());
      }
    }

    // 11. Update the recurring contribution record
    ContributionRecur::update(FALSE)
      ->addWhere('id', '=', $recurId)
      ->addValue('processor_id', $subscriptionId)
      ->addValue('trxn_id', $subscriptionId)
      ->addValue('contribution_status_id', 2) // Pending
      ->execute();

    // 12. Return CiviCRM-standard response
    return [
      'payment_status_id' => 2,               // Pending
      'contribution_status_id' => 2,          // Pending
      'trxn_id' => $subscriptionId,
      'subscription_id' => $subscriptionId,
    ];
  }

  /**
   * Whether this processor supports recurring payments.
   *
   * @return bool
   */
  public function supportsRecurring() {
    return TRUE;
  }

  /**
   * Whether this processor supports refunds.
   *
   * @return bool
   */
  public function supportsRefund() {
    return TRUE;
  }

  /**
   * Perform a refund via Square Refunds API.
   *
   * @param array $params
   *
   * @return array
   *
   * @throws \CRM_Core_Exception
   */
  public function doRefund(&$params) {
    $trxnId = $params['trxn_id'] ?? $params['transaction_id'] ?? NULL;
    if (empty($trxnId)) {
      throw new CRM_Core_Exception('Missing transaction ID for refund.');
    }

    if (empty($params['amount'])) {
      throw new CRM_Core_Exception('Missing refund amount.');
    }

    $rawAmount = (float) $params['amount'];
    $amountInCents = (int) round($rawAmount * 100);
    if ($amountInCents <= 0) {
      throw new CRM_Core_Exception('Refund amount must be greater than zero.');
    }

    $currency = $params['currencyID'] ?? $params['currency'] ?? 'USD';

    $body = [
      'idempotency_key' => CRM_Utils_Random::generate(16),
      'payment_id' => $trxnId,
      'amount_money' => [
        'amount' => $amountInCents,
        'currency' => $currency,
      ],
    ];

    $response = $this->squareRequest('POST', '/v2/refunds', $body);

    if (empty($response['refund']) || empty($response['refund']['id'])) {
      $msg = 'Square refund failed: unexpected response.';
      throw new CRM_Core_Exception($msg);
    }

    $refund = $response['refund'];
    $status = $refund['status'] ?? 'UNKNOWN';

    if (!in_array($status, ['PENDING', 'COMPLETED', 'APPROVED'], TRUE)) {
      $msg = "Square refund not completed. Status: {$status}";
      throw new CRM_Core_Exception($msg);
    }

    // Populate some common fields back into $params.
    $params['refund_trxn_id'] = $refund['id'];

    return $params;
  }

  /**
   * Basic Square REST request helper.
   *
   * @param string $method
   *   HTTP method (GET, POST, etc.).
   * @param string $endpoint
   *   API path, e.g. '/v2/payments'.
   * @param array|null $body
   *   Request body as array.
   *
   * @return array
   *   Decoded JSON response.
   *
   * @throws \CRM_Core_Exception
   */
  protected function squareRequest($method, $endpoint, array $body = NULL) {
    $url = rtrim($this->getApiBaseUrl(), '/') . $endpoint;
    
    $ch = curl_init($url);
    if ($ch === FALSE) {
      throw new CRM_Core_Exception('Failed to initialize cURL for Square request.');
    }

    $accessToken = $this->getAccessToken();
    $headers = [
      'Authorization: Bearer ' . $accessToken,
      // Use a recent Square API version; adjust as needed.
      'Square-Version: 2025-01-15',
      'Content-Type: application/json',
      'Accept: application/json',
    ];

    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    if (!empty($body)) {
      $jsonBody = json_encode($body);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
    }

    $raw = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
      $msg = 'Square API cURL error: ' . $curlError;
     // Civi::log()->debug($msg);
      throw new CRM_Core_Exception($msg);
    }

    if ($raw === FALSE || $raw === '') {
      $msg = 'Empty response from Square API.';
     // Civi::log()->debug($msg);
      throw new CRM_Core_Exception($msg);
    }

    $decoded = json_decode($raw, TRUE);
   // Civi::log()->debug('Square squareRequest: Decoded response=' . json_encode($decoded, JSON_UNESCAPED_SLASHES));
    
    if ($decoded === NULL) {
      $msg = 'Failed to decode Square API response JSON.';
     // Civi::log()->debug($msg . ' Raw: ' . $raw);
      throw new CRM_Core_Exception($msg);
    }

    if ($httpCode < 200 || $httpCode >= 300) {
      $errorMsg = "Square API returned HTTP {$httpCode}.";
      if (!empty($decoded['errors'])) {
        $errorDetails = [];
        foreach ($decoded['errors'] as $err) {
          $code = $err['code'] ?? 'UNKNOWN';
          $detail = $err['detail'] ?? '';
          $errorDetails[] = "{$code}: {$detail}";
        }
        $errorMsg .= ' ' . implode(' | ', $errorDetails);
      }
     // Civi::log()->debug($errorMsg . ' Response: ' . print_r($decoded, TRUE));
      throw new CRM_Core_Exception($errorMsg);
    }

    return $decoded;
  }

  /**
   * Look up an existing Square customer by email.
   *
   * @param string $email
   * @return string|null
   */
  protected function findSquareCustomerByEmail($email) {
    if (empty($email)) {
      return NULL;
    }

    $resp = $this->squareRequest('GET', '/v2/customers?email_address=' . urlencode($email));

    if (!empty($resp['customers'][0]['id'])) {
      return $resp['customers'][0]['id'];
    }

    return NULL;
  }

  /**
   * Look up an existing Square customer by email.
   *
   * @param string $email
   * @return string|null
   */
  protected function findSquareCustomerById($customerID) {
    if (empty($customerID)) {
      return NULL;
    }

    $resp = $this->squareRequest('GET', '/v2/customers/' . $customerID);
    if (!empty($resp['customer']['id'])) {
      return $resp['customer']['id'];
    }

    return NULL;
  }

  /**
   * @param $customerID
   * @param $params
   * @return void
   * @throws CRM_Core_Exception
   */
  protected function updateSquareCustomerDetails($customerID , $params) {
    $firstName = $params['first_name'] ?? NULL;
    $lastName = $params['last_name'] ?? NULL;
    $email = $params['email'] ?? $params['email-5'] ?? NULL;
    $contactID = $params['contactID'] ?? $params['contact_id'] ?? NULL;

    $body = [
      'given_name' => $firstName,
      'family_name' => $lastName,
      'email_address' => $email,
      'reference_id' => (string) $contactID,
    ];
    // remove empty value key
    $body = array_filter($body, function ($value) {
      return !is_null($value) && $value !== '';
    });
    $this->squareRequest('PUT', '/v2/customers/' . $customerID, $body);
  }

  /**
   * Ensure a Square customer exists for this contact. Also handles storing card_id if available.
   *
   * 1. Check for a stored Square Customer ID in a custom field.
   * 2. If none exists, check for existing Square customer by email.
   * 3. If still none, create a new customer in Square.
   * 4. Persist the new customer ID back to the contact.
   * 5. If card token/nonce is present in $params, create and store the card_id as well.
   *
   * @param array $params
   *   Contribution params (includes contactID/contact_id).
   *
   * @return string
   *   Square customer ID.
   *
   * @throws CRM_Core_Exception
   */
  public function ensureSquareCustomer(array $params) {
    $contactID = $params['contactID'] ?? $params['contact_id'] ?? NULL;
    if (!$contactID) {
      throw new CRM_Core_Exception('Missing contactID in params for Square recurring payment.');
    }

    $contactID = (int) $contactID;

    // 1. Check if we already have a stored Square Customer ID.
    if ($customerId = $this->getSquareCustomerId($contactID) ) {
     // Civi::log()->debug('Square customer already exists for contact ' . $contactID . ': ' . $customerId);
      return $customerId;
    }
    // Migration logic: check whether this contact already exists in Square based on reference_id.
    // If Square already has a customer with reference_id == Civi contact ID, we adopt that one.
    try {
      $respLookup = $this->squareRequest('GET', '/v2/customers?reference_id=' . urlencode((string) $contactID));
      if (!empty($respLookup['customers'][0]['id'])) {
        $migratedCustomerId = $respLookup['customers'][0]['id'];
        // Check if another Civi contact already mapped to this customerId.
        $existingMapping = Contact::get(FALSE)
          ->addWhere('square_data.square_customer_id', '=', $migratedCustomerId)
          ->addSelect('id')
          ->execute()
          ->first();

        if (!empty($existingMapping) && (int) $existingMapping['id'] !== $contactID) {
          throw new CRM_Core_Exception(
            "Square customer {$migratedCustomerId} already mapped to a different CiviCRM contact ({$existingMapping['id']})."
          );
        }

        // Store mapping if safe
        $this->saveSquareCustomerId($contactID, $migratedCustomerId);

        // If a card token is present, attach card to this existing Square customer
        $cardNonce = $params['square_payment_token']
          ?? $params['payment_token']
          ?? $params['token']
          ?? NULL;

        if (!empty($cardNonce)) {
          $cardId = $this->createCardOnFile($migratedCustomerId, $cardNonce, $params, $contactID);
          $this->saveSquareCardId($contactID, $cardId);
        }

        return $migratedCustomerId;
      }
    }
    catch (\Exception $e) {
     // Civi::log()->debug('Square migration lookup error: ' . $e->getMessage());
    }
    $existingCustomerId = $this->getSquareCustomerId($contactID);
    if (!empty($existingCustomerId)) {
      // If card token/nonce is present, create and store card_id
      $cardNonce = $params['square_payment_token']
        ?? $params['payment_token']
        ?? $params['token']
        ?? NULL;
      if (!empty($cardNonce)) {
        $cardId = $this->createCardOnFile($existingCustomerId, $cardNonce, $params, $contactID);
        $this->saveSquareCardId($contactID, $cardId);
      }
      return $existingCustomerId;
    }

    // Load contact email
    $contact = Contact::get(FALSE)
      ->addWhere('id', '=', $contactID)
      ->addSelect('email')
      ->execute()
      ->first();

    $email = $contact['email'] ?? NULL;

    // Check for existing Square customer by email
    $squareCustomerByEmail = $this->findSquareCustomerByEmail($email);

    if (!empty($squareCustomerByEmail)) {
      // Check if mapped to another contact
      $existingMapping = Contact::get(FALSE)
        ->addWhere('square_data.square_customer_id', '=', $squareCustomerByEmail)
        ->addSelect('id')
        ->execute()
        ->first();

      if (!empty($existingMapping) && (int) $existingMapping['id'] !== $contactID) {
        throw new CRM_Core_Exception('This email address is already associated with a different Square customer in our system.');
      }

      // Save mapping if none existed previously
      $this->saveSquareCustomerId($contactID, $squareCustomerByEmail);
      // If card token/nonce is present, create and store card_id
      $cardNonce = $params['square_payment_token']
        ?? $params['payment_token']
        ?? $params['token']
        ?? NULL;
      if (!empty($cardNonce)) {
        $cardId = $this->createCardOnFile($squareCustomerByEmail, $cardNonce, $params, $contactID);
        $this->saveSquareCardId($contactID, $cardId);
      }
      return $squareCustomerByEmail;
    }

    // 2. Load contact info from CiviCRM using API4 for customer creation.
    $contactInfo = Contact::get(FALSE)
      ->addWhere('id', '=', $contactID)
      ->addSelect('first_name', 'last_name', 'email')
      ->execute()
      ->first();

    if (empty($contactInfo)) {
      throw new CRM_Core_Exception("Unable to load contact {$contactID} for Square customer creation.");
    }

    $firstName = $contactInfo['first_name'] ?? NULL;
    $lastName = $contactInfo['last_name'] ?? NULL;
    $email = $contactInfo['email'] ?? NULL;

    $body = [
      'given_name' => $firstName,
      'family_name' => $lastName,
      'email_address' => $email,
      'reference_id' => (string) $contactID,
    ];

    $resp = $this->squareRequest('POST', '/v2/customers', $body);

    if (empty($resp['customer']['id'])) {
      throw new CRM_Core_Exception('Failed to create Square customer.');
    }

    $customerId = $resp['customer']['id'];

    // 3. Persist the customer ID in a custom field on the contact.
    $this->saveSquareCustomerId($contactID, $customerId);

    // If card token/nonce is present, create and store card_id
    $cardNonce = $params['square_payment_token']
      ?? $params['payment_token']
      ?? $params['token']
      ?? NULL;
    if (!empty($cardNonce)) {
      $cardId = $this->createCardOnFile($customerId, $cardNonce, $params, $contactID);
      $this->saveSquareCardId($contactID, $cardId);
    }

    return $customerId;
  }

  /**
   * Attach a card to the Square customer using the tokenized card nonce.
   *
   * @param string $customerId
   *   Square customer ID.
   * @param string $cardNonce
   *   Token from Web Payments SDK.
   * @param array $params
   *   Additional parameters, possibly including verification_token.
   * @param int|null $contactId
   *   CiviCRM contact ID (optional, but required if you want to store card_id).
   *
   * @return string
   *   Square card ID.
   *
   * @throws \CRM_Core_Exception
   */
  public function createCardOnFile($customerId, $cardNonce, array $params = [], $contactId = NULL) {
    if (empty($cardNonce)) {
      throw new CRM_Core_Exception('Missing Square card token for recurring payment.');
    }

    $body = [
      'source_id' => $cardNonce,
      'idempotency_key' => uniqid('square_card_', TRUE),
      'card' => [
        'card_type' => $params['card_type'] ?? NULL,
        'last_4' => $params['last_4'] ?? NULL,
        'exp_month' => $params['exp_month'] ?? NULL,
        'exp_year' => $params['exp_year'] ?? NULL,
        'cardholder_name' => $params['cardholder_name'] ?? NULL,
        'customer_id' => $customerId,

      ],
    ];

    // Square requires verification_token for AVS/SCA under certain conditions.
    if (!empty($params['verification_token'])) {
      $body['verification_token'] = $params['verification_token'];
    }
    // Add billing address if available
    if (!empty($params['billing_address'])) {
      $body['card']['billing_address'] = [
        'address_line_1' => $params['billing_address']['street_address'] ?? NULL,
        'address_line_2' => $params['billing_address']['street_address_2'] ?? NULL,
        'locality' => $params['billing_address']['city'] ?? NULL,
        'administrative_district_level_1' => $params['billing_address']['state'] ?? NULL,
        'postal_code' => $params['billing_address']['postal_code'] ?? NULL,
        'country' => $this->mapCountryCode($params['billing_address']['country'] ?? 'US'),
      ];
    }

    // Remove null values
    $body['card'] = array_filter($body['card']);

    try {
      $resp = $this->squareRequest('POST', '/v2/cards', $body);
    }
    catch (\CRM_Core_Exception $e) {
      // If Square gave structured errors, translate them
      $raw = $e->getMessage();
      $decoded = json_decode($raw, TRUE);

      if (!empty($decoded['errors'])) {
        $human = $this->translateSquareCardError($decoded['errors']);
        throw new CRM_Core_Exception($human);
      }

      throw $e;
    }

    if (empty($resp['card']['id'])) {
      throw new CRM_Core_Exception('Failed to create card on file with Square.');
    }

    $cardId = $resp['card']['id'];
    // Save card_id to contact if $contactId is provided
    if (!empty($contactId)) {
      $this->saveSquareCardId($contactId, $cardId);
    }

    return $cardId;
  }

  /**
   * Map country to standardized 2-letter country code
   */
  protected function mapCountryCode($country) {
    // Standardize country codes/names
    $countryMap = [
      'US' => 'US',
      'USA' => 'US',
      'UNITED STATES' => 'US',
      'UNITED STATES OF AMERICA' => 'US',
      'CA' => 'CA',
      'CANADA' => 'CA',
      'GB' => 'GB',
      'UK' => 'GB',
      'UNITED KINGDOM' => 'GB',
    ];

    // Normalize input
    $normalizedCountry = strtoupper(trim($country));

    // Return mapped country or default to US
    return $countryMap[$normalizedCountry] ?? 'US';
  }

  /**
   * Translate Square card errors to human-friendly messages.
   *
   * @param array $errors
   * @return string
   */
  protected function translateSquareCardError(array $errors) {
    $messages = [];

    foreach ($errors as $err) {
      $code = $err['code'] ?? '';
      switch ($code) {
        case 'CARD_DECLINED':
          $messages[] = 'Your card was declined. Please use a different card.';
          break;

        case 'GENERIC_DECLINE':
          $messages[] = 'The card was declined by the bank.';
          break;

        case 'INVALID_EXPIRATION':
          $messages[] = 'The card expiration date is invalid.';
          break;

        case 'CVV_FAILURE':
          $messages[] = 'The CVV security code is incorrect.';
          break;

        case 'ADDRESS_VERIFICATION_FAILURE':
          $messages[] = 'The billing ZIP/postal code did not match the card.';
          break;

        case 'INSUFFICIENT_FUNDS':
          $messages[] = 'The card has insufficient funds.';
          break;

        default:
          if (!empty($err['detail'])) {
            $messages[] = $err['detail'];
          }
          else {
            $messages[] = 'The card could not be processed.';
          }
          break;
      }
    }

    return implode(' ', $messages);
  }

  protected function getPlanVariationIdForParams(array $params): string {
    $entity = $params['component'] ?? 'contribute';
    $amount = (float) ($params['amount'] ?? 0);
    $currency = $params['currency'] ?? 'USD';
    $intervalUnit = $params['frequency_unit'] ?? '';
    $intervalStep = (int) ($params['frequency_interval'] ?? 1);
    $installments = (int) ($params['installments'] ?? 0);
    if (!$amount || !$intervalUnit) {
      throw new CRM_Core_Exception('Amount and cadence are required for Square subscription.');
    }

    /**
     * PLAN = what this subscription is about
     * Variation = amount + cadence
     */
    $planName = sprintf(
      'CiviCRM %s',
      ucfirst($entity)
    );


    $planId = $this->getOrCreateSubscriptionPlan($planName);
   // Civi::log()->debug("Square plan ID for {$entity}: {$planId}");
    return $this->getOrCreatePlanVariation(
      $planId, $amount,
      $currency, $intervalUnit,
      $intervalStep, $installments
    );
  }

  /**
   * Determine the Square plan ID for this recurring payment.
   *
   * @param array $params
   *
   * @return string
   *
   * @throws \CRM_Core_Exception
   */
  protected function getPlanIdForParams(array $params) {
    $membershipTypeId = $params['membership_type_id'] ?? NULL;

    $planMap = Civi::settings()->get('org_uschess_square_plan_map') ?? [];
    $planId = NULL;

    if ($membershipTypeId && isset($planMap[$membershipTypeId])) {
      $planId = $planMap[$membershipTypeId];
    }

    if (!$planId) {
      throw new CRM_Core_Exception("No Square plan mapping found for membership type ID {$membershipTypeId}.");
    }

    return $planId;
  }

  /**
   * Create a Square subscription for this recurring payment.
   *
   * @param string $customerId
   *   Square customer ID.
   * @param string $cardId
   *   Square card ID.
   * @param string $planId
   *   Square catalog plan ID.
   * @param array $params
   *   Contribution / recurring params.
   *
   * @return string
   *   Square subscription ID.
   *
   * @throws \CRM_Core_Exception
   */
  protected function createSubscription($customerId, $cardId, $planId, array $params) {
    $locationId = $this->getLocationId();

    $idempotencyKey = md5(uniqid('square_sub_', TRUE));
    $startDate = date('Y-m-d'); // Could be future-dated based on params if desired.

    $body = [
      'idempotency_key' => $idempotencyKey,
      'location_id' => $locationId,
      'plan_id' => $planId,
      'customer_id' => $customerId,
      'card_id' => $cardId,
      'start_date' => $startDate,
    ];

    $resp = $this->squareRequest('POST', '/v2/subscriptions', $body);

    if (empty($resp['subscription']['id'])) {
      throw new CRM_Core_Exception('Failed to create Square subscription.');
    }

    return $resp['subscription']['id'];
  }

  /**
   * Cancel a Square subscription.
   *
   * You would typically call this from a hook when a recurring
   * contribution is cancelled in CiviCRM.
   *
   * @param string $subscriptionId
   *
   * @throws \CRM_Core_Exception
   */
  public function cancelSubscription($subscriptionId) {
    if (empty($subscriptionId)) {
      throw new CRM_Core_Exception('Missing subscription ID to cancel.');
    }

    $this->squareRequest('POST', "/v2/subscriptions/{$subscriptionId}/cancel", []);
  }

  /**
   * Update subscription amount at Square.
   *
   * @param string $subscriptionId
   * @param float $newAmount
   * @param string $currency
   *
   * @throws \CRM_Core_Exception
   */
  public function updateSubscriptionAmount($subscriptionId, $newAmount, $currency = 'USD') {
    $amountCents = (int) round(((float) $newAmount) * 100);

    $body = [
      'version' => 0,
      'price_override_money' => [
        'amount' => $amountCents,
        'currency' => $currency,
      ],
    ];

    $this->squareRequest('PUT', "/v2/subscriptions/{$subscriptionId}", $body);
  }

  /**
   * Update the billing day / next billing date for a Square subscription.
   *
   * @param string $subscriptionId
   * @param string $nextBillingDate Format: YYYY-MM-DD
   *
   * @throws \CRM_Core_Exception
   */
  public function updateSubscriptionBillingDate($subscriptionId, $nextBillingDate) {
    $body = [
      'version' => 0,
      'start_date' => $nextBillingDate,
    ];

    $this->squareRequest('PUT', "/v2/subscriptions/{$subscriptionId}", $body);
  }

  /**
   * Update the cadence (frequency) of a subscription.
   *
   * @param string $subscriptionId
   * @param string $newPlanId
   *
   * @throws \CRM_Core_Exception
   */
  public function updateSubscriptionPlan($subscriptionId, $newPlanId) {
    $body = [
      'version' => 0,
      'plan_id' => $newPlanId,
    ];

    $this->squareRequest('PUT', "/v2/subscriptions/{$subscriptionId}", $body);
  }

  /**
   * Get the Square Customer ID stored on a contact.
   *
   * Custom field is created in hook_civicrm_install() and its ID
   * stored in the setting 'org_uschess_square_customer_field_id'.
   *
   * @param int $contactId
   *
   * @return string|null
   */
  protected function getSquareCustomerId($contactId) {
    $contactId = (int) $contactId;
    if ($contactId <= 0) {
      return NULL;
    }

    $fieldKey = 'square_data.square_customer_id';
    $row = Contact::get(FALSE)
      ->addSelect($fieldKey)
      ->addWhere('id', '=', $contactId)
      ->execute()
      ->first();
    if (empty($row) || empty($row[$fieldKey])) {
      return NULL;
    }

    return (string) $row[$fieldKey];
  }

  /**
   * Save the Square Customer ID onto a contact's custom field.
   *
   * @param int $contactId
   * @param string $customerId
   */
  protected function saveSquareCustomerId($contactId, $customerId) {
    $contactId = (int) $contactId;
    if ($contactId <= 0 || empty($customerId)) {
      return;
    }

    $fieldKey = 'square_data.square_customer_id';
    Contact::update(FALSE)
      ->addWhere('id', '=', $contactId)
      ->addValue($fieldKey, $customerId)
      ->execute();
  }

  /**
   * Whether processor supports back-office (admin) payments.
   *
   * For now, we only support front-end Web Payments SDK tokenization.
   *
   * @return bool
   */
  public function supportsBackOffice() {
    // You can change this to TRUE if you later support card entry in admin UI.
    return FALSE;
  }

  /**
   * Advertise the configuration fields used by this processor.
   */
  public static function getPaymentProcessorSettings() {
    return [
      'user_name' => [
        'label' => ts('Square Application ID'),
        'description' => ts('Found under Developer Dashboard → Your Application → Credentials.'),
        'type' => 'Text',
        'size' => CRM_Utils_Type::HUGE,
        'required' => TRUE,
      ],
      'password' => [
        'label' => ts('Square Access Token'),
        'description' => ts('The Square Access Token (sandbox or production).'),
        'type' => 'Password',
        'size' => CRM_Utils_Type::HUGE,
        'required' => TRUE,
      ],
      'signature' => [
        'label' => ts('Square Location ID'),
        'description' => ts('Found under Locations in your Square Dashboard.'),
        'type' => 'Text',
        'size' => CRM_Utils_Type::HUGE,
        'required' => TRUE,
      ],
      'test_user_name' => [
        'label' => ts('Square Sandbox Application ID'),
        'description' => ts('Found under Developer Dashboard → Your Application → Credentials.'),
        'type' => 'Text',
        'size' => CRM_Utils_Type::HUGE,
        'required' => TRUE,
      ],
      'test_password' => [
        'label' => ts('Square Sandbox Access Token'),
        'description' => ts('The Square Access Token (sandbox or production).'),
        'type' => 'Password',
        'size' => CRM_Utils_Type::HUGE,
        'required' => TRUE,
      ],
      'test_signature' => [
        'label' => ts('Square Sandbox Location ID'),
        'description' => ts('Found under Locations in your Square Dashboard.'),
        'type' => 'Text',
        'size' => CRM_Utils_Type::HUGE,
        'required' => TRUE,
      ],
      'subject' => [
        'label' => ts('Webhook Signature Key'),
        'description' => ts('The Webhook Signature Key used to validate events from Square.'),
        'type' => 'Text',
        'size' => CRM_Utils_Type::HUGE,
        'required' => TRUE,
      ],
      'test_subject' => [
        'label' => ts('Square Sandbox Webhook Signature Key'),
        'description' => ts('The Webhook Signature Key used to validate events from Square.'),
        'type' => 'Text',
        'size' => CRM_Utils_Type::HUGE,
        'required' => TRUE,
      ],
      'is_test' => [
        'label' => ts('Is Test Mode?'),
        'description' => ts('When enabled, uses Square Sandbox environment instead of Live.'),
        'type' => 'Checkbox',
        'required' => FALSE,
        'default' => 1,
      ],
    ];
  }

  /**
   * Inject Square payment form elements and scripts.
   */
  public function buildForm(&$form) {
    $formName = $form->getName();

    // Add test_url_button to suppress CiviCRM core warning
    if (!$form->elementExists('test_url_button')) {
      $form->addElement('hidden', 'test_url_button', '', ['id' => 'test_url_button']);
    }

    // Add is_test checkbox to the payment processor settings form
    if (!$form->elementExists('is_test') && $formName === 'CRM_Admin_Form_PaymentProcessor') {
      $form->add('checkbox', 'is_test', ts('Is Test Mode?'), NULL, FALSE);
    }

    // Add hidden field for the payment token
    if (!$form->elementExists('square_payment_token')) {
      $form->add('hidden', 'square_payment_token', '', ['id' => 'square_payment_token']);
    }

    // Inject the container where Square will mount the card fields + error box.
    $markup = '
      <div id="square-card-container"></div>
      <div id="square-card-errors" class="messages error" style="display:none"></div>
    ';

    $resources = CRM_Core_Resources::singleton();

    // Decide sandbox vs live SDK URL based on processor mode.
    $isSandbox = !empty($this->_mode) && $this->_mode === 'test';

    $sdkUrl = $isSandbox
      ? 'https://sandbox.web.squarecdn.com/v1/square.js'
      : 'https://web.squarecdn.com/v1/square.js';

    // Load Square's JS SDK.
    CRM_Core_Region::instance('billing-block')->addScriptUrl($sdkUrl);

    // Load our own integration JS from the extension.
    CRM_Core_Region::instance('billing-block')->add([
      'scriptFile' => [
        'org.uschess.square',
        'js/square.js',
      ],
      // Load after other scripts on form (default = 1)
      'weight' => 100,
    ]);
    // Pass settings to JS via window variables (more reliable than CRM.vars)
    $inlineScript = "
      window.squareApplicationId = '" . addslashes($this->_paymentProcessor['user_name'] ?? '') . "';
      window.squareLocationId = '" . addslashes($this->_paymentProcessor['signature'] ?? ($this->_paymentProcessor['password'] ?? '')) . "';
      window.squareIsSandbox = " . ($isSandbox ? 'true' : 'false') . ";
    ";
    CRM_Core_Region::instance('billing-block')->addScript($inlineScript);

    // Also pass settings to JS via CRM.vars for compatibility.
    $settings = [
      'applicationId' => $this->_paymentProcessor['user_name'] ?? '',
      'locationId' => $this->_paymentProcessor['signature'] ?? ($this->_paymentProcessor['password'] ?? ''),
      'isSandbox' => $isSandbox,
    ];
    CRM_Core_Region::instance('billing-block')->addSetting([
      'orgUschessSquare' => $settings,
    ]);

    // Attach this to the billing block region so it appears in the right place.
    CRM_Core_Region::instance('billing-block')->add([
      'markup' => $markup,
    ]);
  }

  /**
   * Validate payment processor settings on save.
   */
  public function validateForm($values, &$errors) {
    if (($values['payment_processor_type_id:name'] ?? '') !== 'Square') {
      return;
    }

    if (empty($values['user_name'])) {
      $errors['user_name'] = ts('Square Application ID is required.');
    }
    if (empty($values['password'])) {
      $errors['password'] = ts('Square Access Token is required.');
    }
    if (empty($values['signature'])) {
      $errors['signature'] = ts('Square Location ID is required.');
    }
    if (empty($values['subject'])) {
      $errors['subject'] = ts('Webhook Signature Key is required.');
    }
  }

  /**
   * Get or create a subscription plan variation with cadence.
   *
   * @param string $planId
   * @param float $amount
   * @param string $currency
   * @param string $cadence MONTHLY | ANNUAL | WEEKLY
   * @param int $intervalStep
   * @param int|null $installments
   * @return string Plan variation ID
   * @throws CRM_Core_Exception
   */
  protected function getOrCreatePlanVariation(
    string $planId,
    float $amount,
    string $currency,
    string $intervalUnit,
    int  $intervalStep = 1,
    ?int $installments = NULL
  ): string {
    // Civi::log()->debug("Looking up Square plan variation: {$planId}, {$amount} {$currency}, every {$intervalStep} {$intervalUnit}");
    $cadence = $this->resolveCadence($intervalUnit, $intervalStep);
    // Civi::log()->debug("Resolved Square cadence: {$cadence}");
    $cacheKey = "{$planId}_{$cadence}_{$amount}";
    $cache = Civi::settings()->get('org_square_plan_variation_cache') ?? [];

    if (!empty($cache[$cacheKey])) {
      return $cache[$cacheKey];
    }

    $label = sprintf(
      '%s %0.2f %s',
      $cadence,
      $amount,
      $currency
    );
    // Civi::log()->debug("Creating label Square plan variation: {$label}");
    $amountCents = (int) round($amount * 100);

    $body = [
      'idempotency_key' => uniqid('plan_var_', TRUE),
      'object' => [
        'type' => 'SUBSCRIPTION_PLAN_VARIATION',
        'id' => '#var_' . md5($cacheKey),
        'subscription_plan_variation_data' => [
          'name' => $label,
          'subscription_plan_id' => $planId,
          'phases' => [
            [
              'ordinal' => 0,
              'cadence' => strtoupper($cadence), // MONTHLY, ANNUAL
              'periods' => $installments ?? 0,
              'pricing' => [
                'type' => 'STATIC',
                'price' => [
                  'amount' => $amountCents,
                  'currency' => $currency,
                ],
              ],
              /*
              'recurring_price_money' => [
                'amount' => $amountCents,
                'currency' => $currency,
              ]
              */
            ],
          ],
        ],
      ],
    ];
    $resp = $this->squareRequest('POST', '/v2/catalog/object', $body);

    $variationId = $resp['catalog_object']['id'] ?? NULL;
   // Civi::log()->debug("Created Square plan variation ID: {$variationId}");
    if (!$variationId) {
      throw new CRM_Core_Exception('Failed to create Square plan variation.');
    }

    $cache[$cacheKey] = $variationId;
    Civi::settings()->set('org_square_plan_variation_cache', $cache);

    return $variationId;
  }

  /**
   * Resolve Square cadence from interval unit + frequency.
   *
   * @param string $unit day|week|month|year
   * @param int $step
   * @return string
   * @throws CRM_Core_Exception
   */
  protected function resolveCadence(string $unit, int $step): string {

    foreach (self::SQUARE_CADENCES as $cadence => $def) {
      if ($def['unit'] === $unit && $def['step'] === $step) {
        return $cadence;
      }
    }

    throw new CRM_Core_Exception(
      "Unsupported Square cadence: every {$step} {$unit}(s)"
    );
  }


  /**
   * Get or create a Square Subscription Plan.
   *
   * @param string $name
   * @return string Plan ID
   * @throws CRM_Core_Exception
   */
  protected function getOrCreateSubscriptionPlan(string $name): string {
   // Civi::log()->debug("Looking up Square subscription plan: {$name}");
    // Cache via Civi setting
    $cache = Civi::settings()->get('org_square_plan_cache') ?? [];

    if (!empty($cache[$name])) {
      return $cache[$name];
    }

    $body = [
      'idempotency_key' => uniqid('plan_', TRUE),
      'object' => [
        'type' => 'SUBSCRIPTION_PLAN',
        'id' => '#plan_' . md5($name),
        'subscription_plan_data' => [
          'name' => $name,
        ],
      ],
    ];
   // Civi::log()->debug("Creating Square subscription plan: {$name}");
    $resp = $this->squareRequest('POST', '/v2/catalog/object', $body);

    $planId = $resp['catalog_object']['id'] ?? NULL;
    if (!$planId) {
      throw new CRM_Core_Exception('Failed to create Square subscription plan.');
    }

    $cache[$name] = $planId;
    Civi::settings()->set('org_square_plan_cache', $cache);

    return $planId;
  }

  /**
   * Save the Square Card ID onto a contact's custom field (if desired).
   *
   * @param int $contactId
   * @param string $cardId
   */
  protected function saveSquareCardId($contactId, $cardId) {
    $contactId = (int) $contactId;
    if ($contactId <= 0 || empty($cardId)) {
      return;
    }

    $fieldKey = 'square_data.square_card_id';
    Contact::update(FALSE)
      ->addWhere('id', '=', $contactId)
      ->addValue($fieldKey, $cardId)
      ->execute();
  }

  /**
   * Map Square payment statuses to CiviCRM contribution status IDs.
   *
   * @param string $squareStatus
   *   Status from Square API (e.g., 'COMPLETED', 'PENDING', 'FAILED', 'CANCELED').
   *
   * @return int|null
   *   CiviCRM contribution_status_id or NULL if unmapped.
   */
  protected function mapPaymentStatus($squareStatus) {
    $squareStatus = strtoupper(trim($squareStatus));

    switch ($squareStatus) {
      case 'COMPLETED':
      case 'APPROVED':
        return 1; // Completed
      case 'PENDING':
      case 'PROCESSING':
        return 2; // Pending
      case 'FAILED':
      case 'DECLINED':
      case 'CANCELED':
        return 4; // Failed
      case 'REFUNDED':
        return 7; // Refunded
      default:
        return NULL;
    }
  }

  /**
   * Find the contact ID associated with a Square payment.
   *
   * Attempts to resolve contact by:
   * 1. Customer reference_id (if set in Square)
   * 2. Email address from payment receipt
   * 3. Contribution reference_id if payment is linked to existing contribution
   *
   * @param array $payment
   *   Payment object from Square API.
   *
   * @return int|null
   *   CiviCRM contact ID or NULL if not found.
   */
  protected function findContactIdForPayment(array $payment) {
    // 1. Try to find via customer reference_id
    $customerId = $payment['customer_id'] ?? NULL;
    if ($customerId) {
      try {
        $resp = $this->squareRequest('GET', '/v2/customers/' . $customerId);
        if (!empty($resp['customer']['reference_id'])) {
          $refId = $resp['customer']['reference_id'];
          if (ctype_digit((string) $refId)) {
            $contact = \Civi\Api4\Contact::get(FALSE)
              ->addWhere('id', '=', (int) $refId)
              ->addSelect('id')
              ->execute()
              ->first();
            if (!empty($contact)) {
              return (int) $contact['id'];
            }
          }
        }
      }
      catch (\Exception $e) {
       // Civi::log()->debug('Square findContactIdForPayment: Error looking up customer: ' . $e->getMessage());
      }
    }

    // 2. Try via reference_id on payment (if it points to a contribution)
    $referenceId = $payment['reference_id'] ?? NULL;
    if ($referenceId && ctype_digit((string) $referenceId)) {
      $contribution = \Civi\Api4\Contribution::get(FALSE)
        ->addWhere('id', '=', (int) $referenceId)
        ->addSelect('contact_id')
        ->execute()
        ->first();
      if (!empty($contribution)) {
        return (int) $contribution['contact_id'];
      }
    }

    // 3. Try via receipt email (if available)
    $receiptEmail = $payment['receipt_email'] ?? NULL;
    if ($receiptEmail) {
      $contact = \Civi\Api4\Contact::get(FALSE)
        ->addWhere('email', '=', $receiptEmail)
        ->addSelect('id')
        ->execute()
        ->first();
      if (!empty($contact)) {
        return (int) $contact['id'];
      }
    }

    return NULL;
  }

  /**
   * Get webhook signature key from processor config.
   *
   * @return string|null
   */
  public function getWebhookSignatureKey() {
    if ($this->isTestMode()) {
      return $this->_paymentProcessor['test_subject'] ?? $this->_paymentProcessor['subject'] ?? NULL;
    }
    return $this->_paymentProcessor['subject'] ?? NULL;
  }

  /**
   * Handle subscription cancellation from Square webhook.
   *
   * @param array $payload
   *   Full webhook payload from Square.
   */
  public function handleSubscriptionCanceled(array $payload) {
    $this->handleSubscriptionCancelled($payload);
  }

  /**
   * Handle invoice paid from Square webhook.
   *
   * @param array $payload
   *   Full webhook payload from Square.
   */
  public function handleInvoicePaid(array $payload) {
    $this->handleInvoicePaymentCreated($payload);
  }

  /**
   * Handle subscription updated from Square webhook.
   *
   * @param array $payload
   *   Full webhook payload from Square.
   */
  public function handleSubscriptionUpdated(array $payload) {
    if (empty($payload['data']['object']['subscription']['id'])) {
     // Civi::log()->debug('Square webhook: subscription.updated missing subscription ID.');
      return;
    }

    $subscriptionId = $payload['data']['object']['subscription']['id'];
    $this->syncSubscriptionFromSquare($subscriptionId);
  }

  /**
   * Sync a Square subscription cancellation into CiviCRM.
   *
   * Called when Square sends subscription.canceled or subscription.deleted webhook.
   *
   * @param string $squareSubscriptionId
   *   The subscription ID from Square.
   *
   * @throws \CRM_Core_Exception
   */
  public function syncSubscriptionCancellationFromSquare($squareSubscriptionId) {
    if (empty($squareSubscriptionId)) {
     // Civi::log()->debug('Square syncSubscriptionCancellationFromSquare(): missing subscription ID.');
      return;
    }

    // Find the recurring contribution linked to this subscription
    $recur = ContributionRecur::get(FALSE)
      ->addWhere('processor_id', '=', $squareSubscriptionId)
      ->addSelect('id', 'contribution_status_id')
      ->execute()
      ->first();

    if (empty($recur)) {
     // Civi::log()->debug("Square syncSubscriptionCancellationFromSquare(): no recurring contribution found for subscription {$squareSubscriptionId}");
      return;
    }

    $recurId = (int) $recur['id'];

    // Update the recurring contribution to Cancelled (status_id = 3)
    ContributionRecur::update(FALSE)
      ->addWhere('id', '=', $recurId)
      ->addValue('contribution_status_id', 3) // Cancelled
      ->execute();

   // Civi::log()->debug("Square syncSubscriptionCancellationFromSquare(): marked recurring contribution {$recurId} as cancelled for subscription {$squareSubscriptionId}");
  }


  /**
   * Override CRM_Core_Payment function
   *
   * @return array
   */
  public function getPaymentFormFields(): array {
    return [];
  }

  /**
   * Return an array of all the details about the fields potentially required for payment fields.
   *
   * Only those determined by getPaymentFormFields will actually be assigned to the form
   *
   * @return array
   *   field metadata
   */
  public function getPaymentFormFieldsMetadata(): array {
    return [];
  }

}
