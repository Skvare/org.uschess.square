<?php

use Civi\Api4\Generic\DAOCreateAction;

class CRM_UschessSquare_Webhook {

  protected $processor;

  public function __construct($processor) {
    $this->processor = $processor;
  }

  /**
   * Handle incoming webhook requests from Square.
   */
  public function handle() {
    $raw = file_get_contents('php://input');
    $headers = getallheaders();
    $url = $this->getNotificationUrl();

    try {
      // Validate signature
      if (!$this->isValidSquareSignature($raw, $headers, $url)) {
        $this->logWebhookDelivery(NULL, 'INVALID_SIGNATURE', 'Signature validation failed', 401);
        header("HTTP/1.1 401 Unauthorized");
        echo "Invalid signature";
        return;
      }

      // Parse payload
      $payload = json_decode($raw, TRUE);
      if (!$payload) {
        $this->logWebhookDelivery(NULL, 'INVALID_JSON', 'Failed to decode JSON body', 400);
        header("HTTP/1.1 400 Bad Request");
        echo "Invalid JSON";
        return;
      }

      $eventId = $payload['event_id'] ?? NULL;
      $eventType = $payload['type'] ?? 'unknown';

      // Check for duplicates
      if ($eventId && $this->isDuplicateEvent($eventId)) {
        $this->logWebhookDelivery($eventId, $eventType, 'Duplicate event skipped', 200);
        header("HTTP/1.1 200 OK");
        echo "OK";
        return;
      }

      // Mark as processed
      if ($eventId) {
        $this->markEventProcessed($eventId);
      }

      // Route event
      $this->routeEvent($payload, $eventType, $eventId);

      // Log success
      $this->logWebhookDelivery($eventId, $eventType, 'Successfully processed', 200);

      header("HTTP/1.1 200 OK");
      echo "OK";
    }
    catch (Exception $e) {
      $eventId = $payload['event_id'] ?? NULL;
      $eventType = $payload['type'] ?? 'unknown';
      $errorMsg = "Webhook processing error: " . $e->getMessage();
      
      $this->logWebhookDelivery($eventId, $eventType, $errorMsg, 500);
      Civi::log()->error($errorMsg);

      header("HTTP/1.1 500 Internal Server Error");
      echo "Error processing webhook";
    }
  }

  /**
   * Route webhook event to appropriate handler.
   *
   * @param array $payload
   * @param string $eventType
   * @param string|null $eventId
   */
  protected function routeEvent(array $payload, $eventType, $eventId = NULL) {
    try {
      switch ($eventType) {

        case 'payment.created':
        case 'payment.updated':
          $payment = $payload['data']['object']['payment'] ?? [];
          if (!empty($payment)) {
            $this->processor->syncPaymentFromSquare($payment);
          }
          break;

        case 'payment.refunded':
          $refund = $payload['data']['object']['refund'] ?? [];
          if (!empty($refund)) {
            $this->processor->syncRefundFromSquare($refund);
          }
          break;

        case 'subscription.created':
        case 'subscription.updated':
          $subscription = $payload['data']['object']['subscription'] ?? [];
          $subscriptionId = $subscription['id'] ?? NULL;
          if ($subscriptionId) {
            $this->processor->syncSubscriptionFromSquare($subscriptionId);
          }
          break;

        case 'subscription.canceled':
        case 'subscription.deleted':
          $subscription = $payload['data']['object']['subscription'] ?? [];
          $subscriptionId = $subscription['id'] ?? NULL;
          if ($subscriptionId) {
            $this->processor->syncSubscriptionCancellationFromSquare($subscriptionId);
          }
          break;

        case 'invoice.paid':
        case 'invoice.payment_failed':
          $invoice = $payload['data']['object']['invoice'] ?? [];
          if (!empty($invoice)) {
            $this->processor->syncInvoiceFromSquare($invoice);
          }
          break;

        case 'payment.failed':
          $payment = $payload['data']['object']['payment'] ?? [];
          if (!empty($payment)) {
            $this->handlePaymentFailed($payment);
          }
          break;

        default:
          Civi::log()->debug("Square Webhook: Unhandled event type {$eventType}");
          break;
      }
    }
    catch (Exception $e) {
      Civi::log()->error("Square Webhook: Error routing event {$eventType}: " . $e->getMessage());
      throw $e;
    }
  }

  /**
   * Handle payment.failed webhook event.
   *
   * @param array $payment
   */
  protected function handlePaymentFailed(array $payment) {
    $paymentId = $payment['id'] ?? NULL;
    if (!$paymentId) {
      Civi::log()->debug('Square webhook: payment.failed missing payment ID.');
      return;
    }

    // Find contribution by transaction ID
    $contribution = \Civi\Api4\Contribution::get(FALSE)
      ->addSelect('id')
      ->addWhere('trxn_id', '=', $paymentId)
      ->execute()
      ->first();

    if (!$contribution) {
      Civi::log()->debug("Square webhook: No contribution found for failed payment {$paymentId}");
      return;
    }

    // Update status to Failed (4)
    \Civi\Api4\Contribution::update(FALSE)
      ->addWhere('id', '=', $contribution['id'])
      ->addValue('contribution_status_id', 4) // Failed
      ->execute();

    Civi::log()->debug("Square webhook: Marked contribution {$contribution['id']} as failed for payment {$paymentId}");
  }

  /**
   * Validate Square webhook signature (Square 2024–2025 standard).
   */
  protected function isValidSquareSignature($raw, $headers, $url) {
    // Normalize header keys to lowercase
    $normalized = [];
    foreach ($headers as $k => $v) {
      $normalized[strtolower($k)] = $v;
    }

    $key = $this->processor->getWebhookSignatureKey();
    if (!$key) {
      CRM_Core_Error::debug_log_message("Square Webhook: Missing webhook signature key");
      return FALSE;
    }

    // Square sends "X-Square-Signature"
    $provided = $normalized['x-square-signature'] ?? NULL;
    if (!$provided) {
      CRM_Core_Error::debug_log_message(
        "Square Webhook: Signature header missing. Available headers: " . json_encode(array_keys($normalized))
      );
      return FALSE;
    }

    // Square Webhook Signature Algorithm (2024–2025):
    // expected = base64encode( HMAC-SHA256( notification_url + request_body, signature_key ) )
    $message = $url . $raw;

    $expected = base64_encode(
      hash_hmac('sha256', $message, $key, TRUE)
    );

    // Prevent timing attacks
    $valid = hash_equals($expected, $provided);

    if (!$valid) {
      CRM_Core_Error::debug_log_message(
        "Square Webhook: Signature mismatch. expected=$expected provided=$provided url=$url"
      );
    }

    return $valid;
  }

  /**
   * Build the callback URL used by Square signature verification.
   */
  protected function getNotificationUrl() {
    $base = CRM_Utils_System::url('civicrm/square/webhook', NULL, TRUE, NULL, TRUE);
    return $base;
  }

  /**
   * Prevent replay attacks by checking database for processed event IDs.
   */
  protected function isDuplicateEvent($eventId) {
    if (!$eventId) {
      return FALSE;
    }

    try {
      $existing = \Civi\Api4\SquareWebhookEvent::get(FALSE)
        ->addWhere('event_id', '=', $eventId)
        ->addSelect('id')
        ->execute()
        ->first();

      return !empty($existing);
    }
    catch (Exception $e) {
      // If table doesn't exist, fall back to no deduplication
      Civi::log()->debug('Square webhook: Error checking duplicate event: ' . $e->getMessage());
      return FALSE;
    }
  }

  /**
   * Mark event as processed in database.
   */
  protected function markEventProcessed($eventId) {
    if (!$eventId) {
      return;
    }

    try {
      \Civi\Api4\SquareWebhookEvent::create(FALSE)
        ->addValue('event_id', $eventId)
        ->addValue('processed_at', date('Y-m-d H:i:s'))
        ->execute();
    }
    catch (Exception $e) {
      // If table doesn't exist, log but don't fail
      Civi::log()->debug('Square webhook: Error marking event processed: ' . $e->getMessage());
    }
  }

  /**
   * Log webhook delivery for debugging and monitoring.
   *
   * @param string|null $eventId
   * @param string $eventType
   * @param string $message
   * @param int $httpStatus
   */
  protected function logWebhookDelivery($eventId, $eventType, $message, $httpStatus) {
    try {
      \Civi\Api4\SquareWebhookDelivery::create(FALSE)
        ->addValue('event_id', $eventId)
        ->addValue('event_type', $eventType)
        ->addValue('message', $message)
        ->addValue('http_status', $httpStatus)
        ->addValue('delivered_at', date('Y-m-d H:i:s'))
        ->execute();
    }
    catch (Exception $e) {
      // If table doesn't exist, log to CiviCRM logs instead
      Civi::log()->debug("Square Webhook [{$eventType}]: {$message} (HTTP {$httpStatus})");
    }
  }
}