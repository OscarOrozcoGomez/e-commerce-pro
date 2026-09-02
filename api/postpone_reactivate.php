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

$idPostergacion = (int) ($data['id_postergacion'] ?? 0);
if ($idPostergacion <= 0) {
    echo json_encode(['success' => false, 'message' => 'Registro inválido']);
    exit;
}

$pdo = getPDO();

try {
    if (!isAdmin()) {
        $stmt = $pdo->prepare('SELECT id_almacen FROM purchase_order_postponed_items WHERE id_postergacion = ?');
        $stmt->execute([$idPostergacion]);
        $filaAlmacen = $stmt->fetchColumn();

        if ($filaAlmacen === false) {
            echo json_encode(['success' => false, 'message' => 'El registro no existe']);
            exit;
        }
        if ((int) $filaAlmacen !== (int) getCurrentAlmacenId()) {
            echo json_encode(['success' => false, 'message' => 'No puedes modificar pospuestos de otra sucursal']);
            exit;
        }
    }

    $affected = purchaseOrderReactivatePostponed($pdo, $idPostergacion);

    if ($affected <= 0) {
        echo json_encode(['success' => false, 'message' => 'El producto ya no estaba pospuesto']);
        exit;
    }

    logAudit('actualizar', 'purchase_order_postponed_items', $idPostergacion, 'Producto regresado a la lista de compra');

    echo json_encode(['success' => true, 'message' => 'Producto regresado a la lista de compra']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => 'Error en el servidor: ' . $e->getMessage()]);
}
