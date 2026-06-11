<?php
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
    exit;
}

if (!isset($_POST['id_producto']) || empty($_POST['id_producto'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID de producto no proporcionado']);
    exit;
}

$id_producto = intval($_POST['id_producto']);
$nombre = trim($_POST['nombre'] ?? '');
$sku = trim($_POST['sku'] ?? '');
$precio_venta = floatval($_POST['precio_venta'] ?? 0);
$precio_costo = floatval($_POST['precio_costo'] ?? 0);
$categoria = trim($_POST['categoria'] ?? '');

// =========================================================================
// 1. PROCESAR LA IMAGEN PRINCIPAL (LA PORTADA - MULTIMEDIA 1 DE 6)
// =========================================================================
$imagen_principal_final = '';

if (isset($_FILES['imagen_nueva']) && $_FILES['imagen_nueva']['error'] === UPLOAD_ERR_OK) {
    $nombre_temporal = $_FILES['imagen_nueva']['tmp_name'];
    $extension = pathinfo($_FILES['imagen_nueva']['name'], PATHINFO_EXTENSION);
    $imagen_principal_final = uniqid('prod_main_', true) . '.' . $extension;
    move_uploaded_file($nombre_temporal, __DIR__ . '/../uploads/productos/' . $imagen_principal_final);
} else {
    // Si no cambió, rescatamos la principal actual y la limpiamos con basename
    $img_act = trim($_POST['imagen_actual'] ?? '');
    if (empty($img_act) || in_array(strtolower($img_act), ['null', 'undefined', '[object object]'])) {
        $imagen_principal_final = null;
    } else {
        // Solo aplicar basename si parece una ruta de archivo (contiene / o .)
        // Si es Base64 (contiene la firma de WebP o PNG), lo dejamos intacto
        $isBase64 = preg_match('/^(data:image|iVBORw|\/9j\/|UklGR)/', $img_act);
        $imagen_principal_final = $isBase64 ? $img_act : basename($img_act);
    }
}


// =========================================================================
// 2. PROCESAR LAS OTRAS 5 IMÁGENES (LA GALERÍA SECUNDARIA)
// =========================================================================
$galeria_final = [];

// Recuperar las imágenes secundarias que el usuario ya tenía y mantuvo en la edición
$galeria_actual = $_POST['galeria_actual'] ?? [];

if (is_array($galeria_actual)) {
    foreach ($galeria_actual as $img_gal) {
        if (!empty($img_gal)) {
            $nombre_limpio = basename($img_gal);
            // Evitamos que la imagen principal se duplique dentro de la galería secundaria
            if ($nombre_limpio !== $imagen_principal_final) {
                $galeria_final[] = $nombre_limpio;
            }
        }
    }
}

// Procesar si se subieron nuevas fotos a la galería desde el editor
if (isset($_FILES['galeria_nuevas'])) {
    foreach ($_FILES['galeria_nuevas']['tmp_name'] as $index => $tmpName) {
        if ($_FILES['galeria_nuevas']['error'][$index] === UPLOAD_ERR_OK) {
            $ext = pathinfo($_FILES['galeria_nuevas']['name'][$index], PATHINFO_EXTENSION);
            $nuevo_nombre_gal = uniqid('prod_gal_', true) . '.' . $ext;
            
            if (move_uploaded_file($tmpName, __DIR__ . '/../uploads/productos/' . $nuevo_nombre_gal)) {
                $galeria_final[] = $nuevo_nombre_gal;
            }
        }
    }
}

// =========================================================================
// 3. GUARDADO EN BASE DE DATOS (TRANSACCIÓN SEGURA)
// =========================================================================
$pdo = getPDO();

try {
    $pdo->beginTransaction();

    // Actualizar tabla principal (1 imagen)
    $sqlProduct = "UPDATE productos 
                   SET nombre = ?, sku = ?, precio_venta = ?, precio_costo = ?, categoria = ?, imagen = ? 
                   WHERE id_producto = ?";
    $stmtProd = $pdo->prepare($sqlProduct);
    $stmtProd->execute([$nombre, $sku, $precio_venta, $precio_costo, $categoria, $imagen_principal_final, $id_producto]);

    // Limpiar galería secundaria anterior para el producto
    $stmtDel = $pdo->prepare("DELETE FROM producto_imagenes WHERE id_producto = ?");
    $stmtDel->execute([$id_producto]);

    // Insertar las imágenes secundarias restantes (las otras 5 imágenes)
    if (!empty($galeria_final)) {
        $sqlInsGal = "INSERT INTO producto_imagenes (id_producto, ruta_archivo, orden) VALUES (?, ?, ?)";
        $stmtIns = $pdo->prepare($sqlInsGal);
        
        foreach ($galeria_final as $orden => $archivo_limpio) {
            $stmtIns->execute([$id_producto, $archivo_limpio, $orden]);
        }
    }

    $pdo->commit();

    echo json_encode([
        'success' => true, 
        'message' => 'Producto actualizado. Total de imágenes procesadas: ' . (1 + count($galeria_final))
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error' => 'Error al actualizar imágenes: ' . $e->getMessage()
    ]);
}