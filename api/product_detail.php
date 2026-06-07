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
    // 1. Obtener datos básicos del producto (Sin stock sumado aquí para evitar error 500)
    $stmt = $pdo->prepare("SELECT * FROM productos WHERE id_producto = ? AND estado = 'activo'");
    $stmt->execute([$id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$product) {
        http_response_code(404);
        echo json_encode(['error' => 'Producto no encontrado']);
        exit;
    }

    // Obtener stock total de forma independiente
    $stmtStock = $pdo->prepare("SELECT COALESCE(SUM(cantidad_actual), 0) FROM inventario_almacen WHERE id_producto = ?");
    $stmtStock->execute([$id]);
    $product['stock'] = (float)$stmtStock->fetchColumn();

    $product['imagen'] = getProductImageUrl($product['imagen']);

    // 2. Obtener galería de imágenes
    $stmtGal = $pdo->prepare("SELECT ruta_archivo FROM producto_imagenes WHERE id_producto = ? ORDER BY orden ASC");
    $stmtGal->execute([$id]);
    $galeria = $stmtGal->fetchAll(PDO::FETCH_COLUMN);
    
    $imagenes = [];
    if (!empty($product['imagen'])) {
        $imagenes[] = $product['imagen']; // La imagen principal siempre va primero
    }
    foreach ($galeria as $img) {
        $fmt = getProductImageUrl($img);
        if ($fmt && !empty($img)) $imagenes[] = $fmt;
    }
    $product['galeria'] = $imagenes;

    // =========================================================================
    // 3. Obtener variantes por Nombre Exacto (Resistente a errores de id_padre)
    // =========================================================================
    $nombre_base = trim($product['nombre']);

    // Buscamos todos los productos que tengan exactamente el mismo nombre base.
    // Esto garantiza que 90, 180 y 360 caps estén juntos si comparten el nombre.
    $sqlVar = "SELECT id_producto, id_padre, sku, nombre, nombre_variante, unidad, precio_venta, precio_comparacion, imagen 
               FROM productos 
               WHERE estado = 'activo' 
               AND TRIM(nombre) = ?
               ORDER BY precio_venta ASC";

    $stmtVar = $pdo->prepare($sqlVar);
    $stmtVar->execute([$nombre_base]);
    $variantes = $stmtVar->fetchAll(PDO::FETCH_ASSOC);
    
    // Filtro de Calidad: Evitamos duplicados en el array final usando sus IDs únicos
    $variantes_unicas = [];
    $ids_vistos = [];
    
    foreach ($variantes as $v) {
        $v_id = (int)$v['id_producto'];
        if (!in_array($v_id, $ids_vistos)) {
            $ids_vistos[] = $v_id;
            $variantes_unicas[] = $v;
        }
    }
    
    $product['variantes'] = $variantes_unicas;

    // Enviamos la respuesta limpia al frontend
    echo json_encode($product);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error de base de datos']);
}