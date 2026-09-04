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

$pdo = getPDO();
$idAlmacen = getCurrentAlmacenId();

try {
    $pospuestos = purchaseOrderListPostponed($pdo, isAdmin(), $idAlmacen !== null ? (int) $idAlmacen : null);

    echo json_encode(['success' => true, 'pospuestos' => $pospuestos]);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
