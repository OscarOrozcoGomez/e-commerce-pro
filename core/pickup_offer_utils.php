<?php
declare(strict_types=1);

/**
 * @param mixed $raw
 * @return array<int,float>
 */
function parsePickupPieceDiscountMap($raw): array
{
    $normalized = [];

    if (is_string($raw)) {
        $trimmed = trim($raw);
        if ($trimmed !== '') {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                $raw = $decoded;
            }
        }
    }

    if (!is_array($raw)) {
        return [];
    }

    foreach ($raw as $piecesRaw => $discountRaw) {
        $pieces = (int)$piecesRaw;
        $discount = round(max(0.0, (float)$discountRaw), 2);
        if ($pieces <= 0 || $discount <= 0) {
            continue;
        }
        $normalized[$pieces] = $discount;
    }

    if (empty($normalized)) {
        return [];
    }

    ksort($normalized, SORT_NUMERIC);
    return $normalized;
}

/**
 * @param array<int,float> $pieceDiscountMap
 */
function resolvePieceTierDiscountTotal(int $pieces, array $pieceDiscountMap): float
{
    if ($pieces <= 0 || empty($pieceDiscountMap)) {
        return 0.0;
    }

    if (isset($pieceDiscountMap[$pieces])) {
        return (float)$pieceDiscountMap[$pieces];
    }

    $eligibleDiscount = 0.0;
    foreach ($pieceDiscountMap as $minPieces => $discountTotal) {
        if ($pieces >= (int)$minPieces) {
            $eligibleDiscount = (float)$discountTotal;
            continue;
        }
        break;
    }

    return round(max(0.0, $eligibleDiscount), 2);
}

/**
 * @return array{activo:bool, descuento_porcentaje:float, descuento_fijo:float, subtotal_minimo:float, piezas_minimas:int, tope_descuento:float, mensaje_publico:string, descuento_por_piezas_json:string, descuentos_por_pieza:array<int,float>}
 */
function getPickupOfferSettings(PDO $pdo): array
{
    $defaults = [
        'activo' => false,
        'descuento_porcentaje' => 0.0,
        'descuento_fijo' => 0.0,
        'subtotal_minimo' => 0.0,
        'piezas_minimas' => 1,
        'tope_descuento' => 0.0,
        'mensaje_publico' => '',
        'descuento_por_piezas_json' => '{}',
        'descuentos_por_pieza' => [],
    ];

    try {
        $stmt = $pdo->query("SELECT activo, descuento_porcentaje, descuento_fijo, subtotal_minimo, piezas_minimas, tope_descuento, mensaje_publico, descuento_por_piezas_json FROM sucursal_incentivos WHERE id_regla = 1 LIMIT 1");
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if (!$row) {
            return $defaults;
        }

        $pieceMap = parsePickupPieceDiscountMap($row['descuento_por_piezas_json'] ?? '{}');

        return [
            'activo' => ((int)($row['activo'] ?? 0)) === 1,
            'descuento_porcentaje' => max(0.0, (float)($row['descuento_porcentaje'] ?? 0)),
            'descuento_fijo' => max(0.0, (float)($row['descuento_fijo'] ?? 0)),
            'subtotal_minimo' => max(0.0, (float)($row['subtotal_minimo'] ?? 0)),
            'piezas_minimas' => max(1, (int)($row['piezas_minimas'] ?? 1)),
            'tope_descuento' => max(0.0, (float)($row['tope_descuento'] ?? 0)),
            'mensaje_publico' => trim((string)($row['mensaje_publico'] ?? '')) ?: $defaults['mensaje_publico'],
            'descuento_por_piezas_json' => json_encode($pieceMap, JSON_UNESCAPED_UNICODE),
            'descuentos_por_pieza' => $pieceMap,
        ];
    } catch (Throwable $e) {
        return $defaults;
    }
}

/**
 * @param array{activo:bool, descuento_porcentaje:float, descuento_fijo:float, subtotal_minimo:float, piezas_minimas:int, tope_descuento:float, mensaje_publico:string, descuento_por_piezas_json?:string, descuentos_por_pieza?:array<int,float>} $settings
 * @return array{subtotal:float, piezas:int, elegible:bool, ahorro:float, total_sucursal:float, faltante_subtotal:float, faltante_piezas:int}
 */
function calculatePickupOffer(float $subtotal, int $pieces, array $settings): array
{
    $subtotal = max(0.0, round($subtotal, 2));
    $pieces = max(0, $pieces);

    $pieceMap = parsePickupPieceDiscountMap($settings['descuentos_por_pieza'] ?? ($settings['descuento_por_piezas_json'] ?? []));
    $hasDiscountMap = !empty($pieceMap);
    $elegible = (bool)$settings['activo'] && $hasDiscountMap;

    $ahorro = 0.0;
    if ($elegible) {
        $ahorro = resolvePieceTierDiscountTotal($pieces, $pieceMap);
        $ahorro = min($ahorro, $subtotal);
        $ahorro = round(max(0.0, $ahorro), 2);
    }

    return [
        'subtotal' => $subtotal,
        'piezas' => $pieces,
        'elegible' => $elegible,
        'ahorro' => $ahorro,
        'total_sucursal' => round(max(0.0, $subtotal - $ahorro), 2),
        'faltante_subtotal' => 0.0,
        'faltante_piezas' => 0,
    ];
}
