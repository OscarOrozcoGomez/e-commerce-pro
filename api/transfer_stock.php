<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json');
if (!isAdmin()) { echo json_encode(['success' => false, 'message' => 'No autorizado']); exit; }

$pdo = getPDO();
try {
    $pdo->beginTransaction();

    $id_prod = (int)$_POST['id_producto'];
    $id_ori = (int)$_POST['id_origen'];
    $id_des = (int)$_POST['id_destino'];
    $qty = (int)$_POST['cantidad'];
    $obs = "Transferencia: " . ($_POST['observacion'] ?? 'Sin nota');

    // 1. Validar stock en origen
    $stmt = $pdo->prepare("SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = ? AND id_almacen = ?");
    $stmt->execute([$id_prod, $id_ori]);
    $stock_actual = (int)$stmt->fetchColumn();

    if ($stock_actual < $qty) {
        throw new Exception("Stock insuficiente en origen. Disponible: $stock_actual");
    }

    // 2. Restar de origen
    $pdo->prepare("UPDATE inventario_almacen SET cantidad_actual = cantidad_actual - ? WHERE id_producto = ? AND id_almacen = ?")
        ->execute([$qty, $id_prod, $id_ori]);

    // 3. Sumar a destino (usando INSERT ... ON DUPLICATE por si el producto no existe en el destino)
    $pdo->prepare("INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual) 
                   VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE cantidad_actual = cantidad_actual + VALUES(cantidad_actual)")
        ->execute([$id_prod, $id_des, $qty]);

    // 4. Registrar movimientos (Salida y Entrada)
    $stmtMov = $pdo->prepare("INSERT INTO movimientos_inventario (id_producto, tipo_movimiento, id_almacen_origen, id_almacen_destino, cantidad, id_usuario, observacion) VALUES (?, 'transferencia', ?, ?, ?, ?, ?)");
    $stmtMov->execute([$id_prod, $id_ori, $id_des, $qty, $_SESSION['usuario']['id_usuario'], $obs]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Mercancía transferida correctamente']);
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}