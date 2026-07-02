<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class FinanceUtilsTest extends TestCase
{
    public function testCalculateGrossProfitReturnsIncomeMinusCost(): void
    {
        $this->assertSame(125.5, financeCalculateGrossProfit(200.0, 74.5));
    }

    public function testBuildDailySeriesFillsMissingDaysWithZeros(): void
    {
        $series = financeBuildDailySeries([
            ['fecha' => '2026-07-01', 'ingresos' => 100, 'costos' => 40],
            ['fecha' => '2026-07-03', 'ingresos' => 250, 'costos' => 120],
        ], '2026-07-01', '2026-07-03');

        $this->assertCount(3, $series);
        $this->assertSame('2026-07-01', $series[0]['fecha']);
        $this->assertSame(100.0, $series[0]['ingresos']);
        $this->assertSame(60.0, $series[0]['utilidad']);
        $this->assertSame(0.0, $series[1]['ingresos']);
        $this->assertSame('2026-07-02', $series[1]['fecha']);
        $this->assertSame(130.0, $series[2]['utilidad']);
    }

    public function testBuildDailySeriesIgnoresRowsWithoutFecha(): void
    {
        $series = financeBuildDailySeries([
            ['ingresos' => 100, 'costos' => 10],
        ], '2026-07-01', '2026-07-01');

        $this->assertCount(1, $series);
        $this->assertSame(0.0, $series[0]['ingresos']);
        $this->assertSame(0.0, $series[0]['utilidad']);
    }
}