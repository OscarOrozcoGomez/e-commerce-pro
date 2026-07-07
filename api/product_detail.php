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

    $collectFolderImagesByProductId = static function (int $productId, string $preferredFolderName = ''): array {
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

        $preferredFolderName = strtolower(trim($preferredFolderName));
        if ($preferredFolderName !== '') {
            foreach ($folderMatches as $candidatePath) {
                if (strtolower((string)basename($candidatePath)) === $preferredFolderName) {
                    $folderMatches = [$candidatePath];
                    break;
                }
            }
        }

        if (count($folderMatches) > 1) {
            usort($folderMatches, static function (string $a, string $b): int {
                $aBase = (string)basename($a);
                $bBase = (string)basename($b);
                $lenCompare = strlen($aBase) <=> strlen($bBase);
                if ($lenCompare !== 0) {
                    return $lenCompare;
                }

                $mtimeA = @filemtime($a) ?: 0;
                $mtimeB = @filemtime($b) ?: 0;
                if ($mtimeA !== $mtimeB) {
                    return $mtimeB <=> $mtimeA;
                }

                return strcasecmp($aBase, $bBase);
            });
            $folderMatches = [reset($folderMatches)];
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

    $localProductAssetExists = static function (string $url): bool {
        $url = trim($url);
        if ($url === '') {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return false;
        }

        $marker = '/assets/img/products/';
        $pos = stripos($path, $marker);
        if ($pos === false) {
            return false;
        }

        $rel = ltrim(substr($path, $pos + strlen($marker)), '/');
        if ($rel === '') {
            return false;
        }
        $full = __DIR__ . '/../assets/img/products/' . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        return is_file($full);
    };

    $filterValidProductUrls = static function (array $urls) use ($localProductAssetExists): array {
        $filtered = [];
        foreach ($urls as $url) {
            $u = trim((string)$url);
            if ($u === '') {
                continue;
            }
            if ($localProductAssetExists($u)) {
                $filtered[] = $u;
            }
        }
        return array_values(array_unique($filtered));
    };

    $extractFolderNameFromImagePath = static function (string $rawPath): string {
        $candidate = trim($rawPath);
        if ($candidate === '') {
            return '';
        }

        $parsedPath = parse_url($candidate, PHP_URL_PATH);
        if (is_string($parsedPath) && $parsedPath !== '') {
            $candidate = $parsedPath;
        }

        $candidate = str_replace('\\', '/', $candidate);
        $marker = '/assets/img/products/';
        $markerPos = stripos($candidate, $marker);
        if ($markerPos !== false) {
            $candidate = substr($candidate, $markerPos + strlen($marker));
        }

        $candidate = ltrim($candidate, '/');
        if ($candidate === '') {
            return '';
        }

        $parts = explode('/', $candidate, 2);
        if (count($parts) < 2) {
            return '';
        }

        $folder = trim($parts[0]);
        if ($folder === '' || $folder === '.' || $folder === '..') {
            return '';
        }

        return $folder;
    };

    $imageCandidates = [];
    $dbPreferredFolder = '';

        $stmtGal = $pdo->prepare(
            "SELECT pi.ruta_archivo
             FROM producto_imagenes pi
             INNER JOIN productos p_img ON pi.id_producto = p_img.id_producto
             WHERE p_img.id_producto IN (?, ?)
             ORDER BY (p_img.id_producto = ?) DESC, (p_img.id_producto = ?) DESC, pi.orden ASC"
        );
        $stmtGal->execute([$currentId, $rootId, $currentId, $rootId]);
    $galeria = $stmtGal->fetchAll(PDO::FETCH_COLUMN);
        $hasDbGallery = false;
    foreach ($galeria as $img) {
        $img = trim((string)$img);
        if ($img !== '') {
                $hasDbGallery = true;
            $imageCandidates[] = $img;
            if ($dbPreferredFolder === '') {
                $dbPreferredFolder = $extractFolderNameFromImagePath($img);
            }
        }
    }

    foreach (['imagen', 'imagen_url'] as $field) {
        $raw = trim((string)($product[$field] ?? ''));
        if ($raw !== '') {
            $imageCandidates[] = $raw;
        }
    }


    $currentPreferredFolder = $dbPreferredFolder !== ''
        ? $dbPreferredFolder
        : (slugify($baseName) . '-' . $currentId);
    $currentFolderImages = [];
    // Respetar cambios del admin (alta/baja/reorden) en producto_imagenes.
    // Solo usar archivos de carpeta si no hay galeria definida en DB.
    if (!$hasDbGallery) {
        $currentFolderImages = $collectFolderImagesByProductId($currentId, $currentPreferredFolder);
        foreach ($currentFolderImages as $folderImagePath) {
            $imageCandidates[] = $folderImagePath;
        }
    }

    // Fallback de ultimo recurso para catalogos con variantes no normalizadas:
    // si no logramos ninguna imagen propia, intentamos tomar de productos hermanos por nombre.
    if (empty($imageCandidates) && $baseName !== '') {
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

    $imagenes = [];
    foreach ($imageCandidates as $rawImage) {
        $fmt = getProductImageUrl($rawImage, $currentId);
        if ($fmt !== '') {
            $imagenes[] = $fmt;
        }
    }
    $imagenes = $filterValidProductUrls($imagenes);

    // Si existen archivos fisicos para la variante actual, priorizamos solo esos
    // para evitar mezclar galerias de presentaciones hermanas.
    if (!empty($currentFolderImages)) {
        $currentFolderUrls = [];
        foreach ($currentFolderImages as $folderImagePath) {
            $url = getProductImageUrl($folderImagePath, $currentId);
            if ($url !== '') {
                $currentFolderUrls[] = $url;
            }
        }
        $currentFolderUrls = array_values(array_unique($currentFolderUrls));

        if (!empty($currentFolderUrls)) {
            $allowedMap = array_fill_keys($currentFolderUrls, true);
            $imagenes = array_values(array_filter($imagenes, static function (string $url) use ($allowedMap): bool {
                return isset($allowedMap[$url]);
            }));

            if (empty($imagenes)) {
                $imagenes = $currentFolderUrls;
            }
        }
    }

    // Nota: no hacemos fallback cruzado entre productos hermanos/padre.
    // Si el producto actual no tiene galeria valida, mostramos default.

    // Definir la imagen principal como la primera imagen válida
    $principalImage = getProductImageUrl((string)($product['imagen'] ?? ''), $currentId);
    if (!$localProductAssetExists($principalImage)) {
        $principalImage = '';
    }
    if ($principalImage !== '') {
        $imagenes = array_values(array_unique(array_merge([$principalImage], $imagenes)));
    }

    if (empty($imagenes)) {
        $defaultImage = getDefaultProductImageUrl();
        if ($localProductAssetExists($defaultImage)) {
            $imagenes[] = $defaultImage;
        }
    }

    $product['imagen'] = !empty($imagenes) ? $imagenes[0] : '';
    $product['image_source'] = !empty($imagenes) ? 'local' : 'fallback';
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
            $resolvedVariantImage = getProductImageUrl((string)($v['imagen'] ?? ''), (int)($v['id_producto'] ?? 0));
            if (!$localProductAssetExists($resolvedVariantImage)) {
                    $variantName = trim((string)($v['nombre'] ?? ''));
                    $variantPreferredFolder = slugify($variantName) . '-' . $v_id;
                $fallbackFolderImages = $collectFolderImagesByProductId($v_id, $variantPreferredFolder);
                if (!empty($fallbackFolderImages)) {
                    $resolvedVariantImage = getProductImageUrl($fallbackFolderImages[0], $v_id);
                }
            }
            $v['imagen'] = $localProductAssetExists($resolvedVariantImage) ? $resolvedVariantImage : '';
            $v['variant_image_source'] = $v['imagen'] !== '' ? 'local' : 'fallback';
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