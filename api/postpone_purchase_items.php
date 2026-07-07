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
if (!is_array($data)) {
    $data = [];
}

if (!validateCsrfToken((string) ($data['csrf_token'] ?? ''))) {
    echo json_encode(['success' => false, 'message' => 'Token de seguridad invalido']);
    exit;
}

$items = $data['items'] ?? [];
if (!is_array($items) || $items === []) {
    echo json_encode(['success' => false, 'message' => 'No se enviaron productos para posponer']);
    exit;
}

$pdo = getPDO();

try {
    $affected = purchaseOrderPostponeItems($pdo, $items, (int) $_SESSION['usuario']['id_usuario']);

    if ($affected <= 0) {
        echo json_encode(['success' => false, 'message' => 'No se pudo posponer ningun producto']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Producto pospuesto para el siguiente pedido',
        'affected' => $affected,
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Error en el servidor: ' . $e->getMessage()]);
}
