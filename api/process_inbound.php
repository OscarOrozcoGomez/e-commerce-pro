<?php
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json');

if (!isAuthenticated() || (!isAdmin() && !isEncargado())) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

$pdo = getPDO();
$idOrden = intval($_POST['id_orden_compra'] ?? 0);

if (!$idOrden) {
    echo json_encode(['success' => false, 'error' => 'ID de orden no proporcionado']);
    exit;
}

try {
    $pdo->beginTransaction();

    // 1. Obtener datos de la orden
    $stmtOC = $pdo->prepare("SELECT * FROM ordenes_compra WHERE id_orden_compra = ? AND estado = 'enviada'");
    $stmtOC->execute([$idOrden]);
    $orden = $stmtOC->fetch();

    if (!$orden) throw new Exception("Orden no válida o ya procesada.");

    $idAlmacen = $orden['id_almacen'];

    // 2. Procesar cada producto recibido
    foreach ($_POST as $key => $value) {
        if (strpos($key, 'recibido_') === 0) {
            $idProducto = intval(str_replace('recibido_', '', $key));
            $cantidadRecibida = intval($value);

            if ($cantidadRecibida > 0) {
                // Actualizar inventario (sumar)
                $stmtStock = $pdo->prepare("UPDATE inventario_almacen 
                                           SET cantidad_actual = cantidad_actual + ? 
                                           WHERE id_producto = ? AND id_almacen = ?");
                $stmtStock->execute([$cantidadRecibida, $idProducto, $idAlmacen]);

                // Registrar movimiento de inventario
                $stmtLog = $pdo->prepare("INSERT INTO movimientos_inventario (id_producto, id_almacen, tipo, cantidad, id_usuario, observaciones) 
                                         VALUES (?, ?, 'entrada', ?, ?, ?)");
                $stmtLog->execute([$idProducto, $idAlmacen, $cantidadRecibida, $_SESSION['usuario']['id_usuario'], "Entrada por Orden de Compra #".$orden['referencia']]);
            }

            // Actualizar detalle de la orden
            $stmtUpdateDetalle = $pdo->prepare("UPDATE detalle_orden_compra SET cantidad_recibida = ? WHERE id_orden_compra = ? AND id_producto = ?");
            $stmtUpdateDetalle->execute([$cantidadRecibida, $idOrden, $idProducto]);
        }
    }

    // 3. Marcar orden como recibida
    $stmtFinal = $pdo->prepare("UPDATE ordenes_compra SET estado = 'recibida' WHERE id_orden_compra = ?");
    $stmtFinal->execute([$idOrden]);

    $pdo->commit();
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
