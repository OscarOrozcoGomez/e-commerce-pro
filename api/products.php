<?php
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();

header('Content-Type: application/json');

$search = trim($_GET['search'] ?? '');
$almacenId = getCurrentAlmacenId();

try {
    $pdo = getPDO();

    $sql = "SELECT p.id_producto, p.nombre, p.sku, p.precio_venta, p.precio_costo, p.categoria
            FROM productos p
            WHERE p.estado = 'activo'";

    $params = [];

    if ($search !== '') {
        $sql .= " AND (p.nombre LIKE :search OR p.sku LIKE :search)";
        $params[':search'] = '%' . $search . '%';
    }

    $sql .= " ORDER BY p.nombre ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Agregar campo imagen (por ahora placeholder)
    foreach ($products as &$product) {
        $product['imagen'] = null; // Se puede agregar campo imagen en tabla productos después
    }

    echo json_encode(['success' => true, 'products' => $products]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Error al cargar productos']);
}
