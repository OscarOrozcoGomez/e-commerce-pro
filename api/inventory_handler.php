<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json');

if (!isAuthenticated() || (!isAdmin() && !isEncargado())) {
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

        if ($id_producto <= 0 || $cantidad <= 0 || $almacenId <= 0) {
            throw new Exception("Datos de entrada inválidos.");
        }

        $pdo->beginTransaction();
        
        // Actualizar stock
        $stmt = $pdo->prepare("UPDATE inventario_almacen SET cantidad_actual = cantidad_actual + ? WHERE id_producto = ? AND id_almacen = ?");
        $stmt->execute([$cantidad, $id_producto, $almacenId]);
        
        // Registrar movimiento
        $stmtMov = $pdo->prepare("INSERT INTO movimientos_inventario (id_producto, tipo_movimiento, id_almacen_destino, cantidad, id_usuario, observacion) VALUES (?, 'entrada', ?, ?, ?, ?)");
        $stmtMov->execute([$id_producto, $almacenId, $cantidad, (int)$usuario['id_usuario'], $observacion]);
        
        $pdo->commit();
        echo json_encode(['success' => true, 'message' => 'Stock actualizado correctamente']);
    } else {
        throw new Exception("Acción no permitida");
    }
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}