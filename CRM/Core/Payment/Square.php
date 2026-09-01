<?php
use CRM_Square_ExtensionUtil as E;
use Civi\Api4\Contact;
use Civi\Api4\ContributionRecur;
use Civi\Api4\PaymentToken;
use Civi\Payment\Exception\PaymentProcessorException;
use Civi\Payment\PropertyBag;
use Square\SquareClient;
use Square\Exceptions\SquareApiException;
use Square\Exceptions\SquareException;
use Square\Types\Money;
use Square\Types\Address;
use Square\Types\Card;
use Square\Types\Error;
use Square\Types\CustomerQuery;
use Square\Types\CustomerFilter;
use Square\Types\CustomerTextFilter;
use Square\Types\Subscription;
use Square\Types\SubscriptionSource;
use Square\Types\SubscriptionPhase;
use Square\Types\SubscriptionPricing;
use Square\Types\CatalogObject;
use Square\Types\CatalogObjectBatch;
use Square\Types\CatalogObjectSubscriptionPlan;
use Square\Types\CatalogObjectSubscriptionPlanVariation;
use Square\Types\CatalogSubscriptionPlan;
use Square\Types\CatalogSubscriptionPlanVariation;
use Square\Customers\Requests\CreateCustomerRequest;
use Square\Customers\Requests\UpdateCustomerRequest;
use Square\Customers\Requests\GetCustomersRequest;
use Square\Customers\Requests\SearchCustomersRequest;
use Square\Cards\Requests\CreateCardRequest;
use Square\Payments\Requests\CreatePaymentRequest;
use Square\Subscriptions\Requests\CreateSubscriptionRequest;
use Square\Subscriptions\Requests\GetSubscriptionsRequest;
use Square\Subscriptions\Requests\UpdateSubscriptionRequest;
use Square\Subscriptions\Requests\CancelSubscriptionsRequest;
use Square\Refunds\Requests\RefundPaymentRequest;
use Square\Catalog\Requests\BatchUpsertCatalogObjectsRequest;

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

  /** @var array Payment-processor instance configuration. */
  protected $_paymentProcessor = [];

  /**
   * Square-supported cadence definitions.
   */
  protected const SQUARE_CADENCES = [
    'DAILY' => [
      'label' => 'Daily',
      'unit' => 'day',
      'step' => 1,
    ],
    'WEEKLY' => [
      'label' => 'Weekly',
      'unit' => 'week',
      'step' => 1,
    ],
    'EVERY_TWO_WEEKS' => [
      'label' => 'Every 2 Weeks',
      'unit' => 'week',
      'step' => 2,
    ],
    'MONTHLY' => [
      'label' => 'Monthly',
      'unit' => 'month',
      'step' => 1,
    ],
    'EVERY_TWO_MONTHS' => [
      'label' => 'Every 2 Months',
      'unit' => 'month',
      'step' => 2,
    ],
    'QUARTERLY' => [
      'label' => 'Quarterly',
      'unit' => 'month',
      'step' => 3,
    ],
    'EVERY_SIX_MONTHS' => [
      'label' => 'Every 6 Months',
      'unit' => 'month',
      'step' => 6,
    ],
    'ANNUAL' => [
      'label' => 'Annual',
      'unit' => 'year',
      'step' => 1,
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
  public function __construct($mode, array &$paymentProcessor) {
    // Store processor config
    $this->_paymentProcessor = $paymentProcessor;
  }

  /**
   * Whether this processor is in test/sandbox mode.
   *
   * @return bool
   */
  protected function isTestMode() {
    return !empty($this->_paymentProcessor['is_test']);
  }

  /**
   * Inject Square Web Payments SDK, square.js, and card container HTML into the
   * billing block.
   *
   * This method is called by CiviCRM for ALL form types that render the billing
   * block, including:
   *  - Native contribution pages / event registration
   *  - Drupal Webform AJAX billing block requests (CRM_Core_Payment_Form)
   *  - Backend contribution/event forms
   *
   * We use CRM_Core_Region::instance('billing-block')->add() rather than
   * \Civi::resources()->addScriptFile() because the latter does NOT work for
   * AJAX billing block responses (e.g. Drupal webforms).
   *
   * @param \CRM_Core_Form $form
   */
  public function buildForm(&$form) {
    $isSandbox = FALSE;
    if ($this->_paymentProcessor['is_test']) {
      $isSandbox = TRUE;
    }

    $sdkUrl = $isSandbox
      ? 'https://sandbox.web.squarecdn.com/v1/square.js'
      : 'https://web.squarecdn.com/v1/square.js';

    $jsVars = [
      'id' => (int) ($this->_paymentProcessor['id'] ?? 0),
      'applicationId' => $this->_paymentProcessor['user_name'] ?? '',
      'locationId' => $this->_paymentProcessor['signature'] ?? ($this->_paymentProcessor['password'] ?? ''),
      'isSandbox' => (bool) $isSandbox,
    ];

    // Add hidden field for the payment token
    if (!$form->elementExists('square_payment_token')) {
      $form->add('hidden', 'square_payment_token', '', ['id' => 'square_payment_token']);
    }


    // Square Web Payments SDK (loaded before our JS).
    CRM_Core_Region::instance('billing-block')->add([
      'scriptUrl' => $sdkUrl,
      'weight' => -1,
    ]);

    // Our integration JS (loaded last so CRM.squarePayment utilities are ready).
    CRM_Core_Region::instance('billing-block')->add([
      'scriptUrl' => E::url('js/square.js'),
      'weight' => 100,
    ]);

    // Publish settings to CRM.vars.orgUschessSquare (works for normal page load).
    CRM_Core_Resources::singleton()->addSetting(['orgUschessSquare' => $jsVars]);

    // Pass vars to Smarty so the template can emit an inline <script> fallback
    // for Drupal webforms where addSetting() responses may not be processed.
    $form->assign('squareJSVarsJson', json_encode($jsVars, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT));

    // Billing block HTML: card container + error element + inline JS fallback.
    CRM_Core_Region::instance('billing-block')->add([
      'template' => E::path('templates/CRM/Core/Payment/Square/Card.tpl'),
      'weight' => -1,
    ]);

    // Enable JS validation so submission only happens after fields are valid.
    $form->assign('isJsValidate', TRUE);
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
    return new SquareClient(
      token: $this->getAccessToken(),
      options: ['baseUrl' => $this->getApiBaseUrl()],
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

    return trim($this->_paymentProcessor['password'] ?? '');
  }

  /**
   * Get the Square Location ID from processor config.
   *
   * @return string
   *
   * @throws \CRM_Core_Exception
   */
  protected function getLocationId() {
    $loc = trim($this->_paymentProcessor['signature'] ?? '');
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
   * Build an idempotency key that is stable for retries of the same CiviCRM
   * operation, but distinct between processors and environments.
   */
  protected function idempotencyKey(string $operation, string $reference): string {
    $processorId = (string) ($this->_paymentProcessor['id'] ?? '0');
    $environment = $this->isTestMode() ? 'test' : 'live';
    return substr('civi-' . $operation . '-' . hash('sha256', implode(':', [
      $processorId,
      $environment,
      $reference,
    ])), 0, 45);
  }

  /** Resolve a contribution status without relying on installation-specific IDs. */
  protected function contributionStatusId(string $name): int {
    static $statuses = NULL;
    if ($statuses === NULL) {
      $statuses = \CRM_Contribute_PseudoConstant::contributionStatus();
    }
    $id = array_search($name, $statuses, TRUE);
    if ($id === FALSE) {
      throw new \CRM_Core_Exception("CiviCRM contribution status '{$name}' is unavailable.");
    }
    return (int) $id;
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
      // CRM_Core_Payment_SquareDebugLogger::log("Square Webhook: Missing signature key");
      return FALSE;
    }

    $provided = $headers['X-Square-Signature']
      ?? $headers['x-square-signature']
      ?? NULL;

    if (!$provided) {
      // CRM_Core_Payment_SquareDebugLogger::log("Square Webhook: Missing X-Square-Signature header");
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
    $missing = [];
    foreach ([
      'user_name' => 'Application ID',
      'password' => 'Access Token',
      'signature' => 'Location ID',
      'subject' => 'Webhook Signature Key',
    ] as $field => $label) {
      if (trim((string) ($this->_paymentProcessor[$field] ?? '')) === '') {
        $missing[] = $label;
      }
    }
    return $missing ? ts('Square configuration is missing: %1.', [1 => implode(', ', $missing)]) : NULL;
  }

  /**
   * Sync a Square payment (from webhook payload) into CiviCRM.
   *
   * @param array $payment
   *   Payment object from Square webhooks.
   */
  public function syncPaymentFromSquare(array $payment) {
    $paymentId = $payment['id'] ?? NULL;
    CRM_Core_Payment_SquareDebugLogger::log("Square syncPaymentFromSquare(): called from SquareIPN for payment_id={$paymentId}, status=" . ($payment['status'] ?? 'UNKNOWN') . ', order_id=' . ($payment['order_id'] ?? 'null'));
    if (!$paymentId) {
      CRM_Core_Payment_SquareDebugLogger::log('Square syncPaymentFromSquare(): missing payment ID, skipping.');
      return;
    }

    $status = $payment['status'] ?? 'UNKNOWN';
    $sourceType = $payment['source_type'] ?? NULL;

    // Determine amount/currency.
    $money = $payment['amount_money'] ?? NULL;
    $feeMoney = $payment['processing_fee'][0]['amount_money']['amount'] ?? NULL;
    $amount = $money && isset($money['amount']) ? ($money['amount'] / 100) : NULL;
    $feeAmount = $feeMoney !== NULL ? ($feeMoney / 100) : NULL;
    $currency = $money['currency'] ?? 'USD';
    $orderID = $payment['order_id'] ?? NULL;

    // 1. Try to find existing contribution using order id as invoice number, or by
    // trxn_id (Square payment ID). The initial payment of a new subscription is
    // charged directly via doPayment() and only ever gets trxn_id set (no
    // invoice_number/invoice_id), so it must also be matched on trxn_id — otherwise
    // every payment.updated webhook for it fails to find it, falls through to the
    // "create new contribution" branch below, and collides with CiviCRM's own
    // duplicate-transaction guard on trxn_id.
    $existingQuery = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('id')
      ->addWhere('is_test', 'IN', [TRUE, FALSE]);
    if ($orderID !== NULL) {
      $existingQuery->addClause('OR', ['invoice_number', '=', $orderID], ['trxn_id', '=', $paymentId]);
    }
    else {
      $existingQuery->addWhere('trxn_id', '=', $paymentId);
    }
    $existing = $existingQuery->execute()->first();

    if ($existing) {
      CRM_Core_Payment_SquareDebugLogger::log("Square syncPaymentFromSquare(): found existing contribution {$existing['id']} for payment {$paymentId}, updating.");
      $update = \Civi\Api4\Contribution::update(FALSE)
        ->addWhere('id', '=', $existing['id'])
        ->addValue('total_amount', $amount)
        ->addValue('currency', $currency)
        ->addValue('contribution_status_id', $this->mapPaymentStatus($status))
        ->addValue('payment_instrument_id', $this->mapPaymentInstrument($sourceType));
      if ($feeAmount !== NULL) {
        $update->addValue('fee_amount', $feeAmount);
      }
      $update->execute();
      CRM_Core_Payment_SquareDebugLogger::log("Square syncPaymentFromSquare(): Updated existing contribution {$existing['id']} for payment {$paymentId}.");
      return;
    }

    CRM_Core_Payment_SquareDebugLogger::log("Square syncPaymentFromSquare(): no existing contribution found for payment {$paymentId} (order_id={$orderID}), attempting to create a new one.");

    // If no contribution exists, try mapping by reference_id → contact or contribution.
    $referenceId = $payment['reference_id'] ?? NULL;
    $contactId = NULL;

    if ($referenceId && ctype_digit((string) $referenceId)) {
      $refRecurContribution = \Civi\Api4\ContributionRecur::get(FALSE)
        ->addSelect('id', 'contact_id', 'is_test', 'financial_type_id')
        ->addWhere('id', '=', (int) $referenceId)
        ->addWhere('is_test', 'IN', [TRUE, FALSE])
        ->execute()
        ->first();

      if ($refRecurContribution) {
        $contactId = (int) $refRecurContribution['contact_id'];
      }
    }

    if (!$contactId) {
      $contactId = $this->findContactIdForPayment($payment);
    }

    if (!$contactId) {
      CRM_Core_Payment_SquareDebugLogger::log("Square syncPaymentFromSquare(): cannot resolve contact for payment {$paymentId}, skipping create.");
      return;
    }

    // Default financial type is Donation (ID=1) unless better mapping is added later.
    $financialTypeId = 1;

    $applicationId = $payment['application_details']['application_id'] ?? '';
    $isTest = str_contains($applicationId, 'sandbox') ? 1 : 0;
    if (!empty($refRecurContribution)) {
      $isTest = $refRecurContribution['is_test'] ? 1 : 0;
      $financialTypeId = $refRecurContribution['financial_type_id'] ?? $financialTypeId;
    }

    $create = \Civi\Api4\Contribution::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('financial_type_id', $financialTypeId)
      ->addValue('total_amount', $amount)
      ->addValue('currency', $currency)
      ->addValue('contribution_status_id', $this->mapPaymentStatus($status))
      ->addValue('payment_instrument_id', $this->mapPaymentInstrument($sourceType))
      ->addValue('trxn_id', $paymentId)
      ->addValue('is_test', $isTest)
      ->addValue('source', 'Square Payment (Webhook)');
    if ($feeAmount !== NULL) {
      $create->addValue('fee_amount', $feeAmount);
    }
    if ($orderID !== NULL) {
      $create->addValue('invoice_number', $orderID);
    }
    $newContribution = $create->execute()->first();
    CRM_Core_Payment_SquareDebugLogger::log("Square syncPaymentFromSquare(): Created new contribution {$newContribution['id']} for payment {$paymentId}, contact {$contactId}.");
  }

  /**
   * Sync a Square refund into CiviCRM.
   *
   * @param array $refund
   */
  public function syncRefundFromSquare(array $refund) {
    $paymentId = $refund['payment_id'] ?? NULL;
    $refundId = $refund['id'] ?? NULL;
    CRM_Core_Payment_SquareDebugLogger::log("Square syncRefundFromSquare(): called from SquareIPN for refund_id={$refundId}, payment_id={$paymentId}.");
    if (!$paymentId || !$refundId) {
      CRM_Core_Payment_SquareDebugLogger::log('Square syncRefundFromSquare(): missing payment_id or refund_id, skipping.');
      return;
    }

    // Find contribution by trxn_id.
    $contribution = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('id')
      ->addWhere('trxn_id', '=', $paymentId)
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->execute()
      ->first();

    if (!$contribution) {
      CRM_Core_Payment_SquareDebugLogger::log("Square syncRefundFromSquare(): no contribution found for payment {$paymentId}, skipping.");
      return;
    }

    CRM_Core_Payment_SquareDebugLogger::log("Square syncRefundFromSquare(): found contribution {$contribution['id']} for payment {$paymentId}, marking Refunded.");

    \Civi\Api4\Contribution::update(FALSE)
      ->addWhere('id', '=', $contribution['id'])
      ->addValue('contribution_status_id', 'Refunded')
      ->addValue('refund_trxn_id', $refundId)
      ->execute();

    CRM_Core_Payment_SquareDebugLogger::log("Square syncRefundFromSquare(): Updated contribution {$contribution['id']} as Refunded for refund {$refundId}.");
  }

  /**
   * Sync a Square subscription update into CiviCRM.
   *
   * @param array $subscription
   */
  public function syncSubscriptionFromWebhook(array $subscription) {
    $id = $subscription['id'] ?? NULL;
    if (!$id) {
      // CRM_Core_Payment_SquareDebugLogger::log('Square syncSubscriptionFromWebhook(): missing subscription ID.');
      return;
    }

    $status = $subscription['status'] ?? 'UNKNOWN';
    $amount = $this->extractSubscriptionAmount($subscription);

    $recur = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addSelect('id', 'amount', 'contribution_status_id')
      ->addWhere('processor_id', '=', $id)
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->execute()
      ->first();

    if (!$recur) {
      CRM_Core_Payment_SquareDebugLogger::log("Square syncSubscriptionFromWebhook(): no matching recur for {$id}");
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
      $q = \Civi\Api4\ContributionRecur::update(FALSE)
        ->addWhere('id', '=', $recur['id']);
      foreach ($updates as $field => $value) {
        $q->addValue($field, $value);
      }
      $q->execute();
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
      // CRM_Core_Payment_SquareDebugLogger::log('Square syncInvoiceFromSquare(): missing invoice ID.');
      return;
    }

    $subscriptionId = $invoice['subscription_id'] ?? NULL;
    if (!$subscriptionId) {
      // CRM_Core_Payment_SquareDebugLogger::log("Square syncInvoiceFromSquare(): invoice {$invoiceId} has no subscription_id.");
      return;
    }

    // Find recur.
    $recur = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addSelect('id', 'contact_id', 'financial_type_id', 'currency', 'is_test')
      ->addWhere('processor_id', '=', $subscriptionId)
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->execute()
      ->first();

    if (!$recur) {
      CRM_Core_Payment_SquareDebugLogger::log("Square syncInvoiceFromSquare(): no recur for subscription {$subscriptionId}");
      return;
    }

    // Prevent duplicates.
    $existing = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('id')
      ->addWhere('invoice_id', '=', $invoiceId)
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
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
      ->addValue('is_test', $recur['is_test'])
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
    CRM_Core_Payment_SquareDebugLogger::log("Square syncSubscriptionFromSquare(): called from SquareIPN for subscription_id={$squareSubscriptionId}.");
    if (empty($squareSubscriptionId)) {
      throw new CRM_Core_Exception('Missing Square subscription ID for sync.');
    }

    // 1. Look up the subscription in Square
    $response = $this->callSquare(fn (SquareClient $client) => $client->subscriptions->get(
      new GetSubscriptionsRequest(['subscriptionId' => $squareSubscriptionId])
    ));
    $sub = $response->getSubscription();

    if (empty($sub)) {
      throw new CRM_Core_Exception("Square subscription {$squareSubscriptionId} not found.");
    }

    $status = $sub->getStatus() ?? 'UNKNOWN';
    // extractSubscriptionAmount() expects the REST JSON shape; jsonSerialize()
    // gives us that from the SDK object without duplicating the extraction logic.
    $amount = $this->extractSubscriptionAmount($sub->jsonSerialize());

    // 2. Find local CiviCRM recurring contribution
    $recur = ContributionRecur::get(FALSE)
      ->addWhere('processor_id', '=', $squareSubscriptionId)
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->addSelect('id', 'amount', 'currency', 'contribution_status_id')
      ->execute()
      ->first();

    if (empty($recur)) {
      // No such recurring record exists — log and stop
      CRM_Core_Payment_SquareDebugLogger::log("Square sync: No local contribution_recur record found for subscription {$squareSubscriptionId}");
      return;
    }

    $recurId = (int) $recur['id'];
    CRM_Core_Payment_SquareDebugLogger::log("Square syncSubscriptionFromSquare(): found contribution_recur {$recurId} for subscription {$squareSubscriptionId} (current status {$recur['contribution_status_id']}, square status {$status}).");

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
      CRM_Core_Payment_SquareDebugLogger::log("Square sync: Status change for subscription {$squareSubscriptionId} mapped to contribution_recur {$recurId} status {$mappedStatus}");
    }

    // 6. Apply updates
    if (!empty($updates)) {
      $q = ContributionRecur::update(FALSE)
        ->addWhere('id', '=', $recurId);
      foreach ($updates as $field => $value) {
        $q->addValue($field, $value);
      }
      $q->execute();

      CRM_Core_Payment_SquareDebugLogger::log("Square sync: Updated recurring contribution {$recurId} from subscription {$squareSubscriptionId}");
    }
    else {
      CRM_Core_Payment_SquareDebugLogger::log("Square syncSubscriptionFromSquare(): contribution_recur {$recurId} already up to date for subscription {$squareSubscriptionId}, no changes made.");
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

    // CiviCRM contribution_status_id values used by this extension for
    // contribution_recur: 1 = Completed, 2 = Pending, 3 = Cancelled,
    // 4 = Failed, 5 = In Progress.
    switch ($squareStatus) {
      case 'ACTIVE':
        return 5; // In Progress

      case 'CANCELED':
      case 'DEACTIVATED':
        return 3; // Cancelled

      case 'SUSPENDED':
        return 4; // Failed / On Hold

      case 'PENDING':
        // Square subscriptions report PENDING until their start_date is
        // reached (we deliberately set start_date to tomorrow — see
        // doRecurPayment()), even when we've already charged and recorded
        // a successful initial payment and marked the recur In Progress.
        // Don't let this calendar-based status downgrade a recurring
        // record that already has a completed payment back to Pending.
        return 2;
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
        ->addWhere('is_test', 'IN', [TRUE, FALSE])
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
    CRM_Core_Payment_SquareDebugLogger::log('Square handleInvoicePaymentCreated(): called from SquareIPN for invoice.payment_made.');
    if (empty($payload['data']['object']['invoice'])) {
      CRM_Core_Payment_SquareDebugLogger::log('Square webhook: invoice.payment_made missing invoice object.');
      return;
    }

    $invoice = $payload['data']['object']['invoice'];
    $invoiceId = $invoice['id'] ?? NULL;
    $subscriptionId = $invoice['subscription_id'] ?? NULL;

    if (!$invoiceId) {
      CRM_Core_Payment_SquareDebugLogger::log('Square webhook: invoice missing ID.');
      return;
    }

    // Fix 1: guard against non-subscription invoices (same as syncInvoiceFromSquare).
    if (!$subscriptionId) {
      CRM_Core_Payment_SquareDebugLogger::log("Square webhook: invoice {$invoiceId} has no subscription_id, skipping.");
      return;
    }

    // Load subscription payment info
    $total = $invoice['payment_requests'][0]['computed_amount_money']['amount'] ?? NULL;
    $currency = $invoice['payment_requests'][0]['computed_amount_money']['currency'] ?? 'USD';

    if ($total === NULL) {
      CRM_Core_Payment_SquareDebugLogger::log("Square webhook: invoice {$invoiceId} missing payment amount.");
      return;
    }

    $amount = ((float) $total) / 100;

    // Fix 4: use the invoice updated_at as receive_date rather than defaulting to today.
    $receiveDate = !empty($invoice['updated_at'])
      ? date('Y-m-d H:i:s', strtotime($invoice['updated_at']))
      : date('Y-m-d H:i:s');

    // Find matching Civi recurring record
    $recur = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addWhere('processor_id', '=', $subscriptionId)
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->addSelect('id', 'contact_id', 'is_test', 'payment_instrument_id')
      ->execute()
      ->first();

    if (empty($recur)) {
      CRM_Core_Payment_SquareDebugLogger::log("Square webhook: No matching contribution_recur for subscription {$subscriptionId}.");
      return;
    }

    $contactId = (int) $recur['contact_id'];
    $recurId = (int) $recur['id'];
    $paymentInstrumentId = $recur['payment_instrument_id'] ?? 1;

    // Check for duplicate contribution by invoice ID
    $existing = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('id', 'contribution_status_id', 'contribution_recur_id')
      ->addWhere('invoice_id', '=', $invoiceId)
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->execute()
      ->first();

    CRM_Core_Payment_SquareDebugLogger::log($existing
      ? "Square webhook: found existing contribution {$existing['id']} (status {$existing['contribution_status_id']}) for invoice {$invoiceId}."
      : "Square webhook: no existing contribution found for invoice {$invoiceId}, will create a new one.");

    // check record exist and status completed and recurring is linked
    if (!empty($existing)) {
      if ($existing['contribution_status_id'] == 1 && !empty($existing['contribution_recur_id'])) {
        CRM_Core_Payment_SquareDebugLogger::log("Square webhook: Invoice {$invoiceId} already processed as contribution {$existing['id']}.");
        return;
      }
      else {
        CRM_Core_Payment_SquareDebugLogger::log("Square webhook: Updating existing contribution {$existing['id']} for invoice {$invoiceId} (subscription {$subscriptionId}).");
        \Civi\Api4\Contribution::update(FALSE)
          ->addWhere('id', '=', $existing['id'])
          ->addValue('total_amount', $amount)
          ->addValue('currency', $currency)
          ->addValue('receive_date', $receiveDate)
          ->addValue('contribution_status_id', 1)
          ->addValue('contribution_recur_id', $recurId)
          ->addValue('payment_instrument_id', $paymentInstrumentId)
          ->execute();
        try {
          CRM_Contribute_BAO_ContributionRecur::updateOnNewPayment($recurId, 'Completed');
        }
        catch (Exception $e) {
          Civi::log()->error("Square webhook: Failed to update contribution recur {$recurId} after processing invoice {$invoiceId}: " . $e->getMessage());
        }
        return;
      }
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
      ->addValue('currency', $currency)
      ->addValue('receive_date', $receiveDate)
      ->addValue('contribution_recur_id', $recurId)
      ->addValue('contribution_status_id', 1)
      ->addValue('payment_instrument_id', $paymentInstrumentId)
      ->addValue('invoice_id', $invoiceId)
      ->addValue('is_test', $recur['is_test'])
      ->addValue('source', 'Square Recurring Payment')
      ->execute();

    try {
      CRM_Contribute_BAO_ContributionRecur::updateOnNewPayment($recurId, 'Completed');
    }
    catch (Exception $e) {
      Civi::log()->error("Square webhook: Failed to update contribution recur {$recurId} after processing invoice {$invoiceId}: " . $e->getMessage());
    }
    CRM_Core_Payment_SquareDebugLogger::log("Square webhook: Created contribution for invoice {$invoiceId} (subscription {$subscriptionId}).");
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
      // CRM_Core_Payment_SquareDebugLogger::log('Square webhook: subscription.canceled missing subscription ID.');
      return;
    }

    $subscriptionId = $payload['data']['object']['subscription']['id'];

    // Find associated recurring contribution.
    $recur = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addWhere('processor_id', '=', $subscriptionId)
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->addSelect('id')
      ->execute()
      ->first();

    if (empty($recur)) {
      CRM_Core_Payment_SquareDebugLogger::log("Square webhook: No matching contribution_recur found for cancelled subscription {$subscriptionId}.");
      return;
    }

    $recurId = (int) $recur['id'];

    // Update recurring record to Cancelled (3).
    \Civi\Api4\ContributionRecur::update(FALSE)
      ->addWhere('id', '=', $recurId)
      ->addValue('contribution_status_id', 3)
      ->execute();

    CRM_Core_Payment_SquareDebugLogger::log("Square webhook: Marked recurring contribution {$recurId} as Cancelled for subscription {$subscriptionId}.");
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
    if ($amount === NULL || $amount === '') {
      throw new \CRM_Core_Exception('Missing contribution amount.');
    }

    if ((float) $amount === 0.0) {
      return $params;
    }

    $amountCents = (int) round(((float) $amount) * 100);
    $currency = $params['currency'] ?? $params['currencyID'] ?? 'USD';

    // 3. Idempotency key.
    $idempotencyKey = $this->idempotencyKey('payment', (string) ($params['invoiceID'] ?? $params['invoice_id'] ?? $params['contributionID'] ?? $params['contribution_id'] ?? 'unknown'));

    // 4. Build payload for CreatePayment.
    $requestValues = [
      'idempotencyKey' => $idempotencyKey,
      'sourceId' => $token,
      'amountMoney' => new Money(['amount' => $amountCents, 'currency' => $currency]),
      'locationId' => $this->getLocationId(),
    ];

    // Add customer ID if available (optional for one-off payments)
    $contactId = $params['contactID'] ?? $params['contact_id'] ?? NULL;
    if ($contactId) {
      $customerId = $this->getSquareCustomerId($contactId);
      if ($customerId) {
        $requestValues['customerId'] = $customerId;
      }
    }

    // Optional reference
    if (!empty($params['invoiceID'])) {
      $requestValues['referenceId'] = (string) $params['invoiceID'];
    }

    // 5. Send request.
    $response = $this->callSquare(fn (SquareClient $client) => $client->payments->create(new CreatePaymentRequest($requestValues)));
    $payment = $response->getPayment();
    if (empty($payment) || empty($payment->getId())) {
      throw new \CRM_Core_Exception('Square payment failed: Missing payment ID.');
    }

    $trxnId = $payment->getId();

    // 6. Set required CiviCRM transaction fields.
    $params['trxn_id'] = $trxnId;
    $params['payment_status_id'] = $this->contributionStatusId('Completed');
    $params['contribution_status_id'] = $this->contributionStatusId('Completed');

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
    // Determine amount and currency.
    $amount = $params['amount'] ?? $params['total_amount'] ?? NULL;
    if ($amount === NULL || $amount === '') {
      throw new \CRM_Core_Exception('Missing contribution amount.');
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
      CRM_Core_Payment_SquareDebugLogger::log("Square doRecurPayment: Found existing Square customer ID {$customerId} for CiviCRM recur ID {$recurId}");
      $this->updateSquareCustomerDetails($customerId, $params);
    }
    else {
      throw new CRM_Core_Exception("Failed to find or create Square customer for CiviCRM recur ID {$recurId}");
    }

    // 4. Fetch the Square card ID via the PaymentToken that
    // ensureSquareCustomer() -> createCardOnFile() just attached to this
    // recurring contribution. Card nonces are single-use, so we must not
    // redeem $token a second time here — ensureSquareCustomer() already
    // did that, and always redeems a fresh nonce into a brand new token,
    // so this is always the right one for this specific recur ID.
    $recurForToken = ContributionRecur::get(FALSE)
      ->addWhere('id', '=', $recurId)
      ->addSelect('payment_token_id')
      ->execute()
      ->first();
    $paymentToken = empty($recurForToken['payment_token_id']) ? NULL : PaymentToken::get(FALSE)
      ->addWhere('id', '=', $recurForToken['payment_token_id'])
      ->addSelect('token')
      ->execute()
      ->first();
    $cardId = $paymentToken['token'] ?? NULL;
    if (empty($cardId)) {
      throw new CRM_Core_Exception("Failed to attach a Square card on file for CiviCRM recur ID {$recurId}.");
    }
    CRM_Core_Payment_SquareDebugLogger::log("Square doRecurPayment: Using card ID {$cardId} for customer ID {$customerId} and CiviCRM recur ID {$recurId}");

    // 5. Determine plan ID
    $planVariationId = $this->getPlanVariationIdForParams($params);
    CRM_Core_Payment_SquareDebugLogger::log("Square doRecurPayment: Using plan variation ID {$planVariationId} for CiviCRM recur ID {$recurId}");
    // 6. Determine start date (Square-safe)
    // Square rejects same-day start if UTC has rolled to next day.
    // To avoid timezone hell: always start tomorrow.
    $startDate = (new DateTime('tomorrow'))->format('Y-m-d');

    // 7. Generate idempotency key tied to the recurring record so re-posts don't duplicate.
    $idempotencyKey = $this->idempotencyKey('subscription', (string) $recurId);
    $source = [
      'contact_id' => (string) ($params['contactID'] ?? $params['contact_id'] ?? ''),
      'recur_id' => (string) $recurId,
    ];
    // implode key and value of source
    // convert array to string with maintain key value
    $note = json_encode($source, JSON_UNESCAPED_SLASHES);


    // 8. Build subscription payload
    $createSubscriptionRequest = new CreateSubscriptionRequest([
      'idempotencyKey' => $idempotencyKey,
      'locationId' => $this->getLocationId(),
      'planVariationId' => $planVariationId,
      'customerId' => $customerId,
      'cardId' => $cardId,
      'startDate' => $startDate,
      'source' => new SubscriptionSource(['name' => $note]),
    ]);

    // 9. Send subscription create request
    $response = $this->callSquare(fn (SquareClient $client) => $client->subscriptions->create($createSubscriptionRequest));
    $subscription = $response->getSubscription();

    if (empty($subscription) || empty($subscription->getId())) {
      throw new CRM_Core_Exception('Failed to create Square subscription.');
    }
    $subscriptionId = $subscription->getId();
    CRM_Core_Payment_SquareDebugLogger::log('Square subscription created: ' . json_encode([
        'subscription_id' => $subscriptionId,
        'recur_id' => $recurId,
      ]));

    // 10. Handle initial payment (if needed)
    // Square subscriptions don't charge on creation, they start on start_date.
    // If the user wants to charge immediately, we need to make a one-time payment.
    $initialPaymentNeeded = !empty($params['send_receipt']) || !empty($params['is_recur']);

    if ($initialPaymentNeeded) {
      $amountCents = (int) round(((float) $amount) * 100);
      $currency = $params['currency'] ?? $params['currencyID'] ?? 'USD';
      try {
        // Make an initial one-time payment for the first billing cycle.
        // Use a distinct operation name (rather than prefixing $idempotencyKey,
        // which is already truncated to Square's 45-char max) so this stays <= 45 chars.
        $initialPaymentIdempotencyKey = $this->idempotencyKey('subscription-init', (string) $recurId);
        $initialPaymentRequest = new CreatePaymentRequest([
          'idempotencyKey' => $initialPaymentIdempotencyKey,
          'sourceId' => $cardId,
          'amountMoney' => new Money(['amount' => $amountCents, 'currency' => $currency]),
          'locationId' => $this->getLocationId(),
          'customerId' => $customerId,
          'referenceId' => (string) $recurId,
        ]);

        $paymentResponse = $this->callSquare(fn (SquareClient $client) => $client->payments->create($initialPaymentRequest));
        $initialPayment = $paymentResponse->getPayment();
        if (!empty($initialPayment) && !empty($initialPayment->getId())) {
          $transactionId = $initialPayment->getId();
          $status = $initialPayment->getStatus() ?? 'UNKNOWN';
          ContributionRecur::update(FALSE)
            ->addWhere('id', '=', $recurId)
            ->addValue('processor_id', $subscriptionId)
            ->addValue('trxn_id', $subscriptionId)
            ->addValue('contribution_status_id', 5) // In Progress
            ->execute();
          CRM_Core_Payment_SquareDebugLogger::log("Square initial payment for subscription {$subscriptionId} created with transaction ID {$transactionId} and status {$status}");
          return [
            'payment_status_id' => 1,               // Completed
            'contribution_status_id' => 1,          // Completed
            'trxn_id' => $transactionId,
            'subscription_id' => $subscriptionId,
          ];
        }
      }
      catch (Exception $e) {
        // Log but don't fail - subscription is already created
        CRM_Core_Payment_SquareDebugLogger::log('Square initial payment failed (non-fatal): ' . $e->getMessage());
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

    $refundRequest = new RefundPaymentRequest([
      'idempotencyKey' => $this->idempotencyKey('refund', (string) $trxnId . ':' . $amountInCents),
      'paymentId' => $trxnId,
      'amountMoney' => new Money(['amount' => $amountInCents, 'currency' => $currency]),
    ]);

    $response = $this->createRefund($refundRequest);
    $refund = $response->getRefund();

    if (empty($refund) || empty($refund->getId())) {
      $msg = 'Square refund failed: unexpected response.';
      throw new CRM_Core_Exception($msg);
    }

    $status = $refund->getStatus() ?? 'UNKNOWN';

    if (!in_array($status, ['PENDING', 'COMPLETED', 'APPROVED'], TRUE)) {
      $msg = "Square refund not completed. Status: {$status}";
      throw new CRM_Core_Exception($msg);
    }

    return [
      'refund_status' => $status,
      'refund_trxn_id' => $refund->getId(),
    ];
  }

  /**
   * Thin seam around the Square Refunds API so tests can stub a refund
   * response without a network call.
   *
   * @param \Square\Refunds\Requests\RefundPaymentRequest $request
   *
   * @return \Square\Types\RefundPaymentResponse
   *
   * @throws \CRM_Core_Exception
   */
  protected function createRefund(RefundPaymentRequest $request) {
    return $this->callSquare(fn (SquareClient $client) => $client->refunds->refundPayment($request));
  }

  /**
   * Invoke a call against the official Square PHP SDK client, translating SDK
   * exceptions into CRM_Core_Exception so callers only need to catch one
   * exception type.
   *
   * @param callable(\Square\SquareClient): mixed $call
   *
   * @return mixed
   *   Whatever $call returns (typically a Square SDK response object).
   *
   * @throws \CRM_Core_Exception
   */
  protected function callSquare(callable $call) {
    try {
      return $call($this->buildSquareClient());
    }
    catch (SquareApiException $e) {
      throw $this->squareApiError($e);
    }
    catch (SquareException $e) {
      throw new CRM_Core_Exception('Square API request failed: ' . $e->getMessage());
    }
  }

  /**
   * Build a CRM_Core_Exception from a Square SDK API exception, preserving
   * the "HTTP <code>. <CODE>: <detail>" message shape that callers and log
   * messages throughout this class expect.
   *
   * @param \Square\Exceptions\SquareApiException $e
   *
   * @return \CRM_Core_Exception
   */
  protected function squareApiError(SquareApiException $e): CRM_Core_Exception {
    $errorDetails = [];
    foreach ($e->getErrors() as $err) {
      $code = $err->getCode() ?: 'UNKNOWN';
      $detail = $err->getDetail() ?? '';
      $errorDetails[] = "{$code}: {$detail}";
    }
    $msg = "Square API returned HTTP {$e->getStatusCode()}.";
    if ($errorDetails) {
      $msg .= ' ' . implode(' | ', $errorDetails);
    }
    return new CRM_Core_Exception($msg);
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

    $response = $this->callSquare(fn (SquareClient $client) => $client->customers->search(new SearchCustomersRequest([
      'query' => new CustomerQuery([
        'filter' => new CustomerFilter([
          'emailAddress' => new CustomerTextFilter(['exact' => $email]),
        ]),
      ]),
    ])));

    $customers = $response->getCustomers();
    if (!empty($customers[0])) {
      return $customers[0]->getId();
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

    $response = $this->callSquare(fn (SquareClient $client) => $client->customers->get(
      new GetCustomersRequest(['customerId' => $customerID])
    ));
    $customer = $response->getCustomer();
    if (!empty($customer) && !empty($customer->getId())) {
      return $customer->getId();
    }

    return NULL;
  }

  /**
   * @param $customerID
   * @param $params
   * @return void
   * @throws CRM_Core_Exception
   */
  protected function updateSquareCustomerDetails($customerID, $params) {
    $firstName = $params['first_name'] ?? NULL;
    $lastName = $params['last_name'] ?? NULL;
    $email = $params['email'] ?? $params['email-5'] ?? NULL;
    $contactID = $params['contactID'] ?? $params['contact_id'] ?? NULL;

    $requestValues = ['customerId' => $customerID];
    if (!empty($firstName)) {
      $requestValues['givenName'] = $firstName;
    }
    if (!empty($lastName)) {
      $requestValues['familyName'] = $lastName;
    }
    if (!empty($email)) {
      $requestValues['emailAddress'] = $email;
    }
    if (!empty($contactID)) {
      $requestValues['referenceId'] = (string) $contactID;
    }

    $this->callSquare(fn (SquareClient $client) => $client->customers->update(new UpdateCustomerRequest($requestValues)));
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
    if ($customerId = $this->getSquareCustomerId($contactID)) {
      // CRM_Core_Payment_SquareDebugLogger::log('Square customer already exists for contact ' . $contactID . ': ' . $customerId);
      // If a fresh card token/nonce was submitted (e.g. a returning donor
      // entering a new card), attach and store it. Card nonces are
      // single-use, so this must be the only place that redeems it.
      $cardNonce = $params['square_payment_token']
        ?? $params['payment_token']
        ?? $params['token']
        ?? NULL;
      if (!empty($cardNonce)) {
        $this->createCardOnFile($customerId, $cardNonce, $params, $contactID);
      }
      return $customerId;
    }
    // Migration logic: check whether this contact already exists in Square based on reference_id.
    // If Square already has a customer with reference_id == Civi contact ID, we adopt that one.
    try {
      $lookupResponse = $this->callSquare(fn (SquareClient $client) => $client->customers->search(new SearchCustomersRequest([
        'query' => new CustomerQuery([
          'filter' => new CustomerFilter([
            'referenceId' => new CustomerTextFilter(['exact' => (string) $contactID]),
          ]),
        ]),
      ])));
      $migratedCustomers = $lookupResponse->getCustomers();
      if (!empty($migratedCustomers[0])) {
        $migratedCustomerId = $migratedCustomers[0]->getId();
        // Check if another Civi contact already mapped to this customerId
        // (for this payment processor).
        $existingContactId = CRM_Core_DAO::singleValueQuery(
          'SELECT contact_id FROM square_customer_map WHERE square_customer_id = %1 AND payment_processor_id = %2',
          [1 => [$migratedCustomerId, 'String'], 2 => [(int) ($this->_paymentProcessor['id'] ?? 0), 'Integer']]
        );

        if (!empty($existingContactId) && (int) $existingContactId !== $contactID) {
          throw new CRM_Core_Exception(
            "Square customer {$migratedCustomerId} already mapped to a different CiviCRM contact ({$existingContactId})."
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
          $this->createCardOnFile($migratedCustomerId, $cardNonce, $params, $contactID);
        }

        return $migratedCustomerId;
      }
    }
    catch (\Exception $e) {
      // CRM_Core_Payment_SquareDebugLogger::log('Square migration lookup error: ' . $e->getMessage());
    }
    $existingCustomerId = $this->getSquareCustomerId($contactID);
    if (!empty($existingCustomerId)) {
      // If card token/nonce is present, create and store card_id
      $cardNonce = $params['square_payment_token']
        ?? $params['payment_token']
        ?? $params['token']
        ?? NULL;
      if (!empty($cardNonce)) {
        $this->createCardOnFile($existingCustomerId, $cardNonce, $params, $contactID);
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
      // Check if mapped to another contact (for this payment processor).
      $existingContactId = CRM_Core_DAO::singleValueQuery(
        'SELECT contact_id FROM square_customer_map WHERE square_customer_id = %1 AND payment_processor_id = %2',
        [1 => [$squareCustomerByEmail, 'String'], 2 => [(int) ($this->_paymentProcessor['id'] ?? 0), 'Integer']]
      );

      if (!empty($existingContactId) && (int) $existingContactId !== $contactID) {
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
        $this->createCardOnFile($squareCustomerByEmail, $cardNonce, $params, $contactID);
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

    $createResponse = $this->callSquare(fn (SquareClient $client) => $client->customers->create(new CreateCustomerRequest([
      'givenName' => $firstName,
      'familyName' => $lastName,
      'emailAddress' => $email,
      'referenceId' => (string) $contactID,
    ])));

    $newCustomer = $createResponse->getCustomer();
    if (empty($newCustomer) || empty($newCustomer->getId())) {
      throw new CRM_Core_Exception('Failed to create Square customer.');
    }

    $customerId = $newCustomer->getId();

    // 3. Persist the customer ID mapping for this contact + processor.
    $this->saveSquareCustomerId($contactID, $customerId);

    // If card token/nonce is present, create and store card_id
    $cardNonce = $params['square_payment_token']
      ?? $params['payment_token']
      ?? $params['token']
      ?? NULL;
    if (!empty($cardNonce)) {
      $this->createCardOnFile($customerId, $cardNonce, $params, $contactID);
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
   *   CiviCRM contact ID (optional, but required to record a PaymentToken).
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

    $cardValues = ['customerId' => $customerId];

    // Add billing address if available
    if (!empty($params['billing_address'])) {
      $cardValues['billingAddress'] = new Address([
        'addressLine1' => $params['billing_address']['street_address'] ?? NULL,
        'addressLine2' => $params['billing_address']['street_address_2'] ?? NULL,
        'locality' => $params['billing_address']['city'] ?? NULL,
        'administrativeDistrictLevel1' => $params['billing_address']['state'] ?? NULL,
        'postalCode' => $params['billing_address']['postal_code'] ?? NULL,
        'country' => $this->mapCountryCode($params['billing_address']['country'] ?? 'US'),
      ]);
    }

    $requestValues = [
      'idempotencyKey' => uniqid('square_card_', TRUE),
      'sourceId' => $cardNonce,
      'card' => new Card($cardValues),
    ];
    // Square requires verification_token for AVS/SCA under certain conditions.
    if (!empty($params['verification_token'])) {
      $requestValues['verificationToken'] = $params['verification_token'];
    }

    try {
      $response = $this->buildSquareClient()->cards->create(new CreateCardRequest($requestValues));
    }
    catch (SquareApiException $e) {
      // Translate structured Square card errors into a human-friendly message.
      throw new CRM_Core_Exception($this->translateSquareCardError($e->getErrors()));
    }
    catch (SquareException $e) {
      throw new CRM_Core_Exception('Square API request failed: ' . $e->getMessage());
    }

    $card = $response->getCard();
    if (empty($card) || empty($card->getId())) {
      throw new CRM_Core_Exception('Failed to create card on file with Square.');
    }

    $cardId = $card->getId();

    // Record this card as a CiviCRM PaymentToken (instead of a custom
    // field) so multiple cards per contact are properly distinguished, and
    // link it to the recurring contribution it was created for, if any.
    if (!empty($contactId)) {
      $expiryDate = NULL;
      if ($card->getExpYear() && $card->getExpMonth()) {
        $expiryDate = date('Y-m-t', mktime(0, 0, 0, (int) $card->getExpMonth(), 1, (int) $card->getExpYear()));
      }

      $tokenCreate = PaymentToken::create(FALSE)
        ->addValue('contact_id', (int) $contactId)
        ->addValue('payment_processor_id', (int) ($this->_paymentProcessor['id'] ?? 0))
        ->addValue('token', $cardId)
        ->addValue('masked_account_number', $card->getLast4());
      if ($expiryDate) {
        $tokenCreate->addValue('expiry_date', $expiryDate);
      }
      if (!empty($params['first_name'])) {
        $tokenCreate->addValue('billing_first_name', $params['first_name']);
      }
      if (!empty($params['last_name'])) {
        $tokenCreate->addValue('billing_last_name', $params['last_name']);
      }
      $email = $params['email'] ?? $params['email-5'] ?? NULL;
      if (!empty($email)) {
        $tokenCreate->addValue('email', $email);
      }
      $paymentToken = $tokenCreate->execute()->first();

      if (!empty($paymentToken['id']) && !empty($params['contributionRecurID'])) {
        ContributionRecur::update(FALSE)
          ->addWhere('id', '=', (int) $params['contributionRecurID'])
          ->addValue('payment_token_id', $paymentToken['id'])
          ->execute();
      }
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
   * @param \Square\Types\Error[] $errors
   * @return string
   */
  protected function translateSquareCardError(array $errors) {
    $messages = [];

    foreach ($errors as $err) {
      $code = $err->getCode() ?? '';
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
          if (!empty($err->getDetail())) {
            $messages[] = $err->getDetail();
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
    // CRM_Core_Payment_SquareDebugLogger::log("Square plan ID for {$entity}: {$planId}");
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
   * Cancel a recurring contribution at Square.
   *
   * Overrides CRM_Core_Payment::doCancelRecurring() so CiviCRM core calls this
   * directly and never falls back to the legacy cancelSubscription() path (which
   * used an incompatible two-argument signature causing a TypeError).
   *
   * @param \Civi\Payment\PropertyBag $propertyBag
   *
   * @return array
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function doCancelRecurring(PropertyBag $propertyBag): array {
    if (!$propertyBag->has('isNotifyProcessorOnCancelRecur')) {
      $propertyBag->setIsNotifyProcessorOnCancelRecur(TRUE);
    }

    if (!$propertyBag->getIsNotifyProcessorOnCancelRecur()) {
      return ['message' => E::ts('Successfully cancelled the subscription in CiviCRM ONLY.')];
    }

    if (!$propertyBag->has('recurProcessorID')) {
      $errorMessage = E::ts('The recurring contribution cannot be cancelled (no Square subscription ID found).');
      \Civi::log('square')->error($errorMessage);
      throw new PaymentProcessorException($errorMessage);
    }

    try {
      $this->cancelSquareSubscription($propertyBag->getRecurProcessorID());
    }
    catch (PaymentProcessorException $e) {
      throw $e;
    }
    catch (\Exception $e) {
      $errorMessage = E::ts('Failed to cancel the Square subscription: ') . $e->getMessage();
      \Civi::log('square')->error($errorMessage);
      throw new PaymentProcessorException($errorMessage, 0, $e);
    }

    return ['message' => E::ts('Successfully cancelled the Square subscription.')];
  }

  /**
   * Cancel a Square subscription by its processor ID.
   *
   * Low-level helper used by doCancelRecurring() and by the civicrm_post hook.
   * Makes the Square API call directly without PropertyBag logic.
   *
   * @param string $subscriptionId Square subscription ID (e.g. "SUB_xxx").
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function cancelSquareSubscription(string $subscriptionId): void {
    if (empty($subscriptionId)) {
      throw new PaymentProcessorException(E::ts('Cannot cancel Square subscription: empty subscription ID.'));
    }

    $this->callSquare(fn (SquareClient $client) => $client->subscriptions->cancel(
      new CancelSubscriptionsRequest(['subscriptionId' => $subscriptionId])
    ));
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

    $this->callSquare(fn (SquareClient $client) => $client->subscriptions->update(new UpdateSubscriptionRequest([
      'subscriptionId' => $subscriptionId,
      'subscription' => new Subscription([
        'priceOverrideMoney' => new Money(['amount' => $amountCents, 'currency' => $currency]),
      ]),
    ])));
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
    $this->callSquare(fn (SquareClient $client) => $client->subscriptions->update(new UpdateSubscriptionRequest([
      'subscriptionId' => $subscriptionId,
      'subscription' => new Subscription([
        'startDate' => $nextBillingDate,
      ]),
    ])));
  }

  /**
   * Update the cadence (frequency) of a subscription.
   *
   * @param string $subscriptionId
   * @param string $newPlanId
   *   Square plan *variation* ID — the SDK's Subscription resource only has
   *   a planVariationId field (no legacy plan_id equivalent).
   *
   * @throws \CRM_Core_Exception
   */
  public function updateSubscriptionPlan($subscriptionId, $newPlanId) {
    $this->callSquare(fn (SquareClient $client) => $client->subscriptions->update(new UpdateSubscriptionRequest([
      'subscriptionId' => $subscriptionId,
      'subscription' => new Subscription([
        'planVariationId' => $newPlanId,
      ]),
    ])));
  }

  /**
   * Get the Square Customer ID stored for this contact, scoped to this
   * payment processor (live and sandbox Square accounts have distinct
   * customer records, so the mapping is per (contact, processor)).
   *
   * Stored in the extension-owned `square_customer_map` table — see
   * CRM_Square_Upgrader.
   *
   * @param int $contactId
   *
   * @return string|null
   */
  protected function getSquareCustomerId($contactId) {
    $contactId = (int) $contactId;
    $processorId = (int) ($this->_paymentProcessor['id'] ?? 0);
    if ($contactId <= 0 || $processorId <= 0) {
      return NULL;
    }

    $customerId = CRM_Core_DAO::singleValueQuery(
      'SELECT square_customer_id FROM square_customer_map WHERE contact_id = %1 AND payment_processor_id = %2',
      [1 => [$contactId, 'Integer'], 2 => [$processorId, 'Integer']]
    );

    return $customerId !== NULL ? (string) $customerId : NULL;
  }

  /**
   * Save the Square Customer ID for this contact, scoped to this payment
   * processor. See getSquareCustomerId().
   *
   * @param int $contactId
   * @param string $customerId
   */
  protected function saveSquareCustomerId($contactId, $customerId) {
    $contactId = (int) $contactId;
    $processorId = (int) ($this->_paymentProcessor['id'] ?? 0);
    if ($contactId <= 0 || $processorId <= 0 || empty($customerId)) {
      return;
    }

    CRM_Core_DAO::executeQuery(
      'INSERT INTO square_customer_map (contact_id, payment_processor_id, square_customer_id)
       VALUES (%1, %2, %3)
       ON DUPLICATE KEY UPDATE square_customer_id = VALUES(square_customer_id)',
      [1 => [$contactId, 'Integer'], 2 => [$processorId, 'Integer'], 3 => [$customerId, 'String']]
    );
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
   * Does this processor support cancelling recurring contributions through code.
   *
   * If the processor returns true it must be possible to take action from within CiviCRM
   * that will result in no further payments being processed.
   *
   * @return bool
   */
  protected function supportsCancelRecurring() {
    return TRUE;
  }

  /**
   * Does the processor support the user having a choice as to whether to cancel the recurring with the processor?
   *
   * If this returns TRUE then there will be an option to send a cancellation request in the cancellation form.
   *
   * This would normally be false for processors where CiviCRM maintains the schedule.
   *
   * @return bool
   */
  protected function supportsCancelRecurringNotifyOptional() {
    return TRUE;
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
    float  $amount,
    string $currency,
    string $intervalUnit,
    int    $intervalStep = 1,
    ?int   $installments = NULL
  ): string {
    // CRM_Core_Payment_SquareDebugLogger::log("Looking up Square plan variation: {$planId}, {$amount} {$currency}, every {$intervalStep} {$intervalUnit}");
    $cadence = $this->resolveCadence($intervalUnit, $intervalStep);
    // CRM_Core_Payment_SquareDebugLogger::log("Resolved Square cadence: {$cadence}");
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
    // CRM_Core_Payment_SquareDebugLogger::log("Creating label Square plan variation: {$label}");
    $amountCents = (int) round($amount * 100);

    $phase = new SubscriptionPhase([
      'ordinal' => 0,
      'cadence' => strtoupper($cadence), // MONTHLY, ANNUAL
      'periods' => $installments ?? 0,
      'pricing' => new SubscriptionPricing([
        'type' => 'STATIC',
        'priceMoney' => new Money(['amount' => $amountCents, 'currency' => $currency]),
      ]),
    ]);

    $catalogObject = CatalogObject::subscriptionPlanVariation(new CatalogObjectSubscriptionPlanVariation([
      'id' => '#var_' . md5($cacheKey),
      'subscriptionPlanVariationData' => new CatalogSubscriptionPlanVariation([
        'name' => $label,
        'subscriptionPlanId' => $planId,
        'phases' => [$phase],
      ]),
    ]));

    $response = $this->callSquare(fn (SquareClient $client) => $client->catalog->batchUpsert(new BatchUpsertCatalogObjectsRequest([
      'idempotencyKey' => uniqid('plan_var_', TRUE),
      'batches' => [new CatalogObjectBatch(['objects' => [$catalogObject]])],
    ])));

    $objects = $response->getObjects();
    $variationId = !empty($objects[0]) ? $objects[0]->getValue()->getId() : NULL;
    // CRM_Core_Payment_SquareDebugLogger::log("Created Square plan variation ID: {$variationId}");
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
    // CRM_Core_Payment_SquareDebugLogger::log("Looking up Square subscription plan: {$name}");
    // Cache via Civi setting
    $cache = Civi::settings()->get('org_square_plan_cache') ?? [];

    if (!empty($cache[$name])) {
      return $cache[$name];
    }

    $catalogObject = CatalogObject::subscriptionPlan(new CatalogObjectSubscriptionPlan([
      'id' => '#plan_' . md5($name),
      'subscriptionPlanData' => new CatalogSubscriptionPlan([
        'name' => $name,
      ]),
    ]));

    // CRM_Core_Payment_SquareDebugLogger::log("Creating Square subscription plan: {$name}");
    $response = $this->callSquare(fn (SquareClient $client) => $client->catalog->batchUpsert(new BatchUpsertCatalogObjectsRequest([
      'idempotencyKey' => uniqid('plan_', TRUE),
      'batches' => [new CatalogObjectBatch(['objects' => [$catalogObject]])],
    ])));

    $objects = $response->getObjects();
    $planId = !empty($objects[0]) ? $objects[0]->getValue()->getId() : NULL;
    if (!$planId) {
      throw new CRM_Core_Exception('Failed to create Square subscription plan.');
    }

    $cache[$name] = $planId;
    Civi::settings()->set('org_square_plan_cache', $cache);

    return $planId;
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
   * Map a Square source_type to a CiviCRM payment_instrument_id.
   *
   * Square source_type values: CARD, BANK_ACCOUNT, WALLET, CASH, EXTERNAL,
   * BUY_NOW_PAY_LATER, SQUARE_ACCOUNT.
   *
   * CiviCRM defaults: 1=Credit Card, 2=Debit Card, 3=Cash, 4=Check, 5=EFT.
   */
  protected function mapPaymentInstrument(?string $sourceType): int {
    switch (strtoupper((string) $sourceType)) {
      case 'CARD':
      case 'WALLET':         // Apple Pay / Google Pay / Cash App Pay tokenize as cards
      case 'BUY_NOW_PAY_LATER':
      case 'SQUARE_ACCOUNT':
        return 1; // Credit Card
      case 'BANK_ACCOUNT':
        return 5; // EFT
      case 'CASH':
        return 3; // Cash
      default:
        return 1; // Credit Card (Square's most common instrument)
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
        $customerResponse = $this->buildSquareClient()->customers->get(new GetCustomersRequest(['customerId' => $customerId]));
        $customer = $customerResponse->getCustomer();
        if (!empty($customer) && !empty($customer->getReferenceId())) {
          $refId = $customer->getReferenceId();
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
        // CRM_Core_Payment_SquareDebugLogger::log('Square findContactIdForPayment: Error looking up customer: ' . $e->getMessage());
      }
    }

    // 2. Try via reference_id on payment (if it points to a contribution)
    $referenceId = $payment['reference_id'] ?? NULL;
    if ($referenceId && ctype_digit((string) $referenceId)) {
      $contribution = \Civi\Api4\Contribution::get(FALSE)
        ->addWhere('id', '=', (int) $referenceId)
        ->addWhere('is_test', 'IN', [TRUE, FALSE])
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
      // CRM_Core_Payment_SquareDebugLogger::log('Square webhook: subscription.updated missing subscription ID.');
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
    CRM_Core_Payment_SquareDebugLogger::log("Square syncSubscriptionCancellationFromSquare(): called from SquareIPN for subscription_id={$squareSubscriptionId}.");
    if (empty($squareSubscriptionId)) {
      CRM_Core_Payment_SquareDebugLogger::log('Square syncSubscriptionCancellationFromSquare(): missing subscription ID, skipping.');
      return;
    }

    // Find the recurring contribution linked to this subscription
    $recur = ContributionRecur::get(FALSE)
      ->addWhere('processor_id', '=', $squareSubscriptionId)
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->addSelect('id', 'contribution_status_id')
      ->execute()
      ->first();

    if (empty($recur)) {
      CRM_Core_Payment_SquareDebugLogger::log("Square syncSubscriptionCancellationFromSquare(): no recurring contribution found for subscription {$squareSubscriptionId}, skipping.");
      return;
    }

    $recurId = (int) $recur['id'];
    CRM_Core_Payment_SquareDebugLogger::log("Square syncSubscriptionCancellationFromSquare(): found contribution_recur {$recurId} (current status {$recur['contribution_status_id']}) for subscription {$squareSubscriptionId}, marking Cancelled.");

    // Update the recurring contribution to Cancelled (status_id = 3)
    ContributionRecur::update(FALSE)
      ->addWhere('id', '=', $recurId)
      ->addValue('contribution_status_id', 3) // Cancelled
      ->execute();

    CRM_Core_Payment_SquareDebugLogger::log("Square syncSubscriptionCancellationFromSquare(): Updated recurring contribution {$recurId} to Cancelled for subscription {$squareSubscriptionId}.");
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

  /**
   * Process incoming payment notification (IPN).
   *
   * Called by CiviCRM core when it receives a POST to:
   *   civicrm/payment/ipn/{processor_id}
   *
   * Validates the Square webhook signature, then delegates event processing
   * to CRM_Core_Payment_SquareIPN.
   */
  public function handlePaymentNotification() {
    http_response_code(200);
    $rawData = file_get_contents('php://input');

    if (!$this->validateWebhookSignature($rawData, getallheaders())) {
      Civi::log()->error('Square IPN: webhook signature validation failed.');
      http_response_code(401);
      exit();
    }

    $payload = json_decode($rawData, TRUE);
    if (empty($payload)) {
      Civi::log()->error('Square IPN: invalid JSON body received.');
      http_response_code(400);
      exit();
    }

    CRM_Core_Payment_SquareDebugLogger::log('Square IPN: handlePaymentNotification() received webhook. event_id=' . ($payload['event_id'] ?? 'unknown') . ', type=' . ($payload['type'] ?? 'unknown') . ', processor_id=' . $this->getID());

    $ipn = new CRM_Core_Payment_SquareIPN($this);
    $ipn->setData($rawData);
    if (!$ipn->onReceiveWebhook($payload)) {
      http_response_code(500);
    }
  }

  /** Process a webhook record queued by CiviCRM's webhook worker. */
  public function processWebhookEvent(array $webhookEvent): bool {
    $ipn = new CRM_Core_Payment_SquareIPN($this);
    return $ipn->processQueuedWebhookEvent($webhookEvent);
  }

  /**
   * Validate the Square webhook HMAC-SHA256 signature.
   *
   * Square signs webhooks as:
   *   base64( HMAC-SHA256( notification_url + raw_body, signature_key ) )
   *
   * @param string $rawData Raw request body.
   * @param array $headers HTTP headers from getallheaders().
   * @return bool
   */
  protected function validateWebhookSignature(string $rawData, array $headers): bool {
    $key = $this->getWebhookSignatureKey();
    if (!$key) {
      Civi::log()->error('Square IPN: webhook signature key not configured (check "Subject" field on payment processor).');
      return FALSE;
    }

    // Header keys are case-insensitive; normalise to lowercase.
    $normalised = [];
    foreach ($headers as $k => $v) {
      $normalised[strtolower($k)] = $v;
    }

    $provided = $normalised['x-square-hmacsha256-signature'] ?? NULL;
    if (!$provided) {
      Civi::log()->error('Square IPN: X-Square-Signature header missing.');
      return FALSE;
    }

    $notifyUrl = $this->getNotifyUrl();
    $expected = base64_encode(hash_hmac('sha256', $notifyUrl . $rawData, $key, TRUE));

    if (!hash_equals($expected, $provided)) {
      // Do not log signatures: they are credential-derived authentication data.
      Civi::log()->error('Square IPN: signature validation failed.');
      return FALSE;
    }

    return TRUE;
  }

}
