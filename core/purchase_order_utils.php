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

    // Oculta productos que ya están dentro de una orden de compra sin cerrar:
    // reaparecen solos cuando la orden pasa a 'recibida' o 'cancelada'.
    $openOrderFilter = " AND NOT EXISTS (
                SELECT 1 FROM detalle_orden_compra doc
                JOIN ordenes_compra oc ON oc.id_orden_compra = doc.id_orden_compra
                WHERE doc.id_producto = ia.id_producto
                  AND oc.id_almacen = ia.id_almacen
                  AND oc.estado IN ('borrador','enviada','parcial')
            )";

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
              AND ppi.id_postergacion IS NULL" . $warehouseFilter . $openOrderFilter . "
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
                   AND ppi.id_postergacion IS NULL" . $warehouseFilter . $openOrderFilter . "
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
 * Procesa una entrada individual de inventario (usada por api/inventory_handler.php,
 * tanto el formulario "Entrada Individual" como la "Carga Rápida" fila por fila).
 *
 * Valida los datos, verifica que el producto exista en el inventario de la sucursal
 * indicada y —dentro de una transacción— incrementa el stock y registra el movimiento.
 * Sin la verificación previa, un producto no asignado a la sucursal dejaba el UPDATE
 * sin efecto pero igual insertaba el movimiento de inventario.
 *
 * @throws InvalidArgumentException si los datos son inválidos.
 * @throws RuntimeException si el producto no pertenece a esa sucursal.
 */
function purchaseOrderProcessSingleInbound(
    PDO $pdo,
    int $idProducto,
    int $idAlmacen,
    int $cantidad,
    int $userId,
    string $observacion = 'Entrada manual'
): void {
    if ($idProducto <= 0 || $cantidad <= 0 || $idAlmacen <= 0) {
        throw new InvalidArgumentException('Datos de entrada inválidos.');
    }

    $stmtCheck = $pdo->prepare('SELECT 1 FROM inventario_almacen WHERE id_producto = ? AND id_almacen = ?');
    $stmtCheck->execute([$idProducto, $idAlmacen]);
    if ($stmtCheck->fetchColumn() === false) {
        throw new RuntimeException('El producto no está asignado a esta sucursal.');
    }

    $pdo->beginTransaction();

    try {
        $stmtStock = $pdo->prepare('UPDATE inventario_almacen SET cantidad_actual = cantidad_actual + ? WHERE id_producto = ? AND id_almacen = ?');
        $stmtStock->execute([$cantidad, $idProducto, $idAlmacen]);

        $stmtMov = $pdo->prepare("INSERT INTO movimientos_inventario (id_producto, tipo_movimiento, id_almacen_destino, cantidad, id_usuario, observacion) VALUES (?, 'entrada', ?, ?, ?, ?)");
        $stmtMov->execute([$idProducto, $idAlmacen, $cantidad, $userId, $observacion]);

        $pdo->commit();
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

/**
 * Normaliza items para generar una orden de compra. A diferencia de
 * purchaseOrderNormalizeInboundItems() conserva el precio de costo por unidad.
 *
 * @return array<int, array{id_producto:int, id_almacen:int, cantidad:int, precio_costo:float}>
 */
function purchaseOrderNormalizeOrderItems(array $items): array
{
    $normalized = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $idProducto = (int) ($item['id_producto'] ?? 0);
        $idAlmacen = (int) ($item['id_almacen'] ?? 0);
        $cantidad = (int) ($item['cantidad'] ?? 0);
        $precioCosto = (float) ($item['precio_costo'] ?? 0);

        if ($idProducto <= 0 || $idAlmacen <= 0 || $cantidad <= 0) {
            continue;
        }

        if ($precioCosto < 0) {
            $precioCosto = 0.0;
        }

        $normalized[] = [
            'id_producto' => $idProducto,
            'id_almacen' => $idAlmacen,
            'cantidad' => $cantidad,
            'precio_costo' => $precioCosto,
        ];
    }

    return $normalized;
}

/**
 * Crea una o varias órdenes de compra (una por sucursal) a partir de la lista
 * ajustada. Deja las líneas con cantidad_recibida = 0; el inventario NO se toca
 * hasta que se surte la orden.
 *
 * @return array{ordenes: array<int,int>, lineas: int}
 */
function purchaseOrderCreateFromItems(PDO $pdo, array $items, int $userId): array
{
    $normalized = purchaseOrderNormalizeOrderItems($items);
    if ($normalized === []) {
        return ['ordenes' => [], 'lineas' => 0];
    }

    $porAlmacen = [];
    foreach ($normalized as $item) {
        $porAlmacen[$item['id_almacen']][] = $item;
    }

    $pdo->beginTransaction();

    try {
        $stmtOrden = $pdo->prepare("INSERT INTO ordenes_compra (id_usuario, id_almacen, referencia, estado, total_estimado)
            VALUES (:id_usuario, :id_almacen, :referencia, 'enviada', :total)");
        $stmtLinea = $pdo->prepare("INSERT INTO detalle_orden_compra (id_orden_compra, id_producto, cantidad_solicitada, cantidad_recibida, costo_unitario)
            VALUES (:id_orden, :id_producto, :cantidad, 0, :costo)");

        $ordenIds = [];
        $totalLineas = 0;
        $marca = date('Ymd-His');

        foreach ($porAlmacen as $idAlmacen => $lineas) {
            $total = 0.0;
            foreach ($lineas as $linea) {
                $total += $linea['cantidad'] * $linea['precio_costo'];
            }

            $referencia = 'OC-' . $marca . '-' . (int) $idAlmacen;
            $stmtOrden->execute([
                ':id_usuario' => $userId,
                ':id_almacen' => (int) $idAlmacen,
                ':referencia' => $referencia,
                ':total' => $total,
            ]);
            $idOrden = (int) $pdo->lastInsertId();
            $ordenIds[] = $idOrden;

            foreach ($lineas as $linea) {
                $stmtLinea->execute([
                    ':id_orden' => $idOrden,
                    ':id_producto' => $linea['id_producto'],
                    ':cantidad' => $linea['cantidad'],
                    ':costo' => $linea['precio_costo'],
                ]);
                $totalLineas++;
            }
        }

        $pdo->commit();
        return ['ordenes' => $ordenIds, 'lineas' => $totalLineas];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

/**
 * Lista las órdenes de compra sin cerrar con sus líneas.
 *
 * @return array<int, array<string,mixed>>
 */
function purchaseOrderListOpen(PDO $pdo, bool $adminMode, ?int $idAlmacen = null): array
{
    $params = [];
    $filter = '';

    if (!$adminMode) {
        $filter = ' AND oc.id_almacen = :id_almacen';
        $params[':id_almacen'] = (int) $idAlmacen;
    }

    $sql = "SELECT oc.id_orden_compra, oc.referencia, oc.estado, oc.total_estimado,
                   oc.fecha_creacion, oc.id_almacen, a.nombre AS sucursal
            FROM ordenes_compra oc
            JOIN almacenes a ON a.id_almacen = oc.id_almacen
            WHERE oc.estado IN ('borrador','enviada','parcial')" . $filter . "
            ORDER BY oc.fecha_creacion DESC, oc.id_orden_compra DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $ordenes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($ordenes === []) {
        return [];
    }

    $ids = array_map(static fn(array $o): int => (int) $o['id_orden_compra'], $ordenes);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $sqlLineas = "SELECT doc.id_detalle, doc.id_orden_compra, doc.id_producto,
                         doc.cantidad_solicitada, doc.cantidad_recibida, doc.costo_unitario,
                         p.nombre, p.sku
                  FROM detalle_orden_compra doc
                  JOIN productos p ON p.id_producto = doc.id_producto
                  WHERE doc.id_orden_compra IN ($placeholders)
                  ORDER BY p.nombre";

    $stmtLineas = $pdo->prepare($sqlLineas);
    $stmtLineas->execute($ids);

    $lineasPorOrden = [];
    foreach ($stmtLineas->fetchAll(PDO::FETCH_ASSOC) as $linea) {
        $lineasPorOrden[(int) $linea['id_orden_compra']][] = $linea;
    }

    foreach ($ordenes as &$orden) {
        $orden['lineas'] = $lineasPorOrden[(int) $orden['id_orden_compra']] ?? [];
    }
    unset($orden);

    return $ordenes;
}

/**
 * Surte una orden de compra: sube al inventario lo recibido, registra el
 * movimiento y cierra la orden. Las líneas sin enviar se asumen completas;
 * las que llegan en 0 no tocan el inventario. La orden siempre queda 'recibida'.
 *
 * @param array<int, array{id_detalle:int, cantidad_recibida:int}> $lineas
 * @return array{recibidas:int, faltantes:int}
 *
 * @throws InvalidArgumentException si el id de orden es inválido.
 * @throws RuntimeException si la orden no existe o ya fue cerrada.
 */
function purchaseOrderReceive(PDO $pdo, int $idOrden, array $lineas, int $userId): array
{
    if ($idOrden <= 0) {
        throw new InvalidArgumentException('Orden de compra inválida.');
    }

    $recibidoPorDetalle = [];
    foreach ($lineas as $linea) {
        if (!is_array($linea)) {
            continue;
        }
        $idDetalle = (int) ($linea['id_detalle'] ?? 0);
        if ($idDetalle <= 0) {
            continue;
        }
        $recibida = (int) ($linea['cantidad_recibida'] ?? 0);
        $recibidoPorDetalle[$idDetalle] = $recibida > 0 ? $recibida : 0;
    }

    $pdo->beginTransaction();

    try {
        $stmtOrden = $pdo->prepare('SELECT id_almacen, referencia, estado FROM ordenes_compra WHERE id_orden_compra = ?');
        $stmtOrden->execute([$idOrden]);
        $orden = $stmtOrden->fetch(PDO::FETCH_ASSOC);

        if ($orden === false) {
            throw new RuntimeException('La orden de compra no existe.');
        }
        if (!in_array($orden['estado'], ['borrador', 'enviada', 'parcial'], true)) {
            throw new RuntimeException('La orden de compra ya fue cerrada.');
        }

        $idAlmacen = (int) $orden['id_almacen'];
        $referencia = (string) $orden['referencia'];

        $stmtLineasOrden = $pdo->prepare('SELECT id_detalle, id_producto, cantidad_solicitada FROM detalle_orden_compra WHERE id_orden_compra = ?');
        $stmtLineasOrden->execute([$idOrden]);
        $lineasOrden = $stmtLineasOrden->fetchAll(PDO::FETCH_ASSOC);

        $stmtDetalle = $pdo->prepare('UPDATE detalle_orden_compra SET cantidad_recibida = :recibida WHERE id_detalle = :id_detalle AND id_orden_compra = :id_orden');
        $stmtStock = $pdo->prepare('UPDATE inventario_almacen SET cantidad_actual = cantidad_actual + :cantidad WHERE id_producto = :id_producto AND id_almacen = :id_almacen');
        $stmtMov = $pdo->prepare("INSERT INTO movimientos_inventario (id_producto, tipo_movimiento, id_almacen_destino, cantidad, id_usuario, observacion) VALUES (:id_producto, 'entrada', :id_almacen_destino, :cantidad, :id_usuario, :observacion)");

        $recibidas = 0;
        $faltantes = 0;

        foreach ($lineasOrden as $linea) {
            $idDetalle = (int) $linea['id_detalle'];
            $idProducto = (int) $linea['id_producto'];
            $solicitada = (int) $linea['cantidad_solicitada'];

            $recibida = array_key_exists($idDetalle, $recibidoPorDetalle)
                ? $recibidoPorDetalle[$idDetalle]
                : $solicitada;

            $stmtDetalle->execute([
                ':recibida' => $recibida,
                ':id_detalle' => $idDetalle,
                ':id_orden' => $idOrden,
            ]);

            if ($recibida > 0) {
                $stmtStock->execute([
                    ':cantidad' => $recibida,
                    ':id_producto' => $idProducto,
                    ':id_almacen' => $idAlmacen,
                ]);

                if ($stmtStock->rowCount() > 0) {
                    $stmtMov->execute([
                        ':id_producto' => $idProducto,
                        ':id_almacen_destino' => $idAlmacen,
                        ':cantidad' => $recibida,
                        ':id_usuario' => $userId,
                        ':observacion' => 'Recepción orden de compra ' . $referencia,
                    ]);
                    $recibidas++;
                    continue;
                }
            }

            $faltantes++;
        }

        $stmtCerrar = $pdo->prepare("UPDATE ordenes_compra SET estado = 'recibida' WHERE id_orden_compra = ?");
        $stmtCerrar->execute([$idOrden]);

        $pdo->commit();
        return ['recibidas' => $recibidas, 'faltantes' => $faltantes];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

/**
 * Cancela una orden de compra sin cerrar. Devuelve el número de filas afectadas
 * (0 si no existía o ya estaba cerrada).
 */
function purchaseOrderCancel(PDO $pdo, int $idOrden): int
{
    if ($idOrden <= 0) {
        return 0;
    }

    $stmt = $pdo->prepare("UPDATE ordenes_compra SET estado = 'cancelada'
        WHERE id_orden_compra = ? AND estado IN ('borrador','enviada','parcial')");
    $stmt->execute([$idOrden]);

    return $stmt->rowCount();
}

/**
 * Lista los productos pospuestos que siguen pendientes.
 *
 * @return array<int, array<string,mixed>>
 */
function purchaseOrderListPostponed(PDO $pdo, bool $adminMode, ?int $idAlmacen = null): array
{
    $params = [];
    $filter = '';

    if (!$adminMode) {
        $filter = ' AND ppi.id_almacen = :id_almacen';
        $params[':id_almacen'] = (int) $idAlmacen;
    }

    $sql = "SELECT ppi.id_postergacion, ppi.id_producto, ppi.id_almacen, ppi.motivo,
                   ppi.pospuesto_en, p.nombre, p.sku, a.nombre AS sucursal,
                   u.nombre AS pospuesto_por
            FROM purchase_order_postponed_items ppi
            JOIN productos p ON p.id_producto = ppi.id_producto
            JOIN almacenes a ON a.id_almacen = ppi.id_almacen
            LEFT JOIN usuarios u ON u.id_usuario = ppi.pospuesto_por
            WHERE ppi.estado = 'pendiente'" . $filter . "
            ORDER BY ppi.pospuesto_en DESC, p.nombre";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Regresa un producto pospuesto a la lista de compra (marca la fila como
 * 'reactivado'). Devuelve las filas afectadas (0 si no estaba pendiente).
 */
function purchaseOrderReactivatePostponed(PDO $pdo, int $idPostergacion): int
{
    if ($idPostergacion <= 0) {
        return 0;
    }

    $stmt = $pdo->prepare("UPDATE purchase_order_postponed_items
        SET estado = 'reactivado', reactivado_en = CURRENT_TIMESTAMP
        WHERE id_postergacion = :id AND estado = 'pendiente'");
    $stmt->execute([':id' => $idPostergacion]);

    return $stmt->rowCount();
}
