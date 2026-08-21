<?php
declare(strict_types=1);

/**
 * Numero minimo de pedidos reales (no cancelados) para marcar a un cliente como "Frecuente".
 * Definido por el dueño del negocio; subir este numero conforme crezca el volumen de pedidos
 * (no hay pantalla de admin para esto todavia, es a proposito).
 */
const CLIENTE_FRECUENTE_MIN_PEDIDOS = 7;

/**
 * IDs de clientes que califican como "Frecuente": CLIENTE_FRECUENTE_MIN_PEDIDOS o mas pedidos
 * que no terminaron cancelados. Se regresa el set completo (no una funcion por cliente) porque
 * las pantallas que lo usan casi siempre listan varios clientes a la vez; el llamador solo
 * checa pertenencia (in_array/isset) sobre el resultado.
 *
 * @return int[]
 */
function clienteFrecuenteGetIds(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        "SELECT id_cliente FROM pedidos
         WHERE id_cliente IS NOT NULL AND estado <> 'cancelado'
         GROUP BY id_cliente
         HAVING COUNT(*) >= ?"
    );
    // bindValue explicito: PDO vincula placeholders como string por defecto, y comparar un
    // COUNT(*) entero contra un texto en HAVING da resultados distintos segun el motor (MySQL
    // lo tolera, SQLite -usado en las pruebas- no) -- forzar PARAM_INT lo vuelve correcto en
    // ambos.
    $stmt->bindValue(1, CLIENTE_FRECUENTE_MIN_PEDIDOS, PDO::PARAM_INT);
    $stmt->execute();

    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Verifica si UN cliente especifico califica como "Frecuente". Para pantallas que ya conocen
 * el id_cliente puntual (ej. detalle de una compra) en vez de tener que listar varios.
 */
function clienteEsFrecuente(PDO $pdo, ?int $idCliente): bool
{
    if ($idCliente === null || $idCliente <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM pedidos WHERE id_cliente = ? AND estado <> 'cancelado'"
    );
    $stmt->execute([$idCliente]);

    return ((int)$stmt->fetchColumn()) >= CLIENTE_FRECUENTE_MIN_PEDIDOS;
}

/**
 * HTML del chip visual para marcar a un cliente frecuente, consistente en todas las pantallas
 * donde el staff ve al cliente (lista de clientes, POS, asignar entregas, tarjetas de
 * repartidor, detalle de compra).
 */
function clienteFrecuenteBadgeHtml(): string
{
    return '<span class="chip green white-text" style="height:22px; line-height:22px; font-size:0.72rem; font-weight:bold; padding:0 8px; margin:0 0 0 6px;"><i class="material-icons tiny" style="vertical-align:middle;">star</i> Frecuente</span>';
}
