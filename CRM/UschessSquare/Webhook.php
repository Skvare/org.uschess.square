<?php

/**
 * Class CRM_UschessSquare_Webhook
 *
 * Legacy webhook handler kept for backwards compatibility.
 *
 * The preferred entry point is now the standard CiviCRM IPN URL:
 *   civicrm/payment/ipn/{processor_id}
 *
 * All processing logic has been moved to:
 *   CRM_Core_Payment_Square::handlePaymentNotification()  (signature validation)
 *   CRM_Core_Payment_SquareIPN::onReceiveWebhook()        (dedup, routing, logging)
 */
class CRM_UschessSquare_Webhook {

  /**
   * @var CRM_Core_Payment_Square
   */
  protected $processor;

  /**
   * @param CRM_Core_Payment_Square $processor
   */
  public function __construct($processor) {
    $this->processor = $processor;
  }

  /**
   * Handle incoming webhook request.
   *
   * Delegates entirely to Square::handlePaymentNotification() so that both
   * the legacy custom URL (civicrm/square/webhook) and the standard IPN URL
   * (civicrm/payment/ipn/{id}) use the same code path.
   */
  public function handle() {
    $this->processor->handlePaymentNotification();
  }

}
