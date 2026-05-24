<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

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
    echo json_encode(['success' => false, 'message' => 'No se enviaron datos']);
    exit;
}

$pdo = getPDO();
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("UPDATE inventario_almacen SET stock_minimo = ?, stock_maximo = ? WHERE id_producto = ? AND id_almacen = ?");

    foreach ($data['items'] as $item) {
        $idProd = (int)$item['id_producto'];
        $idAlm = (int)$item['id_almacen'];
        $min = (int)$item['stock_minimo'];
        $max = (int)$item['stock_maximo'];
        $stmt->execute([$min, $max, $idProd, $idAlm]);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Reglas de stock actualizadas correctamente']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}