<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

$categoriaSeleccionada = $_GET['categoria'] ?? '';
$busqueda = $_GET['search'] ?? '';
$categorias = dbGetCategories();

// Lógica para obtener y filtrar productos
$pdo = getPDO();
$sql = "SELECT p.*, 
        (SELECT MIN(precio_venta) FROM productos p3 WHERE (p3.id_padre = p.id_producto OR p3.id_producto = p.id_producto OR TRIM(p3.nombre) = TRIM(p.nombre)) AND p3.estado = 'activo') as precio_desde,
        (SELECT COUNT(*) FROM productos p2 WHERE (p2.id_padre = p.id_producto OR p2.id_producto = p.id_producto OR TRIM(p2.nombre) = TRIM(p.nombre)) AND p2.estado = 'activo') as total_variantes 
        FROM productos p ";
$params = [];
$whereClauses = ["p.estado = 'activo'", "p.id_padre IS NULL"];

if (!empty($categoriaSeleccionada)) {
    $sql .= " JOIN producto_categorias pc ON p.id_producto = pc.id_producto 
              JOIN categorias c ON pc.id_categoria = c.id_categoria ";
    $whereClauses[] = "c.nombre = :cat";
    $params[':cat'] = $categoriaSeleccionada;
}

if (!empty($busqueda)) {
    $whereClauses[] = "(p.nombre LIKE :search OR p.sku LIKE :search OR p.descripcion LIKE :search)";
    $params[':search'] = '%' . $busqueda . '%';
}

$sql .= " WHERE " . implode(" AND ", $whereClauses);
$sql .= " GROUP BY p.nombre"; // Agrupamiento fail-safe por nombre para evitar duplicados visuales
$sql .= " ORDER BY p.nombre ASC";

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
                    <a href="<?php echo BASE_URL; ?>views/catalogo.php" class="collection-item <?php echo empty($categoriaSeleccionada) ? 'active blue darken-4' : 'grey-text text-darken-3'; ?>" style="border-radius: 4px; margin-bottom: 5px;">
                        Todas las categorías
                    </a>
                    <?php if (empty($categorias)): ?>
                        <p class="grey-text center-align" style="font-size: 0.9rem; padding: 10px;">No se encontraron categorías.</p>
                    <?php else: ?>
                        <?php foreach ($categorias as $cat): ?>
                            <a href="<?php echo BASE_URL; ?>views/catalogo.php?categoria=<?php echo urlencode($cat['nombre']); ?>" 
                               class="collection-item <?php echo $categoriaSeleccionada === $cat['nombre'] ? 'active blue darken-4' : 'grey-text text-darken-3'; ?>"
                               style="border-radius: 4px; margin-bottom: 5px;">
                                <?php echo esc($cat['nombre']); ?>
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
                        <div class="input-field col s10 m11" style="margin: 0; border: none;">
                            <i class="material-icons prefix blue-text text-darken-4" style="top: 10px;">search</i>
                            <input type="text" name="search" id="search-input" value="<?php echo esc($busqueda); ?>" placeholder="¿Qué estás buscando hoy?" style="border-bottom: none !important; box-shadow: none !important; margin: 0; height: 45px;">
                        </div>
                        <div class="col s2 m1 center-align">
                            <button type="submit" class="btn-flat waves-effect waves-circle" style="padding: 0; width: 40px; height: 40px; line-height: 40px;">
                                <i class="material-icons blue-text text-darken-4">send</i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

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
                                    <?php $imgSrc = getProductImageUrl($p['imagen']); ?>
                                    <img src="<?php echo $imgSrc; ?>" style="max-height: 180px; width: auto; object-fit: contain;">
                                </div>
                                <div class="card-content" style="flex-grow: 1; padding: 15px;">
                                    <span class="card-title truncate" style="font-size: 1.1rem; font-weight: bold; margin-bottom: 5px;">
                                        <?php echo esc($p['nombre']); ?>
                                    </span>
                                    <p class="blue-text text-darken-4" style="font-size: 1.3rem; margin: 10px 0;">
                                        <?php if ($p['total_variantes'] > 1): ?>
                                            <span style="font-size: 0.8rem; color: #757575; display: block;">Desde</span>
                                        <?php endif; ?>
                                        $<?php echo number_format((float)($p['precio_desde'] ?? $p['precio_venta']), 2); ?>
                                        
                                        <?php if ($p['total_variantes'] == 1 && (float)($p['precio_comparacion'] ?? 0) > 0): ?>
                                            <span class="grey-text" style="text-decoration: line-through; font-size: 0.9rem; margin-left: 8px;">
                                                $<?php echo number_format((float)$p['precio_comparacion'], 2); ?>
                                            </span>
                                        <?php endif; ?>
                                    </p>
                                    <?php if ($p['total_variantes'] > 1): ?>
                                        <p class="orange-text text-darken-3" style="font-size: 0.8rem; font-weight: bold;">
                                            <?php echo $p['total_variantes']; ?> presentaciones disponibles
                                        </p>
                                    <?php endif; ?>
                                    <p class="grey-text truncate-3-lines" style="font-size: 0.85rem;">
                                        <?php echo esc($p['descripcion'] ?? 'Sin descripción disponible.'); ?>
                                    </p>
                                </div>
                                <div class="card-action center-align" style="border-top: 1px solid #eee; background: white;">
                                    <a href="<?php echo BASE_URL; ?>product_detail.php?id=<?php echo $p['id_producto']; ?>" class="btn-flat blue-text text-darken-4 waves-effect">VER</a>
                                    <button class="btn blue darken-4 waves-effect waves-light" 
                                            data-img="<?php echo esc($imgSrc); ?>"
                                            onclick="addToCart(
                                                <?php echo (int)$p['id_producto']; ?>, 
                                                '<?php echo addslashes(esc($p['nombre'])); ?>', 
                                                <?php echo (float)$p['precio_venta']; ?>,
                                                this.dataset.img
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

<div id="mini-cart-preview" class="mini-cart-floating z-depth-3" style="display: none;">
    <div class="mini-cart-header indigo darken-4 white-text">
        <i class="material-icons left">shopping_basket</i> Tu Carrito
        <span id="mini-cart-count" class="badge white blue-text text-darken-4" style="float:right; border-radius:50%; font-weight:bold; margin-top:3px;">0</span>
    </div>
    <div id="mini-cart-list" class="mini-cart-items">
    </div>
    <div class="mini-cart-footer center-align">
        <div id="mini-cart-total" style="padding: 10px; font-weight: bold; border-top: 1px solid #eee;">Total: $0.00</div>
        <a href="cart.php" class="btn green darken-2 waves-effect waves-light btn-small" style="width: 100%;">
            CONFIRMAR PEDIDO
        </a>
    </div>
</div>

<script>
// Evitar que el formulario recargue la página al presionar Enter
document.querySelector('form[action*="catalogo.php"]')?.addEventListener('submit', function(e) {
    e.preventDefault();
});

function addToCart(id, nombre, precio, imagen = '') {
    let cart = getCart();
    // Usamos comparación de Strings para evitar fallos de tipo
    let item = cart.find(i => String(i.id_producto) === String(id));
    
    if (item) {
        item.quantity = (parseInt(item.quantity) || 0) + 1;
    } else {
        cart.push({
            id_producto: String(id),
            nombre: nombre,
            precio: parseFloat(precio),
            imagen: imagen,
            quantity: 1
        });
    }
    
    localStorage.setItem('cart', JSON.stringify(cart));
    // Notificación visual robusta
    M.toast({html: `🛒 <b>${nombre}</b> añadido al carrito`, classes: 'green rounded'});
    
    updateCartBadge();
    renderMiniCart();
}

function renderMiniCart() {
    const container = document.getElementById('mini-cart-preview');
    const list = document.getElementById('mini-cart-list');
    if (!container || !list) return;

    const cart = getCart();
    if (cart.length === 0) {
        container.style.display = 'none';
        return;
    }

    container.style.display = 'flex';
    list.innerHTML = '';
    let totalItems = 0;
    let grandTotal = 0;

    const totalDisplay = document.getElementById('mini-cart-total');

    cart.forEach(item => {
        totalItems += item.quantity;
        grandTotal += (item.precio * item.quantity);
        const itemDiv = document.createElement('div');
        itemDiv.className = 'mini-cart-item';
        const imgHtml = item.imagen ? `<img src="${item.imagen}">` : `<i class="material-icons grey-text">image</i>`;
        
        itemDiv.innerHTML = `
            ${imgHtml}
            <div class="item-info">
                <span class="item-name truncate">${item.nombre}</span>
                <span class="item-qty grey-text">${item.quantity} x $${parseFloat(item.precio).toFixed(2)}</span>
            </div>
        `;
        list.appendChild(itemDiv);
    });

    if (totalDisplay) {
        totalDisplay.textContent = 'Total: $' + grandTotal.toFixed(2);
    }
}

document.addEventListener('DOMContentLoaded', renderMiniCart);
</script>

<style>
    .collection.borderless .collection-item { border: none; }
    .truncate-3-lines {
        display: -webkit-box;
        -webkit-line-clamp: 3;
        -webkit-box-orient: vertical;  
        overflow: hidden;
    }

    /* Estilos Mini Carrito */
    .mini-cart-floating {
        position: fixed;
        bottom: 20px;
        left: 20px;
        width: 280px;
        max-height: 400px;
        background: white;
        border-radius: 8px;
        display: none;
        flex-direction: column;
        z-index: 999;
    }
    .mini-cart-header { padding: 10px; border-radius: 8px 8px 0 0; font-size: 0.9rem; font-weight: bold; }
    .mini-cart-items { overflow-y: auto; flex-grow: 1; padding: 5px; }
    .mini-cart-footer { padding: 10px; border-top: 1px solid #eee; }
    .mini-cart-item { display: flex; align-items: center; padding: 8px; border-bottom: 1px solid #f5f5f5; }
    .mini-cart-item img { width: 40px; height: 40px; object-fit: contain; margin-right: 10px; border-radius: 4px; background: #fafafa; }
    .mini-cart-item .item-info { display: flex; flex-direction: column; width: calc(100% - 50px); }
    .mini-cart-item .item-name { font-size: 0.85rem; font-weight: bold; }
    .mini-cart-item .item-qty { font-size: 0.8rem; }
</style>
<?php include __DIR__ . '/includes/footer.php'; ?>