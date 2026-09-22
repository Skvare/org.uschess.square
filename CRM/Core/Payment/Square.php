<?php

use CRM_Square_ExtensionUtil as E;
use Civi\Api4\Contribution;
use Civi\Api4\Contact;
use Civi\Api4\ContributionRecur;
use Civi\Api4\Payment;
use Civi\Api4\PaymentToken;
use Civi\Payment\Exception\PaymentProcessorException;
use Civi\Payment\PropertyBag;
use Square\SquareClient;
use Square\Exceptions\SquareApiException;
use Square\Exceptions\SquareException;
use Square\Types\Money;
use Square\Types\Address;
use Square\Types\Card;
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
// Explicit require (rather than relying on CiviCRM's CRM_ classloader,
// unavailable in the mocked-CiviCRM PHPUnit suite — see tests/phpunit/
// bootstrap.php) since callSquare()/squareApiError() below construct this
// class directly.
require_once __DIR__ . '/SquareRetryableException.php';
/**
 * Square Payment Processor for CiviCRM.
 *
 * This processor supports:
 *  - One-off (non-recurring) card payments via Square Payments API
 *  - Recurring contributions via Square Subscriptions API.
 *
 * Card details are never handled by CiviCRM directly. Instead, the
 * Square Web Payments SDK is used in the browser to tokenize the card
 * and pass a token/nonce back to this class via $params.
 */
class CRM_Core_Payment_Square extends CRM_Core_Payment {

  /**
   * Payment-processor instance configuration.
   *
   * @var array
   */
  protected $_paymentProcessor = [];

  /**
   * Active CiviCRM component.
   *
   * @var string
   *   Component name (e.g. 'contribute' or 'event'), set by doPayment().
   *   Declared explicitly to avoid PHP 8.2's deprecated dynamic-property
   *   creation notice — CiviCRM core's CRM_Core_Payment declares this too,
   *   but not every context this class runs in guarantees that.
   */
  protected $_component = 'contribute';

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
   *   Test or live.
   * @param array $paymentProcessor
   *   Row from civicrm_payment_processor.
   */
  public function __construct($mode, array &$paymentProcessor) {
    // Store processor config.
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
   * Inject Square assets and card-container HTML into the billing block.
   *
   * This method is called by CiviCRM for ALL form types that render the billing
   * block, including:
   *  - Native contribution pages / event registration
   *  - Drupal Webform AJAX billing block requests (CRM_Core_Payment_Form)
   *  - Backend contribution/event forms.
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

    // Add hidden field for the payment token.
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

    // We augment the standard billing block rather than replace it, so form
    // building must continue as normal (CRM_Core_Payment::buildForm()'s
    // contract: FALSE = "continue normal form building").
    return FALSE;
  }

  /**
   * Build a SquareClient configured for this processor (2025 SDK style).
   *
   * Uses the password field as access token and honours is_test.
   *
   * @return \Square\SquareClient
   *
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
   * Build a retry-stable idempotency key unique to each processor and environment.
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

  /**
   * Resolve a contribution_status_id by name without relying on installation-specific IDs.
   */
  protected function contributionStatusId(string $name): int {
    return $this->pseudoConstantId('contribution_status_id', $name);
  }

  /**
   * Resolve a payment_instrument_id by name without relying on installation-specific IDs.
   */
  protected function paymentInstrumentId(string $name): int {
    return $this->pseudoConstantId('payment_instrument_id', $name);
  }

  /**
   * Resolve a financial_type_id by name without relying on installation-specific IDs.
   */
  protected function financialTypeId(string $name): int {
    return $this->pseudoConstantId('financial_type_id', $name);
  }

  /**
   * Resolve a Contribution-entity pseudoconstant value by name.
   *
   * Replaces the deprecated CRM_Contribute_PseudoConstant::contributionStatus()
   * (and avoids hard-coding installation-specific numeric IDs for statuses,
   * payment instruments, and financial types).
   */
  protected function pseudoConstantId(string $field, string $name): int {
    $id = \CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', $field, $name);
    if ($id === FALSE || $id === NULL) {
      throw new \CRM_Core_Exception("CiviCRM {$field} '{$name}' is unavailable.");
    }
    return (int) $id;
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
    $existingQuery = Contribution::get(FALSE)
      ->addSelect('id', 'contribution_status_id', 'total_amount', 'currency')
      ->addWhere('is_test', 'IN', [TRUE, FALSE]);
    if ($orderID !== NULL) {
      $existingQuery->addClause('OR', ['invoice_number', '=', $orderID], ['trxn_id', '=', $paymentId]);
    }
    else {
      $existingQuery->addWhere('trxn_id', '=', $paymentId);
    }
    $existing = $existingQuery->execute()->first();

    if ($existing) {
      $this->reconcileExistingContributionPayment($existing, $paymentId, $amount, $currency, $status, $sourceType, $feeAmount);
      return;
    }

    CRM_Core_Payment_SquareDebugLogger::log("Square syncPaymentFromSquare(): no existing contribution found for payment {$paymentId} (order_id={$orderID}), attempting to create a new one.");

    // If no contribution exists, try mapping by reference_id → contact or contribution.
    $referenceId = $payment['reference_id'] ?? NULL;
    $contactId = NULL;

    if ($referenceId && ctype_digit((string) $referenceId)) {
      $refRecurContribution = ContributionRecur::get(FALSE)
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

    // No better mapping available (no recur template) — fall back to the
    // Donation financial type resolved by name, never a hard-coded ID.
    $financialTypeId = $this->financialTypeId('Donation');

    $applicationId = $payment['application_details']['application_id'] ?? '';
    $isTest = str_contains($applicationId, 'sandbox') ? 1 : 0;
    if (!empty($refRecurContribution)) {
      $isTest = $refRecurContribution['is_test'] ? 1 : 0;
      $financialTypeId = $refRecurContribution['financial_type_id'] ?? $financialTypeId;
    }

    // Create the contribution Pending, then complete it (if warranted) via
    // Payment.create so the CiviCRM financial ledger (FinancialTrxn /
    // EntityFinancialTrxn) is populated correctly — never write a
    // "Completed" status directly onto the contribution row.
    $create = Contribution::create(FALSE)
      ->addValue('contact_id', $contactId)
      ->addValue('financial_type_id', $financialTypeId)
      ->addValue('total_amount', $amount)
      ->addValue('currency', $currency)
      ->addValue('contribution_status_id', $this->contributionStatusId('Pending'))
      ->addValue('trxn_id', $paymentId)
      ->addValue('is_test', $isTest)
      ->addValue('source', 'Square Payment (Webhook)');
    if ($orderID !== NULL) {
      $create->addValue('invoice_number', $orderID);
    }
    $newContribution = $create->execute()->first();
    $newContributionId = (int) $newContribution['id'];

    if ($this->mapPaymentStatus($status) === $this->contributionStatusId('Completed')) {
      $this->completeContributionPayment($newContributionId, $amount, $paymentId, $this->mapPaymentInstrument($sourceType), $feeAmount);
      CRM_Core_Payment_SquareDebugLogger::log("Square syncPaymentFromSquare(): Created and completed contribution {$newContributionId} for payment {$paymentId}, contact {$contactId}.");
    }
    else {
      CRM_Core_Payment_SquareDebugLogger::log("Square syncPaymentFromSquare(): Created Pending contribution {$newContributionId} for payment {$paymentId} (status {$status}), contact {$contactId}.");
    }
  }

  /**
   * Reconcile a Square payment against an existing CiviCRM contribution.
   *
   * Amount/currency mismatches are never silently corrected — they are
   * logged as reconciliation errors requiring manual review, preserving
   * whatever line items and financial allocations the contribution already
   * has. Completion is only ever recorded via Payment.create so the
   * financial ledger stays accurate.
   *
   * @param array $existing
   *   Contribution row with id, contribution_status_id, total_amount, currency.
   * @param string $paymentId
   *   Square payment ID.
   * @param float|null $amount
   *   Amount Square reports for this payment, in dollars.
   * @param string $currency
   *   Currency Square reports for this payment.
   * @param string $status
   *   Square payment status (e.g. COMPLETED, PENDING).
   * @param string|null $sourceType
   *   Square source_type (CARD, BANK_ACCOUNT, etc), for payment_instrument_id mapping.
   * @param float|null $feeAmount
   *   Processing fee Square reports, in dollars, if any.
   */
  protected function reconcileExistingContributionPayment(array $existing, string $paymentId, ?float $amount, string $currency, string $status, ?string $sourceType, ?float $feeAmount): void {
    $contributionId = (int) $existing['id'];

    if ($amount !== NULL && round((float) $existing['total_amount'], 2) !== round($amount, 2)) {
      Civi::log()->error("Square syncPaymentFromSquare(): amount mismatch for contribution {$contributionId} (payment {$paymentId}): CiviCRM has {$existing['total_amount']} {$existing['currency']}, Square reports {$amount} {$currency}. Not modifying — requires manual reconciliation.");
      return;
    }
    if (!empty($existing['currency']) && strcasecmp((string) $existing['currency'], $currency) !== 0) {
      Civi::log()->error("Square syncPaymentFromSquare(): currency mismatch for contribution {$contributionId} (payment {$paymentId}): CiviCRM has {$existing['currency']}, Square reports {$currency}. Not modifying — requires manual reconciliation.");
      return;
    }

    $completedStatusId = $this->contributionStatusId('Completed');
    if ((int) $existing['contribution_status_id'] === $completedStatusId) {
      CRM_Core_Payment_SquareDebugLogger::log("Square syncPaymentFromSquare(): contribution {$contributionId} already Completed for payment {$paymentId}, skipping.");
      return;
    }

    if ($this->mapPaymentStatus($status) !== $completedStatusId) {
      CRM_Core_Payment_SquareDebugLogger::log("Square syncPaymentFromSquare(): payment {$paymentId} status '{$status}' does not indicate completion, leaving contribution {$contributionId} unchanged.");
      return;
    }

    $this->completeContributionPayment(
      $contributionId,
      $amount ?? (float) $existing['total_amount'],
      $paymentId,
      $this->mapPaymentInstrument($sourceType),
      $feeAmount
    );
    CRM_Core_Payment_SquareDebugLogger::log("Square syncPaymentFromSquare(): completed contribution {$contributionId} for payment {$paymentId} via Payment.create.");
  }

  /**
   * Complete a Pending contribution's payment via CiviCRM's Payment.create.
   *
   * This creates FinancialTrxn/EntityFinancialTrxn ledger rows and runs
   * CRM_Contribute_BAO_Contribution::completeOrder() as it would for any
   * other payment processor.
   *
   * @throws \CRM_Core_Exception
   */
  protected function completeContributionPayment(int $contributionId, float $amount, string $trxnId, ?int $paymentInstrumentId = NULL, ?float $feeAmount = NULL, ?string $receiveDate = NULL): void {
    $create = Payment::create(FALSE)
      ->addValue('contribution_id', $contributionId)
      ->addValue('total_amount', $amount)
      ->addValue('trxn_id', $trxnId);
    if ($paymentInstrumentId !== NULL) {
      $create->addValue('payment_instrument_id', $paymentInstrumentId);
    }
    if ($feeAmount !== NULL) {
      $create->addValue('fee_amount', $feeAmount);
    }
    if ($receiveDate !== NULL) {
      $create->addValue('trxn_date', $receiveDate);
    }
    if (!empty($this->_paymentProcessor['id'])) {
      $create->addValue('payment_processor_id', (int) $this->_paymentProcessor['id']);
    }
    $create->execute();
  }

  /**
   * Sync a Square refund into CiviCRM.
   *
   * @param array $refund
   */
  public function syncRefundFromSquare(array $refund) {
    $paymentId = $refund['payment_id'] ?? NULL;
    $refundId = $refund['id'] ?? NULL;
    $refundStatus = strtoupper(trim($refund['status'] ?? ''));
    CRM_Core_Payment_SquareDebugLogger::log("Square syncRefundFromSquare(): called from SquareIPN for refund_id={$refundId}, payment_id={$paymentId}, status={$refundStatus}.");
    if (!$paymentId || !$refundId) {
      CRM_Core_Payment_SquareDebugLogger::log('Square syncRefundFromSquare(): missing payment_id or refund_id, skipping.');
      return;
    }
    if (!in_array($refundStatus, ['COMPLETED', 'APPROVED'], TRUE)) {
      CRM_Core_Payment_SquareDebugLogger::log("Square syncRefundFromSquare(): refund {$refundId} not yet settled (status={$refundStatus}), skipping until it completes.");
      return;
    }

    $money = $refund['amount_money'] ?? NULL;
    $refundAmount = ($money && isset($money['amount'])) ? ((float) $money['amount'] / 100) : NULL;
    if ($refundAmount === NULL || $refundAmount <= 0) {
      Civi::log()->error("Square syncRefundFromSquare(): refund {$refundId} for payment {$paymentId} has no usable amount, skipping.");
      return;
    }

    // Find contribution by trxn_id.
    $contribution = Contribution::get(FALSE)
      ->addSelect('id')
      ->addWhere('trxn_id', '=', $paymentId)
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->execute()
      ->first();

    if (!$contribution) {
      CRM_Core_Payment_SquareDebugLogger::log("Square syncRefundFromSquare(): no contribution found for payment {$paymentId}, skipping.");
      return;
    }
    $contributionId = (int) $contribution['id'];

    // Idempotency: a replayed refund.created webhook must not double-refund.
    $alreadyRecorded = civicrm_api3('Payment', 'get', [
      'entity_id' => $contributionId,
      'trxn_id' => $refundId,
      'options' => ['limit' => 1],
    ])['count'] ?? 0;
    if (!empty($alreadyRecorded)) {
      CRM_Core_Payment_SquareDebugLogger::log("Square syncRefundFromSquare(): refund {$refundId} already recorded for contribution {$contributionId}, skipping.");
      return;
    }

    // Find the original payment being refunded so we can link the refund to
    // it via cancelled_payment_id, matching CiviCRM's own Payment.cancel
    // behaviour (api/v3/Payment.php civicrm_api3_payment_cancel()).
    $originalPayments = civicrm_api3('Payment', 'get', [
      'entity_id' => $contributionId,
      'trxn_id' => $paymentId,
      'options' => ['limit' => 1, 'sort' => 'id DESC'],
    ])['values'] ?? [];
    $originalPaymentId = !empty($originalPayments) ? (int) array_key_first($originalPayments) : NULL;

    if (!$originalPaymentId) {
      Civi::log()->error("Square syncRefundFromSquare(): could not find the original Payment record for contribution {$contributionId}, payment {$paymentId}. Cannot record refund {$refundId} unambiguously — requires manual reconciliation.");
      return;
    }

    // Record the refund as a negative payment linked to the original
    // payment. cancelled_payment_id is only accepted by the API3 action —
    // API4 Payment::create does not declare it as a writable field — and
    // the contribution_status_id transition to Refunded/Partially paid is
    // handled automatically by CRM_Financial_BAO_Payment::create().
    civicrm_api3('Payment', 'create', [
      'contribution_id' => $contributionId,
      'total_amount' => -$refundAmount,
      'trxn_id' => $refundId,
      'cancelled_payment_id' => $originalPaymentId,
    ]);

    CRM_Core_Payment_SquareDebugLogger::log("Square syncRefundFromSquare(): recorded refund {$refundId} ({$refundAmount}) against contribution {$contributionId}, cancelling payment {$originalPaymentId}.");
  }

  /**
   * Sync a Square subscription update into CiviCRM.
   *
   * @param array $subscription
   */
  public function syncSubscriptionFromWebhook(array $subscription) {
    $id = $subscription['id'] ?? NULL;
    if (!$id) {
      CRM_Core_Payment_SquareDebugLogger::log('Square syncSubscriptionFromWebhook(): missing subscription ID.');
      return;
    }

    $status = $subscription['status'] ?? 'UNKNOWN';
    $amount = $this->extractSubscriptionAmount($subscription);

    $recur = ContributionRecur::get(FALSE)
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
      $q = ContributionRecur::update(FALSE)
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
   * Not currently called by any webhook route (invoice.payment_made is
   * handled by handleInvoicePaymentCreated() instead) but kept as public
   * API surface, so it delegates to the same ledger-safe path rather than
   * duplicating the old direct-Completed-contribution logic.
   *
   * @param array $invoice
   */
  public function syncInvoiceFromSquare(array $invoice) {
    $this->handleInvoicePaymentCreated(['data' => ['object' => ['invoice' => $invoice]]]);
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
      // No such recurring record exists — log and stop.
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
   *
   * @return int|null
   */
  protected function mapSquareSubscriptionStatusToCivi($squareStatus) {
    $squareStatus = strtoupper(trim($squareStatus));

    // Square subscription statuses per the Subscriptions API: PENDING,
    // ACTIVE, CANCELED, DEACTIVATED, PAUSED, and (API versions
    // 2025-09-24+) COMPLETED. There is no SUSPENDED status.
    switch ($squareStatus) {
      case 'PENDING':
      case 'PAUSED':
        return $this->contributionStatusId('Pending');

      case 'ACTIVE':
        return $this->contributionStatusId('In Progress');

      case 'COMPLETED':
        return $this->contributionStatusId('Completed');

      case 'CANCELED':
        return $this->contributionStatusId('Cancelled');

      case 'DEACTIVATED':
        return $this->contributionStatusId('Failed');
    }

    // If unknown, don't change local status.
    return NULL;
  }

  /**
   * Extract override amount from Square subscription.
   *
   * @param array $subscription
   *
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
   *  1. Contribution params (financialTypeID / financial_type_id).
   *  2. Recurring template on contribution_recur.
   *  3. "Donation", resolved by name.
   *
   * @param array $params
   *
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
      $recur = ContributionRecur::get(FALSE)
        ->addWhere('id', '=', (int) $params['contributionRecurID'])
        ->addWhere('is_test', 'IN', [TRUE, FALSE])
        ->addSelect('financial_type_id')
        ->execute()
        ->first();

      if (!empty($recur['financial_type_id'])) {
        return (int) $recur['financial_type_id'];
      }
    }

    // 3. Fallback to Donation, resolved by name — never a hard-coded ID,
    // which is not stable across CiviCRM installs.
    CRM_Core_Payment_SquareDebugLogger::log('Square getFinancialTypeId(): no financial type resolved from params or recurring template; falling back to Donation.');
    return $this->financialTypeId('Donation');
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

    // Load subscription payment info.
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

    // Find matching Civi recurring record.
    $recur = ContributionRecur::get(FALSE)
      ->addWhere('processor_id', '=', $subscriptionId)
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->addSelect('id', 'contact_id', 'is_test', 'payment_instrument_id')
      ->execute()
      ->first();

    if (empty($recur)) {
      CRM_Core_Payment_SquareDebugLogger::log("Square webhook: No matching contribution_recur for subscription {$subscriptionId}.");
      return;
    }

    $recurId = (int) $recur['id'];
    $paymentInstrumentId = $recur['payment_instrument_id'] ?? $this->paymentInstrumentId('Credit Card');
    $completedStatusId = $this->contributionStatusId('Completed');

    // Check for duplicate contribution by invoice ID.
    $existing = Contribution::get(FALSE)
      ->addSelect('id', 'contribution_status_id', 'contribution_recur_id')
      ->addWhere('invoice_id', '=', $invoiceId)
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->execute()
      ->first();

    CRM_Core_Payment_SquareDebugLogger::log($existing
      ? "Square webhook: found existing contribution {$existing['id']} (status {$existing['contribution_status_id']}) for invoice {$invoiceId}."
      : "Square webhook: no existing contribution found for invoice {$invoiceId}, will create a new one.");

    // Check record exist and status completed and recurring is linked.
    if (!empty($existing)) {
      if ((int) $existing['contribution_status_id'] === $completedStatusId && !empty($existing['contribution_recur_id'])) {
        CRM_Core_Payment_SquareDebugLogger::log("Square webhook: Invoice {$invoiceId} already processed as contribution {$existing['id']}.");
        return;
      }

      // Complete the existing (Pending) contribution via Payment.create so
      // the financial ledger (FinancialTrxn/EntityFinancialTrxn) is
      // populated correctly — never write "Completed" directly onto the
      // contribution row.
      $this->completeContributionPayment((int) $existing['id'], $amount, $invoiceId, $paymentInstrumentId, NULL, $receiveDate);
      try {
        CRM_Contribute_BAO_ContributionRecur::updateOnNewPayment($recurId, 'Completed');
      }
      catch (\Throwable $e) {
        Civi::log()->error("Square webhook: Failed to update contribution recur {$recurId} after processing invoice {$invoiceId}: " . $e->getMessage());
      }
      CRM_Core_Payment_SquareDebugLogger::log("Square webhook: Completed existing contribution {$existing['id']} for invoice {$invoiceId} (subscription {$subscriptionId}).");
      return;
    }

    // No existing contribution — clone one from the recurring template via
    // CiviCRM's own repeat-transaction flow (preserves line items and
    // financial allocations), left Pending by default, then complete it
    // via Payment.create.
    $repeatResult = civicrm_api3('Contribution', 'repeattransaction', [
      'contribution_recur_id' => $recurId,
      'trxn_id' => $invoiceId,
      'total_amount' => $amount,
      'receive_date' => $receiveDate,
    ]);
    $newContributionId = (int) ($repeatResult['id'] ?? 0);
    if (!$newContributionId) {
      Civi::log()->error("Square webhook: Contribution.repeattransaction did not return a contribution ID for invoice {$invoiceId} (subscription {$subscriptionId}).");
      return;
    }

    // repeattransaction() doesn't accept invoice_id — set it separately so
    // future webhook deliveries for this invoice can find the contribution.
    Contribution::update(FALSE)
      ->addWhere('id', '=', $newContributionId)
      ->addValue('invoice_id', $invoiceId)
      ->execute();

    $this->completeContributionPayment($newContributionId, $amount, $invoiceId, $paymentInstrumentId, NULL, $receiveDate);

    try {
      CRM_Contribute_BAO_ContributionRecur::updateOnNewPayment($recurId, 'Completed');
    }
    catch (\Throwable $e) {
      Civi::log()->error("Square webhook: Failed to update contribution recur {$recurId} after processing invoice {$invoiceId}: " . $e->getMessage());
    }
    CRM_Core_Payment_SquareDebugLogger::log("Square webhook: Created and completed contribution {$newContributionId} for invoice {$invoiceId} (subscription {$subscriptionId}).");
  }

  /**
   * Handle a subscription cancellation event coming from Square.
   *
   * Triggered by webhook event: subscription.canceled.
   *
   * @param array $payload
   *   Full decoded JSON body from Square webhook.
   */
  public function handleSubscriptionCancelled(array $payload) {
    if (empty($payload['data']['object']['subscription']['id'])) {
      CRM_Core_Payment_SquareDebugLogger::log('Square webhook: subscription.canceled missing subscription ID.');
      return;
    }

    $subscriptionId = $payload['data']['object']['subscription']['id'];

    // Find associated recurring contribution.
    $recur = ContributionRecur::get(FALSE)
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
    ContributionRecur::update(FALSE)
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
    // This method is called by CiviCRM but we delegate to doRecurPayment.
    return $this->doRecurPayment($params);
  }

  /**
   * Handle one-time Square payments.
   *
   * @param array $params
   *
   * @return array
   *
   * @throws \CRM_Core_Exception
   */
  protected function doOneTimePayment(&$params) {
    // 1. Determine amount and currency first — a zero-amount contribution
    // (e.g. a 100%-discount code) needs no card at all, so it must not be
    // rejected for lacking a Square token.
    $amount = $params['amount'] ?? $params['total_amount'] ?? NULL;
    if ($amount === NULL || $amount === '') {
      throw new \CRM_Core_Exception('Missing contribution amount.');
    }

    if ((float) $amount === 0.0) {
      return $params;
    }

    // 2. Extract Web Payments SDK token.
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

    $amountCents = (int) round(((float) $amount) * 100);
    $currency = $params['currency'] ?? $params['currencyID'] ?? 'USD';

    // 3. Idempotency key. The previous fallback was the shared literal
    // 'unknown' when no invoice/contribution reference was available —
    // meaning any two payments both missing a reference would collide on
    // the exact same idempotency key, and Square would silently treat the
    // second as a duplicate of the first rather than charging it. A
    // per-call-random reference can never collide with a different
    // payment; it just can't protect that specific request against a
    // network-level client retry (no stable reference exists to retry
    // against), which is the same behaviour this fallback always had.
    $paymentReference = $params['invoiceID'] ?? $params['invoice_id'] ?? $params['contributionID'] ?? $params['contribution_id'] ?? NULL;
    if ($paymentReference === NULL || $paymentReference === '') {
      // No stable reference available — a per-call random one can never
      // collide with a different payment, unlike the previous literal
      // 'unknown' fallback, but (like that fallback) can't protect this
      // specific request against a client-level network retry either.
      $paymentReference = bin2hex(random_bytes(16));
    }
    $idempotencyKey = $this->idempotencyKey('payment', (string) $paymentReference);

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

    // Optional reference.
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
   * Process a recurring payment.
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
    // 6. Generate idempotency key tied to the recurring record so re-posts don't duplicate.
    $idempotencyKey = $this->idempotencyKey('subscription', (string) $recurId);
    $source = [
      'contact_id' => (string) ($params['contactID'] ?? $params['contact_id'] ?? ''),
      'recur_id' => (string) $recurId,
    ];
    // Implode key and value of source
    // convert array to string with maintain key value.
    $note = json_encode($source, JSON_UNESCAPED_SLASHES);

    // 7. Build subscription payload. startDate is deliberately omitted —
    // Square defaults it to "today" in the location's timezone, and Square
    // bills the first invoice ON start_date. Previously this code set
    // start_date to "tomorrow" AND made a separate immediate CreatePayment
    // call for "today", which double-charges the donor once Square's own
    // invoice for tomorrow is paid. The initial charge is now handled
    // exactly once, entirely by Square's subscription billing, confirmed
    // asynchronously via the invoice.payment_made webhook (see
    // handleInvoicePaymentCreated()).
    $createSubscriptionRequest = new CreateSubscriptionRequest([
      'idempotencyKey' => $idempotencyKey,
      'locationId' => $this->getLocationId(),
      'planVariationId' => $planVariationId,
      'customerId' => $customerId,
      'cardId' => $cardId,
      'source' => new SubscriptionSource(['name' => $note]),
    ]);

    // 8. Send subscription create request
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

    // 9. Record the subscription on the recurring contribution. Leave it
    // Pending — Square has not charged anything yet. The first successful
    // charge is confirmed via the invoice.payment_made webhook, which
    // completes both this recurring record (via updateOnNewPayment()) and
    // the initial contribution (via Payment.create).
    ContributionRecur::update(FALSE)
      ->addWhere('id', '=', $recurId)
      ->addValue('processor_id', $subscriptionId)
      ->addValue('trxn_id', $subscriptionId)
      ->addValue('contribution_status_id', $this->contributionStatusId('Pending'))
      ->execute();

    // 10. Return CiviCRM-standard response: the initial contribution stays
    // Pending until Square confirms the charge via webhook.
    return [
      'payment_status_id' => $this->contributionStatusId('Pending'),
      'contribution_status_id' => $this->contributionStatusId('Pending'),
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
   * Call the Square Refunds API through a testable seam.
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
   * Invoke the Square SDK client and translate its exceptions to CRM_Core_Exception.
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
      // Transport-level failure (timeout, DNS, connection reset, etc.) —
      // there's no reason to believe retrying would fail again the same
      // way, so callers processing a queued webhook should retry rather
      // than give up permanently.
      throw new CRM_Core_Payment_SquareRetryableException('Square API request failed: ' . $e->getMessage());
    }
  }

  /**
   * Convert a Square SDK API exception to the message shape callers expect.
   *
   * HTTP 5xx and 429 are treated as transient (Square-side outage or rate
   * limiting) so webhook processing retries them; 4xx (other than 429)
   * indicates a malformed/rejected request that will fail identically on
   * retry, so it is treated as permanent.
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
    $statusCode = $e->getStatusCode();
    $msg = "Square API returned HTTP {$statusCode}.";
    if ($errorDetails) {
      $msg .= ' ' . implode(' | ', $errorDetails);
    }
    $exceptionClass = ($statusCode >= 500 || $statusCode === 429)
      ? CRM_Core_Payment_SquareRetryableException::class
      : CRM_Core_Exception::class;
    return new $exceptionClass($msg);
  }

  /**
   * Look up an existing Square customer by email.
   *
   * @param string $email
   *   Customer email address.
   *
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
   * Look up an existing Square customer by ID.
   *
   * @param string $customerID
   *   Square customer ID.
   *
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
   * Update a Square customer's details from CiviCRM payment parameters.
   *
   * @param string $customerID
   *   Square customer ID.
   * @param array $params
   *   CiviCRM payment parameters.
   *
   * @return void
   *
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
      CRM_Core_Payment_SquareDebugLogger::log('Square customer already exists for contact ' . $contactID . ': ' . $customerId);
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

        // Store mapping if safe.
        $this->saveSquareCustomerId($contactID, $migratedCustomerId);

        // If a card token is present, attach card to this existing Square customer.
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
    catch (\Throwable $e) {
      CRM_Core_Payment_SquareDebugLogger::log('Square migration lookup error: ' . $e->getMessage());
    }
    $existingCustomerId = $this->getSquareCustomerId($contactID);
    if (!empty($existingCustomerId)) {
      // If card token/nonce is present, create and store card_id.
      $cardNonce = $params['square_payment_token']
        ?? $params['payment_token']
        ?? $params['token']
        ?? NULL;
      if (!empty($cardNonce)) {
        $this->createCardOnFile($existingCustomerId, $cardNonce, $params, $contactID);
      }
      return $existingCustomerId;
    }

    // Load contact email.
    $contact = Contact::get(FALSE)
      ->addWhere('id', '=', $contactID)
      ->addSelect('email')
      ->execute()
      ->first();

    $email = $contact['email'] ?? NULL;

    // Check for existing Square customer by email.
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

      // Save mapping if none existed previously.
      $this->saveSquareCustomerId($contactID, $squareCustomerByEmail);
      // If card token/nonce is present, create and store card_id.
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

    // If card token/nonce is present, create and store card_id.
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

    // Add billing address if available.
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
      // Deterministic on the (single-use) nonce, rather than uniqid() —
      // uniqid() is never the same twice, which defeats the point of an
      // idempotency key: an accidental double-submit with the same nonce
      // would otherwise create two cards.
      'idempotencyKey' => $this->idempotencyKey('card', $cardNonce),
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
   * Map country to standardized 2-letter country code.
   */
  protected function mapCountryCode($country) {
    // Standardize country codes/names.
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

    // Normalize input.
    $normalizedCountry = strtoupper(trim($country));

    // Return mapped country or default to US.
    return $countryMap[$normalizedCountry] ?? 'US';
  }

  /**
   * Translate Square card errors to human-friendly messages.
   *
   * @param \Square\Types\Error[] $errors
   *   Square card errors.
   *
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

  /**
   * Get the plan variation ID for recurring-payment parameters.
   */
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

    // A plan identifies a subscription purpose; a variation identifies its amount and cadence.
    $planName = sprintf(
      'CiviCRM %s',
      ucfirst($entity)
    );

    $planId = $this->getOrCreateSubscriptionPlan($planName);
    CRM_Core_Payment_SquareDebugLogger::log("Square plan ID for {$entity}: {$planId}");
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
   *
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
    catch (\Throwable $e) {
      $errorMessage = E::ts('Failed to cancel the Square subscription: ') . $e->getMessage();
      \Civi::log('square')->error($errorMessage);
      // $previous is CRM_Core_Exception's 4th constructor argument
      // ($message, $error_code, $errorData, $previous) — passing $e as the
      // 3rd argument silently drops it into the $errorData slot instead.
      throw new PaymentProcessorException($errorMessage, 0, [], $e);
    }

    return ['message' => E::ts('Successfully cancelled the Square subscription.')];
  }

  /**
   * Cancel a Square subscription by its processor ID.
   *
   * Low-level helper used by doCancelRecurring() and by the civicrm_post hook.
   * Makes the Square API call directly without PropertyBag logic.
   *
   * @param string $subscriptionId
   *   Square subscription ID (e.g. "SUB_xxx").
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
   * Change the amount of an active Square subscription.
   *
   * This is CiviCRM's real "change recurring amount" contract — see
   * CRM_Core_Payment_AuthorizeNet::changeSubscriptionAmount() and
   * CRM_Core_Payment_PayPalImpl::changeSubscriptionAmount(). It is not a
   * declared abstract on the base class, so a processor that names its
   * equivalent method anything else (as this one previously did, with
   * updateSubscriptionAmount()) is simply never called by CiviCRM's
   * "change amount" UI.
   *
   * @param string $message
   * @param array $params
   *   Expects 'subscriptionId' (Square subscription ID) and 'amount'
   *   (new total amount); optionally 'currency'.
   *
   * @return bool
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function changeSubscriptionAmount(&$message = '', $params = []) {
    $subscriptionId = $params['subscriptionId'] ?? NULL;
    $newAmount = $params['amount'] ?? NULL;
    if (empty($subscriptionId) || $newAmount === NULL) {
      $message = E::ts('Missing subscription ID or amount for Square subscription amount change.');
      throw new PaymentProcessorException($message);
    }

    $currency = $params['currency'] ?? $this->_paymentProcessor['currency'] ?? 'USD';

    try {
      // Square requires the current object version on every update to
      // guard against concurrent modification, so fetch it immediately
      // before applying the change.
      $current = $this->callSquare(fn (SquareClient $client) => $client->subscriptions->get(
        new GetSubscriptionsRequest(['subscriptionId' => $subscriptionId])
      ))->getSubscription();
      $version = $current ? $current->getVersion() : NULL;

      $amountCents = (int) round(((float) $newAmount) * 100);
      $subscriptionValues = [
        'priceOverrideMoney' => new Money(['amount' => $amountCents, 'currency' => $currency]),
      ];
      if ($version !== NULL) {
        $subscriptionValues['version'] = $version;
      }

      $this->callSquare(fn (SquareClient $client) => $client->subscriptions->update(new UpdateSubscriptionRequest([
        'subscriptionId' => $subscriptionId,
        'subscription' => new Subscription($subscriptionValues),
      ])));
    }
    catch (PaymentProcessorException $e) {
      throw $e;
    }
    catch (\Throwable $e) {
      $message = E::ts('Failed to change Square subscription amount: ') . $e->getMessage();
      throw new PaymentProcessorException($message, 0, [], $e);
    }

    $message = E::ts('Square subscription amount updated.');
    return TRUE;
  }

  /**
   * Get the Square customer ID stored for a contact and processor.
   *
   * Live and sandbox accounts use distinct customer records.
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
   * Save the Square customer ID for a contact and processor.
   *
   * See getSquareCustomerId().
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
   * Validate the billing-block submission before doPayment() is invoked.
   *
   * Overrides CRM_Core_Payment::validatePaymentInstrument(), the actual
   * hook CiviCRM calls for processor-specific checkout validation. The
   * previous validateForm($values, &$errors) method had a signature that
   * doesn't match any CiviCRM contract (checkConfig() already validates
   * processor *settings*), so it was never invoked by anything.
   *
   * @param array $values
   * @param array $errors
   */
  public function validatePaymentInstrument($values, &$errors) {
    $amount = (float) ($values['amount'] ?? $values['total_amount'] ?? 0);
    if ($amount === 0.0) {
      // No card is required (or collected) for zero-amount contributions.
      return;
    }

    $hasToken = !empty($values['square_payment_token'])
      || !empty($values['payment_token'])
      || !empty($values['token'])
      || !empty($_POST['square_payment_token'])
      || !empty($_REQUEST['square_payment_token']);

    if (!$hasToken) {
      $errors['square_payment_token'] = ts('Please enter your card details.');
    }
  }

  /**
   * Get or create a subscription plan variation with cadence.
   *
   * @param string $planId
   * @param float $amount
   * @param string $currency
   * @param string $intervalUnit
   *   Day, week, month, or year.
   * @param int $intervalStep
   * @param int|null $installments
   *
   * @return string Plan variation ID
   *
   * @throws CRM_Core_Exception
   */
  protected function getOrCreatePlanVariation(
    string $planId,
    float $amount,
    string $currency,
    string $intervalUnit,
    int $intervalStep = 1,
    ?int $installments = NULL,
  ): string {
    CRM_Core_Payment_SquareDebugLogger::log("Looking up Square plan variation: {$planId}, {$amount} {$currency}, every {$intervalStep} {$intervalUnit}");
    $cadence = $this->resolveCadence($intervalUnit, $intervalStep);
    CRM_Core_Payment_SquareDebugLogger::log("Resolved Square cadence: {$cadence}");
    // Scope the cache by everything that changes what gets created in
    // Square, not just plan/cadence/amount — two processor records (or a
    // staging clone) pointing at the same Square account/location must
    // never reuse each other's cached variation IDs.
    $cacheKey = implode('_', [
      (int) ($this->_paymentProcessor['id'] ?? 0),
      $this->isTestMode() ? 'test' : 'live',
      $this->getLocationId(),
      $planId,
      $cadence,
      $amount,
      $currency,
      $installments ?: 'indefinite',
    ]);
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
    CRM_Core_Payment_SquareDebugLogger::log("Creating label Square plan variation: {$label}");
    $amountCents = (int) round($amount * 100);

    $phaseValues = [
      'ordinal' => 0,
    // MONTHLY, ANNUAL.
      'cadence' => strtoupper($cadence),
      'pricing' => new SubscriptionPricing([
        'type' => 'STATIC',
        'priceMoney' => new Money(['amount' => $amountCents, 'currency' => $currency]),
      ]),
    ];
    // Only finite subscriptions get a periods count — sending periods: 0
    // for an open-ended subscription is wrong (0 periods, not indefinite);
    // omitting the field entirely is what tells Square the phase never ends.
    if (!empty($installments)) {
      $phaseValues['periods'] = $installments;
    }
    $phase = new SubscriptionPhase($phaseValues);

    $catalogObject = CatalogObject::subscriptionPlanVariation(new CatalogObjectSubscriptionPlanVariation([
      'id' => '#var_' . md5($cacheKey),
      'subscriptionPlanVariationData' => new CatalogSubscriptionPlanVariation([
        'name' => $label,
        'subscriptionPlanId' => $planId,
        'phases' => [$phase],
      ]),
    ]));

    $response = $this->callSquare(fn (SquareClient $client) => $client->catalog->batchUpsert(new BatchUpsertCatalogObjectsRequest([
      'idempotencyKey' => $this->idempotencyKey('plan-variation', $cacheKey),
      'batches' => [new CatalogObjectBatch(['objects' => [$catalogObject]])],
    ])));

    $objects = $response->getObjects();
    $variationId = !empty($objects[0]) ? $objects[0]->getValue()->getId() : NULL;
    CRM_Core_Payment_SquareDebugLogger::log("Created Square plan variation ID: {$variationId}");
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
   * @param string $unit
   *   Day, week, month, or year.
   * @param int $step
   *
   * @return string
   *
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
   *
   * @return string Plan ID
   *
   * @throws CRM_Core_Exception
   */
  protected function getOrCreateSubscriptionPlan(string $name): string {
    // Scope the cache by processor + environment + location, same reasoning
    // as getOrCreatePlanVariation() above.
    $cacheKey = implode('_', [
      (int) ($this->_paymentProcessor['id'] ?? 0),
      $this->isTestMode() ? 'test' : 'live',
      $this->getLocationId(),
      $name,
    ]);
    $cache = Civi::settings()->get('org_square_plan_cache') ?? [];

    if (!empty($cache[$cacheKey])) {
      return $cache[$cacheKey];
    }

    $catalogObject = CatalogObject::subscriptionPlan(new CatalogObjectSubscriptionPlan([
      'id' => '#plan_' . md5($cacheKey),
      'subscriptionPlanData' => new CatalogSubscriptionPlan([
        'name' => $name,
      ]),
    ]));

    $response = $this->callSquare(fn (SquareClient $client) => $client->catalog->batchUpsert(new BatchUpsertCatalogObjectsRequest([
      'idempotencyKey' => $this->idempotencyKey('plan', $cacheKey),
      'batches' => [new CatalogObjectBatch(['objects' => [$catalogObject]])],
    ])));

    $objects = $response->getObjects();
    $planId = !empty($objects[0]) ? $objects[0]->getValue()->getId() : NULL;
    if (!$planId) {
      throw new CRM_Core_Exception('Failed to create Square subscription plan.');
    }

    $cache[$cacheKey] = $planId;
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
        return $this->contributionStatusId('Completed');

      case 'PENDING':
      case 'PROCESSING':
        return $this->contributionStatusId('Pending');

      case 'FAILED':
      case 'DECLINED':
      case 'CANCELED':
        return $this->contributionStatusId('Failed');

      case 'REFUNDED':
        return $this->contributionStatusId('Refunded');

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
        // Apple Pay / Google Pay / Cash App Pay tokenize as cards.
      case 'WALLET':
      case 'BUY_NOW_PAY_LATER':
      case 'SQUARE_ACCOUNT':
        return $this->paymentInstrumentId('Credit Card');

      case 'BANK_ACCOUNT':
        return $this->paymentInstrumentId('EFT');

      case 'CASH':
        return $this->paymentInstrumentId('Cash');

      default:
        // Credit Card (Square's most common instrument)
        return $this->paymentInstrumentId('Credit Card');
    }
  }

  /**
   * Find the contact ID associated with a Square payment.
   *
   * Attempts to resolve contact by:
   * 1. Customer reference_id (if set in Square)
   * 2. Email address from payment receipt
   * 3. Contribution reference_id if payment is linked to existing contribution.
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
            $contact = Contact::get(FALSE)
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
      catch (\Throwable $e) {
        CRM_Core_Payment_SquareDebugLogger::log('Square findContactIdForPayment(): customer lookup error: ' . $e->getMessage());
      }
    }

    // 2. Try via reference_id on payment (if it points to a contribution)
    $referenceId = $payment['reference_id'] ?? NULL;
    if ($referenceId && ctype_digit((string) $referenceId)) {
      $contribution = Contribution::get(FALSE)
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
      $contact = Contact::get(FALSE)
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
      // CRM_Core_Payment_SquareDebugLogger::log('Square webhook: subscription.updated missing subscription ID.');.
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

    // Find the recurring contribution linked to this subscription.
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
    // Cancelled.
      ->addValue('contribution_status_id', 3)
      ->execute();

    CRM_Core_Payment_SquareDebugLogger::log("Square syncSubscriptionCancellationFromSquare(): Updated recurring contribution {$recurId} to Cancelled for subscription {$squareSubscriptionId}.");
  }

  /**
   * Override CRM_Core_Payment function.
   *
   * @return array
   */
  public function getPaymentFormFields(): array {
    return [];
  }

  /**
   * Return an array of all the details about the fields potentially required for payment fields.
   *
   * Only those determined by getPaymentFormFields will actually be assigned to the form.
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

  /**
   * Process a webhook record queued by CiviCRM's webhook worker. */
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
   * @param string $rawData
   *   Raw request body.
   * @param array $headers
   *   HTTP headers from getallheaders().
   *
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
