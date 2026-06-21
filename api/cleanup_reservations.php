<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json');

// Permite ejecución local sin sesión para cron/manual en localhost
$isLocalCron = (!isAuthenticated() && ($_SERVER['REMOTE_ADDR'] ?? '') === '127.0.0.1');
if (!$isLocalCron) {
    requireAuth();
    if (!isAdmin() && !isEncargado()) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'No autorizado']);
        exit;
    }
}

$pdo = getPDO();
$defaultExpiryHours = max(1, (int)getEnvVar('RESERVATION_EXPIRY_HOURS', '48'));

function jsonResponse(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

function resolveThresholdHours($value, int $fallback): int
{
    $hours = (int)$value;
    if ($hours <= 0) {
        $hours = $fallback;
    }
    return min(720, max(1, $hours));
}

function resolveApplyThreshold($value, bool $fallback = true): bool
{
    if ($value === null) {
        return $fallback;
    }
    if (is_bool($value)) {
        return $value;
    }
    return in_array((string)$value, ['1', 'true', 'yes', 'on'], true);
}

function buildOrdersWhereClause(bool $applyThreshold, int $expiryHours): string
{
    $where = "p.estado IN ('pendiente_pago', 'apartado')
              AND EXISTS (
                  SELECT 1 FROM detalle_pedidos dpv
                  WHERE dpv.id_pedido = p.id_pedido AND dpv.cantidad > 0
              )";

    if ($applyThreshold) {
        $where .= " AND p.fecha_creacion <= DATE_SUB(NOW(), INTERVAL {$expiryHours} HOUR)";
    }

    return $where;
}

function recalculateOrderTotals(PDO $pdo, int $orderId): void
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(subtotal), 0) AS subtotal_actual FROM detalle_pedidos WHERE id_pedido = ?");
    $stmt->execute([$orderId]);
    $subtotalActual = (float)($stmt->fetchColumn() ?: 0);

    $stmtDiscount = $pdo->prepare("SELECT COALESCE(descuento_total, 0) FROM pedidos WHERE id_pedido = ?");
    $stmtDiscount->execute([$orderId]);
    $descuentoTotal = (float)($stmtDiscount->fetchColumn() ?: 0);
    $nuevoTotal = max(0.0, $subtotalActual - $descuentoTotal);

    $stmtUpdate = $pdo->prepare("UPDATE pedidos SET subtotal = ?, total = ? WHERE id_pedido = ?");
    $stmtUpdate->execute([$subtotalActual, $nuevoTotal, $orderId]);
}

function getExpiredOrdersPreview(PDO $pdo, int $expiryHours, bool $applyThreshold): array
{
    $where = buildOrdersWhereClause($applyThreshold, $expiryHours);

    $sql = "SELECT p.id_pedido, p.numero_pedido, p.estado, p.fecha_creacion, p.total,
                   COALESCE(c.nombre, 'Cliente General') AS cliente_nombre,
                   COALESCE(c.telefono, '') AS cliente_telefono,
                   COALESCE(u.nombre, 'Sistema') AS usuario_nombre,
                   TIMESTAMPDIFF(HOUR, p.fecha_creacion, NOW()) AS horas_reservado
            FROM pedidos p
            LEFT JOIN clientes c ON p.id_cliente = c.id_cliente
            LEFT JOIN usuarios u ON p.id_usuario = u.id_usuario
            WHERE {$where}
            ORDER BY p.fecha_creacion ASC";

    $orders = $pdo->query($sql)->fetchAll() ?: [];
    if (empty($orders)) {
        return [];
    }

    $ids = array_map(static fn(array $o): int => (int)$o['id_pedido'], $orders);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $sqlItems = "SELECT dp.id_detalle, dp.id_pedido, dp.id_producto, dp.cantidad,
                   pr.nombre, pr.nombre_variante, pr.sku
               FROM detalle_pedidos dp
               JOIN productos pr ON pr.id_producto = dp.id_producto
               WHERE dp.id_pedido IN ($placeholders)
                 AND dp.cantidad > 0
               ORDER BY dp.id_pedido ASC";
    $stmtItems = $pdo->prepare($sqlItems);
    $stmtItems->execute($ids);
    $itemRows = $stmtItems->fetchAll() ?: [];

    $itemsByOrder = [];
    foreach ($itemRows as $row) {
        $id = (int)$row['id_pedido'];
        if (!isset($itemsByOrder[$id])) {
            $itemsByOrder[$id] = [];
        }
        $itemsByOrder[$id][] = [
            'id_detalle' => (int)$row['id_detalle'],
            'id_producto' => (int)$row['id_producto'],
            'cantidad' => (int)$row['cantidad'],
            'nombre' => trim((string)$row['nombre'] . (!empty($row['nombre_variante']) ? ' - ' . $row['nombre_variante'] : '')),
            'sku' => (string)($row['sku'] ?? ''),
        ];
    }

    foreach ($orders as &$order) {
        $orderId = (int)$order['id_pedido'];
        $order['id_pedido'] = $orderId;
        $order['horas_reservado'] = (int)$order['horas_reservado'];
        $order['items'] = $itemsByOrder[$orderId] ?? [];
    }
    unset($order);

    return $orders;
}

try {
    $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    $expiryHours = resolveThresholdHours($_GET['threshold_hours'] ?? null, $defaultExpiryHours);
    $applyThreshold = resolveApplyThreshold($_GET['apply_threshold'] ?? null, true);

    if ($method === 'GET') {
        $preview = getExpiredOrdersPreview($pdo, $expiryHours, $applyThreshold);
        jsonResponse([
            'success' => true,
            'apply_threshold' => $applyThreshold,
            'threshold_hours' => $expiryHours,
            'total' => count($preview),
            'orders' => $preview,
        ]);
    }

    $payload = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($payload)) {
        $payload = [];
    }

    $expiryHours = resolveThresholdHours($payload['threshold_hours'] ?? ($_GET['threshold_hours'] ?? null), $defaultExpiryHours);
    $applyThreshold = resolveApplyThreshold($payload['apply_threshold'] ?? ($_GET['apply_threshold'] ?? null), true);
    $action = (string)($payload['action'] ?? 'release_orders');

    if ($action === 'release_item') {
        $detailId = (int)($payload['detail_id'] ?? 0);
        $requestedQty = (int)($payload['release_qty'] ?? 0);
        if ($detailId <= 0) {
            jsonResponse(['success' => false, 'error' => 'Detalle invalido para liberar.'], 422);
        }

        $pdo->beginTransaction();

        $where = buildOrdersWhereClause($applyThreshold, $expiryHours);
        $sqlDetail = "SELECT dp.id_detalle, dp.id_pedido, dp.id_producto, dp.cantidad,
                             dp.precio_unitario, p.id_almacen
                      FROM detalle_pedidos dp
                      JOIN pedidos p ON p.id_pedido = dp.id_pedido
                      WHERE dp.id_detalle = ?
                        AND dp.cantidad > 0
                        AND {$where}
                      FOR UPDATE";
        $stmtDetail = $pdo->prepare($sqlDetail);
        $stmtDetail->execute([$detailId]);
        $detail = $stmtDetail->fetch();

        if (!$detail) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            jsonResponse(['success' => false, 'error' => 'El producto no es elegible para liberar con el filtro actual.'], 404);
        }

        $currentQty = (int)$detail['cantidad'];
        $releaseQty = $requestedQty > 0 ? min($requestedQty, $currentQty) : $currentQty;
        $newQty = $currentQty - $releaseQty;

        $stmtRestock = $pdo->prepare("UPDATE inventario_almacen
                                      SET cantidad_actual = cantidad_actual + ?
                                      WHERE id_producto = ? AND id_almacen = ?");
        $stmtRestock->execute([$releaseQty, (int)$detail['id_producto'], (int)$detail['id_almacen']]);

        $newSubtotal = $newQty * (float)$detail['precio_unitario'];
        $stmtUpdateDetail = $pdo->prepare("UPDATE detalle_pedidos SET cantidad = ?, subtotal = ? WHERE id_detalle = ?");
        $stmtUpdateDetail->execute([$newQty, $newSubtotal, $detailId]);

        recalculateOrderTotals($pdo, (int)$detail['id_pedido']);

        $stmtRemain = $pdo->prepare("SELECT COUNT(*) FROM detalle_pedidos WHERE id_pedido = ? AND cantidad > 0");
        $stmtRemain->execute([(int)$detail['id_pedido']]);
        $remainingItems = (int)$stmtRemain->fetchColumn();
        if ($remainingItems === 0) {
            $stmtCancel = $pdo->prepare("UPDATE pedidos SET estado = 'cancelado', observaciones = CONCAT(COALESCE(observaciones,''), ' | Cancelado por liberacion total: tiempo maximo de apartado excedido; otro cliente en espera.') WHERE id_pedido = ?");
            $stmtCancel->execute([(int)$detail['id_pedido']]);
        } else {
            $stmtObs = $pdo->prepare("UPDATE pedidos SET observaciones = CONCAT(COALESCE(observaciones,''), ' | Producto liberado parcialmente por exceder tiempo de apartado; inventario reasignado.') WHERE id_pedido = ?");
            $stmtObs->execute([(int)$detail['id_pedido']]);
        }

        if (isAuthenticated()) {
            logAudit('PRODUCTO_LIBERADO', 'detalle_pedidos', $detailId, "Liberacion manual de producto del pedido #" . (int)$detail['id_pedido'] . " (cantidad {$releaseQty})");
        }

        $pdo->commit();
        jsonResponse([
            'success' => true,
            'message' => "Se libero {$releaseQty} unidad(es) del producto seleccionado.",
            'id_pedido' => (int)$detail['id_pedido'],
            'id_detalle' => $detailId,
            'cantidad_restante' => $newQty,
            'remaining_items' => $remainingItems,
            'threshold_hours' => $expiryHours,
            'apply_threshold' => $applyThreshold,
        ]);
    }

    $releaseAll = !empty($payload['release_all']);
    $orderIdsRaw = $payload['order_ids'] ?? [];
    $orderIds = [];
    if (is_array($orderIdsRaw)) {
        foreach ($orderIdsRaw as $id) {
            $idNum = (int)$id;
            if ($idNum > 0) {
                $orderIds[] = $idNum;
            }
        }
        $orderIds = array_values(array_unique($orderIds));
    }

    if (!$releaseAll && empty($orderIds)) {
        jsonResponse([
            'success' => false,
            'error' => 'No se enviaron pedidos para liberar.',
        ], 422);
    }

    $pdo->beginTransaction();

    $where = buildOrdersWhereClause($applyThreshold, $expiryHours);
    $params = [];
    if (!$releaseAll) {
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
        $where .= " AND p.id_pedido IN ($placeholders)";
        $params = $orderIds;
    }

    $sqlTargets = "SELECT p.id_pedido, p.id_almacen
                   FROM pedidos p
                   WHERE $where
                   FOR UPDATE";
    $stmtTargets = $pdo->prepare($sqlTargets);
    $stmtTargets->execute($params);
    $pedidosAExpirar = $stmtTargets->fetchAll() ?: [];

    $count = 0;
    $releasedIds = [];
    foreach ($pedidosAExpirar as $p) {
        $id_pedido = (int)$p['id_pedido'];
        $id_almacen = (int)$p['id_almacen'];

        // Devolver stock al almacén original
        $stmtItems = $pdo->prepare("SELECT id_producto, cantidad FROM detalle_pedidos WHERE id_pedido = ? AND cantidad > 0");
        $stmtItems->execute([$id_pedido]);
        $items = $stmtItems->fetchAll() ?: [];

        foreach ($items as $item) {
            $stmtRestock = $pdo->prepare("UPDATE inventario_almacen 
                                         SET cantidad_actual = cantidad_actual + ? 
                                         WHERE id_producto = ? AND id_almacen = ?");
            $stmtRestock->execute([(int)$item['cantidad'], (int)$item['id_producto'], $id_almacen]);
        }

        $stmtCancel = $pdo->prepare("UPDATE pedidos SET estado = 'cancelado', observaciones = CONCAT(COALESCE(observaciones,''), ' | Expirado por tiempo limite de reserva; inventario liberado para otros clientes.') WHERE id_pedido = ?");
        $stmtCancel->execute([$id_pedido]);

        if (isAuthenticated()) {
            $scope = $applyThreshold ? "reserva expirada ({$expiryHours}h)" : 'sin filtro de tiempo';
            logAudit('PEDIDO_LIBERADO', 'pedidos', $id_pedido, "Liberacion manual de stock {$scope}");
        }

        $count++;
        $releasedIds[] = $id_pedido;
    }

    $pdo->commit();
    $scopeLabel = $applyThreshold ? 'expirados' : 'pendientes/apartados';
    jsonResponse([
        'success' => true,
        'message' => "Se liberaron {$count} pedidos {$scopeLabel}.",
        'apply_threshold' => $applyThreshold,
        'threshold_hours' => $expiryHours,
        'pedidos_procesados' => $count,
        'released_ids' => $releasedIds,
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    jsonResponse(['success' => false, 'error' => $e->getMessage()], 500);
}
