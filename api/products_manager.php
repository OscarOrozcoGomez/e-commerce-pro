<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json');

if (!isAuthenticated() || !hasPermission('gestionar_productos')) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$pdo = getPDO();
$action = $_GET['action'] ?? '';
$usuario = $_SESSION['usuario'];

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($action === 'list') {
            $id_alm = (int)($_GET['almacen_id'] ?? 1);
            $sql = "SELECT p.*, GROUP_CONCAT(DISTINCT pc.id_categoria) as categorias_ids, GROUP_CONCAT(DISTINCT pi.ruta_archivo ORDER BY pi.orden ASC) as galeria_paths, ia.stock_minimo, ia.stock_maximo, ia.cantidad_actual 
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
            echo json_encode([
                'success' => true,
                'almacenes' => $pdo->query("SELECT * FROM almacenes WHERE estado = 'activo' ORDER BY nombre ASC")->fetchAll(),
                'categorias' => dbGetCategories(),
                'presentaciones' => $pdo->query("SELECT nombre FROM tipos_presentacion ORDER BY nombre ASC")->fetchAll(PDO::FETCH_COLUMN),
                'productos_padre' => dbGetParentProducts()
            ]);
        }
        elseif ($action === 'fetch_blife_info') {
            $variant_id = $_GET['variant_id'] ?? '';
            if (empty($variant_id)) throw new Exception("ID de variante requerido");

            $url = "https://backend.blife-mx.com/nutritional-information/get-by-variant-id/" . $variant_id;
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Útil en entornos locales como XAMPP
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36',
                'Referer: https://blife.mx/',
                'Cookie: connect.sid=s%3Ae5ccf23c-c139-4165-82cf-ca226e77832b.AeGgR1Hq8dlTZimdmSSWC4Cm0cggyYIzbZe0GNdFWE0; session_uid=e5ccf23c-c139-4165-82cf-ca226e77832b'
            ]);

            $response_curl = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($http_code !== 200) throw new Exception("Error consultando API externa ($http_code)");
            echo json_encode(['success' => true, 'blife_data' => json_decode($response_curl, true)]);
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
            
            if ($id > 0) {
                // EDITAR
                $sql = "UPDATE productos SET `nombre` = :nombre, `nombre_variante` = :nombre_variante, `sku` = :sku, `codigo_barras` = :codigo_barras, 
                        `descripcion` = :descripcion, `ingredientes` = :ingredientes, `modo_uso` = :modo_uso,
                        `tabla_nutrimental` = :tabla, `unidad` = :unidad, `id_padre` = :id_padre, `precio_costo` = :precio_costo, 
                        `precio_venta` = :precio_venta, `precio_comparacion` = :precio_comparacion, `estado` = :estado
                        WHERE id_producto = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':nombre' => $data['nombre'] ?? '', ':nombre_variante' => $data['nombre_variante'] ?? null,
                    ':sku' => $data['sku'] ?? null, ':codigo_barras' => $data['codigo_barras'] ?? null,
                    ':descripcion' => $data['descripcion'] ?? '', ':ingredientes' => $data['ingredientes'] ?? '',
                    ':modo_uso' => $data['modo_uso'] ?? '', ':tabla' => $data['tabla_nutrimental'] ?? '[]',
                    ':unidad' => $data['unidad'] ?? null, ':id_padre' => !empty($data['id_padre']) ? (int)$data['id_padre'] : null,
                    ':precio_costo' => $data['precio_costo'] ?? 0,
                    ':precio_venta' => $data['precio_venta'] ?? 0, ':precio_comparacion' => $data['precio_comparacion'] ?? 0,
                    ':estado' => $estado, ':id' => $id
                ]);
            } else {
                // AGREGAR
                $sql = "INSERT INTO productos (`nombre`, `nombre_variante`, `sku`, `codigo_barras`, `descripcion`, `ingredientes`, `modo_uso`, `tabla_nutrimental`, `unidad`, `id_padre`, `precio_costo`, `precio_venta`, `precio_comparacion`, `estado`) 
                        VALUES (:nombre, :nombre_variante, :sku, :codigo_barras, :descripcion, :ingredientes, :modo_uso, :tabla, :unidad, :id_padre, :precio_costo, :precio_venta, :precio_comparacion, :estado)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':nombre' => $data['nombre'] ?? '',
                    ':nombre_variante' => $data['nombre_variante'] ?? null,
                    ':sku' => $data['sku'] ?? null,
                    ':codigo_barras' => $data['codigo_barras'] ?? null,
                    ':descripcion' => $data['descripcion'] ?? '',
                    ':ingredientes' => $data['ingredientes'] ?? '',
                    ':modo_uso' => $data['modo_uso'] ?? '',
                    ':tabla' => $data['tabla_nutrimental'] ?? '[]',
                    ':unidad' => $data['unidad'] ?? null,
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
                $folderName = slugify($data['nombre'] ?? 'producto') . '-' . $id;
                $targetDir = PRODUCTS_IMG_DIR . $folderName . '/';
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
                            $uploadedPaths[] = $folderName . '/' . $fileName;
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

                            if (!file_exists($targetFile)) {
                                $ch = curl_init($url);
                                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                                $imgRaw = curl_exec($ch);
                                curl_close($ch);
                                if ($imgRaw) file_put_contents($targetFile, $imgRaw);
                            }
                            $remoteDownloaded[$url] = $dbPath;
                        }
                    }
                }

                // Reconstruir lista final basada en el orden enviado desde el cliente
                $finalPaths = [];
                if ($hasOrden) {
                    $orden = json_decode($data['imagenes_orden_json'], true);
                    foreach ($orden as $ref) {
                        if (strpos($ref, 'server:') === 0) {
                            $finalPaths[] = substr($ref, 7);
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

                // Limpiar galería actual
                $pdo->prepare("DELETE FROM producto_imagenes WHERE id_producto = ?")->execute([$id]);
                
                if (!empty($finalPaths)) {
                    // Actualizar imagen principal (index 0)
                    $pdo->prepare("UPDATE productos SET imagen = ? WHERE id_producto = ?")->execute([$finalPaths[0], $id]);
                    // Insertar resto en galería
                    for ($i = 1; $i < count($finalPaths); $i++) {
                        if ($i >= 6) break; // Límite de 6 imágenes
                        $pdo->prepare("INSERT INTO producto_imagenes (id_producto, ruta_archivo, orden) VALUES (?, ?, ?)")
                            ->execute([$id, $finalPaths[$i], $i]);
                    }
                }
            }

            dbSetProductCategories($id, $data['categorias'] ?? []);
            
            if (isAdmin()) {
                $id_alm = (int)($data['id_almacen_stock'] ?? 1);
                $stmtInv = $pdo->prepare("INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual, stock_minimo, stock_maximo) 
                                          VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE cantidad_actual = VALUES(cantidad_actual), stock_minimo = VALUES(stock_minimo), stock_maximo = VALUES(stock_maximo)");
                $stmtInv->execute([$id, $id_alm, (int)($data['cantidad_actual'] ?? 0), (int)($data['stock_minimo'] ?? 2), (int)($data['stock_maximo'] ?? 5)]);
            }

            echo json_encode(['success' => true, 'message' => 'Producto guardado con éxito']);
        } 
        elseif ($action === 'delete') {
            $id = (int)$data['id_producto'];
            $pdo->prepare("UPDATE productos SET estado = 'inactivo' WHERE id_producto = ?")->execute([$id]);
            echo json_encode(['success' => true, 'message' => 'Producto eliminado']);
        }
        elseif ($action === 'add_category') {
            if (!isAdmin()) throw new Exception("No permitido");
            $nombre = trim($data['nuevo_nombre_cat'] ?? '');
            if (dbCreateCategory($nombre)) {
                echo json_encode(['success' => true, 'message' => 'Categoría creada']);
            } else {
                throw new Exception("Error al crear categoría");
            }
        }
    }
} catch (Throwable $e) {
    http_response_code(400);
    error_log("Error en products_manager: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}