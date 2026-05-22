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

// Procesar creación de categorías (Solo Admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion_categoria']) && isAdmin()) {
    if (dbCreateCategory($_POST['nuevo_nombre_cat'] ?? '')) {
        $success = 'Categoría creada con éxito.';
    } else {
        $error = 'No se pudo crear la categoría.';
    }
}

// Procesar formulario de agregar/editar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF inválido. Por favor recarga la página e inténtalo de nuevo.';
    } else {
        $accion = sanitize($_POST['accion']);
        
        if ($accion === 'agregar') {
            try {
                $sql = "INSERT INTO productos (nombre, sku, codigo_barras, descripcion, unidad, precio_costo, precio_venta, estado) 
                        VALUES (:nombre, :sku, :codigo_barras, :descripcion, :unidad, :precio_costo, :precio_venta, 'activo')";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':nombre' => sanitize($_POST['nombre'] ?? ''),
                    ':sku' => sanitize($_POST['sku'] ?? ''),
                    ':codigo_barras' => sanitize($_POST['codigo_barras'] ?? ''),
                    ':descripcion' => sanitize($_POST['descripcion'] ?? ''),
                    ':unidad' => sanitize($_POST['unidad'] ?? ''),
                    ':precio_costo' => floatval($_POST['precio_costo'] ?? 0),
                    ':precio_venta' => floatval($_POST['precio_venta'] ?? 0),
                ]);
                
                $id_nuevo = (int)$pdo->lastInsertId();
                dbSetProductCategories($id_nuevo, $_POST['categorias'] ?? []);
                
                $success = 'Producto agregado correctamente.';
            } catch (PDOException $e) {
                $error = 'Error al agregar producto: ' . $e->getMessage();
            }
        } elseif ($accion === 'editar') {
            try {
                $id = intval($_POST['id_producto']);
                $sql = "UPDATE productos SET nombre = :nombre, sku = :sku, codigo_barras = :codigo_barras, 
                        descripcion = :descripcion, unidad = :unidad, precio_costo = :precio_costo, 
                        precio_venta = :precio_venta WHERE id_producto = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':nombre' => sanitize($_POST['nombre'] ?? ''),
                    ':sku' => sanitize($_POST['sku'] ?? ''),
                    ':codigo_barras' => sanitize($_POST['codigo_barras'] ?? ''),
                    ':descripcion' => sanitize($_POST['descripcion'] ?? ''),
                    ':unidad' => sanitize($_POST['unidad'] ?? ''),
                    ':precio_costo' => floatval($_POST['precio_costo'] ?? 0),
                    ':precio_venta' => floatval($_POST['precio_venta'] ?? 0),
                    ':id' => $id
                ]);
                
                dbSetProductCategories($id, $_POST['categorias'] ?? []);
                $success = 'Producto actualizado correctamente.';
            } catch (PDOException $e) {
                $error = 'Error al actualizar producto: ' . $e->getMessage();
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
    $sql = "SELECT p.*, GROUP_CONCAT(pc.id_categoria) as categorias_ids 
            FROM productos p 
            LEFT JOIN producto_categorias pc ON p.id_producto = pc.id_producto
            WHERE p.estado = 'activo' 
            GROUP BY p.id_producto
            ORDER BY p.nombre";
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

$categoriasDisponibles = dbGetCategories();
include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="col s12">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 20px; flex-wrap: wrap;">
                <h4 style="margin: 0;">Gestionar Productos</h4>
                <a href="dashboard.php" class="btn blue darken-4 waves-effect waves-light"><i class="material-icons left">dashboard</i> Volver al Dashboard</a>
            </div>
            
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

    <?php if (isAdmin()): ?>
    <!-- Gestión de Categorías (Solo Admin) -->
    <div class="row">
        <div class="col s12">
            <div class="card-panel blue lighten-5">
                <form method="POST" class="row" style="margin-bottom: 0; display: flex; align-items: center;">
                    <div class="input-field col s12 m8">
                        <i class="material-icons prefix">label</i>
                        <input type="text" id="nuevo_nombre_cat" name="nuevo_nombre_cat" required>
                        <label for="nuevo_nombre_cat">Nueva Categoría Maestra</label>
                    </div>
                    <div class="col s12 m4">
                        <button type="submit" name="accion_categoria" class="btn blue darken-4">CREAR CATEGORÍA</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row">
        <div class="col s12 m6">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Agregar Nuevo Producto</span>
                    <form method="POST" id="form-producto">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="accion" id="accion" value="agregar">
                        <input type="hidden" name="id_producto" id="id_producto" value="">
                        
                        <div class="input-field">
                            <input type="text" id="nombre" name="nombre" required>
                            <label for="nombre">Nombre del Producto</label>
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
                            <select name="categorias[]" multiple>
                                <option value="" disabled>Selecciona una o varias categorías</option>
                                <?php foreach ($categoriasDisponibles as $cat): ?>
                                    <option value="<?php echo $cat['id_categoria']; ?>"><?php echo esc($cat['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label>Asignar Categorías</label>
                        </div>
                        
                        <button type="submit" id="btn-submit" class="btn waves-effect waves-light green">
                            Agregar Producto <i class="material-icons right">add</i>
                        </button>
                        <button type="button" id="btn-cancel" class="btn waves-effect waves-light grey" style="display:none;" onclick="cancelarEdicion()">
                            Cancelar
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col s12 m6">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Listado de Productos</span>
                    <div class="input-field">
                        <i class="material-icons prefix">search</i>
                        <input type="text" id="buscar_producto" placeholder="Buscar por nombre o SKU...">
                    </div>
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
                                        <td>
                                            <?php if ($prod['imagen']): 
                                                $mime = 'image/png';
                                                if (strpos($prod['imagen'], 'UklGR') === 0) $mime = 'image/webp';
                                                elseif (strpos($prod['imagen'], '/9j/') === 0) $mime = 'image/jpeg';
                                                elseif (strpos($prod['imagen'], 'iVBORw') === 0) $mime = 'image/png';
                                                elseif (strpos($prod['imagen'], 'R0lGOD') === 0) $mime = 'image/gif';
                                            ?>
                                                <img src="data:<?php echo $mime; ?>;base64,<?php echo $prod['imagen']; ?>" style="width: 60px; height: 60px; object-fit: contain; background: #f5f5f5;" class="circle shadow-1">
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo esc($prod['nombre']); ?></td>
                                        <td><?php echo esc($prod['sku']); ?></td>
                                        <td>$<?php echo number_format((float)$prod['precio_venta'], 2); ?></td>
                                        <td>
                                            <button type="button" class="btn-floating btn-small blue waves-effect waves-light" 
                                                    onclick='abrirEditar(<?php echo json_encode($prod); ?>)'>
                                                <i class="material-icons">edit</i>
                                            </button>
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

<script>
    function abrirEditar(prod) {
        document.getElementById('accion').value = 'editar';
        document.getElementById('id_producto').value = prod.id_producto;
        
        document.getElementById('nombre').value = prod.nombre;
        document.getElementById('sku').value = prod.sku;
        document.getElementById('codigo_barras').value = prod.codigo_barras || '';
        document.getElementById('descripcion').value = prod.descripcion || '';
        document.getElementById('unidad').value = prod.unidad || '';
        document.getElementById('precio_costo').value = prod.precio_costo;
        document.getElementById('precio_venta').value = prod.precio_venta;
        
        // Manejo de select múltiple
        const selectCats = document.querySelector('select[name="categorias[]"]');
        for (let i = 0; i < selectCats.options.length; i++) {
            selectCats.options[i].selected = false;
        }
        if (prod.categorias_ids) {
            const catIds = prod.categorias_ids.split(',');
            for (let i = 0; i < selectCats.options.length; i++) {
                if (catIds.includes(selectCats.options[i].value)) {
                    selectCats.options[i].selected = true;
                }
            }
        }
        
        M.updateTextFields();
        M.FormSelect.init(selectCats);
        M.textareaAutoResize(document.getElementById('descripcion'));
        
        const btnSubmit = document.getElementById('btn-submit');
        btnSubmit.innerHTML = 'Guardar Cambios <i class="material-icons right">save</i>';
        btnSubmit.classList.remove('green');
        btnSubmit.classList.add('blue');
        
        document.getElementById('btn-cancel').style.display = 'inline-block';
        
        window.scrollTo({top: 0, behavior: 'smooth'});
    }
    
    function cancelarEdicion() {
        document.getElementById('form-producto').reset();
        document.getElementById('accion').value = 'agregar';
        document.getElementById('id_producto').value = '';
        
        const selectCats = document.querySelector('select[name="categorias[]"]');
        for (let i = 0; i < selectCats.options.length; i++) {
            selectCats.options[i].selected = false;
        }
        
        M.updateTextFields();
        M.FormSelect.init(selectCats);
        M.textareaAutoResize(document.getElementById('descripcion'));
        
        const btnSubmit = document.getElementById('btn-submit');
        btnSubmit.innerHTML = 'Agregar Producto <i class="material-icons right">add</i>';
        btnSubmit.classList.remove('blue');
        btnSubmit.classList.add('green');
        
        document.getElementById('btn-cancel').style.display = 'none';
    }
    
    document.getElementById('buscar_producto').addEventListener('keyup', function() {
        const filter = this.value.toLowerCase();
        const rows = document.querySelectorAll('table.striped tbody tr');
        
        rows.forEach(row => {
            const nombre = row.cells[1] ? row.cells[1].textContent.toLowerCase() : '';
            const sku = row.cells[2] ? row.cells[2].textContent.toLowerCase() : '';
            if (nombre.includes(filter) || sku.includes(filter)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    });

    document.addEventListener('DOMContentLoaded', function() {
        <?php if ($success): ?>
            M.toast({html: '<?php echo esc($success); ?>', classes: 'green darken-1 rounded', displayLength: 4000});
        <?php endif; ?>
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
