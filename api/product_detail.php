<?php
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json');

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'ID de producto no proporcionado']);
    exit;
}

$id = intval($_GET['id']);
$pdo = getPDO();

try {
    // 1. Obtener producto principal
    $stmt = $pdo->prepare("SELECT p.*, COALESCE(SUM(i.cantidad_actual), 0) as stock 
                          FROM productos p 
                          LEFT JOIN inventario_almacen i ON p.id_producto = i.id_producto 
                          WHERE p.id_producto = ? AND p.estado = 'activo'
                          GROUP BY p.id_producto");
    $stmt->execute([$id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        http_response_code(404);
        echo json_encode(['error' => 'Producto no encontrado']);
        exit;
    }

    // Función auxiliar para detectar MIME
    function formatBase64Image($base64String) {
        if (empty($base64String)) return null;
        $mime = 'image/png';
        if (strpos($base64String, 'UklGR') === 0) $mime = 'image/webp';
        elseif (strpos($base64String, '/9j/') === 0) $mime = 'image/jpeg';
        elseif (strpos($base64String, 'iVBORw') === 0) $mime = 'image/png';
        elseif (strpos($base64String, 'R0lGOD') === 0) $mime = 'image/gif';
        return 'data:' . $mime . ';base64,' . $base64String;
    }

    $product['imagen'] = formatBase64Image($product['imagen']);

    // 2. Obtener galería de imágenes
    $stmtGal = $pdo->prepare("SELECT imagen_base64 FROM producto_imagenes WHERE id_producto = ? ORDER BY orden ASC");
    $stmtGal->execute([$id]);
    $galeria = $stmtGal->fetchAll(PDO::FETCH_COLUMN);
    
    $imagenes = [];
    if ($product['imagen']) {
        $imagenes[] = $product['imagen']; // La imagen principal siempre va primero
    }
    foreach ($galeria as $img) {
        $fmt = formatBase64Image($img);
        if ($fmt) $imagenes[] = $fmt;
    }
    $product['galeria'] = $imagenes;

    // 3. Obtener variantes (productos con el mismo nombre base)
    $stmtVar = $pdo->prepare("SELECT id_producto, sku, nombre_variante, precio_venta, precio_comparacion 
                             FROM productos 
                             WHERE nombre = ? AND estado = 'activo' 
                             ORDER BY precio_venta ASC");
    $stmtVar->execute([$product['nombre']]);
    $variantes = $stmtVar->fetchAll(PDO::FETCH_ASSOC);
    
    $product['variantes'] = $variantes;

    echo json_encode($product);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error de base de datos']);
}
