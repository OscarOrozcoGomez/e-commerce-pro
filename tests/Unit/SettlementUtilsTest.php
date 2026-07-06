<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SettlementUtilsTest extends TestCase
{
    public function testCalculateBaseAmountSubtractsCommissionFromSales(): void
    {
        $base = settlementCalculateBaseAmount(1219.0, 5, 50.0);
        $this->assertSame(969.0, $base);
    }

    public function testCalculatePendingAmountSupportsAccumulatedDeliveredValue(): void
    {
        $pending = settlementCalculatePendingAmount(969.0, 500.0);
        $this->assertSame(469.0, $pending);
    }

    public function testCalculatePendingAmountReturnsZeroWhenDeliveredCoversBase(): void
    {
        $pending = settlementCalculatePendingAmount(600.0, 600.0);
        $this->assertSame(0.0, $pending);
    }

    public function testResolveDeclaredAmountUsesPendingWhenInputMissingOrZero(): void
    {
        $this->assertSame(350.0, settlementResolveDeclaredAmount(null, 350.0));
        $this->assertSame(350.0, settlementResolveDeclaredAmount(0.0, 350.0));
    }

    public function testResolveDeclaredAmountCapsInputToPending(): void
    {
        $this->assertSame(200.0, settlementResolveDeclaredAmount(250.0, 200.0));
    }

    public function testAccumulatedScenarioAcrossSeveralDaysMatchesExpectedPending(): void
    {
        $base = settlementCalculateBaseAmount(2500.0, 20, 50.0); // 2500 - 1000 = 1500
        $pendingAfterPreviousCuts = settlementCalculatePendingAmount($base, 900.0);

        $this->assertSame(600.0, $pendingAfterPreviousCuts);
        $this->assertSame(600.0, settlementResolveDeclaredAmount(0.0, $pendingAfterPreviousCuts));
    }
}
