<?php
require 'core/config.php';
$pdo = getPDO();
$sql = "SELECT p.id_producto, p.nombre, p.imagen FROM productos p WHERE p.estado = 'activo' LIMIT 1";
$stmt = $pdo->query($sql);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!empty($product['imagen'])) {
    $product['imagen'] = 'data:image/webp;base64,' . $product['imagen'];
} else {
    $product['imagen'] = null;
}

echo json_encode($product);
