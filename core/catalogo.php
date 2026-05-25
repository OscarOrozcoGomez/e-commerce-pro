<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$categoriaSeleccionada = $_GET['categoria'] ?? '';
$categorias = dbGetCategories();

// Lógica para obtener y filtrar productos
$pdo = getPDO();
$sql = "SELECT p.* FROM productos p ";
$params = [];
if (!empty($categoriaSeleccionada)) {
    $sql .= " JOIN producto_categorias pc ON p.id_producto = pc.id_producto 
              JOIN categorias c ON pc.id_categoria = c.id_categoria 
              WHERE c.nombre = :cat AND p.estado = 'activo'";
    $params[':cat'] = $categoriaSeleccionada;
} else {
    $sql .= " WHERE p.estado = 'activo'";
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

try {
    $stmtBlogs = $pdo->prepare("SELECT * FROM blogs WHERE estado = 'publicado' ORDER BY fecha_creacion DESC LIMIT 5");
    $stmtBlogs->execute();
    $blogsRecientes = $stmtBlogs->fetchAll();
} catch (PDOException $e) {
    error_log("Error al cargar blogs: " . $e->getMessage());
    $blogsRecientes = [];
}


$pageTitle = 'Catálogo de Productos';
include __DIR__ . '/../views/includes/header.php';
?>

<div class="container" style="margin-top: 30px;">
    <div class="row">
        <!-- Sidebar de Categorización (Lado Izquierdo) -->
        <div class="col s12 m3">
            <div class="card-panel z-depth-1" style="padding: 10px; border-radius: 8px; position: sticky; top: 20px;">
                <h6 class="blue-text text-darken-4" style="padding-left: 15px; margin-bottom: 20px; font-weight: bold;">
                    <i class="material-icons left">filter_list</i> Categorías
                </h6>
                <div class="collection borderless" style="border: none;">
                    <a href="catalogo.php" class="collection-item <?php echo empty($categoriaSeleccionada) ? 'active blue darken-4' : 'grey-text text-darken-3'; ?>" style="border-radius: 4px; margin-bottom: 5px; padding: 12px 15px;">
                        Todas las categorías
                    </a>
                    <?php if (empty($categorias)): ?>
                        <p class="grey-text center-align" style="font-size: 0.9rem; padding: 10px;">No hay categorías configuradas.</p>
                    <?php else: ?>
                        <?php foreach ($categorias as $cat): ?>
                            <a href="catalogo.php?categoria=<?php echo urlencode($cat['nombre']); ?>" 
                               class="collection-item <?php echo $categoriaSeleccionada === $cat['nombre'] ? 'active blue darken-4' : 'grey-text text-darken-3'; ?>"
                               style="border-radius: 4px; margin-bottom: 5px;">
                                <?php echo esc($cat['nombre']); ?>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Sección de Blogs -->
                <h6 class="blue-text text-darken-4" style="padding-left: 15px; margin-top: 30px; margin-bottom: 20px; font-weight: bold;">
                    <i class="material-icons left">article</i> Información de Interés
                </h6>
                <div class="collection borderless" style="border: none;">
                    <?php if (empty($blogsRecientes)): ?>
                        <p class="grey-text center-align" style="font-size: 0.9rem; padding: 10px;">No hay artículos disponibles.</p>
                    <?php else: ?>
                        <?php foreach ($blogsRecientes as $blog): ?>
                            <a href="../views/blog_detail.php?id=<?php echo $blog['id_blog']; ?>" 
                               class="collection-item grey-text text-darken-3 hoverable-item"
                               style="border-radius: 4px; margin-bottom: 5px;">
                                <div style="font-weight: bold; font-size: 0.95rem; line-height: 1.2; margin-bottom: 5px;">
                                    <?php echo esc($blog['titulo']); ?>
                                </div>
                                <div class="grey-text" style="font-size: 0.8rem;">
                                    <?php echo date('d M Y', strtotime($blog['fecha_creacion'])); ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Contenido Principal: Listado de Productos -->
        <div class="col s12 m9">
            <h4 class="header-title grey-text text-darken-3" style="font-weight: 300; margin-bottom: 30px;">
                <?php echo empty($categoriaSeleccionada) ? 'Nuestros Productos' : esc($categoriaSeleccionada); ?>
            </h4>
            
            <div class="row">
                <?php if (empty($productos)): ?>
                    <div class="col s12 center-align" style="padding: 50px;">
                        <i class="material-icons large grey-text lighten-2">inventory_2</i>
                        <p class="grey-text">No se encontraron productos disponibles en este momento.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($productos as $p): ?>
                        <div class="col s12 m6 l4">
                            <div class="card hoverable border-radius-8" style="height: 420px; display: flex; flex-direction: column;">
                                <div class="card-image waves-effect waves-block waves-light" style="height: 200px; background: #f9f9f9; display: flex; align-items: center; justify-content: center;">
                                    <?php $imgSrc = getProductImageUrl($p['imagen']); ?>
                                    <img src="<?php echo $imgSrc; ?>" loading="lazy" style="max-height: 100%; width: auto; object-fit: contain;">
                                </div>
                                <div class="card-content" style="flex-grow: 1;">
                                    <span class="card-title grey-text text-darken-4 truncate" style="font-size: 1rem; font-weight: bold;" title="<?php echo esc($p['nombre']); ?>">
                                        <a href="<?php echo BASE_URL; ?>product_detail.php?id=<?php echo $p['id_producto']; ?>" class="grey-text text-darken-4">
                                        <?php echo esc($p['nombre']); ?>
                                        </a>
                                    </span>
                                    <p class="blue-text text-darken-4" style="font-size: 1.3rem; margin: 10px 0;">
                                        $<?php echo number_format((float)$p['precio_venta'], 2); ?>
                                    </p>
                                    <p class="grey-text truncate-3-lines" style="font-size: 0.9rem;">
                                        <?php echo esc($p['descripcion'] ?? 'Sin descripción disponible.'); ?>
                                    </p>
                                </div>
                                <div class="card-action center-align" style="border-top: 1px solid #eee;">
                                    <a href="<?php echo BASE_URL; ?>product_detail.php?id=<?php echo $p['id_producto']; ?>" class="btn-flat blue-text text-darken-4 waves-effect">DETALLES</a>
                                    <button class="btn blue darken-4 waves-effect waves-light" 
                                            onclick="addToCart(
                                                <?php echo (int)$p['id_producto']; ?>, 
                                                '<?php echo addslashes(esc($p['nombre'])); ?>', 
                                                <?php echo (float)$p['precio_venta']; ?>
                                            )">
                                        <i class="material-icons">add_shopping_cart</i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function addToCart(id, nombre, precio) {
    let cart = JSON.parse(localStorage.getItem('cart') || '[]');
    
    // Buscar si el producto ya está en el carrito
    let item = cart.find(i => i.id_producto === id);
    
    if (item) {
        item.quantity += 1;
    } else {
        cart.push({
            id_producto: id,
            nombre: nombre,
            precio: precio,
            quantity: 1
        });
    }
    
    localStorage.setItem('cart', JSON.stringify(cart));
    M.toast({html: 'Producto añadido al carrito', classes: 'green rounded'});
    
    if (typeof updateCartBadge === 'function') {
        updateCartBadge();
    }
}
</script>

<style>
    .border-radius-8 { border-radius: 8px; overflow: hidden; }
    .collection.borderless .collection-item { border: none; }
    .truncate-3-lines { display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
    .hoverable-item { transition: background-color 0.2s ease; }
    .hoverable-item:hover { background-color: #f5f5f5 !important; }
</style>

<?php include __DIR__ . '/../views/includes/footer.php'; ?>