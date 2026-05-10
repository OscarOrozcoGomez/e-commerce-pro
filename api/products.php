<?php
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';


header('Content-Type: application/json');

$search = trim($_GET['search'] ?? '');
$almacenId = getCurrentAlmacenId();

try {
    $pdo = getPDO();

    $sql = "SELECT p.id_producto, p.nombre, p.sku, p.precio_venta, p.precio_costo, p.categoria, p.imagen
            FROM productos p
            WHERE p.estado = 'activo'";

    $params = [];

    if ($search !== '') {
        $sql .= " AND (p.nombre LIKE :search1 OR p.sku LIKE :search2)";
        $params[':search1'] = '%' . $search . '%';
        $params[':search2'] = '%' . $search . '%';
    }

    $sql .= " ORDER BY p.nombre ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Formatear el campo imagen
    foreach ($products as &$product) {
        if (!empty($product['imagen'])) {
            $mime = 'image/png';
            if (strpos($product['imagen'], 'UklGR') === 0) $mime = 'image/webp';
            elseif (strpos($product['imagen'], '/9j/') === 0) $mime = 'image/jpeg';
            elseif (strpos($product['imagen'], 'iVBORw') === 0) $mime = 'image/png';
            elseif (strpos($product['imagen'], 'R0lGOD') === 0) $mime = 'image/gif';
            
            $product['imagen'] = 'data:' . $mime . ';base64,' . $product['imagen'];
        } else {
            $product['imagen'] = null;
        }
    }

    echo json_encode(['success' => true, 'products' => $products]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'Error al cargar productos']);
}
