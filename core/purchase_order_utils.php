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
    $pdo->beginTransaction();

    try {
        $processed = purchaseOrderProcessInboundTx($pdo, $items, $userId);
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
 * Cuerpo de purchaseOrderProcessInbound() sin manejo de transacción: asume que el
 * llamador ya abrió una y hará commit/rollback. Lo usa purchaseOrderCommitImport()
 * para que todo el import (entradas directas + recepción de órdenes) sea atómico.
 */
function purchaseOrderProcessInboundTx(PDO $pdo, array $items, int $userId): int
{
    $normalizedItems = purchaseOrderNormalizeInboundItems($items);
    if ($normalizedItems === []) {
        return 0;
    }

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

    return $processed;
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
    $pdo->beginTransaction();

    try {
        $result = purchaseOrderReceiveTx($pdo, $idOrden, $lineas, $userId);
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

/**
 * Cuerpo de purchaseOrderReceive() sin manejo de transacción: asume que el
 * llamador ya abrió una y hará commit/rollback. Lo usa purchaseOrderCommitImport()
 * para surtir varias órdenes + entradas directas en una sola transacción.
 *
 * @param array<int, array{id_detalle:int, cantidad_recibida:int}> $lineas
 * @return array{recibidas:int, faltantes:int}
 */
function purchaseOrderReceiveTx(PDO $pdo, int $idOrden, array $lineas, int $userId): array
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

    return ['recibidas' => $recibidas, 'faltantes' => $faltantes];
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

// ===========================================================================
// Importar pedido de proveedor desde una captura / correo y surtir inventario
// ---------------------------------------------------------------------------
// Flujo "sin IA": el correo del proveedor trae líneas tipo "Nombre del producto × 2".
// Se parsea con regex (o el texto sale de un OCR local en el navegador), se mapea
// cada línea a un producto por similitud de texto, y tras la revisión del usuario
// se surte: si el producto está en una orden de compra abierta de esa sucursal se
// recibe esa orden (cerrándola), si no entra como entrada directa a inventario.
// ===========================================================================

/** Tope defensivo de cantidad por línea (evita overflow / basura del OCR). */
const PURCHASE_ORDER_IMPORT_MAX_QTY = 9999;

/** Umbral de score (0..100) bajo el cual el match se considera dudoso. */
const PURCHASE_ORDER_IMPORT_MATCH_THRESHOLD = 45.0;

/**
 * Limpia el nombre de un producto detectado: quita etiquetas HTML, viñetas de
 * lista al inicio, espacios repetidos y separadores de cola.
 */
function purchaseOrderCleanItemName(string $nombre): string
{
    $nombre = strip_tags($nombre);
    $nombre = str_replace("\xc2\xa0", ' ', $nombre); // NBSP -> espacio
    // Viñetas / numeración al inicio: "• ", "- ", "* ", "1. ", "1) ".
    $nombre = preg_replace('/^\s*(?:[•\-\*\x{00B7}\x{2022}\x{25AA}\x{25E6}\x{2023}]+|\d{1,3}[.)])\s+/u', '', $nombre) ?? $nombre;
    $nombre = preg_replace('/\s+/u', ' ', $nombre) ?? $nombre;
    $nombre = preg_replace('/[\s\-|.:,;]+$/u', '', $nombre) ?? $nombre;
    return trim($nombre);
}

/**
 * Convierte el fragmento numérico de una cantidad ("2", "1,200", "2.9") a entero.
 * Congelado: se quitan las comas (separador de miles) y se trunca en el punto
 * decimal. "1,200" -> 1200 ; "2.9" -> 2.
 */
function purchaseOrderParseQuantityToken(string $token): int
{
    $token = str_replace(',', '', trim($token));
    if ($token === '' || !is_numeric($token)) {
        return 0;
    }
    return (int) floor((float) $token);
}

/**
 * Parsea el texto de un correo/captura de proveedor a líneas de pedido.
 *
 * @param string     $texto      Texto pegado o salido de OCR.
 * @param array|null $sinCantidad Se llena (por referencia) con las líneas de
 *                                contenido que no se pudieron resolver a un ítem.
 * @return array<int, array{nombre:string, cantidad:int, raw:string}>
 */
function purchaseOrderParseSupplierText(string $texto, ?array &$sinCantidad = null): array
{
    $sinCantidad = [];

    if ($texto === '') {
        return [];
    }

    // Normaliza saltos de línea y quita bytes nulos / UTF-8 inválido.
    $texto = str_replace(["\r\n", "\r"], "\n", $texto);
    $texto = str_replace("\0", '', $texto);
    if (function_exists('mb_check_encoding') && !mb_check_encoding($texto, 'UTF-8')) {
        $texto = function_exists('mb_convert_encoding')
            ? mb_convert_encoding($texto, 'UTF-8', 'UTF-8')
            : (string) @iconv('UTF-8', 'UTF-8//IGNORE', $texto);
    }
    // Si pegaron HTML (una tabla de correo, p. ej.), cada etiqueta pasa a ser un
    // espacio para no partir "<td>Nombre</td><td>× 2</td>" de forma rara.
    if (strpos($texto, '<') !== false && strpos($texto, '>') !== false) {
        $texto = preg_replace('/<[^>]+>/', ' ', $texto) ?? $texto;
        $texto = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    $rawLines = explode("\n", $texto);
    $items = [];
    $pending = [];

    $priceOnly = '/^\s*\$?\s*\d[\d.,]*\s*(?:mxn|usd)?(?:\s+\$?\s*\d[\d.,]*\s*(?:mxn|usd)?)*\s*$/iu';
    // Sólo se usa para NO acumular una línea de encabezado dentro de un nombre; una
    // línea que además trae cantidad se resuelve antes de llegar aquí.
    $headerLine = '/^\s*(resumen del pedido|resumen|subtotal|sub total|total a pagar|gran total|total:|total \$|env[ií]o|envio|costo de env[ií]o|shipping|iva|impuestos?|tax|descuentos?|ahorro|ahorras|precio unitario|cantidad|subtotal:)\s*/iu';

    $maxPending = 6;

    $finalize = static function (string $nombre, int $cantidad) use (&$items, &$sinCantidad): void {
        $limpio = purchaseOrderCleanItemName($nombre);
        if ($limpio === '') {
            return;
        }
        if ($cantidad <= 0) {
            // "× 0" / "× -3": línea reconocible pero cantidad inválida -> se descarta.
            $sinCantidad[] = $limpio;
            return;
        }
        $items[] = [
            'nombre' => $limpio,
            'cantidad' => min($cantidad, PURCHASE_ORDER_IMPORT_MAX_QTY),
            'raw' => $limpio,
        ];
    };

    foreach ($rawLines as $line) {
        $line = trim(preg_replace('/\t+/', ' ', $line) ?? $line);
        if ($line === '') {
            continue;
        }
        if (preg_match($priceOnly, $line)) {
            continue;
        }

        $candidate = trim(implode(' ', array_merge($pending, [$line])));
        $matched = false;

        // a) "Nombre ... × N" al final (gana el último signo de multiplicación).
        if (preg_match('/^(.*?\S)\s*[x×X]\s*(\d[\d.,]*)\s*(?:pz|pzs|pzas|piezas|pieza|uds?|unidades?|tomas?|cap[s]?|c[aá]psulas?)?\.?\s*$/u', $candidate, $m)) {
            $finalize($m[1], purchaseOrderParseQuantityToken($m[2]));
            $pending = [];
            $matched = true;
        // b) "N x Nombre" al inicio.
        } elseif (preg_match('/^(\d[\d.,]*)\s*[x×X]\s*(.+?)\.?\s*$/u', $candidate, $m)) {
            $finalize($m[2], purchaseOrderParseQuantityToken($m[1]));
            $pending = [];
            $matched = true;
        // c) "Nombre (N)" al final.
        } elseif (preg_match('/^(.*?\S)\s*\((\d[\d.,]*)\)\s*$/u', $candidate, $m)) {
            $finalize($m[1], purchaseOrderParseQuantityToken($m[2]));
            $pending = [];
            $matched = true;
        // d) "Cantidad: N" / "Qty N" en su propia línea (el nombre viene del buffer).
        } elseif ($pending !== [] && preg_match('/^(?:cantidad|cant|qty|q)\s*[:.]?\s*(\d[\d.,]*)\s*$/iu', $line, $m)) {
            $finalize(implode(' ', $pending), purchaseOrderParseQuantityToken($m[1]));
            $pending = [];
            $matched = true;
        // e) "Nombre ....... N" (al menos 2 puntos/espacios antes del entero final).
        } elseif (preg_match('/^(.*\S)[\s.]{2,}(\d{1,4})\s*$/u', $candidate, $m)) {
            $finalize($m[1], purchaseOrderParseQuantityToken($m[2]));
            $pending = [];
            $matched = true;
        }

        if (!$matched) {
            // No acumular encabezados/totales dentro de un nombre de producto.
            if (preg_match($headerLine, $line)) {
                continue;
            }
            $pending[] = $line;
            if (count($pending) > $maxPending) {
                array_shift($pending);
            }
        }
    }

    // Lo que quedó en el buffer sin terminador de cantidad = líneas sin resolver.
    if ($pending !== []) {
        $resto = purchaseOrderCleanItemName(implode(' ', $pending));
        if ($resto !== '') {
            $sinCantidad[] = $resto;
        }
    }

    return $items;
}

/**
 * Normaliza una cadena para comparaciones: sin acentos, minúsculas, sólo
 * alfanumérico separado por espacios simples.
 */
function purchaseOrderNormalizeText(string $s): string
{
    if ($s === '') {
        return '';
    }
    if (function_exists('mb_check_encoding') && !mb_check_encoding($s, 'UTF-8')) {
        $s = (string) @iconv('UTF-8', 'UTF-8//IGNORE', $s);
    }

    // Mapa explícito de acentos/ligaduras -> ASCII: iconv//TRANSLIT es poco fiable
    // en Windows (produce "col'ageno") y transliterator_* requiere la extensión intl.
    static $accents = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'Á' => 'a', 'À' => 'a', 'Ä' => 'a', 'Â' => 'a', 'Ã' => 'a', 'Å' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e', 'É' => 'e', 'È' => 'e', 'Ë' => 'e', 'Ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'Í' => 'i', 'Ì' => 'i', 'Ï' => 'i', 'Î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o', 'Ó' => 'o', 'Ò' => 'o', 'Ö' => 'o', 'Ô' => 'o', 'Õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'Ú' => 'u', 'Ù' => 'u', 'Ü' => 'u', 'Û' => 'u',
        'ñ' => 'n', 'Ñ' => 'n', 'ç' => 'c', 'Ç' => 'c', 'ß' => 'ss', 'ø' => 'o', 'Ø' => 'o',
    ];
    $s = strtr($s, $accents);

    $s = strtolower($s);
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? $s;
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;

    return trim($s);
}

/**
 * Puntúa (0..100) qué tan parecidas son dos cadenas ya normalizadas, combinando
 * similar_text() con el solape de tokens (Jaccard) y un bono si la consulta está
 * contenida por completo.
 *
 * @param list<string> $queryTokens tokens únicos de $query
 */
function purchaseOrderTextScore(string $query, array $queryTokens, string $candidate): float
{
    if ($query === '' || $candidate === '') {
        return 0.0;
    }

    $pct = 0.0;
    similar_text($query, $candidate, $pct);

    $candTokens = array_values(array_unique(array_filter(explode(' ', $candidate), 'strlen')));
    $inter = count(array_intersect($queryTokens, $candTokens));
    $union = count(array_unique(array_merge($queryTokens, $candTokens)));
    $jaccard = $union > 0 ? ($inter / $union) * 100 : 0.0;

    $score = 0.55 * $pct + 0.45 * $jaccard;

    // Todos los tokens de la consulta aparecen en el candidato.
    if ($queryTokens !== [] && array_diff($queryTokens, $candTokens) === []) {
        $score = max($score, 88.0) + min(10, count($queryTokens));
    }

    return min(100.0, round($score, 1));
}

/**
 * Ordena el catálogo por parecido al nombre detectado y devuelve los mejores.
 *
 * @param array<int, array{id_producto:int|string, nombre:string, sku?:string|null}> $catalogo
 * @return array<int, array{id_producto:int, nombre:string, sku:string, score:float}>
 */
function purchaseOrderMatchProduct(array $catalogo, string $rawNombre, int $topN = 5): array
{
    $query = purchaseOrderNormalizeText($rawNombre);
    if ($query === '' || $catalogo === []) {
        return [];
    }

    $queryTokens = array_values(array_unique(array_filter(explode(' ', $query), 'strlen')));

    $scored = [];
    foreach ($catalogo as $p) {
        $nombre = (string) ($p['nombre'] ?? '');
        $sku = (string) ($p['sku'] ?? '');

        // El catálogo usa "Nombre inglés | Descripción español"; comparamos contra
        // el nombre completo, la mitad izquierda y la mitad derecha.
        $partes = array_map('trim', explode('|', $nombre));
        $candidatosTexto = [$nombre];
        if (count($partes) > 1) {
            $candidatosTexto[] = $partes[0];
            $candidatosTexto[] = $partes[count($partes) - 1];
        }

        $best = 0.0;
        foreach ($candidatosTexto as $texto) {
            $norm = purchaseOrderNormalizeText($texto);
            if ($norm === '') {
                continue;
            }
            $best = max($best, purchaseOrderTextScore($query, $queryTokens, $norm));
        }

        $skuNorm = purchaseOrderNormalizeText($sku);
        if (strlen($skuNorm) >= 4 && strpos($query, $skuNorm) !== false) {
            $best = max($best, 96.0);
        }

        $scored[] = [
            'id_producto' => (int) $p['id_producto'],
            'nombre' => $nombre,
            'sku' => $sku,
            'score' => $best,
        ];
    }

    // Score desc, y a igualdad id asc para que el orden sea determinista.
    usort($scored, static function (array $a, array $b): int {
        return ($b['score'] <=> $a['score']) ?: ($a['id_producto'] <=> $b['id_producto']);
    });

    return array_slice($scored, 0, max(1, $topN));
}

/**
 * Arma la vista previa de un import: parsea el texto, mapea cada línea a un
 * producto y marca las que caen en una orden de compra abierta de la sucursal.
 *
 * @return array{rows: array<int, array<string,mixed>>, warnings: array<int, array{tipo:string, texto:string}>}
 */
function purchaseOrderBuildImportPreview(PDO $pdo, string $texto, int $idAlmacen): array
{
    $sinCantidad = [];
    $items = purchaseOrderParseSupplierText($texto, $sinCantidad);

    $catalogo = $pdo->query(
        "SELECT id_producto, nombre, sku FROM productos WHERE estado <> 'archivado' ORDER BY id_producto"
    )->fetchAll(PDO::FETCH_ASSOC);

    // Línea de OC abierta por producto (la de menor id_orden_compra / id_detalle).
    $ocPorProducto = [];
    if ($idAlmacen > 0) {
        $stmt = $pdo->prepare(
            "SELECT doc.id_detalle, doc.id_producto, doc.id_orden_compra, oc.referencia
             FROM detalle_orden_compra doc
             JOIN ordenes_compra oc ON oc.id_orden_compra = doc.id_orden_compra
             WHERE oc.estado IN ('borrador','enviada','parcial') AND oc.id_almacen = ?
             ORDER BY doc.id_orden_compra, doc.id_detalle"
        );
        $stmt->execute([$idAlmacen]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linea) {
            $pid = (int) $linea['id_producto'];
            if (!isset($ocPorProducto[$pid])) {
                $ocPorProducto[$pid] = $linea;
            }
        }
    }

    $warnings = [];
    foreach ($sinCantidad as $texto2) {
        $warnings[] = ['tipo' => 'no_parseada', 'texto' => (string) $texto2];
    }

    $rows = [];
    foreach ($items as $item) {
        $candidatos = purchaseOrderMatchProduct($catalogo, $item['nombre'], 5);
        $mejor = $candidatos[0] ?? null;
        $sugerido = ($mejor !== null && $mejor['score'] >= PURCHASE_ORDER_IMPORT_MATCH_THRESHOLD)
            ? (int) $mejor['id_producto']
            : 0;

        $row = [
            'raw' => $item['nombre'],
            'cantidad' => (int) $item['cantidad'],
            'candidatos' => $candidatos,
            'sugerido_id_producto' => $sugerido,
            'score' => $mejor['score'] ?? 0.0,
            'id_detalle' => null,
            'id_orden_compra' => null,
            'referencia' => null,
        ];

        if ($sugerido > 0 && isset($ocPorProducto[$sugerido])) {
            $oc = $ocPorProducto[$sugerido];
            $row['id_detalle'] = (int) $oc['id_detalle'];
            $row['id_orden_compra'] = (int) $oc['id_orden_compra'];
            $row['referencia'] = (string) $oc['referencia'];
        }

        if ($sugerido === 0) {
            $warnings[] = ['tipo' => 'sin_match', 'texto' => $item['nombre']];
        }

        $rows[] = $row;
    }

    return ['rows' => $rows, 'warnings' => $warnings];
}

/**
 * Aplica un import revisado por el usuario: entradas directas a inventario para
 * los productos sueltos y recepción (cerrando la orden) para los que caen en una
 * OC abierta. Todo en una sola transacción.
 *
 * @param array<int, array{id_producto:int, cantidad:int, id_detalle?:int|null}> $rows
 * @return array{ordenes_cerradas:int, lineas_oc:int, entradas_directas:int, ignoradas:int}
 */
function purchaseOrderCommitImport(PDO $pdo, array $rows, int $idAlmacen, int $userId): array
{
    $result = [
        'ordenes_cerradas' => 0,
        'lineas_oc' => 0,
        'entradas_directas' => 0,
        'ignoradas' => 0,
    ];

    if ($idAlmacen <= 0) {
        // Sin sucursal válida no se puede surtir nada.
        $result['ignoradas'] = count($rows);
        return $result;
    }

    // 1. Sanitiza y agrupa (sumando cantidades repetidas).
    $directos = [];      // id_producto => cantidad
    $porDetalle = [];     // id_detalle  => cantidad
    foreach ($rows as $r) {
        if (!is_array($r)) {
            $result['ignoradas']++;
            continue;
        }
        $pid = (int) ($r['id_producto'] ?? 0);
        $qty = (int) ($r['cantidad'] ?? 0);
        $det = (isset($r['id_detalle']) && $r['id_detalle'] !== null && $r['id_detalle'] !== '')
            ? (int) $r['id_detalle']
            : 0;

        if ($pid <= 0 || $qty <= 0) {
            $result['ignoradas']++;
            continue;
        }
        if ($qty > PURCHASE_ORDER_IMPORT_MAX_QTY) {
            $qty = PURCHASE_ORDER_IMPORT_MAX_QTY;
        }

        if ($det > 0) {
            $porDetalle[$det] = ($porDetalle[$det] ?? 0) + $qty;
        } else {
            $directos[$pid] = ($directos[$pid] ?? 0) + $qty;
        }
    }

    // 2. Resuelve los id_detalle: sólo valen si su orden está abierta y es de esta sucursal.
    $ocLineas = []; // id_orden_compra => [id_detalle => cantidad]
    if ($porDetalle !== []) {
        $ph = implode(',', array_fill(0, count($porDetalle), '?'));
        $stmt = $pdo->prepare(
            "SELECT doc.id_detalle, doc.id_orden_compra, oc.id_almacen, oc.estado
             FROM detalle_orden_compra doc
             JOIN ordenes_compra oc ON oc.id_orden_compra = doc.id_orden_compra
             WHERE doc.id_detalle IN ($ph)"
        );
        $stmt->execute(array_keys($porDetalle));
        $mapa = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $mapa[(int) $d['id_detalle']] = $d;
        }

        foreach ($porDetalle as $det => $qty) {
            $d = $mapa[$det] ?? null;
            if (
                $d === null
                || (int) $d['id_almacen'] !== $idAlmacen
                || !in_array($d['estado'], ['borrador', 'enviada', 'parcial'], true)
            ) {
                $result['ignoradas']++;
                continue;
            }
            $ocLineas[(int) $d['id_orden_compra']][$det] = $qty;
        }
    }

    if ($ocLineas === [] && $directos === []) {
        return $result;
    }

    // 3. Aplica todo en una transacción.
    $pdo->beginTransaction();
    try {
        foreach ($ocLineas as $idOrden => $detQtys) {
            $all = $pdo->prepare('SELECT id_detalle FROM detalle_orden_compra WHERE id_orden_compra = ?');
            $all->execute([$idOrden]);

            $lineas = [];
            foreach ($all->fetchAll(PDO::FETCH_COLUMN) as $idDet) {
                $idDet = (int) $idDet;
                $lineas[] = [
                    'id_detalle' => $idDet,
                    'cantidad_recibida' => $detQtys[$idDet] ?? 0,
                ];
            }

            purchaseOrderReceiveTx($pdo, $idOrden, $lineas, $userId);
            $result['ordenes_cerradas']++;
            $result['lineas_oc'] += count($detQtys);
        }

        if ($directos !== []) {
            $items = [];
            foreach ($directos as $pid => $qty) {
                $items[] = ['id_producto' => $pid, 'id_almacen' => $idAlmacen, 'cantidad' => $qty];
            }
            $procesados = purchaseOrderProcessInboundTx($pdo, $items, $userId);
            $result['entradas_directas'] = $procesados;
            // Productos sin fila de inventario en la sucursal: no suman stock ni movimiento.
            $result['ignoradas'] += count($items) - $procesados;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return $result;
}
