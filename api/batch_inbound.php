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
    echo json_encode(['success' => false, 'message' => 'No se enviaron productos']);
    exit;
}

$pdo = getPDO();
try {
    $pdo->beginTransaction();

    $stmtStock = $pdo->prepare("UPDATE inventario_almacen SET cantidad_actual = cantidad_actual + ? WHERE id_producto = ? AND id_almacen = ?");
    $stmtMov = $pdo->prepare("INSERT INTO movimientos_inventario (id_producto, tipo_movimiento, id_almacen_destino, cantidad, id_usuario, observacion) VALUES (?, 'entrada', ?, ?, ?, ?)");

    foreach ($data['items'] as $item) {
        $idProd = (int)$item['id_producto'];
        $idAlm = (int)$item['id_almacen'];
        $qty = (int)$item['cantidad'];

        if ($qty <= 0) continue;

        // 1. Actualizar stock físico
        $stmtStock->execute([$qty, $idProd, $idAlm]);

        // 2. Registrar el movimiento para auditoría
        $obs = "Entrada masiva desde Lista de Resurtido";
        $stmtMov->execute([$idProd, $idAlm, $qty, $_SESSION['usuario']['id_usuario'], $obs]);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Inventario actualizado correctamente']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Error en el servidor: ' . $e->getMessage()]);
}