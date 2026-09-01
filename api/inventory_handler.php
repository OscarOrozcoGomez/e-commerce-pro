<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/purchase_order_utils.php';

header('Content-Type: application/json');

// Fase 4: el permiso 'inventario' abre este endpoint; el rol se mantiene como respaldo.
if (!isAuthenticated() || (!hasPermission('inventario') && !isAdmin() && !isEncargado())) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$data = $_POST;
if (!validateCsrfToken($data['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido']);
    exit;
}

$pdo = getPDO();
$usuario = $_SESSION['usuario'];
$almacenId = (int)($data['id_almacen'] ?? ($usuario['id_almacen'] ?: 0));

try {
    $accion = $data['accion'] ?? '';
    if ($accion === 'entrada_individual') {
        $id_producto = (int)($data['id_producto'] ?? 0);
        $cantidad = (int)($data['cantidad'] ?? 0);
        $observacion = htmlspecialchars(trim($data['observacion'] ?? 'Entrada manual'));

        purchaseOrderProcessSingleInbound(
            $pdo,
            $id_producto,
            $almacenId,
            $cantidad,
            (int)$usuario['id_usuario'],
            $observacion
        );

        echo json_encode(['success' => true, 'message' => 'Stock actualizado correctamente']);
    } else {
        throw new Exception("Acción no permitida");
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}