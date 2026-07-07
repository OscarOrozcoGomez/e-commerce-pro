<?php
declare(strict_types=1);

/**
 * Normaliza los items de entrada para evitar cantidades o IDs inválidos.
 *
 * @return array<int, array{id_producto:int, id_almacen:int, cantidad:int}>
 */
function purchaseOrderNormalizeInboundItems(array $items): array
{
    $normalized = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $idProducto = (int) ($item['id_producto'] ?? 0);
        $idAlmacen = (int) ($item['id_almacen'] ?? 0);
        $cantidad = (int) ($item['cantidad'] ?? 0);

        if ($idProducto <= 0 || $idAlmacen <= 0 || $cantidad <= 0) {
            continue;
        }

        $normalized[] = [
            'id_producto' => $idProducto,
            'id_almacen' => $idAlmacen,
            'cantidad' => $cantidad,
        ];
    }

    return $normalized;
}

/**
 * @return array<int, array{id_producto:int, id_almacen:int, motivo:string}>
 */
function purchaseOrderNormalizePostponeItems(array $items): array
{
    $normalized = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $idProducto = (int) ($item['id_producto'] ?? 0);
        $idAlmacen = (int) ($item['id_almacen'] ?? 0);
        $motivo = trim((string) ($item['motivo'] ?? 'No disponible por proveedor'));

        if ($idProducto <= 0 || $idAlmacen <= 0) {
            continue;
        }

        $normalized[] = [
            'id_producto' => $idProducto,
            'id_almacen' => $idAlmacen,
            'motivo' => $motivo,
        ];
    }

    return $normalized;
}

/**
 * @return array{listaCompra: array<int, array<string,mixed>>, chartData: array<int, array<string,mixed>>}
 */
function purchaseOrderFetchSuggestions(PDO $pdo, bool $adminMode, ?int $idAlmacen = null): array
{
    $params = [];
    $warehouseFilter = '';

    if (!$adminMode) {
        $warehouseFilter = ' AND ia.id_almacen = :id_almacen';
        $params[':id_almacen'] = (int) $idAlmacen;
    }

    $sql = "SELECT p.id_producto, p.nombre, p.sku, p.precio_costo, p.precio_venta, ia.cantidad_actual, ia.stock_minimo, ia.stock_maximo, a.nombre AS sucursal, ia.id_almacen
            FROM productos p
            JOIN inventario_almacen ia ON p.id_producto = ia.id_producto
            JOIN almacenes a ON ia.id_almacen = a.id_almacen
            LEFT JOIN purchase_order_postponed_items ppi
                ON ppi.id_producto = ia.id_producto
                AND ppi.id_almacen = ia.id_almacen
                AND ppi.estado = 'pendiente'
            WHERE ia.cantidad_actual <= ia.stock_minimo
              AND p.estado = 'activo'
              AND ppi.id_postergacion IS NULL" . $warehouseFilter . "
            ORDER BY a.nombre, p.nombre";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $listaCompra = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $sqlChart = "SELECT COALESCE(c.nombre, 'Sin Categoría') AS categoria, COUNT(DISTINCT p.id_producto) AS total
                 FROM productos p
                 JOIN inventario_almacen ia ON p.id_producto = ia.id_producto
                 LEFT JOIN producto_categorias pc ON p.id_producto = pc.id_producto
                 LEFT JOIN categorias c ON pc.id_categoria = c.id_categoria
                 LEFT JOIN purchase_order_postponed_items ppi
                    ON ppi.id_producto = ia.id_producto
                    AND ppi.id_almacen = ia.id_almacen
                    AND ppi.estado = 'pendiente'
                 WHERE ia.cantidad_actual <= ia.stock_minimo
                   AND p.estado = 'activo'
                   AND ppi.id_postergacion IS NULL" . $warehouseFilter . "
                 GROUP BY categoria
                 ORDER BY total DESC";

    $stmtChart = $pdo->prepare($sqlChart);
    $stmtChart->execute($params);
    $chartData = $stmtChart->fetchAll(PDO::FETCH_ASSOC);

    return [
        'listaCompra' => $listaCompra,
        'chartData' => $chartData,
    ];
}

/**
 * Marca productos como pendientes en la lista actual de compra.
 */
function purchaseOrderPostponeItems(PDO $pdo, array $items, int $userId): int
{
    $normalizedItems = purchaseOrderNormalizePostponeItems($items);
    if ($normalizedItems === []) {
        return 0;
    }

    $pdo->beginTransaction();

    try {
        $stmtUpdate = $pdo->prepare("UPDATE purchase_order_postponed_items
            SET estado = 'pendiente',
                motivo = :motivo,
                pospuesto_por = :usuario,
                pospuesto_en = CURRENT_TIMESTAMP,
                reactivado_en = NULL
            WHERE id_producto = :id_producto AND id_almacen = :id_almacen");

        $stmtInsert = $pdo->prepare("INSERT INTO purchase_order_postponed_items (id_producto, id_almacen, estado, motivo, pospuesto_por)
            VALUES (:id_producto, :id_almacen, 'pendiente', :motivo, :usuario)");

        $affected = 0;

        foreach ($normalizedItems as $item) {
            $params = [
                ':id_producto' => $item['id_producto'],
                ':id_almacen' => $item['id_almacen'],
                ':motivo' => $item['motivo'],
                ':usuario' => $userId,
            ];

            $stmtUpdate->execute($params);
            if ($stmtUpdate->rowCount() === 0) {
                $stmtInsert->execute($params);
            }

            $affected++;
        }

        $pdo->commit();
        return $affected;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

/**
 * Procesa entradas a inventario y libera pospuestos del almacén para el siguiente ciclo.
 */
function purchaseOrderProcessInbound(PDO $pdo, array $items, int $userId): int
{
    $normalizedItems = purchaseOrderNormalizeInboundItems($items);
    if ($normalizedItems === []) {
        return 0;
    }

    $pdo->beginTransaction();

    try {
        $stmtStock = $pdo->prepare('UPDATE inventario_almacen SET cantidad_actual = cantidad_actual + :cantidad WHERE id_producto = :id_producto AND id_almacen = :id_almacen');
        $stmtMov = $pdo->prepare("INSERT INTO movimientos_inventario (id_producto, tipo_movimiento, id_almacen_destino, cantidad, id_usuario, observacion) VALUES (:id_producto, 'entrada', :id_almacen_destino, :cantidad, :id_usuario, :observacion)");

        $warehouseMap = [];
        $processed = 0;

        foreach ($normalizedItems as $item) {
            $stmtStock->execute([
                ':cantidad' => $item['cantidad'],
                ':id_producto' => $item['id_producto'],
                ':id_almacen' => $item['id_almacen'],
            ]);

            if ($stmtStock->rowCount() <= 0) {
                continue;
            }

            $stmtMov->execute([
                ':id_producto' => $item['id_producto'],
                ':id_almacen_destino' => $item['id_almacen'],
                ':cantidad' => $item['cantidad'],
                ':id_usuario' => $userId,
                ':observacion' => 'Entrada masiva desde Lista de Resurtido',
            ]);

            $warehouseMap[$item['id_almacen']] = true;
            $processed++;
        }

        if ($warehouseMap !== []) {
            $placeholders = implode(',', array_fill(0, count($warehouseMap), '?'));
            $sqlRelease = "UPDATE purchase_order_postponed_items
                SET estado = 'reactivado',
                    reactivado_en = CURRENT_TIMESTAMP
                WHERE estado = 'pendiente' AND id_almacen IN ($placeholders)";
            $stmtRelease = $pdo->prepare($sqlRelease);
            $stmtRelease->execute(array_keys($warehouseMap));
        }

        $pdo->commit();
        return $processed;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}
