<?php
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';


header('Content-Type: application/json');

$search = trim($_GET['search'] ?? '');
$almacenId = getCurrentAlmacenId();

try {
    $pdo = getPDO();

    $sql = "SELECT p.id_producto, p.nombre, p.sku, p.precio_venta, p.precio_costo, p.categoria, p.imagen,
                   SUM(COALESCE(ia.cantidad_actual, 0)) as stock
            FROM productos p
            LEFT JOIN inventario_almacen ia ON p.id_producto = ia.id_producto
            WHERE p.estado = 'activo'";

    $params = [];

    if ($search !== '') {
        $sql .= " AND (p.nombre LIKE :search1 OR p.sku LIKE :search2)";
        $params[':search1'] = '%' . $search . '%';
        $params[':search2'] = '%' . $search . '%';
    }

    $sql .= " GROUP BY p.id_producto ORDER BY p.nombre ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Formatear el campo imagen
    foreach ($products as &$product) {
        $product['imagen'] = getProductImageUrl($product['imagen']);
    }

    echo json_encode(['success' => true, 'products' => $products]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Error al cargar productos']);
}
