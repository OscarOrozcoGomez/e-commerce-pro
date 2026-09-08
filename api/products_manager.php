<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/image_optimizer.php';

header('Content-Type: application/json');

// Refresca permisos por si se revocaron/concedieron desde el panel hace poco.
refreshSessionPermissions();
if (!isAuthenticated() || !hasPermission('gestionar_productos')) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$pdo = getPDO();
$action = $_GET['action'] ?? '';
$usuario = $_SESSION['usuario'];

/**
 * Helpers de la sincronización con B-Life (blifeResolveHandle, blifeCatalog, blifeHtmlToText,
 * blifeTabText, blifeVariantMetaFromJsonLd, ...). Viven en core/ para poder cubrirlos con
 * pruebas unitarias sin arrastrar la sesión ni cURL.
 */
require_once __DIR__ . '/../core/blife_sync_utils.php';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($action === 'list') {
            $id_alm = (int)($_GET['almacen_id'] ?? 1);
            $sql = "SELECT p.*, 
                    (SELECT pi2.ruta_archivo FROM producto_imagenes pi2 WHERE pi2.id_producto = p.id_producto ORDER BY pi2.orden ASC LIMIT 1) as imagen,
                    GROUP_CONCAT(DISTINCT pc.id_categoria) as categorias_ids, 
                    GROUP_CONCAT(DISTINCT pi.ruta_archivo ORDER BY pi.orden ASC) as galeria_paths,
                    COALESCE(ia.stock_minimo, 2) as stock_minimo,
                    COALESCE(ia.stock_maximo, 5) as stock_maximo,
                    COALESCE(ia.cantidad_actual, 0) as cantidad_actual,
                    COALESCE((SELECT SUM(ia_total.cantidad_actual) FROM inventario_almacen ia_total WHERE ia_total.id_producto = p.id_producto), 0) as total_stock 
                    FROM productos p 
                    LEFT JOIN producto_categorias pc ON p.id_producto = pc.id_producto
                    LEFT JOIN producto_imagenes pi ON p.id_producto = pi.id_producto
                    LEFT JOIN inventario_almacen ia ON p.id_producto = ia.id_producto AND ia.id_almacen = :id_alm
                    WHERE p.estado != 'inactivo' 
                    GROUP BY p.id_producto
                    ORDER BY p.nombre";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':id_alm' => $id_alm]);
            echo json_encode(['success' => true, 'data' => $stmt->fetchAll()]);
        } 
        elseif ($action === 'get_dependencies') {
            // Carga almacenes y categorías para los dropdowns
            try {
                $almacenes = $pdo->query("SELECT * FROM almacenes WHERE estado = 'activo' ORDER BY nombre ASC") ?: null;
                $presentaciones = $pdo->query("SELECT nombre FROM tipos_presentacion ORDER BY nombre ASC") ?: null;

                echo json_encode([
                    'success' => true,
                    'almacenes' => $almacenes ? $almacenes->fetchAll() : [],
                    'categorias' => dbGetCategories(),
                    'presentaciones' => $presentaciones ? $presentaciones->fetchAll(PDO::FETCH_COLUMN) : [],
                    'productos_padre' => dbGetParentProducts()
                ]);
            } catch (Throwable $e) {
                throw new Exception("Error cargando dependencias: " . $e->getMessage());
            }
        }
        elseif ($action === 'fetch_blife_info') {
            $rawId = trim((string)($_GET['variant_id'] ?? ''));

            // 1. Identificador -> handle del producto en la tienda Shopify de B-Life.
            [$handle, $variantHint] = blifeResolveHandle($rawId);

            // 2. Datos estructurados (nombre, variantes, precios, SKU, imágenes).
            [$jsonBody, $jsonCode, $jsonErr] = blifeHttpGet(BLIFE_SHOP . '/products/' . rawurlencode($handle) . '.json');
            if ($jsonErr !== '') {
                throw new Exception("No se pudo conectar con la tienda de B-Life: $jsonErr");
            }
            if ($jsonCode === 404) {
                throw new Exception("B-Life no tiene un producto con handle «{$handle}».");
            }
            if ($jsonCode !== 200) {
                error_log("fetch_blife_info handle=$handle json_http=$jsonCode body=" . substr($jsonBody, 0, 300));
                throw new Exception("B-Life devolvió $jsonCode al pedir «{$handle}».");
            }
            $prod = json_decode($jsonBody, true)['product'] ?? null;
            if (!is_array($prod)) {
                throw new Exception("Respuesta inesperada de B-Life para «{$handle}».");
            }
            $productId = (int)($prod['id'] ?? 0);

            // 3. Variante elegida: la que pidió el usuario o la primera.
            $variants = $prod['variants'] ?? [];
            $variante = [];
            if ($variantHint) {
                foreach ($variants as $v) {
                    if ((int)($v['id'] ?? 0) === $variantHint) { $variante = $v; break; }
                }
            }
            if (!$variante) $variante = $variants[0] ?? [];

            // 4. Imágenes: la destacada de la variante al frente, luego el resto del producto.
            $normImg = static function ($src): string {
                $src = trim((string)$src);
                if ($src === '') return '';
                if (strpos($src, '//') === 0) $src = 'https:' . $src;
                return $src;
            };
            $galeria = [];
            foreach ($prod['images'] ?? [] as $img) {
                $u = $normImg($img['src'] ?? '');
                if ($u !== '') $galeria[] = $u;
            }
            $destacada = $normImg($variante['featured_image']['src'] ?? '');
            if ($destacada !== '') {
                $galeria = array_values(array_filter($galeria, static fn($u) => $u !== $destacada));
                array_unshift($galeria, $destacada);
            }
            $galeria = array_values(array_unique($galeria));

            // 5. Página del producto: pestañas del tema (ingredientes / modo de uso) y el
            //    JSON-LD, de donde sale el código de barras (gtin), que products.json no trae.
            [$htmlBody, $htmlCode] = blifeHttpGet(BLIFE_SHOP . '/products/' . rawurlencode($handle));
            $ingredientes = $htmlCode === 200 ? blifeTabText($htmlBody, 'ingredientes', $productId) : '';
            $modoUso      = $htmlCode === 200 ? blifeTabText($htmlBody, 'modo-uso', $productId) : '';
            $variantMeta  = $htmlCode === 200 ? blifeVariantMetaFromJsonLd($htmlBody) : [];

            $variantId = (int)($variante['id'] ?? 0);
            $sku       = trim((string)($variante['sku'] ?? '')) ?: trim((string)($variantMeta[$variantId]['sku'] ?? ''));
            // El código de barras viene en products/<handle>.json (campo barcode). El JSON-LD
            // (gtin) queda como respaldo por si Shopify deja de exponerlo en el .json.
            $barcode   = trim((string)($variante['barcode'] ?? '')) ?: trim((string)($variantMeta[$variantId]['barcode'] ?? ''));

            // Precio de retail de B-Life (arranque para "Precio de Venta"; el usuario lo ajusta).
            // El costo real (mayoreo) no está en la tienda pública, ese lo pone el usuario.
            $precioVenta = (float)($variante['price'] ?? 0);
            $precioComp  = (float)($variante['compare_at_price'] ?? 0);

            // 6. Respuesta con la MISMA forma que consumía fetchBlifeData() en views/products.php.
            $blife_data = [
                'producto' => [
                    'title'       => (string)($prod['title'] ?? ''),
                    'description' => blifeHtmlToText((string)($prod['body_html'] ?? '')),
                    'ingredients' => $ingredientes,
                    'mode_use'    => $modoUso,
                    'sku'         => $sku,
                    'codigo_barras' => $barcode,
                    'precio_venta'       => $precioVenta > 0 ? number_format($precioVenta, 2, '.', '') : '',
                    'precio_comparacion' => $precioComp  > 0 ? number_format($precioComp, 2, '.', '') : '',
                    'variante'    => [
                        'title'          => (string)($variante['title'] ?? ($prod['options'][0]['values'][0] ?? '')),
                        'sku'            => $sku,
                        'codigo_barras'  => $barcode,
                        'precio_venta'       => $precioVenta > 0 ? number_format($precioVenta, 2, '.', '') : '',
                        'precio_comparacion' => $precioComp  > 0 ? number_format($precioComp, 2, '.', '') : '',
                        'featuredImage'  => $galeria[0] ?? '',
                        'secondaryImage' => $galeria[1] ?? '',
                        'gallery'        => array_slice($galeria, 2),
                    ],
                ],
                // La tabla nutrimental (custom.tabla_nutrimental) no se expone sin token de
                // Storefront API. Se deja vacía para captura manual.
                'rows' => [],
            ];

            // Todas las variantes del producto, por si quieres alimentar un bot.
            $variantesLista = [];
            foreach ($variants as $v) {
                $vid = (int)($v['id'] ?? 0);
                $variantesLista[] = [
                    'variant_id'    => $vid,
                    'title'         => (string)($v['title'] ?? ''),
                    'sku'           => trim((string)($v['sku'] ?? '')) ?: trim((string)($variantMeta[$vid]['sku'] ?? '')),
                    'codigo_barras' => trim((string)($v['barcode'] ?? '')) ?: trim((string)($variantMeta[$vid]['barcode'] ?? '')),
                    'precio'        => (string)($v['price'] ?? ''),
                    'precio_comparacion' => (string)($v['compare_at_price'] ?? ''),
                ];
            }

            $sinCodigo = $barcode === '' ? ' No encontré código de barras para esta variante.' : '';

            echo json_encode([
                'success'    => true,
                'blife_data' => $blife_data,
                'handle'     => $handle,
                'variantes'  => $variantesLista,
                'blife_note' => 'Se importó nombre, descripción, presentación, ingredientes, modo de uso, SKU, precio de venta e imágenes de B-Life. Falta el precio de costo (mayoreo) y la tabla nutrimental.' . $sinCodigo,
            ]);
            exit;
        }
        elseif ($action === 'blife_search') {
            // Busca en el catálogo cacheado de B-Life por nombre, SKU o código de barras,
            // para no tener que ir a buscar la URL/handle a mano.
            // Normaliza (minúsculas + sin acentos) para que "multivitaminico mujer" encuentre
            // "Multivitamínico para Mujer ...".
            $q = blifeNormalizeText((string)($_GET['q'] ?? ''));
            if (mb_strlen($q) < 2) {
                echo json_encode(['success' => true, 'results' => []]);
                exit;
            }

            $tokens = array_values(array_filter(preg_split('~\s+~', $q) ?: []));
            $results = [];
            foreach (blifeCatalog() as $p) {
                $title = (string)($p['title'] ?? '');
                $skus  = [];
                $barcodes = [];
                foreach ($p['variants'] ?? [] as $v) {
                    if (!empty($v['sku']))     $skus[] = (string)$v['sku'];
                    if (!empty($v['barcode'])) $barcodes[] = (string)$v['barcode'];
                }
                $haystack = blifeNormalizeText($title . ' ' . implode(' ', $skus) . ' ' . implode(' ', $barcodes));

                $match = true;
                foreach ($tokens as $t) {
                    if (mb_strpos($haystack, $t) === false) { $match = false; break; }
                }
                if (!$match) continue;

                $results[] = [
                    'handle'    => (string)($p['handle'] ?? ''),
                    'title'     => $title,
                    'image'     => (string)($p['images'][0]['src'] ?? ''),
                    'variantes' => array_map(static fn($v) => [
                        'variant_id'    => (int)($v['id'] ?? 0),
                        'title'         => (string)($v['title'] ?? ''),
                        'sku'           => (string)($v['sku'] ?? ''),
                        'codigo_barras' => (string)($v['barcode'] ?? ''),
                        'precio'        => (string)($v['price'] ?? ''),
                    ], $p['variants'] ?? []),
                ];
                if (count($results) >= 25) break;
            }

            echo json_encode(['success' => true, 'results' => $results]);
            exit;
        }
    }
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = $_POST;
        if (!validateCsrfToken($data['csrf_token'] ?? '')) {
            throw new Exception("Token de seguridad inválido.");
        }

        if ($action === 'save') {
            $id = (int)($data['id_producto'] ?? 0);
            $estado = ($data['visible_catalogo'] ?? '0') === '1' ? 'activo' : 'archivado';
            $mostrar_tabla = ($data['mostrar_tabla'] ?? '0') === '1' ? 1 : 0;
            // SKU y código de barras son opcionales; se guardan como NULL (no '') para no chocar
            // con sus índices únicos cuando varios productos se quedan sin este dato.
            $sku = trim((string)($data['sku'] ?? ''));
            $sku = $sku === '' ? null : $sku;
            $codigoBarras = trim((string)($data['codigo_barras'] ?? ''));
            $codigoBarras = $codigoBarras === '' ? null : $codigoBarras;

            $capsulasPorEnvase = isset($data['capsulas_por_envase']) && $data['capsulas_por_envase'] !== ''
                ? max(0, (int)$data['capsulas_por_envase']) : null;
            $porcionCapsulas = isset($data['porcion_capsulas']) && $data['porcion_capsulas'] !== ''
                ? max(0, (int)$data['porcion_capsulas']) : null;

            if ($id > 0) {
                // EDITAR
                $sql = "UPDATE productos SET `nombre` = :nombre, `nombre_variante` = :nombre_variante, `sku` = :sku, `codigo_barras` = :codigo_barras,
                        `descripcion` = :descripcion, `ingredientes` = :ingredientes, `modo_uso` = :modo_uso,
                        `tabla_nutrimental` = :tabla, `mostrar_tabla` = :mostrar_tabla, `unidad` = :unidad,
                        `capsulas_por_envase` = :capsulas_por_envase, `porcion_capsulas` = :porcion_capsulas,
                        `id_padre` = :id_padre, `precio_costo` = :precio_costo,
                        `precio_venta` = :precio_venta, `precio_comparacion` = :precio_comparacion, `estado` = :estado
                        WHERE id_producto = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':nombre' => $data['nombre'] ?? '', ':nombre_variante' => $data['nombre_variante'] ?? null,
                    ':sku' => $sku, ':codigo_barras' => $codigoBarras,
                    ':descripcion' => $data['descripcion'] ?? '', ':ingredientes' => $data['ingredientes'] ?? '',
                    ':modo_uso' => $data['modo_uso'] ?? '', ':tabla' => $data['tabla_nutrimental'] ?? '[]',
                    ':mostrar_tabla' => $mostrar_tabla,
                    ':unidad' => $data['unidad'] ?? null,
                    ':capsulas_por_envase' => $capsulasPorEnvase, ':porcion_capsulas' => $porcionCapsulas,
                    ':id_padre' => !empty($data['id_padre']) ? (int)$data['id_padre'] : null,
                    ':precio_costo' => $data['precio_costo'] ?? 0,
                    ':precio_venta' => $data['precio_venta'] ?? 0, ':precio_comparacion' => $data['precio_comparacion'] ?? 0,
                    ':estado' => $estado, ':id' => $id
                ]);
            } else {
                // AGREGAR
                $sql = "INSERT INTO productos (`nombre`, `nombre_variante`, `sku`, `codigo_barras`, `descripcion`, `ingredientes`, `modo_uso`, `tabla_nutrimental`, `mostrar_tabla`, `unidad`, `capsulas_por_envase`, `porcion_capsulas`, `id_padre`, `precio_costo`, `precio_venta`, `precio_comparacion`, `estado`)
                        VALUES (:nombre, :nombre_variante, :sku, :codigo_barras, :descripcion, :ingredientes, :modo_uso, :tabla, :mostrar_tabla, :unidad, :capsulas_por_envase, :porcion_capsulas, :id_padre, :precio_costo, :precio_venta, :precio_comparacion, :estado)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':nombre' => $data['nombre'] ?? '',
                    ':nombre_variante' => $data['nombre_variante'] ?? null,
                    ':sku' => $sku,
                    ':codigo_barras' => $codigoBarras,
                    ':descripcion' => $data['descripcion'] ?? '',
                    ':ingredientes' => $data['ingredientes'] ?? '',
                    ':modo_uso' => $data['modo_uso'] ?? '',
                    ':tabla' => $data['tabla_nutrimental'] ?? '[]',
                    ':mostrar_tabla' => $mostrar_tabla,
                    ':unidad' => $data['unidad'] ?? null,
                    ':capsulas_por_envase' => $capsulasPorEnvase,
                    ':porcion_capsulas' => $porcionCapsulas,
                    ':id_padre' => !empty($data['id_padre']) ? (int)$data['id_padre'] : null,
                    ':precio_costo' => $data['precio_costo'] ?? 0,
                    ':precio_venta' => $data['precio_venta'] ?? 0,
                    ':precio_comparacion' => $data['precio_comparacion'] ?? 0,
                    ':estado' => $estado
                ]);
                $id = (int)$pdo->lastInsertId();
            }

            // PROCESAR IMÁGENES (Combinación de locales y remotas de B-Life)
            $hasLocal = isset($_FILES['imagenes']) && !empty($_FILES['imagenes']['name'][0]);
            $hasRemote = !empty($data['remote_images_urls']);
            $hasOrden = !empty($data['imagenes_orden_json']);

            if ($hasLocal || $hasRemote || $hasOrden) {
                // Reusar carpeta existente para evitar duplicados cuando cambia el nombre/slug.
                $existingFolderName = '';
                $baseDir = rtrim(dirname(__DIR__), '/\\') . '/assets/img/products/';
                if ($id > 0) {
                    $stmtFolder = $pdo->prepare(
                        "SELECT ruta_archivo
                         FROM producto_imagenes
                         WHERE id_producto = ? AND ruta_archivo IS NOT NULL AND ruta_archivo <> ''
                         ORDER BY orden ASC
                         LIMIT 1"
                    );
                    $stmtFolder->execute([$id]);
                    $firstPath = trim((string)$stmtFolder->fetchColumn());
                    if ($firstPath !== '') {
                        $normalizedPath = str_replace('\\\\', '/', $firstPath);
                        $firstFolder = explode('/', ltrim($normalizedPath, '/'), 2)[0] ?? '';
                        $firstFolder = trim($firstFolder);
                        if ($firstFolder !== '' && $firstFolder !== '.' && $firstFolder !== '..') {
                            $existingFolderName = $firstFolder;
                        }
                    }

                    // Fallback: si DB no trae ruta, intentar detectar carpeta existente en disco por sufijo -ID.
                    if ($existingFolderName === '') {
                        $diskMatches = glob($baseDir . '*-' . $id, GLOB_ONLYDIR);
                        if (is_array($diskMatches) && !empty($diskMatches)) {
                            usort($diskMatches, static function (string $a, string $b): int {
                                $countImages = static function (string $dir): int {
                                    $files = glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '*.{webp,jpg,jpeg,png,gif,svg,avif}', GLOB_BRACE);
                                    return is_array($files) ? count($files) : 0;
                                };

                                $aCount = $countImages($a);
                                $bCount = $countImages($b);
                                if ($aCount !== $bCount) {
                                    return $bCount <=> $aCount;
                                }

                                $aBase = (string)basename($a);
                                $bBase = (string)basename($b);
                                $lenCmp = strlen($aBase) <=> strlen($bBase);
                                if ($lenCmp !== 0) {
                                    return $lenCmp;
                                }

                                return strcasecmp($aBase, $bBase);
                            });

                            $existingFolderName = (string)basename($diskMatches[0]);
                        }
                    }
                }

                // Solo si no hay historial, usar slug-id como carpeta nueva.
                $folderName = $existingFolderName !== ''
                    ? $existingFolderName
                    : (slugify($data['nombre'] ?? 'producto') . '-' . $id);
                
                // FORZAR RUTA ABSOLUTA: Independiente de si PRODUCTS_IMG_DIR es relativo o absoluto
                // Buscamos la carpeta assets desde la raíz del proyecto (un nivel arriba de api/)
                $targetDir = $baseDir . $folderName . '/';
                
                if (!is_dir($targetDir)) {
                    if (!mkdir($targetDir, 0755, true)) throw new Exception("Error al crear carpeta de imágenes. Revisa permisos en assets/img/products/");
                }
                
                $uploadedPaths = [];
                if ($hasLocal) {
                    $files = $_FILES['imagenes'];
                    for ($i = 0; $i < count($files['name']); $i++) {
                        if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
                        $ext = strtolower(pathinfo($files['name'][$i], PATHINFO_EXTENSION));
                        $fileName = "upd_" . $i . "_" . time() . "." . $ext;
                        $targetFile = $targetDir . $fileName;
                        
                        if (move_uploaded_file($files['tmp_name'][$i], $targetFile)) {
                            optimizeUploadedProductImage($targetFile);
                            $uploadedPaths[$i] = $folderName . '/' . $fileName;
                        } else {
                            throw new Exception("Error al mover el archivo subido al servidor. Revisa permisos de escritura en: " . $targetDir);
                        }
                    }
                }

                // Pre-descargar imágenes remotas (B-Life) si vienen en el orden
                $remoteDownloaded = [];
                if ($hasOrden) {
                    $ordenRaw = json_decode($data['imagenes_orden_json'], true);
                    foreach ($ordenRaw as $ref) {
                        if (strpos($ref, 'remote:') === 0) {
                            $url = substr($ref, 7);
                            $parsedPath = parse_url($url, PHP_URL_PATH);
                            $ext = strtolower(pathinfo($parsedPath, PATHINFO_EXTENSION)) ?: 'webp';
                            $fileName = "blife_" . md5($url) . "." . $ext;
                            $targetFile = $targetDir . $fileName;
                            $dbPath = $folderName . '/' . $fileName;

                            // Si el archivo ya existe localmente (por un sync previo), no lo descargamos de nuevo
                            if (file_exists($targetFile)) {
                                $remoteDownloaded[$url] = $dbPath;
                                continue;
                            }

                                $ch = curl_init($url);
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                                curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                                $imgRaw = curl_exec($ch);
                                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                                $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
                                curl_close($ch);

                                // Solo guardar si el servidor respondió 200 OK y es una imagen real
                                if ($httpCode === 200 && strpos($contentType, 'image/') !== false && $imgRaw) {
                                    file_put_contents($targetFile, $imgRaw);
                                    optimizeUploadedProductImage($targetFile);
                                    $remoteDownloaded[$url] = $dbPath;
                                }
                        }
                    }
                }

                // Reconstruir lista final basada en el orden enviado desde el cliente
                $finalPaths = [];
                if ($hasOrden) {
                    $orden = json_decode($data['imagenes_orden_json'], true);
                    foreach ($orden as $ref) {
                        if (strpos($ref, 'server:') === 0) {
                            $rawServerPath = trim((string)substr($ref, 7));
                            $rawServerPath = ltrim(str_replace('\\\\', '/', $rawServerPath), '/');
                            if ($rawServerPath === '') {
                                continue;
                            }

                            $segments = explode('/', $rawServerPath);
                            if (count($segments) < 2) {
                                continue;
                            }

                            $folderPart = trim((string)($segments[0] ?? ''));
                            $filePart = trim((string)basename($rawServerPath));
                            if ($folderPart === '' || $filePart === '') {
                                continue;
                            }

                            // Solo aceptar rutas de la carpeta activa del producto.
                            if ($folderPart !== $folderName) {
                                // Compatibilidad: si coincide por nombre de archivo en la carpeta activa,
                                // canonicalizamos a folderName/archivo y descartamos referencias cruzadas.
                                $candidateInTarget = $targetDir . $filePart;
                                if (is_file($candidateInTarget)) {
                                    $finalPaths[] = $folderName . '/' . $filePart;
                                }
                                continue;
                            }

                            $canonicalServerPath = $folderName . '/' . $filePart;
                            if (is_file($targetDir . $filePart)) {
                                $finalPaths[] = $canonicalServerPath;
                            }
                        } elseif (strpos($ref, 'local:') === 0) {
                            $idx = (int)substr($ref, 6);
                            if (isset($uploadedPaths[$idx])) $finalPaths[] = $uploadedPaths[$idx];
                        } elseif (strpos($ref, 'remote:') === 0) {
                            $url = substr($ref, 7);
                            if (isset($remoteDownloaded[$url])) $finalPaths[] = $remoteDownloaded[$url];
                        }
                    }
                } else {
                    $finalPaths = $uploadedPaths;
                }

                $finalPaths = array_values(array_unique(array_filter(array_map(static function ($path): string {
                    return trim((string)$path);
                }, $finalPaths), static function (string $path): bool {
                    return $path !== '';
                })));

                // Limpiar galería actual
                $pdo->prepare("DELETE FROM producto_imagenes WHERE id_producto = ?")->execute([$id]);
                
                if (!empty($finalPaths)) {
                    // Insertar todas en la galería (la primera será la principal por orden 0)
                    for ($i = 0; $i < count($finalPaths); $i++) {
                        $pdo->prepare("INSERT INTO producto_imagenes (id_producto, ruta_archivo, orden) VALUES (?, ?, ?)")
                            ->execute([$id, $finalPaths[$i], $i]);
                    }
                }

                // Sincronizar campo legacy productos.imagen con la primera imagen vigente.
                $legacyMainImage = !empty($finalPaths) ? $finalPaths[0] : null;
                $pdo->prepare("UPDATE productos SET imagen = ? WHERE id_producto = ?")
                    ->execute([$legacyMainImage, $id]);

                // Limpieza fisica: eliminar archivos no referenciados por la galeria final.
                // Esto evita que el fallback por carpeta vuelva a mostrar imagenes ya eliminadas desde admin.
                $keepFiles = [];
                foreach ($finalPaths as $path) {
                    $path = trim((string)$path);
                    if ($path === '') {
                        continue;
                    }

                    $normalized = str_replace('\\\\', '/', $path);
                    $parts = explode('/', ltrim($normalized, '/'));
                    if (count($parts) < 2) {
                        continue;
                    }

                    $folderPart = trim((string)$parts[0]);
                    $filePart = trim((string)($parts[count($parts) - 1] ?? ''));
                    if ($folderPart === $folderName && $filePart !== '') {
                        $keepFiles[$filePart] = true;
                    }
                }

                $diskImages = glob($targetDir . '*.{webp,jpg,jpeg,png,gif,svg,avif}', GLOB_BRACE);
                if (is_array($diskImages)) {
                    foreach ($diskImages as $absPath) {
                        $base = (string)basename($absPath);
                        if (!isset($keepFiles[$base]) && is_file($absPath)) {
                            @unlink($absPath);
                        }
                    }
                }
            }

            dbSetProductCategories($id, $data['categorias'] ?? []);
            
            // El inventario_almacen SOLO se escribe si el usuario editó a propósito los campos
            // de stock de la ficha (stock_touched=1). Antes esto corría en cada guardado, así
            // que cambiar el nombre/foto/precio de un producto reescribía cantidad_actual,
            // stock_minimo y stock_maximo del almacén seleccionado (y con el selector de la
            // lista podía terminar escribiendo en el almacén equivocado).
            if (isAdmin() && ($data['stock_touched'] ?? '0') === '1') {
                $id_alm = (int)($data['id_almacen_stock'] ?? 0);
                if ($id_alm > 0) {
                    $nuevaCantidad = max(0, (int)($data['cantidad_actual'] ?? 0));
                    $nuevoMin = max(0, (int)($data['stock_minimo'] ?? 2));
                    // 0 es válido: "sin objetivo de reorden" para almacenes que no se resurten.
                    $nuevoMax = max(0, (int)($data['stock_maximo'] ?? 5));

                    // Existencias previas en ese almacén, para el registro de auditoría.
                    $stmtPrev = $pdo->prepare("SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = ? AND id_almacen = ?");
                    $stmtPrev->execute([$id, $id_alm]);
                    $prevRaw = $stmtPrev->fetchColumn();
                    $cantidadPrevia = ($prevRaw === false) ? null : (int)$prevRaw;

                    $stmtInv = $pdo->prepare("INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual, stock_minimo, stock_maximo)
                                              VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE cantidad_actual = VALUES(cantidad_actual), stock_minimo = VALUES(stock_minimo), stock_maximo = VALUES(stock_maximo)");
                    $stmtInv->execute([$id, $id_alm, $nuevaCantidad, $nuevoMin, $nuevoMax]);

                    // Auditoría: dejar rastro del ajuste manual de existencias. Si la tabla de
                    // movimientos falla, no se rompe el guardado del producto.
                    if ($cantidadPrevia === null || $cantidadPrevia !== $nuevaCantidad) {
                        try {
                            $delta = $nuevaCantidad - (int)($cantidadPrevia ?? 0);
                            $obs = sprintf(
                                'Ajuste manual desde ficha de producto (antes: %s, despues: %d)',
                                $cantidadPrevia === null ? 'sin registro' : (string)$cantidadPrevia,
                                $nuevaCantidad
                            );
                            $stmtMov = $pdo->prepare("INSERT INTO movimientos_inventario (id_producto, tipo_movimiento, id_almacen_destino, cantidad, id_usuario, observacion)
                                                      VALUES (?, 'ajuste', ?, ?, ?, ?)");
                            $stmtMov->execute([$id, $id_alm, $delta, $_SESSION['usuario']['id_usuario'] ?? null, $obs]);
                        } catch (Throwable $movErr) {
                            error_log('products_manager: no se pudo registrar movimiento de ajuste: ' . $movErr->getMessage());
                        }
                    }
                }
            }

            echo json_encode(['success' => true, 'message' => 'Producto guardado con éxito']);
        } 
        elseif ($action === 'delete') {
            $id = (int)$data['id_producto'];
            $pdo->prepare("UPDATE productos SET estado = 'inactivo' WHERE id_producto = ?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Producto eliminado']);
        }
        elseif ($action === 'add_category') {
            $nombre = trim($data['nuevo_nombre_cat'] ?? '');
            if (dbCreateCategory($nombre)) {
                echo json_encode(['success' => true, 'message' => 'Categoría creada']);
            } else {
                throw new Exception("Error al crear categoría");
            }
        }
        elseif ($action === 'bulk_assign_category') {
            // Fase 4: basta con 'gestionar_productos' (ya exigido arriba); el rol
            // admin/encargado se mantiene como respaldo.
            if (!hasPermission('gestionar_productos') && !canBulkAssignCategories()) {
                throw new Exception("No tienes permiso para asignar categorías de forma masiva.");
            }

            // Asigna una categoria a muchos productos a la vez, SIN tocar las categorias que
            // cada producto ya tenia (a diferencia de dbSetProductCategories, que reemplaza
            // todo el set de categorias de un producto individual).
            $idCategoria = (int)($data['id_categoria'] ?? 0);
            $nuevaCategoria = trim((string)($data['nueva_categoria'] ?? ''));

            $productosIdsRaw = $data['productos_ids'] ?? [];
            $productosIds = [];
            if (is_array($productosIdsRaw)) {
                foreach ($productosIdsRaw as $pid) {
                    $pidInt = (int)$pid;
                    if ($pidInt > 0) {
                        $productosIds[] = $pidInt;
                    }
                }
            }
            $productosIds = array_values(array_unique($productosIds));

            if (empty($productosIds)) {
                throw new Exception("Selecciona al menos un producto.");
            }

            if ($nuevaCategoria !== '') {
                if (!isAdmin()) {
                    throw new Exception("Solo un administrador puede crear categorías nuevas. Selecciona una existente o pide que la creen primero.");
                }
                if (!dbCreateCategory($nuevaCategoria)) {
                    throw new Exception("No se pudo crear la categoría.");
                }
                $stmtCat = $pdo->prepare("SELECT id_categoria FROM categorias WHERE nombre = ? LIMIT 1");
                $stmtCat->execute([$nuevaCategoria]);
                $idCategoria = (int)($stmtCat->fetchColumn() ?: 0);
            }

            if ($idCategoria <= 0) {
                throw new Exception("Selecciona una categoría existente o escribe el nombre de una nueva.");
            }

            $pdo->beginTransaction();
            try {
                // SELECT + INSERT (en vez de INSERT IGNORE / ON DUPLICATE KEY) porque no se
                // depende de que producto_categorias tenga una llave unica compuesta.
                $stmtCheck = $pdo->prepare("SELECT 1 FROM producto_categorias WHERE id_producto = ? AND id_categoria = ?");
                $stmtInsert = $pdo->prepare("INSERT INTO producto_categorias (id_producto, id_categoria) VALUES (?, ?)");
                $agregados = 0;
                foreach ($productosIds as $pid) {
                    $stmtCheck->execute([$pid, $idCategoria]);
                    if ($stmtCheck->fetchColumn()) {
                        continue; // ya tenia esta categoria, no se duplica
                    }
                    $stmtInsert->execute([$pid, $idCategoria]);
                    $agregados++;
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $e;
            }

            if (function_exists('logAudit')) {
                logAudit(
                    'CATEGORIA_ASIGNADA_MASIVA',
                    'producto_categorias',
                    $idCategoria,
                    'Categoria #' . $idCategoria . ' asignada a ' . $agregados . ' de ' . count($productosIds) . ' productos seleccionados por ' . (string)($usuario['nombre'] ?? 'usuario')
                );
            }

            $yaTenian = count($productosIds) - $agregados;
            $mensaje = 'Categoría asignada a ' . $agregados . ' producto(s).' . ($yaTenian > 0 ? ' ' . $yaTenian . ' ya la tenían.' : '');
            echo json_encode([
                'success' => true,
                'message' => $mensaje,
                'agregados' => $agregados,
                'ya_tenian' => $yaTenian,
            ]);
        }
    }
} catch (Throwable $e) {
    http_response_code(400);
    error_log("Error en products_manager: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}