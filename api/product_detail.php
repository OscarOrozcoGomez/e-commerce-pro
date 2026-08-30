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

    // Obtener stock total de forma independiente, mismo tipo de dato que muestra el
    // panel de administración. Antes esto sumaba TODOS los almacenes en la tabla
    // (incluyendo almacenes internos que el checkout nunca usa para surtir un pedido
    // web, ej. "Luisa"), asi que un producto podia mostrarse "Disponible" en la ficha
    // aun con 0 existencia en el almacen que realmente lo va a surtir.
    $sellableWarehouseIds = getPublicSellableWarehouseIds($pdo);
    $placeholders = implode(',', array_fill(0, count($sellableWarehouseIds), '?'));
    $stmtStock = $pdo->prepare(
        "SELECT COALESCE(SUM(cantidad_actual), 0) FROM inventario_almacen WHERE id_producto = ? AND id_almacen IN ($placeholders)"
    );
    $stmtStock->execute(array_merge([$id], $sellableWarehouseIds));
    $product['stock'] = (float)$stmtStock->fetchColumn();

    // 2. Resolver imagenes del producto para la variante actual exclusivamente.
    $currentId = (int)($product['id_producto'] ?? 0);
    $baseName = trim((string)($product['nombre'] ?? ''));

    $collectFolderImagesByProductId = static function (int $productId, string $preferredFolderName = ''): array {
        if ($productId <= 0) {
            return [];
        }

        $baseDir = realpath(__DIR__ . '/../assets/img/products');
        if ($baseDir === false) {
            return [];
        }

        // Usa el indice id_producto => carpetas cacheado en core/auth.php en vez de
        // re-escanear todo assets/img/products/ con glob(GLOB_ONLYDIR) en cada llamada
        // (aqui se invoca una vez por producto y una vez mas por cada variante).
        $folderNames = productImageFolderIndex($baseDir)[$productId] ?? [];
        if (empty($folderNames)) {
            return [];
        }
        $folderMatches = array_map(
            static fn(string $name): string => $baseDir . DIRECTORY_SEPARATOR . $name,
            $folderNames
        );

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
         WHERE pi.id_producto = ?
         ORDER BY pi.orden ASC"
    );
    $stmtGal->execute([$currentId]);
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

    // Incluir siempre la carpeta fisica activa para reflejar descargas/importaciones
    // aun cuando DB tenga un subconjunto de rutas.
    $currentFolderImages = $collectFolderImagesByProductId($currentId, $currentPreferredFolder);
    foreach ($currentFolderImages as $folderImagePath) {
        $imageCandidates[] = $folderImagePath;
    }

    $imagenes = [];
    foreach ($imageCandidates as $rawImage) {
        $fmt = getProductImageUrl($rawImage, $currentId);
        if ($fmt !== '') {
            $imagenes[] = $fmt;
        }
    }
    $imagenes = $filterValidProductUrls($imagenes);

    // Si existen archivos fisicos para la variante actual, priorizamos solo esos.
    $restrictFolderImages = array_values(array_unique($currentFolderImages));
    if (!empty($restrictFolderImages)) {
        $currentFolderUrls = [];
        foreach ($restrictFolderImages as $folderImagePath) {
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
    // Si la variante actual no tiene galeria valida, mostramos default.

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
    
    // Filtro de Calidad: Evitamos duplicados en el array final usando sus IDs únicos.
    //
    // Nota sobre imagenes: aqui NO se resuelve la imagen de cada variante (antes
    // se hacia is_file()/glob() por cada una, sincronico, antes de poder
    // responder el JSON). El frontend (product_detail.php) nunca lee v.imagen
    // ni variant_image_source -- los pills de variante solo muestran texto, y
    // al hacer click ya se vuelve a llamar a este mismo endpoint con el
    // id_producto de la variante, que trae su galeria completa via el bloque
    // de arriba. Resolver esas imagenes aqui era trabajo muerto que escalaba
    // con el numero de variantes del producto.
    $variantes_unicas = [];
    $ids_vistos = [];

    foreach ($variantes as $v) {
        $v_id = (int)$v['id_producto'];
        if (isset($ids_vistos[$v_id])) {
            continue;
        }
        $ids_vistos[$v_id] = true;
        $v['imagen'] = '';
        $variantes_unicas[] = normalizeProductDisplayRow($v);
    }

    $product['variantes'] = $variantes_unicas;

    // Enviamos la respuesta limpia al frontend
    echo json_encode($product);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error de base de datos']);
}