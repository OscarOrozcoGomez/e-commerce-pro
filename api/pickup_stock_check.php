<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json');

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '[]', true);
$items = is_array($data['items'] ?? null) ? $data['items'] : [];

if (empty($items)) {
    echo json_encode(['success' => false, 'message' => 'Sin items para revisar']);
    exit;
}

$required = [];
foreach ($items as $item) {
    $idProducto = (int)($item['id_producto'] ?? 0);
    $cantidad = max(0, (int)($item['quantity'] ?? 0));
    if ($idProducto <= 0 || $cantidad <= 0) {
        continue;
    }
    $required[$idProducto] = ($required[$idProducto] ?? 0) + $cantidad;
}

if (empty($required)) {
    echo json_encode(['success' => false, 'message' => 'Items invalidos']);
    exit;
}

try {
    $pdo = getPDO();

    $pickupWarehouseId = resolvePickupWarehouseId($pdo);
    if ($pickupWarehouseId <= 0) {
        echo json_encode(['success' => false, 'message' => 'No se pudo resolver sucursal pickup']);
        exit;
    }

    $stmtPickupName = $pdo->prepare("SELECT nombre FROM almacenes WHERE id_almacen = ? LIMIT 1");
    $stmtPickupName->execute([$pickupWarehouseId]);
    $pickupWarehouseName = (string)($stmtPickupName->fetchColumn() ?: ('Sucursal #' . $pickupWarehouseId));

    $selects = [];
    $paramsRequired = [];
    foreach ($required as $idProducto => $cantidad) {
        $selects[] = 'SELECT ? AS id_producto, ? AS cantidad_requerida';
        $paramsRequired[] = $idProducto;
        $paramsRequired[] = $cantidad;
    }
    $requiredSql = implode(' UNION ALL ', $selects);

    $sql = "SELECT
                req.id_producto,
                req.cantidad_requerida,
                COALESCE(pr.nombre, CONCAT('Producto #', req.id_producto)) AS nombre,
                COALESCE(MAX(CASE WHEN ia.id_almacen = ? THEN ia.cantidad_actual ELSE 0 END), 0) AS stock_pickup,
                COALESCE(SUM(CASE WHEN ia.id_almacen <> ? THEN ia.cantidad_actual ELSE 0 END), 0) AS stock_otro,
                MAX(CASE WHEN ia.id_almacen <> ? THEN a.nombre ELSE NULL END) AS almacen_apoyo_nombre
            FROM ({$requiredSql}) req
            LEFT JOIN inventario_almacen ia ON ia.id_producto = req.id_producto
            LEFT JOIN almacenes a ON a.id_almacen = ia.id_almacen
            LEFT JOIN productos pr ON pr.id_producto = req.id_producto
            GROUP BY req.id_producto, req.cantidad_requerida, pr.nombre";

    $stmt = $pdo->prepare($sql);
    $params = array_merge([$pickupWarehouseId, $pickupWarehouseId, $pickupWarehouseId], $paramsRequired);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $faltantes = [];
    $transferible = true;
    $supportWarehouseName = '';

    foreach ($rows as $row) {
        $idProducto = (int)($row['id_producto'] ?? 0);
        $requerido = (int)($row['cantidad_requerida'] ?? 0);
        $stockPickup = (int)($row['stock_pickup'] ?? 0);
        $stockOtro = (int)($row['stock_otro'] ?? 0);
        $faltan = max(0, $requerido - $stockPickup);

        if ($faltan > 0) {
            $puedeTransfer = $stockOtro >= $faltan;
            $transferible = $transferible && $puedeTransfer;
            if ($supportWarehouseName === '' && !empty($row['almacen_apoyo_nombre'])) {
                $supportWarehouseName = (string)$row['almacen_apoyo_nombre'];
            }
            $faltantes[] = [
                'id_producto' => $idProducto,
                'nombre' => (string)($row['nombre'] ?? ('Producto #' . $idProducto)),
                'requerido' => $requerido,
                'stock_pickup' => $stockPickup,
                'stock_otro' => $stockOtro,
                'faltan' => $faltan,
                'transferible' => $puedeTransfer,
            ];
        }
    }

    $status = 'ok';
    if (!empty($faltantes)) {
        $status = $transferible ? 'transferible' : 'sin_stock';
    }

    echo json_encode([
        'success' => true,
        'status' => $status,
        'pickup_almacen_id' => $pickupWarehouseId,
        'pickup_almacen_nombre' => $pickupWarehouseName,
        'almacen_apoyo_nombre' => $supportWarehouseName !== '' ? $supportWarehouseName : 'almacen de apoyo',
        'faltantes' => $faltantes,
    ]);
} catch (Throwable $e) {
    error_log('Error en pickup_stock_check: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'No se pudo revisar stock en este momento']);
}
