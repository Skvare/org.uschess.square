<?php

use PHPUnit\Framework\TestCase;

// Adjust path as needed if your extension lives in a different root structure.
require_once __DIR__ . '/../../../CRM/UschessSquare/Webhook.php';

/**
 * Unit tests for the Square Webhook handler.
 *
 * These tests are deliberately mock-only and focus on
 * logic that does not require a CiviCRM database:
 *  - Signature validation
 *  - Status mapping helpers
 */
class CRM_UschessSquare_WebhookTest extends TestCase {

  /**
   * Helper to build a webhook instance with a mocked processor.
   *
   * @param string|null $signatureKey
   *   The webhook signature key to return from getConfigParam().
   *
   * @return CRM_UschessSquare_Webhook
   */
  protected function createWebhook(?string $signatureKey): CRM_UschessSquare_Webhook {
    // Build a very small mock with a getConfigParam() method.
    $processor = $this->getMockBuilder(stdClass::class)
      ->addMethods(['getConfigParam'])
      ->getMock();

    $processor->method('getConfigParam')
      ->with('webhook_signature_key')
      ->willReturn($signatureKey);

    return new CRM_UschessSquare_Webhook($processor);
  }

  public function testIsValidSquareSignatureReturnsTrueForValidSignature(): void {
    $raw = json_encode(['type' => 'payment.created']);
    $secret = 'test-secret-key';

    $expectedSignature = base64_encode(hash_hmac('sha1', $raw, $secret, true));
    $headers = [
      'x-square-signature' => $expectedSignature,
    ];

    $webhook = $this->createWebhook($secret);

    $refMethod = new ReflectionMethod(CRM_UschessSquare_Webhook::class, 'isValidSquareSignature');
    $refMethod->setAccessible(true);

    $this->assertTrue(
      $refMethod->invoke($webhook, $raw, $headers),
      'Expected a valid signature to be accepted.'
    );
  }

  public function testIsValidSquareSignatureReturnsFalseForInvalidSignature(): void {
    $raw = json_encode(['type' => 'payment.created']);
    $secret = 'test-secret-key';

    $headers = [
      'x-square-signature' => 'this-is-not-correct',
    ];

    $webhook = $this->createWebhook($secret);

    $refMethod = new ReflectionMethod(CRM_UschessSquare_Webhook::class, 'isValidSquareSignature');
    $refMethod->setAccessible(true);

    $this->assertFalse(
      $refMethod->invoke($webhook, $raw, $headers),
      'Expected an invalid signature to be rejected.'
    );
  }

  public function testIsValidSquareSignatureReturnsFalseWhenNoHeaderPresent(): void {
    $raw = json_encode(['type' => 'payment.created']);
    $secret = 'test-secret-key';

    $headers = [];

    $webhook = $this->createWebhook($secret);

    $refMethod = new ReflectionMethod(CRM_UschessSquare_Webhook::class, 'isValidSquareSignature');
    $refMethod->setAccessible(true);

    $this->assertFalse(
      $refMethod->invoke($webhook, $raw, $headers),
      'Expected missing signature header to be rejected.'
    );
  }

  public function testIsValidSquareSignatureReturnsFalseWhenNoSecretConfigured(): void {
    $raw = json_encode(['type' => 'payment.created']);

    $headers = [
      'x-square-signature' => 'anything',
    ];

    // No secret configured.
    $webhook = $this->createWebhook(null);

    $refMethod = new ReflectionMethod(CRM_UschessSquare_Webhook::class, 'isValidSquareSignature');
    $refMethod->setAccessible(true);

    $this->assertFalse(
      $refMethod->invoke($webhook, $raw, $headers),
      'Expected missing signature key in config to cause failure.'
    );
  }

  public function testMapPaymentStatusMappings(): void {
    $webhook = $this->createWebhook('irrelevant');

    $refMethod = new ReflectionMethod(CRM_UschessSquare_Webhook::class, 'mapPaymentStatus');
    $refMethod->setAccessible(true);

    $this->assertSame('Completed', $refMethod->invoke($webhook, 'COMPLETED'));
    $this->assertSame('Completed', $refMethod->invoke($webhook, 'approved'));

    $this->assertSame('Cancelled', $refMethod->invoke($webhook, 'canceled'));
    $this->assertSame('Cancelled', $refMethod->invoke($webhook, 'voided'));

    $this->assertSame('Failed', $refMethod->invoke($webhook, 'failed'));

    $this->assertSame('Pending', $refMethod->invoke($webhook, 'pending'));
    $this->assertSame('Pending', $refMethod->invoke($webhook, 'authorized'));
    $this->assertSame('Pending', $refMethod->invoke($webhook, 'some-weird-status'));
  }

  public function testMapSubscriptionStatusMappings(): void {
    $webhook = $this->createWebhook('irrelevant');

    $refMethod = new ReflectionMethod(CRM_UschessSquare_Webhook::class, 'mapSubscriptionStatus');
    $refMethod->setAccessible(true);

    $this->assertSame('In Progress', $refMethod->invoke($webhook, 'ACTIVE'));

    $this->assertSame('Cancelled', $refMethod->invoke($webhook, 'canceled'));
    $this->assertSame('Cancelled', $refMethod->invoke($webhook, 'paused'));
    $this->assertSame('Cancelled', $refMethod->invoke($webhook, 'suspended'));

    $this->assertSame('Pending', $refMethod->invoke($webhook, 'unknown-status'));
  }
}
