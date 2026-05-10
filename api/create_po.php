<?php
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json');

if (!isAuthenticated() || (!isAdmin() && !isEncargado())) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$pdo = getPDO();
$usuario = $_SESSION['usuario'];
$idAlmacen = $usuario['id_almacen'];

try {
    $pdo->beginTransaction();

    $referencia = 'OC-' . date('Ymd-His');
    $totalEstimado = 0;

    // 1. Crear cabecera de la orden
    $stmt = $pdo->prepare("INSERT INTO ordenes_compra (id_usuario, id_almacen, referencia, estado, total_estimado) VALUES (?, ?, ?, 'enviada', 0)");
    $stmt->execute([$usuario['id_usuario'], $idAlmacen, $referencia]);
    $idOrden = $pdo->lastInsertId();

    // 2. Procesar productos
    $stmtItem = $pdo->prepare("INSERT INTO detalle_orden_compra (id_orden_compra, id_producto, cantidad_solicitada, costo_unitario) VALUES (?, ?, ?, ?)");
    
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'cant_') === 0) {
            $idProducto = intval(str_replace('cant_', '', $key));
            $cantidad = intval($value);

            if ($cantidad > 0) {
                // Obtener costo actual
                $stmtPrice = $pdo->prepare("SELECT precio_costo FROM productos WHERE id_producto = ?");
                $stmtPrice->execute([$idProducto]);
                $costo = $stmtPrice->fetchColumn();

                $stmtItem->execute([$idOrden, $idProducto, $cantidad, $costo]);
                $totalEstimado += ($costo * $cantidad);
            }
        }
    }

    // 3. Actualizar total
    $stmtUpdate = $pdo->prepare("UPDATE ordenes_compra SET total_estimado = ? WHERE id_orden_compra = ?");
    $stmtUpdate->execute([$totalEstimado, $idOrden]);

    $pdo->commit();
    echo json_encode(['success' => true, 'id' => $idOrden, 'referencia' => $referencia]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
