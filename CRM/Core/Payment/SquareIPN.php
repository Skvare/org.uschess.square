<?php

/**
 * Class CRM_Core_Payment_SquareIPN
 *
 * Processes Square webhook events and syncs them into CiviCRM.
 *
 * Entry point: onReceiveWebhook() — called from CRM_Core_Payment_Square::handlePaymentNotification().
 *
 * Handles:
 *   subscription.created, subscription.updated, subscription.canceled
 *   invoice.created, invoice.payment_made, invoice.payment_failed
 *   payment.updated, refund.created
 */
class CRM_Core_Payment_SquareIPN {

  /**
   * @var CRM_Core_Payment_Square
   */
  protected $_paymentProcessor;

  /**
   * @var string|null Event ID of the webhook being processed.
   */
  protected $event_id = NULL;

  /**
   * @var string The event type currently being processed.
   */
  protected $event_type = '';

  /**
   * @var string|null Square subscription ID extracted from the current event.
   */
  protected $subscription_id = NULL;

  /**
   * @var string|null Square invoice ID extracted from the current event.
   */
  protected $invoice_id = NULL;

  /**
   * @var string|null Square customer ID extracted from the current event.
   */
  protected $customer_id = NULL;

  /**
   * @var string|null Square payment ID extracted from the current event.
   */
  protected $payment_id = NULL;

  /**
   * @param CRM_Core_Payment_Square $processor
   */
  public function __construct($processor) {
    $this->_paymentProcessor = $processor;
  }

  /**
   * Square event types this class handles.
   *
   * @return string[]
   */
  public static function getSupportedEventTypes(): array {
    return [
      'subscription.created',
      'subscription.updated',
      'subscription.canceled',
      'invoice.created',
      'invoice.payment_made',
      'invoice.payment_failed',
      'payment.updated',
      'refund.created',
    ];
  }

  /**
   * Main entry point — called from Square::handlePaymentNotification().
   *
   * Handles deduplication, logging, and delegates to processWebhookEvent().
   *
   * @param array $payload Decoded JSON webhook payload.
   * @return bool TRUE on success.
   */
  public function onReceiveWebhook(array $payload): bool {
    $eventId   = $payload['event_id'] ?? NULL;
    $eventType = $payload['type'] ?? 'unknown';

    $this->event_id   = $eventId;
    $this->event_type = $eventType;

    // Ignore event types we do not handle (return 200 so Square does not retry).
    if (!in_array($eventType, self::getSupportedEventTypes(), TRUE)) {
      Civi::log()->debug("Square IPN: ignoring unsupported event type '{$eventType}'.");
      return TRUE;
    }

    // Deduplication — skip if already processed.
    if ($eventId && $this->isDuplicateEvent($eventId)) {
      $this->logWebhookDelivery($eventId, $eventType, 'Duplicate event skipped', 200);
      return TRUE;
    }

    // Mark as processed before routing to prevent parallel double-processing.
    if ($eventId) {
      $this->markEventProcessed($eventId);
    }

    try {
      $this->processWebhookEvent($payload, $eventType);
      $this->logWebhookDelivery($eventId, $eventType, 'Successfully processed', 200);
      return TRUE;
    }
    catch (Exception $e) {
      $msg = 'Square IPN error (' . $eventType . '): ' . $e->getMessage();
      Civi::log()->error($msg);
      $this->logWebhookDelivery($eventId, $eventType, $msg, 500);
      return FALSE;
    }
  }

  /**
   * Extract key identifiers from the payload for use during processing.
   *
   * @param array $payload Decoded JSON webhook payload.
   * @param string $eventType Square event type string.
   */
  public function setInputParameters(array $payload, string $eventType): void {
    $obj = $payload['data']['object'] ?? [];

    $this->event_type = $eventType;

    $this->subscription_id = $obj['subscription']['id']
      ?? $obj['invoice']['subscription_id']
      ?? NULL;

    $this->invoice_id = $obj['invoice']['id'] ?? NULL;

    $this->customer_id = $obj['subscription']['customer_id']
      ?? $obj['invoice']['primary_recipient']['customer_id']
      ?? $obj['payment']['customer_id']
      ?? NULL;

    $this->payment_id = $obj['payment']['id']
      ?? $obj['refund']['payment_id']
      ?? NULL;
  }

  /**
   * Route the webhook event to the appropriate handler.
   *
   * @param array $payload Decoded JSON webhook payload.
   * @param string $eventType Square event type string.
   * @return bool TRUE on success.
   */
  public function processWebhookEvent(array $payload, string $eventType): bool {
    $this->setInputParameters($payload, $eventType);

    $obj = $payload['data']['object'] ?? [];

    switch ($eventType) {

      case 'subscription.created':
        if (!empty($this->subscription_id)) {
          $this->_paymentProcessor->syncSubscriptionFromSquare($this->subscription_id);
          Civi::log()->debug("Square IPN: subscription.created synced for {$this->subscription_id}");
        }
        break;

      case 'subscription.updated':
        if (!empty($this->subscription_id)) {
          $this->_paymentProcessor->syncSubscriptionFromSquare($this->subscription_id);
          Civi::log()->debug("Square IPN: subscription.updated synced for {$this->subscription_id}");
        }
        break;

      case 'subscription.canceled':
        if (!empty($this->subscription_id)) {
          $this->_paymentProcessor->syncSubscriptionCancellationFromSquare($this->subscription_id);
          Civi::log()->debug("Square IPN: subscription.canceled synced for {$this->subscription_id}");
        }
        break;

      case 'invoice.created':
        $invoice = $obj['invoice'] ?? [];
        if (!empty($invoice)) {
          $this->handleInvoiceCreated($invoice);
        }
        break;

      case 'invoice.payment_made':
        $this->_paymentProcessor->handleInvoicePaymentCreated($payload);
        Civi::log()->debug("Square IPN: invoice.payment_made processed for invoice {$this->invoice_id}");
        break;

      case 'invoice.payment_failed':
        $invoice = $obj['invoice'] ?? [];
        if (!empty($invoice)) {
          $this->handleInvoicePaymentFailed($invoice);
        }
        break;

      case 'payment.updated':
        $payment = $obj['payment'] ?? [];
        if (!empty($payment)) {
          $this->_paymentProcessor->syncPaymentFromSquare($payment);
          Civi::log()->debug("Square IPN: payment.updated synced for {$this->payment_id}");
        }
        break;

      case 'refund.created':
        $refund = $obj['refund'] ?? [];
        if (!empty($refund)) {
          $this->_paymentProcessor->syncRefundFromSquare($refund);
          Civi::log()->debug("Square IPN: refund.created synced for payment {$this->payment_id}");
        }
        break;

      default:
        Civi::log()->debug("Square IPN: unhandled event type '{$eventType}'");
        break;
    }

    return TRUE;
  }

  /**
   * Handle invoice.created — create a Pending contribution for an upcoming invoice.
   *
   * Square invoice payload path:
   *   data.object.invoice.{id, subscription_id, status,
   *   payment_requests[0].computed_amount_money.{amount(cents), currency}}
   *
   * @param array $invoice Invoice object from Square webhook payload.
   */
  protected function handleInvoiceCreated(array $invoice): void {
    $invoiceId      = $invoice['id'] ?? NULL;
    $subscriptionId = $invoice['subscription_id'] ?? NULL;
    $status         = strtoupper($invoice['status'] ?? '');

    if (!$invoiceId || !$subscriptionId) {
      Civi::log()->debug('Square IPN: invoice.created missing invoice ID or subscription_id.');
      return;
    }

    // Skip invoices that are already paid — invoice.payment_made handles those.
    if (in_array($status, ['PAID', 'PAYMENT_PENDING'], TRUE)) {
      Civi::log()->debug("Square IPN: invoice.created skipped (status={$status}).");
      return;
    }

    $recur = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addWhere('processor_id', '=', $subscriptionId)
      ->addSelect('id', 'contact_id', 'financial_type_id', 'currency')
      ->execute()
      ->first();

    if (!$recur) {
      Civi::log()->debug("Square IPN: invoice.created — no recur found for subscription {$subscriptionId}.");
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

    $money    = $invoice['payment_requests'][0]['computed_amount_money'] ?? NULL;
    $amount   = $money ? (((float) $money['amount']) / 100) : 0.0;
    $currency = $money['currency'] ?? $recur['currency'] ?? 'USD';

    \Civi\Api4\Contribution::create(FALSE)
      ->addValue('contact_id', $recur['contact_id'])
      ->addValue('contribution_recur_id', $recur['id'])
      ->addValue('financial_type_id', $recur['financial_type_id'])
      ->addValue('total_amount', $amount)
      ->addValue('currency', $currency)
      ->addValue('contribution_status_id', 2) // Pending
      ->addValue('invoice_id', $invoiceId)
      ->addValue('source', 'Square Invoice (Webhook)')
      ->execute();

    Civi::log()->debug("Square IPN: Created Pending contribution for invoice {$invoiceId}.");
  }

  /**
   * Handle invoice.payment_failed — mark existing contribution as Failed or create a new Failed one.
   *
   * @param array $invoice Invoice object from Square webhook payload.
   */
  protected function handleInvoicePaymentFailed(array $invoice): void {
    $invoiceId      = $invoice['id'] ?? NULL;
    $subscriptionId = $invoice['subscription_id'] ?? NULL;

    if (!$invoiceId) {
      Civi::log()->debug('Square IPN: invoice.payment_failed missing invoice ID.');
      return;
    }

    // If a contribution already exists for this invoice, mark it Failed.
    $contribution = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('id')
      ->addWhere('invoice_id', '=', $invoiceId)
      ->execute()
      ->first();

    if ($contribution) {
      \Civi\Api4\Contribution::update(FALSE)
        ->addWhere('id', '=', $contribution['id'])
        ->addValue('contribution_status_id', 4) // Failed
        ->execute();
      Civi::log()->debug("Square IPN: Marked contribution {$contribution['id']} as Failed for invoice {$invoiceId}.");
      return;
    }

    // No existing contribution — create a Failed one from the recurring record.
    if (!$subscriptionId) {
      Civi::log()->debug("Square IPN: invoice.payment_failed — no contribution and no subscription_id for invoice {$invoiceId}.");
      return;
    }

    $recur = \Civi\Api4\ContributionRecur::get(FALSE)
      ->addWhere('processor_id', '=', $subscriptionId)
      ->addSelect('id', 'contact_id', 'financial_type_id', 'currency')
      ->execute()
      ->first();

    if (!$recur) {
      Civi::log()->debug("Square IPN: invoice.payment_failed — no recur for subscription {$subscriptionId}.");
      return;
    }

    $money    = $invoice['payment_requests'][0]['computed_amount_money'] ?? NULL;
    $amount   = $money ? (((float) $money['amount']) / 100) : 0.0;
    $currency = $money['currency'] ?? $recur['currency'] ?? 'USD';

    \Civi\Api4\Contribution::create(FALSE)
      ->addValue('contact_id', $recur['contact_id'])
      ->addValue('contribution_recur_id', $recur['id'])
      ->addValue('financial_type_id', $recur['financial_type_id'])
      ->addValue('total_amount', $amount)
      ->addValue('currency', $currency)
      ->addValue('contribution_status_id', 4) // Failed
      ->addValue('invoice_id', $invoiceId)
      ->addValue('source', 'Square Invoice Failed (Webhook)')
      ->execute();

    Civi::log()->debug("Square IPN: Created Failed contribution for invoice {$invoiceId}.");
  }

  /**
   * Check whether this event ID has already been processed (deduplication).
   *
   * @param string $eventId
   * @return bool
   */
  protected function isDuplicateEvent(string $eventId): bool {
    try {
      $count = CRM_Core_DAO::singleValueQuery(
        'SELECT COUNT(*) FROM civicrm_square_webhook_event WHERE event_id = %1',
        [1 => [$eventId, 'String']]
      );
      return (int) $count > 0;
    }
    catch (Exception $e) {
      Civi::log()->debug('Square IPN: error checking duplicate event: ' . $e->getMessage());
      return FALSE;
    }
  }

  /**
   * Record this event ID as processed to prevent replay.
   *
   * @param string $eventId
   */
  protected function markEventProcessed(string $eventId): void {
    try {
      CRM_Core_DAO::executeQuery(
        'INSERT IGNORE INTO civicrm_square_webhook_event (event_id, processed_at) VALUES (%1, NOW())',
        [1 => [$eventId, 'String']]
      );
    }
    catch (Exception $e) {
      Civi::log()->debug('Square IPN: error marking event processed: ' . $e->getMessage());
    }
  }

  /**
   * Log a webhook delivery attempt for debugging and auditing.
   *
   * @param string|null $eventId
   * @param string $eventType
   * @param string $message
   * @param int $httpStatus
   */
  protected function logWebhookDelivery(?string $eventId, string $eventType, string $message, int $httpStatus): void {
    try {
      CRM_Core_DAO::executeQuery(
        'INSERT INTO civicrm_square_webhook_delivery (event_id, event_type, message, http_status, delivered_at)
         VALUES (%1, %2, %3, %4, NOW())',
        [
          1 => [$eventId ?? '', 'String'],
          2 => [$eventType,     'String'],
          3 => [$message,       'String'],
          4 => [$httpStatus,    'Integer'],
        ]
      );
    }
    catch (Exception $e) {
      Civi::log()->debug("Square IPN [{$eventType}]: {$message} (HTTP {$httpStatus})");
    }
  }

}
