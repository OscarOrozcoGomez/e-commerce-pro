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

if (empty($data['items']) || !is_array($data['items'])) {
    echo json_encode(['success' => false, 'message' => 'No se enviaron productos']);
    exit;
}

$pdo = getPDO();

try {
    $items = $data['items'];

    // Un encargado sólo puede ordenar para su sucursal.
    if (!isAdmin()) {
        $idAlmacen = getCurrentAlmacenId();
        if ($idAlmacen === null) {
            echo json_encode(['success' => false, 'message' => 'No tienes una sucursal asignada']);
            exit;
        }
        foreach ($items as &$item) {
            if (is_array($item)) {
                $item['id_almacen'] = (int) $idAlmacen;
            }
        }
        unset($item);
    }

    $result = purchaseOrderCreateFromItems($pdo, $items, (int) $_SESSION['usuario']['id_usuario']);

    if ($result['lineas'] <= 0) {
        echo json_encode(['success' => false, 'message' => 'No hay productos válidos para la orden']);
        exit;
    }

    foreach ($result['ordenes'] as $idOrden) {
        logAudit('crear', 'ordenes_compra', (int) $idOrden, 'Orden de compra generada desde la lista de compra sugerida');
    }

    echo json_encode([
        'success' => true,
        'message' => count($result['ordenes']) === 1
            ? 'Orden de compra generada'
            : 'Se generaron ' . count($result['ordenes']) . ' órdenes de compra',
        'ordenes' => $result['ordenes'],
        'lineas' => $result['lineas'],
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => 'Error en el servidor: ' . $e->getMessage()]);
}
