<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

$categoriaSeleccionada = $_GET['categoria'] ?? '';
$categorias = dbGetCategories();

// Lógica para obtener y filtrar productos
$pdo = getPDO();
$sql = "SELECT * FROM productos WHERE estado = 'activo'";
$params = [];
if (!empty($categoriaSeleccionada)) {
    $sql .= " AND categoria = :cat";
    $params[':cat'] = $categoriaSeleccionada;
}
$sql .= " ORDER BY nombre ASC";

try {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $productos = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error al cargar catálogo: " . $e->getMessage());
    $productos = [];
}

$pageTitle = 'Catálogo de Productos';
include __DIR__ . '/includes/header.php';
?>

<div class="container" style="margin-top: 30px;">
    <div class="row">
        <!-- Sidebar de Categorización (Lado Izquierdo) -->
        <div class="col s12 m3">
            <div class="card-panel z-depth-1" style="padding: 10px; border-radius: 8px;">
                <h6 class="blue-text text-darken-4" style="padding-left: 15px; margin-bottom: 20px; font-weight: bold;">
                    <i class="material-icons left">filter_list</i> Categorías
                </h6>
                <div class="collection borderless" style="border: none;">
                    <a href="catalogo.php" class="collection-item <?php echo empty($categoriaSeleccionada) ? 'active blue darken-4' : 'grey-text text-darken-3'; ?>" style="border-radius: 4px; margin-bottom: 5px;">
                        Todas las categorías
                    </a>
                    <?php if (empty($categorias)): ?>
                        <p class="grey-text center-align" style="font-size: 0.9rem; padding: 10px;">No se encontraron categorías.</p>
                    <?php else: ?>
                        <?php foreach ($categorias as $cat): ?>
                            <a href="catalogo.php?categoria=<?php echo urlencode($cat); ?>" 
                               class="collection-item <?php echo $categoriaSeleccionada === $cat ? 'active blue darken-4' : 'grey-text text-darken-3'; ?>"
                               style="border-radius: 4px; margin-bottom: 5px;">
                                <?php echo esc($cat); ?>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Contenido Principal: Listado de Productos -->
        <div class="col s12 m9">
            <h4 class="grey-text text-darken-3" style="font-weight: 300; margin-bottom: 30px;">
                <?php echo empty($categoriaSeleccionada) ? 'Explorar Catálogo' : 'Categoría: ' . esc($categoriaSeleccionada); ?>
            </h4>
            
            <div class="row">
                <?php if (empty($productos)): ?>
                    <div class="col s12 center-align" style="padding: 50px;">
                        <i class="material-icons large grey-text lighten-2">inventory_2</i>
                        <p class="grey-text">No hay productos disponibles en esta sección.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($productos as $p): ?>
                        <div class="col s12 m6 l4">
                            <div class="card hoverable" style="height: 420px; display: flex; flex-direction: column; border-radius: 8px; overflow: hidden;">
                                <div class="card-image" style="height: 200px; background: #f9f9f9; display: flex; align-items: center; justify-content: center;">
                                    <?php if (!empty($p['imagen'])): 
                                        $mime = (strpos($p['imagen'], 'iVBORw') === 0) ? 'image/png' : 'image/jpeg';
                                    ?>
                                        <img src="data:<?php echo $mime; ?>;base64,<?php echo $p['imagen']; ?>" style="max-height: 100%; width: auto; object-fit: contain;">
                                    <?php else: ?>
                                        <i class="material-icons grey-text" style="font-size: 5rem;">broken_image</i>
                                    <?php endif; ?>
                                </div>
                                <div class="card-content" style="flex-grow: 1;">
                                    <span class="card-title grey-text text-darken-4 truncate" style="font-size: 1rem; font-weight: bold;">
                                        <?php echo esc($p['nombre']); ?>
                                    </span>
                                    <p class="blue-text text-darken-4" style="font-size: 1.3rem; margin: 10px 0;">
                                        $<?php echo number_format((float)$p['precio_venta'], 2); ?>
                                    </p>
                                    <p class="grey-text truncate-3-lines" style="font-size: 0.85rem;">
                                        <?php echo esc($p['descripcion'] ?? 'Sin descripción disponible.'); ?>
                                    </p>
                                </div>
                                <div class="card-action center-align" style="border-top: 1px solid #eee; background: white;">
                                    <a href="#" class="btn-flat blue-text text-darken-4 waves-effect">VER</a>
                                    <a href="#" class="btn blue darken-4 waves-effect waves-light"><i class="material-icons">add_shopping_cart</i></a>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<style>
    .collection.borderless .collection-item { border: none; }
    .truncate-3-lines {
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;  
        overflow: hidden;
    }
</style>
<?php include __DIR__ . '/includes/footer.php'; ?>