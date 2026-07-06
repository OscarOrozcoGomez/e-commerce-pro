<?php
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/product_display_utils.php';

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

    // 2. Resolver imagenes del producto (galeria + campos legacy) para variante,
    // padre y variantes hermanas con el mismo nombre.
    $currentId = (int)($product['id_producto'] ?? 0);
    $parentId = (int)($product['id_padre'] ?? 0);
    $rootId = $parentId > 0 ? $parentId : $currentId;
    $baseName = trim((string)($product['nombre'] ?? ''));

    $collectFolderImagesByProductId = static function (int $productId): array {
        if ($productId <= 0) {
            return [];
        }

        $baseDir = realpath(__DIR__ . '/../assets/img/products');
        if ($baseDir === false) {
            return [];
        }

        $folderMatches = glob($baseDir . DIRECTORY_SEPARATOR . '*-' . $productId, GLOB_ONLYDIR);
        if (!is_array($folderMatches) || empty($folderMatches)) {
            return [];
        }

        $relativeImages = [];
        foreach ($folderMatches as $folderPath) {
            $files = glob($folderPath . DIRECTORY_SEPARATOR . '*.{webp,jpg,jpeg,png,gif,svg}', GLOB_BRACE);
            if (!is_array($files) || empty($files)) {
                continue;
            }

            usort($files, static function (string $a, string $b): int {
                $aBase = strtolower((string)basename($a));
                $bBase = strtolower((string)basename($b));

                $rank = static function (string $name): int {
                    if (strpos($name, 'principal.') === 0) {
                        return 0;
                    }
                    if (strpos($name, 'gal_') === 0) {
                        return 1;
                    }
                    return 2;
                };

                $rankA = $rank($aBase);
                $rankB = $rank($bBase);
                if ($rankA !== $rankB) {
                    return $rankA <=> $rankB;
                }
                return strnatcasecmp($aBase, $bBase);
            });

            foreach ($files as $filePath) {
                $relative = str_replace('\\', '/', ltrim(str_replace($baseDir, '', $filePath), '\\/'));
                if ($relative !== '') {
                    $relativeImages[] = $relative;
                }
            }
        }

        return array_values(array_unique($relativeImages));
    };

    $imageCandidates = [];

        $stmtGal = $pdo->prepare(
        "SELECT pi.ruta_archivo
         FROM producto_imagenes pi
         INNER JOIN productos p_img ON pi.id_producto = p_img.id_producto
            WHERE p_img.id_producto IN (?, ?)
              OR (TRIM(p_img.nombre) = ? AND p_img.estado = 'activo')
            ORDER BY (p_img.id_producto = ?) DESC, (p_img.id_producto = ?) DESC, pi.orden ASC"
    );
        $stmtGal->execute([$currentId, $rootId, $baseName, $currentId, $rootId]);
    $galeria = $stmtGal->fetchAll(PDO::FETCH_COLUMN);
    foreach ($galeria as $img) {
        $img = trim((string)$img);
        if ($img !== '') {
            $imageCandidates[] = $img;
        }
    }

    foreach (['imagen', 'imagen_url'] as $field) {
        $raw = trim((string)($product[$field] ?? ''));
        if ($raw !== '') {
            $imageCandidates[] = $raw;
        }
    }

    if ($rootId > 0 && $rootId !== $currentId) {
        $stmtRoot = $pdo->prepare("SELECT imagen, imagen_url FROM productos WHERE id_producto = ? LIMIT 1");
        $stmtRoot->execute([$rootId]);
        $rootRow = $stmtRoot->fetch(PDO::FETCH_ASSOC) ?: [];
        foreach (['imagen', 'imagen_url'] as $field) {
            $raw = trim((string)($rootRow[$field] ?? ''));
            if ($raw !== '') {
                $imageCandidates[] = $raw;
            }
        }
    }

    if ($baseName !== '') {
        $stmtNameImgs = $pdo->prepare(
            "SELECT imagen, imagen_url
             FROM productos
             WHERE estado = 'activo' AND TRIM(nombre) = ?"
        );
        $stmtNameImgs->execute([$baseName]);
        $nameRows = $stmtNameImgs->fetchAll(PDO::FETCH_ASSOC);
        foreach ($nameRows as $row) {
            foreach (['imagen', 'imagen_url'] as $field) {
                $raw = trim((string)($row[$field] ?? ''));
                if ($raw !== '') {
                    $imageCandidates[] = $raw;
                }
            }
        }
    }

    foreach ($collectFolderImagesByProductId($currentId) as $folderImagePath) {
        $imageCandidates[] = $folderImagePath;
    }

    $imagenes = [];
    foreach ($imageCandidates as $rawImage) {
        $fmt = getProductImageUrl($rawImage, $currentId);
        if ($fmt !== '') {
            $imagenes[] = $fmt;
        }
    }
    $imagenes = array_values(array_unique($imagenes));

    // Definir la imagen principal como la primera imagen válida
    $product['imagen'] = !empty($imagenes) ? $imagenes[0] : getProductImageUrl((string)($product['imagen'] ?? ''), $currentId);
    $product['galeria'] = $imagenes;
    $product = normalizeProductDisplayRow($product);

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
            $v['imagen'] = getProductImageUrl((string)($v['imagen'] ?? ''), (int)($v['id_producto'] ?? 0));
            $variantes_unicas[] = normalizeProductDisplayRow($v);
        }
    }
    
    $product['variantes'] = $variantes_unicas;

    // Enviamos la respuesta limpia al frontend
    echo json_encode($product);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error de base de datos']);
}