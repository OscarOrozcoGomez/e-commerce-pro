<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

$categoriaSeleccionada = $_GET['categoria'] ?? '';
$busqueda = $_GET['search'] ?? '';
$categorias = dbGetCategories();

// Lógica para obtener y filtrar productos
$pdo = getPDO();
$sql = "SELECT p.*, 
        (SELECT MIN(precio_venta) FROM productos p3 WHERE (p3.id_padre = p.id_producto OR p3.id_producto = p.id_producto OR TRIM(p3.nombre) = TRIM(p.nombre)) AND p3.estado = 'activo') as precio_desde,
        (SELECT COUNT(*) FROM productos p2 WHERE (p2.id_padre = p.id_producto OR p2.id_producto = p.id_producto OR TRIM(p2.nombre) = TRIM(p.nombre)) AND p2.estado = 'activo') as total_variantes 
        FROM productos p";
$params = [];

$whereClauses = ["p.estado = 'activo'", "p.id_padre IS NULL"];

if (!empty($categoriaSeleccionada)) {
    $sql .= " JOIN producto_categorias pc ON p.id_producto = pc.id_producto 
              JOIN categorias c ON pc.id_categoria = c.id_categoria ";
    $whereClauses[] = "c.nombre = :cat";
    $params[':cat'] = $categoriaSeleccionada;
}

if (!empty($busqueda)) {
    $whereClauses[] = "(p.nombre LIKE :search OR p.codigo_barras LIKE :search OR p.nombre_variante LIKE :search)";
    $params[':search'] = '%' . $busqueda . '%';
}

$sql .= " WHERE " . implode(" AND ", $whereClauses);
$sql .= " GROUP BY p.nombre"; // Agrupamiento fail-safe por nombre
$sql .= " ORDER BY p.nombre ASC";

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
            <!-- Barra de Búsqueda Moderna -->
            <div class="row">
                <div class="col s12">
                    <form method="GET" action="catalogo.php" class="row valign-wrapper" style="background: #fff; padding: 5px 15px; border-radius: 30px; margin-bottom: 30px; border: 1px solid #ddd; box-shadow: 0 2px 5px rgba(0,0,0,0.05);">
                        <?php if(!empty($categoriaSeleccionada)): ?>
                            <input type="hidden" name="categoria" value="<?php echo esc($categoriaSeleccionada); ?>">
                        <?php endif; ?>
                        <div class="input-field col s12" style="margin: 0; border: none;">
                            <i class="material-icons prefix blue-text text-darken-4" style="top: 11px;">search</i>
                            <input type="text" name="search" id="search-input" value="<?php echo esc($busqueda); ?>" placeholder="¿Qué estás buscando hoy?" style="border-bottom: none !important; box-shadow: none !important; margin: 0; height: 45px; padding-left: 3.5rem !important;">
                        </div>
                    </form>
                </div>
            </div>

            <h4 class="grey-text text-darken-3" style="font-weight: 300; margin-bottom: 30px;">
                <?php echo empty($categoriaSeleccionada) ? 'Explorar Catálogo' : 'Categoría: ' . esc($categoriaSeleccionada); ?>
            </h4>
            
            <div class="row row-products">
                <?php if (empty($productos)): ?>
                    <div class="col s12 center-align" style="padding: 50px;">
                        <i class="material-icons large grey-text lighten-2">inventory_2</i>
                        <p class="grey-text">No se encontraron productos disponibles en este momento.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($productos as $p): ?>
                        <div class="col s12 m6 l4 product-card-container" data-name="<?php echo esc(strtolower($p['nombre'])); ?>" data-sku="<?php echo esc(strtolower($p['sku'] ?? '')); ?>">
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
                                        <?php if ($p['total_variantes'] > 1): ?>Desde <?php endif; ?>
                                        $<?php echo number_format((float)$p['precio_desde'], 2); ?>
                                        <?php if ($p['total_variantes'] > 1): ?>
                                            <span style="font-size: 0.8rem; display: block; color: #757575;">
                                                (<?php echo $p['total_variantes']; ?> opciones)
                                            </span>
                                        <?php endif; ?>
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
// Evitar que el formulario se envíe al presionar Enter (mantiene el filtro en tiempo real sin recargar)
document.querySelector('form[action*="catalogo.php"]')?.addEventListener('submit', function(e) {
    e.preventDefault();
});

// Filtrado dinámico en tiempo real (Estilo "desaparecer poco a poco")
document.getElementById('search-input')?.addEventListener('input', function() {
    const term = this.value.toLowerCase().trim();
    const cards = document.querySelectorAll('.product-card-container');
    let foundCount = 0;

    cards.forEach(card => {
        const name = card.getAttribute('data-name') || '';
        const sku = card.getAttribute('data-sku') || '';
        const desc = card.querySelector('.card-content p.grey-text')?.textContent.toLowerCase() || '';

        // Si el término está en el nombre, SKU o descripción, lo mostramos
        if (name.includes(term) || sku.includes(term) || desc.includes(term)) {
            card.style.display = '';
            foundCount++;
        } else {
            card.style.display = 'none';
        }
    });

    // Si no hay resultados dinámicos, mostrar un mensaje amigable
    let dynamicMsg = document.getElementById('no-results-dynamic');
    if (foundCount === 0 && cards.length > 0) {
        if (!dynamicMsg) {
            dynamicMsg = document.createElement('div');
            dynamicMsg.id = 'no-results-dynamic';
            dynamicMsg.className = 'col s12 center-align grey-text';
            dynamicMsg.style.padding = '50px';
            dynamicMsg.innerHTML = '<i class="material-icons large">search_off</i><p>No hay coincidencias en esta página.</p>';
            document.querySelector('.row-products').appendChild(dynamicMsg);
        }
    } else if (dynamicMsg) {
        dynamicMsg.remove();
    }
});

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