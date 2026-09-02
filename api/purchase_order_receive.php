<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/purchase_order_utils.php';

header('Content-Type: application/json');

if (!isAuthenticated() || (!isAdmin() && !isEncargado())) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

if (!validateCsrfToken($data['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido']);
    exit;
}

$idOrden = (int) ($data['id_orden_compra'] ?? 0);
if ($idOrden <= 0) {
    echo json_encode(['success' => false, 'message' => 'Orden de compra inválida']);
    exit;
}

$pdo = getPDO();

try {
    // Un encargado sólo puede surtir órdenes de su sucursal.
    if (!isAdmin()) {
        $stmt = $pdo->prepare('SELECT id_almacen FROM ordenes_compra WHERE id_orden_compra = ?');
        $stmt->execute([$idOrden]);
        $ordenAlmacen = $stmt->fetchColumn();

        if ($ordenAlmacen === false) {
            echo json_encode(['success' => false, 'message' => 'La orden de compra no existe']);
            exit;
        }
        if ((int) $ordenAlmacen !== (int) getCurrentAlmacenId()) {
            echo json_encode(['success' => false, 'message' => 'No puedes surtir órdenes de otra sucursal']);
            exit;
        }
    }

    $lineas = isset($data['lineas']) && is_array($data['lineas']) ? $data['lineas'] : [];
    $result = purchaseOrderReceive($pdo, $idOrden, $lineas, (int) $_SESSION['usuario']['id_usuario']);

    logAudit(
        'recibir',
        'ordenes_compra',
        $idOrden,
        'Recepción: ' . $result['recibidas'] . ' surtidos, ' . $result['faltantes'] . ' faltantes'
    );

    echo json_encode([
        'success' => true,
        'message' => 'Orden surtida: inventario actualizado',
        'recibidas' => $result['recibidas'],
        'faltantes' => $result['faltantes'],
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => 'Error en el servidor: ' . $e->getMessage()]);
}
