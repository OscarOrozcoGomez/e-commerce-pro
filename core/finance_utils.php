<?php
declare(strict_types=1);

function financeToFloat(mixed $value): float
{
    return round((float) $value, 2);
}

function financeCalculateGrossProfit(float $income, float $cost): float
{
    return round($income - $cost, 2);
}

/**
 * Expande filas agrupadas por día dentro de un rango y completa los huecos en cero.
 *
 * Cada fila debe traer la llave `fecha` en formato YYYY-MM-DD y las columnas
 * numéricas `ingresos` y `costos`. La utilidad se calcula como ingresos - costos.
 *
 * @return array<int, array{fecha:string, ingresos:float, costos:float, utilidad:float}>
 */
function financeBuildDailySeries(array $rows, string $startDate, string $endDate): array
{
    $index = [];
    foreach ($rows as $row) {
        $fecha = (string) ($row['fecha'] ?? '');
        if ($fecha === '') {
            continue;
        }

        $ingresos = financeToFloat($row['ingresos'] ?? 0);
        $costos = financeToFloat($row['costos'] ?? 0);
        $index[$fecha] = [
            'fecha' => $fecha,
            'ingresos' => $ingresos,
            'costos' => $costos,
            'utilidad' => financeCalculateGrossProfit($ingresos, $costos),
        ];
    }

    $series = [];
    $cursor = new DateTimeImmutable($startDate);
    $end = new DateTimeImmutable($endDate);

    while ($cursor <= $end) {
        $key = $cursor->format('Y-m-d');
        $series[] = $index[$key] ?? [
            'fecha' => $key,
            'ingresos' => 0.0,
            'costos' => 0.0,
            'utilidad' => 0.0,
        ];
        $cursor = $cursor->modify('+1 day');
    }

    return $series;
}
