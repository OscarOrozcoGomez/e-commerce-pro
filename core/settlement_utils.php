<?php
declare(strict_types=1);

function settlementCalculateBaseAmount(float $salesTotal, int $soldPieces, float $commissionPerPiece): float
{
    $commissionTotal = round(max(0, $soldPieces) * max(0.0, $commissionPerPiece), 2);
    return round(max(0.0, $salesTotal - $commissionTotal), 2);
}

function settlementCalculatePendingAmount(float $baseAmount, float $deliveredAmount): float
{
    return round(max(0.0, $baseAmount - max(0.0, $deliveredAmount)), 2);
}

function settlementResolveDeclaredAmount(?float $declaredInput, float $pendingAmount): float
{
    if ($declaredInput === null || $declaredInput <= 0) {
        return round(max(0.0, $pendingAmount), 2);
    }

    return round(min($declaredInput, max(0.0, $pendingAmount)), 2);
}
