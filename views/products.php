<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

// Validar permisos
requireAuth();
requirePermission('gestionar_productos', BASE_URL . 'views/dashboard.php');

$pageTitle = 'Gestionar Productos';
$pdo = getPDO();
$usuario = $_SESSION['usuario'];
$error = '';
$success = '';

// Procesar formulario de agregar/editar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF inválido. Por favor recarga la página e inténtalo de nuevo.';
    } else {
        $accion = sanitize($_POST['accion']);
        
        if ($accion === 'agregar') {
            try {
                $sql = "INSERT INTO productos (id_padre, nombre, nombre_variante, sku, codigo_barras, descripcion, unidad, precio_costo, precio_venta, categoria, estado) 
                        VALUES (:id_padre, :nombre, :nombre_variante, :sku, :codigo_barras, :descripcion, :unidad, :precio_costo, :precio_venta, :categoria, 'activo')";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':id_padre' => !empty($_POST['id_padre']) ? intval($_POST['id_padre']) : null,
                    ':nombre' => sanitize($_POST['nombre'] ?? ''),
                    ':nombre_variante' => sanitize($_POST['nombre_variante'] ?? ''),
                    ':sku' => sanitize($_POST['sku'] ?? ''),
                    ':codigo_barras' => sanitize($_POST['codigo_barras'] ?? ''),
                    ':descripcion' => sanitize($_POST['descripcion'] ?? ''),
                    ':unidad' => sanitize($_POST['unidad'] ?? ''),
                    ':precio_costo' => floatval($_POST['precio_costo'] ?? 0),
                    ':precio_venta' => floatval($_POST['precio_venta'] ?? 0),
                    ':categoria' => sanitize($_POST['categoria'] ?? ''),
                ]);
                $success = 'Producto agregado correctamente.';
            } catch (PDOException $e) {
                $error = 'Error al agregar producto: ' . $e->getMessage();
            }
        } elseif ($accion === 'eliminar') {
            try {
                $id = intval($_POST['id_producto']);
                $stmt = $pdo->prepare("UPDATE productos SET estado = 'inactivo' WHERE id_producto = ?");
                $stmt->execute([$id]);
                logAudit('PRODUCTO_DESACTIVADO', 'productos', $id, "Producto marcado como inactivo");
                $success = 'Producto eliminado (desactivado) correctamente.';
            } catch (PDOException $e) {
                $error = 'Error al eliminar producto.';
            }
        }
    }
}

// Obtener productos
try {
    $sql = "SELECT p.*, (SELECT nombre FROM productos p2 WHERE p2.id_producto = p.id_padre) as producto_base 
            FROM productos p WHERE estado = 'activo' ORDER BY COALESCE(p.id_padre, p.id_producto), p.id_padre IS NOT NULL, p.nombre";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $productos = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = 'Error al obtener productos: ' . $e->getMessage();
    $productos = [];
}

function sanitize(mixed $value): string {
    if (!is_string($value)) {
        return '';
    }
    return trim(htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
}

include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="col s12">
            <h4>Gestionar Productos</h4>
            
            <?php if ($error): ?>
                <div class="card red lighten-2">
                    <div class="card-content white-text">
                        <span class="card-title">Error</span>
                        <p><?php echo esc($error); ?></p>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="card green lighten-2">
                    <div class="card-content white-text">
                        <p><?php echo esc($success); ?></p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row">
        <div class="col s12 m6">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Agregar Nuevo Producto</span>
                    <form method="POST">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="accion" value="agregar">
                        
                        <div class="input-field">
                            <select name="id_padre" id="select_padre" onchange="toggleNombreParent(this)">
                                <option value="">-- Es un producto nuevo/base --</option>
                                <?php foreach ($productos as $p): if(!$p['id_padre']): ?>
                                    <option value="<?php echo $p['id_producto']; ?>"><?php echo esc($p['nombre']); ?></option>
                                <?php endif; endforeach; ?>
                            </select>
                            <label>Variante de (Opcional)</label>
                        </div>

                        <div class="input-field">
                            <input type="text" id="nombre" name="nombre" required>
                            <label for="nombre">Nombre del Producto</label>
                        </div>

                        <div class="input-field">
                            <input type="text" id="nombre_variante" name="nombre_variante">
                            <label for="nombre_variante">Presentación (Ej: 60 servicios, 1kg)</label>
                        </div>
                        
                        <div class="input-field">
                            <input type="text" id="sku" name="sku" required>
                            <label for="sku">SKU</label>
                        </div>
                        
                        <div class="input-field">
                            <input type="text" id="codigo_barras" name="codigo_barras">
                            <label for="codigo_barras">Código de Barras</label>
                        </div>
                        
                        <div class="input-field">
                            <textarea id="descripcion" name="descripcion" class="materialize-textarea"></textarea>
                            <label for="descripcion">Descripción</label>
                        </div>
                        
                        <div class="input-field">
                            <input type="text" id="unidad" name="unidad">
                            <label for="unidad">Unidad de Medida</label>
                        </div>
                        
                        <div class="input-field">
                            <input type="number" id="precio_costo" name="precio_costo" step="0.01" required>
                            <label for="precio_costo">Precio de Costo</label>
                        </div>
                        
                        <div class="input-field">
                            <input type="number" id="precio_venta" name="precio_venta" step="0.01" required>
                            <label for="precio_venta">Precio de Venta</label>
                        </div>
                        
                        <div class="input-field">
                            <input type="text" id="categoria" name="categoria">
                            <label for="categoria">Categoría</label>
                        </div>
                        
                        <button type="submit" class="btn waves-effect waves-light green">
                            Agregar Producto <i class="material-icons right">add</i>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col s12 m6">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Listado de Productos</span>
                    <div style="overflow-x: auto;">
                        <table class="striped">
                            <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th>SKU</th>
                                    <th>Precio</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($productos as $prod): ?>
                                    <tr>
                                        <td><?php echo esc($prod['nombre']); ?> <?php echo $prod['nombre_variante'] ? '<small class="grey-text">('.esc($prod['nombre_variante']).')</small>' : ''; ?></td>
                                        <td><?php echo esc($prod['sku']); ?></td>
                                        <td>$<?php echo number_format((float)$prod['precio_venta'], 2); ?></td>
                                        <td>
                                            <form method="POST" style="display:inline;">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="accion" value="eliminar">
                                                <input type="hidden" name="id_producto" value="<?php echo $prod['id_producto']; ?>">
                                                <button type="submit" class="btn-floating btn-small red" onclick="return confirm('¿Desactivar producto?')">
                                                    <i class="material-icons">delete</i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
