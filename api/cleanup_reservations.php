<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

// Solo el personal puede ejecutar la limpieza manualmente, o mediante un cron
if (!isAuthenticated() && $_SERVER['REMOTE_ADDR'] !== '127.0.0.1') {
    http_response_code(403);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

header('Content-Type: application/json');

$pdo = getPDO();
$minutes_limit = 10; // Definimos 10 minutos como límite para una 'cotización' web no concretada

try {
    $pdo->beginTransaction();

    // 1. Encontrar pedidos pendientes/apartados que hayan expirado
    // Nota: Usamos 10 minutos para dar un margen razonable al cliente
    $sqlSelect = "SELECT id_pedido, id_almacen FROM pedidos 
                  WHERE estado IN ('pendiente_pago', 'apartado') 
                  AND fecha_creacion < (NOW() - INTERVAL :mins MINUTE)";
    $stmt = $pdo->prepare($sqlSelect);
    $stmt->execute([':mins' => $minutes_limit]);
    $pedidosAExpirar = $stmt->fetchAll();

    $count = 0;
    foreach ($pedidosAExpirar as $p) {
        $id_pedido = $p['id_pedido'];
        $id_almacen = $p['id_almacen'];

        // 2. Obtener los productos de este pedido para devolver el stock
        $stmtItems = $pdo->prepare("SELECT id_producto, cantidad FROM detalle_pedidos WHERE id_pedido = ?");
        $stmtItems->execute([$id_pedido]);
        $items = $stmtItems->fetchAll();

        foreach ($items as $item) {
            // Devolver stock al almacén original
            $stmtRestock = $pdo->prepare("UPDATE inventario_almacen 
                                         SET cantidad_actual = cantidad_actual + ? 
                                         WHERE id_producto = ? AND id_almacen = ?");
            $stmtRestock->execute([$item['cantidad'], $item['id_producto'], $id_almacen]);
        }

        // 3. Cambiar estado a cancelado
        $stmtCancel = $pdo->prepare("UPDATE pedidos SET estado = 'cancelado', observaciones = CONCAT(COALESCE(observaciones,''), ' | Expirado por tiempo límite.') WHERE id_pedido = ?");
        $stmtCancel->execute([$id_pedido]);
        
        $count++;
    }

    $pdo->commit();
    echo json_encode([
        'success' => true, 
        'message' => "Se liberaron $count pedidos expirados.",
        'pedidos_procesados' => $count
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
