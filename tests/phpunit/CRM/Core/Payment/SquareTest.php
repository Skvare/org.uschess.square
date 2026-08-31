<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 5) . '/CRM/Core/Payment/Square.php';

/**
 * Unit coverage for payment-processor behavior that must not depend on a
 * CiviCRM database or live Square credentials.
 */
class CRM_Core_Payment_SquareTest extends TestCase {

  private function processor(array $overrides = []): CRM_Core_Payment_Square {
    $processor = array_replace([
      'id' => 42,
      'is_test' => FALSE,
      'user_name' => 'application-id',
      'password' => 'access-token',
      'signature' => 'location-id',
      'subject' => 'signature-key',
    ], $overrides);
    return new CRM_Core_Payment_Square('live', $processor);
  }

  public function testCheckConfigReportsEveryMissingCredential(): void {
    $processor = $this->processor([
      'user_name' => '',
      'password' => '',
      'signature' => '',
      'subject' => '',
    ]);

    $error = $processor->checkConfig();

    $this->assertStringContainsString('Application ID', $error);
    $this->assertStringContainsString('Access Token', $error);
    $this->assertStringContainsString('Location ID', $error);
    $this->assertStringContainsString('Webhook Signature Key', $error);
  }

  public function testCheckConfigAcceptsCompleteCredentials(): void {
    $this->assertNull($this->processor()->checkConfig());
  }

  public function testIdempotencyKeyIsStableAndScoped(): void {
    $liveConfig = $this->processorConfig();
    $processor = new class('live', $liveConfig) extends CRM_Core_Payment_Square {
      public function key(string $operation, string $reference): string {
        return $this->idempotencyKey($operation, $reference);
      }
    };

    $same = $processor->key('payment', 'invoice-123');
    $this->assertSame($same, $processor->key('payment', 'invoice-123'));
    $this->assertNotSame($same, $processor->key('refund', 'invoice-123'));

    $sandboxConfig = $this->processorConfig(['is_test' => TRUE]);
    $sandbox = new class('test', $sandboxConfig) extends CRM_Core_Payment_Square {
      public function key(string $operation, string $reference): string {
        return $this->idempotencyKey($operation, $reference);
      }
    };
    $this->assertNotSame($same, $sandbox->key('payment', 'invoice-123'));
  }

  public function testRefundReturnsCiviRefundStatus(): void {
    $config = $this->processorConfig();
    $processor = new class('live', $config) extends CRM_Core_Payment_Square {
      protected function squareRequest($method, $endpoint, ?array $body = NULL) {
        return ['refund' => ['id' => 'refund-1', 'status' => 'COMPLETED']];
      }
    };
    $params = ['trxn_id' => 'payment-1', 'amount' => '12.34', 'currency' => 'USD'];

    $result = $processor->doRefund($params);

    $this->assertSame('COMPLETED', $result['refund_status']);
    $this->assertSame('refund-1', $result['refund_trxn_id']);
  }

  private function processorConfig(array $overrides = []): array {
    return array_replace([
      'id' => 42,
      'is_test' => FALSE,
      'user_name' => 'application-id',
      'password' => 'access-token',
      'signature' => 'location-id',
      'subject' => 'signature-key',
    ], $overrides);
  }

}
