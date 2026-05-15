<?php

use Civi\Api4\PaymentprocessorWebhook;

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
 *
 * Webhook lifecycle:
 *   1. onReceiveWebhook() validates the event type, deduplicates via
 *      civicrm_paymentprocessor_webhook, records the event, then calls
 *      processQueuedWebhookEvent() immediately.
 *   2. processQueuedWebhookEvent() can also be called by the CiviCRM
 *      "Process Pending Webhooks" scheduled job for any records left
 *      with processed_date IS NULL.
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
   * Records the webhook in civicrm_paymentprocessor_webhook for deduplication
   * and audit trail, then processes it immediately.
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

    $this->setInputParameters($payload, $eventType);
    $identifier  = $this->getWebhookIdentifier();
    $processorId = $this->_paymentProcessor->getID();

    // Deduplication: skip if we already have an unprocessed record for this event_id.
    $existingWebhooks = PaymentprocessorWebhook::get(FALSE)
      ->addWhere('payment_processor_id', '=', $processorId)
      ->addWhere('identifier', '=', $identifier)
      ->addWhere('processed_date', 'IS NULL')
      ->execute();

    foreach ($existingWebhooks as $existing) {
      if ($existing['event_id'] === (string) $eventId) {
        Civi::log()->debug("Square IPN: duplicate event '{$eventId}' already queued, skipping.");
        return TRUE;
      }
    }

    $newWebhookEvent = PaymentprocessorWebhook::create(FALSE)
      ->addValue('payment_processor_id', $processorId)
      ->addValue('trigger', $eventType)
      ->addValue('identifier', $identifier)
      ->addValue('event_id', (string) ($eventId ?? ''))
      ->addValue('data', $payload)
      ->execute()
      ->first();

    return $this->processQueuedWebhookEvent($newWebhookEvent);
  }

  /**
   * Process a single queued webhook event and update its record.
   *
   * Called inline from onReceiveWebhook() and may also be called by the
   * CiviCRM "Process Pending Webhooks" scheduled job.
   *
   * @param array $webhookEvent Row from civicrm_paymentprocessor_webhook.
   * @return bool TRUE on success.
   */
  public function processQueuedWebhookEvent(array $webhookEvent): bool {
    $payload = $webhookEvent['data'];
    if (is_string($payload)) {
      $payload = json_decode($payload, TRUE) ?? [];
    }

    $eventType = $webhookEvent['trigger'];
    $this->event_id   = $webhookEvent['event_id'];
    $this->event_type = $eventType;

    $this->setInputParameters($payload, $eventType);

    $ok      = FALSE;
    $message = '';

    try {
      $this->processWebhookEvent($payload, $eventType);
      $ok      = TRUE;
      $message = 'Processed successfully';
    }
    catch (Exception $e) {
      $message = $e->getMessage() . "\n" . $e->getTraceAsString();
      Civi::log()->error("Square IPN: processQueuedWebhookEvent failed. EventID: {$this->event_id}: " . $e->getMessage());
    }

    PaymentprocessorWebhook::update(FALSE)
      ->addWhere('id', '=', $webhookEvent['id'])
      ->addValue('status', $ok ? 'success' : 'error')
      ->addValue('message', preg_replace('/^(.{250}).*/su', '$1 ...', $message))
      ->addValue('processed_date', 'now')
      ->execute();

    return $ok;
  }

  /**
   * Build a unique identifier for this webhook that lets the queue detect
   * related events (e.g. invoice.created + invoice.payment_made for the
   * same invoice share an identifier so they are serialised, not raced).
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
   * @throws \Exception on processing failure.
   */
  public function processWebhookEvent(array $payload, string $eventType): bool {
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

}
