<?php
declare(strict_types=1);

/**
 * Utilidades puras para la pantalla de entregas del repartidor y la de asignacion:
 *
 *  - Agrupacion de tarjetas por dia (encabezados de fecha legibles en espanol).
 *  - Validacion de "entregar sin evidencia" (el repartidor no tomo la foto).
 *  - Calculo del cambio a devolver cuando el cliente paga en efectivo.
 *
 * Sin dependencias de PDO ni de sesion para poder cubrirlas con pruebas unitarias.
 */

/**
 * Clave de dia (YYYY-MM-DD) usada para agrupar entregas. Devuelve '' cuando la fecha viene
 * vacia o no se puede interpretar (p.ej. entregas sin dia programado).
 */
function deliveryDiaKey(?string $fecha): string
{
    $fecha = trim((string) $fecha);
    if ($fecha === '') {
        return '';
    }
    $ts = strtotime($fecha);
    // strtotime('0000-00-00 ...') (fecha cero de MySQL) no devuelve false sino un timestamp
    // de anio negativo; se descarta igual que una fecha vacia.
    if ($ts === false || (int) date('Y', $ts) < 2000) {
        return '';
    }
    return date('Y-m-d', $ts);
}

/**
 * Etiqueta legible en espanol para los encabezados que agrupan entregas por dia.
 * Ej. "Lunes 1 de septiembre de 2026 - hoy". Devuelve "Sin dia programado" cuando la fecha
 * viene vacia o no se puede interpretar.
 *
 * @param ?string $hoy Fecha de referencia (Y-m-d) para "hoy/manana/ayer"; null = hoy real.
 *                     Parametro pensado para las pruebas.
 */
function deliveryFormatDiaLabel(?string $fecha, ?string $hoy = null): string
{
    $fecha = trim((string) $fecha);
    if ($fecha === '') {
        return 'Sin día programado';
    }
    $ts = strtotime($fecha);
    if ($ts === false || (int) date('Y', $ts) < 2000) {
        return 'Sin día programado';
    }

    $hoyTs = $hoy !== null ? strtotime($hoy) : time();
    if ($hoyTs === false) {
        $hoyTs = time();
    }

    $dias = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'];
    $meses = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

    $ymd = date('Y-m-d', $ts);
    $rel = '';
    if ($ymd === date('Y-m-d', $hoyTs)) {
        $rel = ' · hoy';
    } elseif ($ymd === date('Y-m-d', strtotime('+1 day', $hoyTs))) {
        $rel = ' · mañana';
    } elseif ($ymd === date('Y-m-d', strtotime('-1 day', $hoyTs))) {
        $rel = ' · ayer';
    }

    return ucfirst($dias[(int) date('w', $ts)]) . ' ' . (int) date('j', $ts) . ' de '
        . $meses[(int) date('n', $ts) - 1] . ' de ' . date('Y', $ts) . $rel;
}

/**
 * Motivos preestablecidos para cuando el repartidor confirma la entrega SIN foto de
 * evidencia. La clave es lo que viaja en el POST; el valor es la etiqueta que se guarda en
 * el log de auditoria y en observaciones.
 *
 * @return array<string, string>
 */
function deliverySinEvidenciaReasonOptions(): array
{
    return [
        'olvide_foto' => 'Se me olvido tomar la foto en la entrega',
        'fallo_camara' => 'La camara o el telefono fallo',
        'cliente_no_permitio' => 'El cliente no permitio tomar la foto',
        'otro' => 'Otro',
    ];
}

/**
 * Valida los campos del formulario "entregar sin evidencia".
 *
 * @param array<string, mixed> $post Normalmente $_POST.
 * @return array{omitir: bool, valid: bool, motivo_etiqueta: string, error: string}
 *         - omitir: si el repartidor marco explicitamente "entregar sin evidencia".
 *         - valid: true si se puede continuar (omitir=false, o omitir=true con motivo valido).
 *         - motivo_etiqueta: texto ya resuelto del motivo (vacio si omitir=false).
 *         - error: mensaje cuando valid=false.
 */
function deliveryValidateSinEvidencia(array $post): array
{
    $omitir = (string) ($post['omitir_evidencia'] ?? '') === '1';
    if (!$omitir) {
        return ['omitir' => false, 'valid' => true, 'motivo_etiqueta' => '', 'error' => ''];
    }

    $opciones = deliverySinEvidenciaReasonOptions();
    $key = trim((string) ($post['motivo_sin_evidencia'] ?? ''));
    $otro = trim((string) ($post['motivo_sin_evidencia_otro'] ?? ''));

    if (!isset($opciones[$key])) {
        return ['omitir' => true, 'valid' => false, 'motivo_etiqueta' => '', 'error' => 'Selecciona un motivo para entregar sin evidencia.'];
    }
    if ($key === 'otro' && $otro === '') {
        return ['omitir' => true, 'valid' => false, 'motivo_etiqueta' => '', 'error' => 'Escribe el motivo cuando selecciones "Otro".'];
    }

    $etiqueta = $key === 'otro' ? mb_substr($otro, 0, 180) : $opciones[$key];

    return ['omitir' => true, 'valid' => true, 'motivo_etiqueta' => $etiqueta, 'error' => ''];
}

/**
 * Convierte un texto (de un input) a monto. Acepta "$", espacios y comas de miles
 * ("$ 1,250.50" -> 1250.5). La coma se trata SIEMPRE como separador de miles (convencion
 * MXN, punto decimal); "1,50" se interpreta como 150, no como 1.5.
 *
 * @param mixed $valor
 * @return float|null null si no hay un numero interpretable.
 */
function deliveryParseMonto($valor): ?float
{
    if (is_int($valor) || is_float($valor)) {
        return is_nan((float) $valor) ? null : (float) $valor;
    }
    $txt = trim((string) $valor);
    if ($txt === '') {
        return null;
    }
    $txt = str_replace([',', '$', ' ', "\u{00A0}"], '', $txt);
    if (!preg_match('/^-?\d*\.?\d+$/', $txt)) {
        return null;
    }
    return (float) $txt;
}

/**
 * Calcula el cambio a devolver cuando el cliente paga en efectivo.
 *
 * @param mixed $total    Total del pedido (numero o texto).
 * @param mixed $pagaCon  Con cuanto paga el cliente (numero o texto).
 * @return array{
 *     valid: bool, total: float, paga_con: float, cambio: float, falta: float,
 *     suficiente: bool, error: string
 * }
 *   - valid: true si $pagaCon es un numero >= 0 (hay algo que calcular).
 *   - cambio: paga_con - total, nunca negativo, redondeado a 2 decimales.
 *   - falta: total - paga_con cuando el pago no alcanza, nunca negativo.
 *   - suficiente: si el pago cubre el total (con tolerancia de 1 centavo).
 */
function deliveryCalcularCambio($total, $pagaCon): array
{
    $totalNum = deliveryParseMonto($total);
    if ($totalNum === null || $totalNum < 0) {
        $totalNum = 0.0;
    }
    $totalNum = round($totalNum, 2);

    $pagaNum = deliveryParseMonto($pagaCon);

    $base = [
        'valid' => false,
        'total' => $totalNum,
        'paga_con' => 0.0,
        'cambio' => 0.0,
        'falta' => $totalNum,
        'suficiente' => false,
        'error' => '',
    ];

    if ($pagaNum === null) {
        return $base;
    }
    if ($pagaNum < 0) {
        $base['error'] = 'El monto no puede ser negativo.';
        return $base;
    }

    $pagaNum = round($pagaNum, 2);
    $diff = round($pagaNum - $totalNum, 2);
    $suficiente = $diff >= -0.005; // tolerancia de 1 centavo por redondeo

    return [
        'valid' => true,
        'total' => $totalNum,
        'paga_con' => $pagaNum,
        'cambio' => $suficiente ? max(0.0, $diff) : 0.0,
        'falta' => $suficiente ? 0.0 : round($totalNum - $pagaNum, 2),
        'suficiente' => $suficiente,
        'error' => '',
    ];
}
