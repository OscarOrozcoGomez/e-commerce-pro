<?php
declare(strict_types=1);

/**
 * @return array{activo:bool, descuento_porcentaje:float, descuento_fijo:float, subtotal_minimo:float, piezas_minimas:int, tope_descuento:float, mensaje_publico:string}
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
    ];

    try {
        $stmt = $pdo->query("SELECT activo, descuento_porcentaje, descuento_fijo, subtotal_minimo, piezas_minimas, tope_descuento, mensaje_publico FROM sucursal_incentivos WHERE id_regla = 1 LIMIT 1");
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
        if (!$row) {
            return $defaults;
        }

        return [
            'activo' => ((int)($row['activo'] ?? 0)) === 1,
            'descuento_porcentaje' => max(0.0, (float)($row['descuento_porcentaje'] ?? 0)),
            'descuento_fijo' => max(0.0, (float)($row['descuento_fijo'] ?? 0)),
            'subtotal_minimo' => max(0.0, (float)($row['subtotal_minimo'] ?? 0)),
            'piezas_minimas' => max(1, (int)($row['piezas_minimas'] ?? 1)),
            'tope_descuento' => max(0.0, (float)($row['tope_descuento'] ?? 0)),
            'mensaje_publico' => trim((string)($row['mensaje_publico'] ?? '')) ?: $defaults['mensaje_publico'],
        ];
    } catch (Throwable $e) {
        return $defaults;
    }
}

/**
 * @param array{activo:bool, descuento_porcentaje:float, descuento_fijo:float, subtotal_minimo:float, piezas_minimas:int, tope_descuento:float, mensaje_publico:string} $settings
 * @return array{subtotal:float, piezas:int, elegible:bool, ahorro:float, total_sucursal:float, faltante_subtotal:float, faltante_piezas:int}
 */
function calculatePickupOffer(float $subtotal, int $pieces, array $settings): array
{
    $subtotal = max(0.0, round($subtotal, 2));
    $pieces = max(0, $pieces);

    $faltanteSubtotal = max(0.0, round(((float)$settings['subtotal_minimo']) - $subtotal, 2));
    $faltantePiezas = max(0, ((int)$settings['piezas_minimas']) - $pieces);
    $elegible = (bool)$settings['activo'] && $faltanteSubtotal <= 0 && $faltantePiezas <= 0;

    $ahorro = 0.0;
    if ($elegible) {
        $ahorroPorcentaje = round($subtotal * ((float)$settings['descuento_porcentaje'] / 100), 2);
        $ahorro = $ahorroPorcentaje + (float)$settings['descuento_fijo'];
        $tope = (float)$settings['tope_descuento'];
        if ($tope > 0) {
            $ahorro = min($ahorro, $tope);
        }
        $ahorro = min($ahorro, $subtotal);
        $ahorro = round(max(0.0, $ahorro), 2);
    }

    return [
        'subtotal' => $subtotal,
        'piezas' => $pieces,
        'elegible' => $elegible,
        'ahorro' => $ahorro,
        'total_sucursal' => round(max(0.0, $subtotal - $ahorro), 2),
        'faltante_subtotal' => $faltanteSubtotal,
        'faltante_piezas' => $faltantePiezas,
    ];
}
