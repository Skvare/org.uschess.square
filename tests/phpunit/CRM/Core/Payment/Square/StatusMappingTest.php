<?php

require_once __DIR__ . '/SquareUnitTestCase.php';

/**
 * Square status/instrument -> CiviCRM contribution_status_id mappings.
 *
 * These IDs matter: they're the same literals used directly throughout
 * CRM_Core_Payment_Square (1=Completed, 2=Pending, 3=Cancelled, 4=Failed,
 * 5=In Progress, 7=Refunded).
 */
class CRM_Core_Payment_Square_StatusMappingTest extends CRM_Core_Payment_Square_SquareUnitTestCase {

  private function processor(): CRM_Core_Payment_Square {
    $config = $this->processorConfig();
    return new CRM_Core_Payment_Square('live', $config);
  }

  /**
   * @dataProvider paymentStatusProvider
   */
  public function testMapPaymentStatus(string $squareStatus, ?int $expected): void {
    $actual = $this->callMethod($this->processor(), 'mapPaymentStatus', [$squareStatus]);
    $this->assertSame($expected, $actual);
  }

  public static function paymentStatusProvider(): array {
    return [
      'completed' => ['COMPLETED', 1],
      'approved' => ['APPROVED', 1],
      'pending' => ['PENDING', 2],
      'processing' => ['PROCESSING', 2],
      'failed' => ['FAILED', 4],
      'declined' => ['DECLINED', 4],
      'canceled' => ['CANCELED', 4],
      'refunded' => ['REFUNDED', 7],
      'lowercase is normalized' => ['completed', 1],
      'unknown status is unmapped' => ['SOMETHING_NEW', NULL],
    ];
  }

  /**
   * @dataProvider subscriptionStatusProvider
   */
  public function testMapSquareSubscriptionStatusToCivi(string $squareStatus, ?int $expected): void {
    $actual = $this->callMethod($this->processor(), 'mapSquareSubscriptionStatusToCivi', [$squareStatus]);
    $this->assertSame($expected, $actual);
  }

  public static function subscriptionStatusProvider(): array {
    return [
      'active maps to In Progress, not Completed' => ['ACTIVE', 5],
      'canceled maps to Cancelled' => ['CANCELED', 3],
      'deactivated maps to Cancelled' => ['DEACTIVATED', 3],
      'suspended maps to Failed/On Hold' => ['SUSPENDED', 4],
      // Square subscriptions report PENDING until start_date is reached,
      // even after a successful initial payment already moved the local
      // recur to In Progress -- must NOT downgrade back to Pending.
      'pending is intentionally left unmapped' => ['PENDING', NULL],
      'unknown status is unmapped' => ['SOMETHING_NEW', NULL],
    ];
  }

  /**
   * @dataProvider paymentInstrumentProvider
   */
  public function testMapPaymentInstrument(?string $sourceType, int $expected): void {
    $actual = $this->callMethod($this->processor(), 'mapPaymentInstrument', [$sourceType]);
    $this->assertSame($expected, $actual);
  }

  public static function paymentInstrumentProvider(): array {
    return [
      'card' => ['CARD', 1],
      'wallet tokenizes as card' => ['WALLET', 1],
      'buy now pay later' => ['BUY_NOW_PAY_LATER', 1],
      'square account' => ['SQUARE_ACCOUNT', 1],
      'bank account is EFT' => ['BANK_ACCOUNT', 5],
      'cash' => ['CASH', 3],
      'unknown defaults to credit card' => ['SOMETHING_NEW', 1],
      'null defaults to credit card' => [NULL, 1],
    ];
  }

}
