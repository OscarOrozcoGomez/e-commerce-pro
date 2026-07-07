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

if (empty($data['items'])) {
    echo json_encode(['success' => false, 'message' => 'No se enviaron productos']);
    exit;
}

$pdo = getPDO();
try {
    $processed = purchaseOrderProcessInbound($pdo, (array) $data['items'], (int) $_SESSION['usuario']['id_usuario']);
    if ($processed <= 0) {
        echo json_encode(['success' => false, 'message' => 'No se procesaron productos válidos']);
        exit;
    }

    echo json_encode(['success' => true, 'message' => 'Inventario actualizado correctamente']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error en el servidor: ' . $e->getMessage()]);
}