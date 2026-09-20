<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/api_security_utils.php';

header('Content-Type: application/json');

// Publico: limite de consultas por minuto y por IP.
apiLimitarPeticiones('pickup_stock_check', 120);

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '[]', true);
$items = is_array($data['items'] ?? null) ? $data['items'] : [];

if (empty($items)) {
    echo json_encode(['success' => false, 'message' => 'Sin items para revisar']);
    exit;
}

try {
    $pdo = getPDO();
    $result = dbBuildPickupStockHint($pdo, $items);
    echo json_encode($result);
} catch (Throwable $e) {
    error_log('Error en pickup_stock_check: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'No se pudo revisar stock en este momento']);
}
