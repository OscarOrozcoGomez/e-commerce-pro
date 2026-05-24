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
            $sql = "SELECT p.*, GROUP_CONCAT(pc.id_categoria) as categorias_ids, ia.stock_minimo, ia.stock_maximo, ia.cantidad_actual 
                    FROM productos p 
                    LEFT JOIN producto_categorias pc ON p.id_producto = pc.id_producto
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
                'categorias' => dbGetCategories()
            ]);
        }
    } 
    elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $data = $_POST;
        if (!validateCsrfToken($data['csrf_token'] ?? '')) {
            throw new Exception("Token de seguridad inválido.");
        }

        if ($action === 'save') {
            $id = (int)($data['id_producto'] ?? 0);
            $imagenesCargadas = [];
            if (isset($_FILES['imagenes']) && !empty($_FILES['imagenes']['name'][0])) {
                foreach ($_FILES['imagenes']['tmp_name'] as $tmpName) {
                    if (!empty($tmpName)) $imagenesCargadas[] = base64_encode(file_get_contents($tmpName));
                }
            }

            $estado = ($data['visible_catalogo'] ?? '0') === '1' ? 'activo' : 'archivado';
            
            if ($id > 0) {
                // EDITAR
                $updateImagenSql = !empty($imagenesCargadas) ? ", imagen = :imagen" : "";
                $sql = "UPDATE productos SET nombre = :nombre, sku = :sku, codigo_barras = :codigo_barras, 
                        descripcion = :descripcion, unidad = :unidad, precio_costo = :precio_costo, 
                        precio_venta = :precio_venta, precio_comparacion = :precio_comparacion, estado = :estado $updateImagenSql 
                        WHERE id_producto = :id";
                $stmt = $pdo->prepare($sql);
                $params = [
                    ':nombre' => $data['nombre'], ':sku' => $data['sku'], ':codigo_barras' => $data['codigo_barras'],
                    ':descripcion' => $data['descripcion'], ':unidad' => $data['unidad'], ':precio_costo' => $data['precio_costo'],
                    ':precio_venta' => $data['precio_venta'], ':precio_comparacion' => $data['precio_comparacion'],
                    ':estado' => $estado, ':id' => $id
                ];
                if (!empty($imagenesCargadas)) $params[':imagen'] = $imagenesCargadas[0];
                $stmt->execute($params);
            } else {
                // AGREGAR
                $sql = "INSERT INTO productos (nombre, sku, codigo_barras, descripcion, unidad, precio_costo, precio_venta, precio_comparacion, imagen, estado) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $pdo->prepare($sql)->execute([
                    $data['nombre'], $data['sku'], $data['codigo_barras'], $data['descripcion'], $data['unidad'],
                    $data['precio_costo'], $data['precio_venta'], $data['precio_comparacion'], $imagenesCargadas[0] ?? null, $estado
                ]);
                $id = (int)$pdo->lastInsertId();
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
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}