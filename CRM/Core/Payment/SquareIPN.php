<?php

use Civi\Api4\Contribution;
use Civi\Api4\ContributionRecur;
use Civi\Api4\PaymentprocessorWebhook;

/**
 * Class CRM_Core_Payment_SquareIPN.
 *
 * Processes Square webhook events and syncs them into CiviCRM.
 *
 * Entry point: onReceiveWebhook() — called from CRM_Core_Payment_Square::handlePaymentNotification().
 *
 * Handles:
 *   subscription.created, subscription.updated, subscription.canceled
 *   invoice.created, invoice.payment_made, invoice.payment_failed
 *   payment.updated, refund.created
 *
 * Webhook lifecycle:
 *   1. onReceiveWebhook() validates the event type, deduplicates via
 *      civicrm_paymentprocessor_webhook (a table owned by the mjwshared
 *      extension, not CiviCRM core — this extension requires it, see
 *      info.xml) and returns immediately.
 *   2. mjwshared's "Process Payment Processor Webhooks" scheduled job
 *      (Job.process_paymentprocessor_webhooks) polls status='new' rows and
 *      calls CRM_Core_Payment_Square::processWebhookEvent(), which invokes
 *      processQueuedWebhookEvent() here. Transient failures are left
 *      status='new' for the job to retry; permanent failures are marked
 *      'error'.
 */
class CRM_Core_Payment_SquareIPN {

  /**
   * Payment processor handling this webhook.
   *
   * @var CRM_Core_Payment_Square
   */
  protected $_paymentProcessor;

  /**
   * Webhook event ID being processed.
   *
   * @var string|null
   */
  protected $event_id = NULL;

  /**
   * Webhook event type being processed.
   *
   * @var string
   */
  protected $event_type = '';

  /**
   * Square subscription ID from the current event.
   *
   * @var string|null
   */
  protected $subscription_id = NULL;

  /**
   * Square invoice ID from the current event.
   *
   * @var string|null
   */
  protected $invoice_id = NULL;

  /**
   * Square customer ID from the current event.
   *
   * @var string|null
   */
  protected $customer_id = NULL;

  /**
   * Square payment ID from the current event.
   *
   * @var string|null
   */
  protected $payment_id = NULL;

  /**
   * The data provided by the IPN.
   *
   * @var array|Object|string
   */
  protected $data;

  /**
   * Create an IPN processor.
   *
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
   * Records the webhook in civicrm_paymentprocessor_webhook for
   * deduplication and audit trail, then returns immediately — it does NOT
   * process the event inline. mjwshared's
   * "Process Payment Processor Webhooks" scheduled job (Job.
   * process_paymentprocessor_webhooks) polls rows with status='new' and
   * calls CRM_Core_Payment_Square::processWebhookEvent(), which is what
   * actually invokes processQueuedWebhookEvent(). This keeps webhook
   * delivery fast and lets a struggling downstream (CiviCRM DB, Square
   * API) be retried by the job instead of by Square's own webhook retries.
   *
   * @param array $payload
   *   Decoded JSON webhook payload.
   *
   * @return bool TRUE on success.
   */
  public function onReceiveWebhook(array $payload): bool {
    $eventId = $payload['event_id'] ?? NULL;
    $eventType = $payload['type'] ?? 'unknown';

    $this->event_id = $eventId;
    $this->event_type = $eventType;

    CRM_Core_Payment_SquareDebugLogger::log("Square IPN: onReceiveWebhook() called. event_id={$eventId}, type={$eventType}, processor_id={$this->_paymentProcessor->getID()}");

    // Ignore event types we do not handle (return 200 so Square does not retry).
    if (!in_array($eventType, self::getSupportedEventTypes(), TRUE)) {
      CRM_Core_Payment_SquareDebugLogger::log("Square IPN: ignoring unsupported event type '{$eventType}'.");
      return TRUE;
    }

    $this->setInputParameters($payload, $eventType);
    $identifier = $this->getWebhookIdentifier();
    $processorId = $this->_paymentProcessor->getID();

    if (!$eventId) {
      Civi::log()->error('Square IPN: webhook event has no event_id.');
      return FALSE;
    }

    // Guard the check-then-insert dedup below with a lock scoped to this
    // processor+event, so two concurrent deliveries of the same event
    // (Square does redeliver) can't both pass the "no existing row" check
    // and create duplicate queue records.
    $lock = new CRM_Core_Lock("worker.square.webhook.{$processorId}.{$eventId}", 30);
    if (!$lock->acquire()) {
      CRM_Core_Payment_SquareDebugLogger::log("Square IPN: could not acquire dedup lock for event '{$eventId}', treating as in-flight duplicate.");
      return TRUE;
    }

    try {
      // Deduplicate across every queue state. A previously failed event
      // remains retryable; accepting a replay must not create a second
      // queue record.
      $existingWebhooks = PaymentprocessorWebhook::get(FALSE)
        ->addWhere('payment_processor_id', '=', $processorId)
        ->addWhere('event_id', '=', (string) $eventId)
        ->execute();

      foreach ($existingWebhooks as $existing) {
        CRM_Core_Payment_SquareDebugLogger::log("Square IPN: duplicate event '{$eventId}' already queued, skipping.");
        return TRUE;
      }

      PaymentprocessorWebhook::create(FALSE)
        ->addValue('payment_processor_id', $processorId)
        ->addValue('trigger', $eventType)
        ->addValue('identifier', $identifier)
        ->addValue('event_id', (string) ($eventId ?? ''))
        ->addValue('data', $this->getData())
        ->execute();
    }
    finally {
      $lock->release();
    }

    return TRUE;
  }

  /**
   * Process a single queued webhook event and update its record.
   *
   * Called by CRM_Core_Payment_Square::processWebhookEvent(), which
   * mjwshared's Job.process_paymentprocessor_webhooks invokes for every
   * queued row with status='new'.
   *
   * @param array $webhookEvent
   *
   * @return bool TRUE on success.
   */
  public function processQueuedWebhookEvent(array $webhookEvent): bool {
    $payload = $webhookEvent['data'];
    if (is_string($payload)) {
      $payload = json_decode($payload, TRUE) ?? [];
    }

    $eventType = $webhookEvent['trigger'];
    $this->event_id = $webhookEvent['event_id'];
    $this->event_type = $eventType;

    CRM_Core_Payment_SquareDebugLogger::log("Square IPN: processQueuedWebhookEvent() called. webhook_id={$webhookEvent['id']}, event_id={$this->event_id}, type={$eventType}");

    $this->setInputParameters($payload, $eventType);

    $ok = FALSE;
    // 'error' = permanent failure, will not be retried by the scheduled
    // job. 'new' = transient failure, left for the job to retry.
    $status = 'error';
    $message = '';

    try {
      $this->processWebhookEvent($payload, $eventType);
      $ok = TRUE;
      $status = 'success';
      $message = 'Processed successfully';
    }
    catch (CRM_Core_Payment_SquareRetryableException $e) {
      $status = 'new';
      $message = $e->getMessage();
      Civi::log()->warning("Square IPN: processQueuedWebhookEvent transient failure, will retry. EventID: {$this->event_id}: " . $e->getMessage());
    }
    catch (\Throwable $e) {
      // Catches Error/TypeError too, not just Exception — otherwise a bug
      // here would leave the queue row stuck in 'processing' forever
      // (see api/v3/Job/ProcessPaymentprocessorWebhooks.php in mjwshared).
      $message = $e->getMessage() . "\n" . $e->getTraceAsString();
      Civi::log()->error("Square IPN: processQueuedWebhookEvent failed. EventID: {$this->event_id}: " . $e->getMessage());
    }

    $update = PaymentprocessorWebhook::update(FALSE)
      ->addWhere('id', '=', $webhookEvent['id'])
      ->addValue('status', $status)
      ->addValue('message', preg_replace('/^(.{250}).*/su', '$1 ...', $message));
    if ($ok) {
      $update->addValue('processed_date', 'now');
    }
    $update->execute();

    return $ok;
  }

  /**
   * Build a queue identifier for related webhook events.
   *
   * Related invoice events share an identifier and are processed serially.
   *
   * @return string
   */
  private function getWebhookIdentifier(): string {
    return implode(':', [
      $this->payment_id ?? '',
      $this->invoice_id ?? '',
      $this->subscription_id ?? '',
    ]);
  }

  /**
   * Extract key identifiers from the payload for use during processing.
   *
   * @param array $payload
   *   Decoded JSON webhook payload.
   * @param string $eventType
   *   Square event type string.
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
   * @param array $payload
   *   Decoded JSON webhook payload.
   * @param string $eventType
   *   Square event type string.
   *
   * @return bool TRUE on success.
   *
   * @throws \Exception
   *   On processing failure.
   */
  public function processWebhookEvent(array $payload, string $eventType): bool {
    $obj = $payload['data']['object'] ?? [];

    CRM_Core_Payment_SquareDebugLogger::log("Square IPN: processWebhookEvent() dispatching event_id={$this->event_id}, type={$eventType}, subscription_id=" . ($this->subscription_id ?? 'null') . ", invoice_id=" . ($this->invoice_id ?? 'null') . ", payment_id=" . ($this->payment_id ?? 'null'));

    switch ($eventType) {

      case 'subscription.created':
        if (!empty($this->subscription_id)) {
          $this->_paymentProcessor->syncSubscriptionFromSquare($this->subscription_id);
          CRM_Core_Payment_SquareDebugLogger::log("Square IPN: subscription.created synced for {$this->subscription_id}");
        }
        break;

      case 'subscription.updated':
        if (!empty($this->subscription_id)) {
          $this->_paymentProcessor->syncSubscriptionFromSquare($this->subscription_id);
          CRM_Core_Payment_SquareDebugLogger::log("Square IPN: subscription.updated synced for {$this->subscription_id}");
        }
        break;

      case 'subscription.canceled':
        if (!empty($this->subscription_id)) {
          $this->_paymentProcessor->syncSubscriptionCancellationFromSquare($this->subscription_id);
          CRM_Core_Payment_SquareDebugLogger::log("Square IPN: subscription.canceled synced for {$this->subscription_id}");
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
        CRM_Core_Payment_SquareDebugLogger::log("Square IPN: invoice.payment_made processed for invoice {$this->invoice_id}");
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
          CRM_Core_Payment_SquareDebugLogger::log("Square IPN: payment.updated synced for {$this->payment_id}");
        }
        break;

      case 'refund.created':
        $refund = $obj['refund'] ?? [];
        if (!empty($refund)) {
          $this->_paymentProcessor->syncRefundFromSquare($refund);
          CRM_Core_Payment_SquareDebugLogger::log("Square IPN: refund.created synced for payment {$this->payment_id}");
        }
        break;

      default:
        CRM_Core_Payment_SquareDebugLogger::log("Square IPN: unhandled event type '{$eventType}'");
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
   * @param array $invoice
   *   Invoice object from Square webhook payload.
   */
  protected function handleInvoiceCreated(array $invoice): void {
    $invoiceId = $invoice['id'] ?? NULL;
    $subscriptionId = $invoice['subscription_id'] ?? NULL;
    $status = strtoupper($invoice['status'] ?? '');

    if (!$invoiceId || !$subscriptionId) {
      CRM_Core_Payment_SquareDebugLogger::log('Square IPN: invoice.created missing invoice ID or subscription_id.');
      return;
    }

    // Skip invoices that are already paid — invoice.payment_made handles those.
    if (in_array($status, ['PAID', 'PAYMENT_PENDING'], TRUE)) {
      CRM_Core_Payment_SquareDebugLogger::log("Square IPN: invoice.created skipped (status={$status}).");
      return;
    }

    $recur = ContributionRecur::get(FALSE)
      ->addWhere('processor_id', '=', $subscriptionId)
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->execute()
      ->first();

    if (!$recur) {
      CRM_Core_Payment_SquareDebugLogger::log("Square IPN: invoice.created — no recur found for subscription {$subscriptionId}.");
      return;
    }

    // Prevent duplicates.
    $existing = Contribution::get(FALSE)
      ->addSelect('id')
      ->addWhere('invoice_id', '=', $invoiceId)
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->execute()
      ->first();

    if ($existing) {
      return;
    }

    $money = $invoice['payment_requests'][0]['computed_amount_money'] ?? NULL;
    $amount = $money ? (((float) $money['amount']) / 100) : 0.0;
    $currency = $money['currency'] ?? $recur['currency'] ?? 'USD';
    $orderID = $invoice['order_id'] ?? NULL;
    Contribution::create(FALSE)
      ->addValue('contact_id', $recur['contact_id'])
      ->addValue('contribution_recur_id', $recur['id'])
      ->addValue('financial_type_id', $recur['financial_type_id'])
      ->addValue('total_amount', $amount)
      ->addValue('currency', $currency)
    // Pending.
      ->addValue('contribution_status_id', 2)
      ->addValue('invoice_id', $invoiceId)
      ->addValue('invoice_number', $orderID)
      ->addValue('is_test', $recur['is_test'])
      ->addValue('source', 'Square Invoice (Webhook)')
      ->execute();

    CRM_Core_Payment_SquareDebugLogger::log("Square IPN: Created Pending contribution for invoice {$invoiceId}.");
  }

  /**
   * Handle invoice.payment_failed — mark existing contribution as Failed or create a new Failed one.
   *
   * @param array $invoice
   *   Invoice object from Square webhook payload.
   */
  protected function handleInvoicePaymentFailed(array $invoice): void {
    $invoiceId = $invoice['id'] ?? NULL;
    $subscriptionId = $invoice['subscription_id'] ?? NULL;

    if (!$invoiceId) {
      CRM_Core_Payment_SquareDebugLogger::log('Square IPN: invoice.payment_failed missing invoice ID.');
      return;
    }

    // If a contribution already exists for this invoice, mark it Failed.
    $contribution = Contribution::get(FALSE)
      ->addSelect('id')
      ->addWhere('invoice_id', '=', $invoiceId)
      ->execute()
      ->first();

    if (!empty($contribution)) {
      Contribution::update(FALSE)
        ->addWhere('id', '=', $contribution['id'])
      // Failed.
        ->addValue('contribution_status_id', 4)
        ->execute();
      CRM_Core_Payment_SquareDebugLogger::log("Square IPN: Marked contribution {$contribution['id']} as Failed for invoice {$invoiceId}.");
      return;
    }

    // No existing contribution — create a Failed one from the recurring record.
    if (!$subscriptionId) {
      CRM_Core_Payment_SquareDebugLogger::log("Square IPN: invoice.payment_failed — no contribution and no subscription_id for invoice {$invoiceId}.");
      return;
    }

    $recur = ContributionRecur::get(FALSE)
      ->addWhere('processor_id', '=', $subscriptionId)
      ->addWhere('is_test', 'IN', [TRUE, FALSE])
      ->addSelect('id', 'contact_id', 'financial_type_id', 'currency')
      ->execute()
      ->first();

    if (!$recur) {
      CRM_Core_Payment_SquareDebugLogger::log("Square IPN: invoice.payment_failed — no recur for subscription {$subscriptionId}.");
      return;
    }

    $money = $invoice['payment_requests'][0]['computed_amount_money'] ?? NULL;
    $amount = $money ? (((float) $money['amount']) / 100) : 0.0;
    $currency = $money['currency'] ?? $recur['currency'] ?? 'USD';

    Contribution::create(FALSE)
      ->addValue('contact_id', $recur['contact_id'])
      ->addValue('contribution_recur_id', $recur['id'])
      ->addValue('financial_type_id', $recur['financial_type_id'])
      ->addValue('total_amount', $amount)
      ->addValue('currency', $currency)
    // Failed.
      ->addValue('contribution_status_id', 4)
      ->addValue('invoice_id', $invoiceId)
      ->addValue('source', 'Square Invoice Failed (Webhook)')
      ->execute();

    CRM_Core_Payment_SquareDebugLogger::log("Square IPN: Created Failed contribution for invoice {$invoiceId}.");
  }

  /**
   * Set the raw IPN data.
   *
   * @param Object|array|string $data
   */
  public function setData(object|array|string $data) {
    $this->data = $data;
  }

  /**
   * Get the raw IPN data.
   *
   * @return object|array|string
   */
  public function getData(): object|array|string {
    return $this->data;
  }

}
