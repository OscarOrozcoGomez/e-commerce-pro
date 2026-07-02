<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

function catalogDebugEnvEnabled(): bool
{
    $rawEnv = getenv('APP_ENV');
    if ($rawEnv === false) {
        $rawEnv = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? '';
    }

    $env = strtolower(trim((string)$rawEnv));
    if (in_array($env, ['local', 'dev', 'development', 'qa', 'test', 'testing'], true)) {
        return true;
    }

    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    return strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false;
}

function catalogClientContext(): array
{
    $ua = strtolower((string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $device = 'desktop';
    if ($ua !== '' && preg_match('/mobile|android|iphone|ipod|iemobile|blackberry|opera mini/', $ua)) {
        $device = 'mobile';
    } elseif ($ua !== '' && preg_match('/ipad|tablet|kindle|silk/', $ua)) {
        $device = 'tablet';
    }

    $os = 'unknown';
    if (strpos($ua, 'android') !== false) {
        $os = 'android';
    } elseif (strpos($ua, 'iphone') !== false || strpos($ua, 'ipad') !== false || strpos($ua, 'ipod') !== false) {
        $os = 'ios';
    } elseif (strpos($ua, 'windows') !== false) {
        $os = 'windows';
    } elseif (strpos($ua, 'mac os') !== false || strpos($ua, 'macintosh') !== false) {
        $os = 'macos';
    } elseif (strpos($ua, 'linux') !== false) {
        $os = 'linux';
    }

    return [
        'device' => $device,
        'os' => $os,
        'ua' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
    ];
}

function catalogDebugLog(string $event, array $data = []): void
{
    if (!catalogDebugEnvEnabled()) {
        return;
    }

    $data['event'] = $event;
    $data['uri'] = (string)($_SERVER['REQUEST_URI'] ?? '');
    $data['ip'] = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $data['client'] = catalogClientContext();
    error_log('CATALOGO_DEBUG ' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function catalogBindNamedParams(PDOStatement $stmt, array $params): void
{
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
}

$categoriaSeleccionada = $_GET['categoria'] ?? '';
$busqueda = $_GET['search'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$itemsPerPage = 9;
$isAjaxLoadMore = (($_GET['ajax'] ?? '') === '1');
$isSearchRequest = trim((string)$busqueda) !== '';

// En carga normal mostramos acumulado hasta la página actual para que un refresh
// no "pierda" los productos ya visibles tras usar Cargar más.
$limit = $isAjaxLoadMore ? $itemsPerPage : ($page * $itemsPerPage);
$offset = $isAjaxLoadMore ? (($page - 1) * $itemsPerPage) : 0;
$categorias = dbGetCategories();

$catalogBaseUrl = 'catalogo.php';
$catalogBaseParams = [];
if (!empty($busqueda)) {
    $catalogBaseParams['search'] = $busqueda;
}

// --- Lógica para obtener y filtrar productos ---
$pdo = getPDO();
$sql = "SELECT p.*,         COALESCE(NULLIF((SELECT pi.ruta_archivo FROM producto_imagenes pi INNER JOIN productos p_img ON pi.id_producto = p_img.id_producto WHERE (p_img.id_producto = p.id_producto OR p_img.id_padre = p.id_producto) ORDER BY (p_img.id_producto = p.id_producto) DESC, pi.orden ASC LIMIT 1), ''), NULLIF(TRIM(p.imagen), ''), NULLIF(TRIM(p.imagen_url), '')) as imagen,        (SELECT MIN(precio_venta) FROM productos p3 WHERE (p3.id_producto = p.id_producto OR p3.id_padre = p.id_producto) AND p3.estado = 'activo') as precio_desde,
        (SELECT COUNT(*) FROM productos p2 WHERE (p2.id_producto = p.id_producto OR p2.id_padre = p.id_producto) AND p2.estado = 'activo') as total_variantes 
        FROM productos p";
$params = [];

// Mostramos sólo productos raíz.
// Permitimos un padre inactivo si tiene variantes activas.
$whereClauses = [
    "(p.id_padre IS NULL OR p.id_padre = 0)",
    "(p.estado = 'activo' OR EXISTS (SELECT 1 FROM productos p_child WHERE p_child.id_padre = p.id_producto AND p_child.estado = 'activo'))"
];

if (!empty($categoriaSeleccionada)) {
    $sql .= " JOIN producto_categorias pc ON p.id_producto = pc.id_producto 
              JOIN categorias c ON pc.id_categoria = c.id_categoria ";
    $whereClauses[] = "c.nombre = :cat";
    $params[':cat'] = $categoriaSeleccionada;
}

if (!empty($busqueda)) {
    $whereClauses[] = "(p.nombre LIKE :search_name OR p.codigo_barras LIKE :search_code OR p.nombre_variante LIKE :search_variant OR EXISTS (
        SELECT 1 FROM productos p_v 
        WHERE p_v.id_padre = p.id_producto 
          AND (p_v.nombre LIKE :search_ex OR p_v.codigo_barras LIKE :search_ex_code OR p_v.nombre_variante LIKE :search_ex_variant)
    ))";
    $params[':search_name'] = '%' . $busqueda . '%';
    $params[':search_code'] = '%' . $busqueda . '%';
    $params[':search_variant'] = '%' . $busqueda . '%';
    $params[':search_ex'] = '%' . $busqueda . '%';
    $params[':search_ex_code'] = '%' . $busqueda . '%';
    $params[':search_ex_variant'] = '%' . $busqueda . '%';
}

$sql .= " WHERE " . implode(" AND ", $whereClauses);

// --- Conteo de total de productos para paginación ---
$sqlCount = "SELECT COUNT(DISTINCT p.id_producto) FROM productos p";
if (!empty($categoriaSeleccionada)) {
    $sqlCount .= " JOIN producto_categorias pc ON p.id_producto = pc.id_producto 
                   JOIN categorias c ON pc.id_categoria = c.id_categoria ";
}
$sqlCount .= " WHERE " . implode(" AND ", $whereClauses);

$stmtCount = $pdo->prepare($sqlCount);
$countParams = [];
foreach ($params as $key => $value) {
    if (strpos($sqlCount, $key) !== false) {
        $countParams[$key] = $value;
    }
}

if ($isSearchRequest) {
    catalogDebugLog('search_count_query', [
        'page' => $page,
        'is_ajax' => $isAjaxLoadMore,
        'sql' => $sqlCount,
        'params' => $countParams,
    ]);
}

try {
    catalogBindNamedParams($stmtCount, $countParams);
    $stmtCount->execute();
    $totalProductos = (int)$stmtCount->fetchColumn();
} catch (PDOException $e) {
    if ($isSearchRequest) {
        catalogDebugLog('search_count_query_error', [
            'page' => $page,
            'is_ajax' => $isAjaxLoadMore,
            'error' => $e->getMessage(),
            'code' => $e->getCode(),
        ]);
    }
    throw $e;
}

// --- Aplicar orden y paginación a la consulta principal ---
$sql .= " ORDER BY p.nombre ASC LIMIT :limit OFFSET :offset";

try {
    $stmt = $pdo->prepare($sql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

    if ($isSearchRequest) {
        catalogDebugLog('search_main_query', [
            'page' => $page,
            'is_ajax' => $isAjaxLoadMore,
            'sql' => $sql,
            'params' => array_merge($params, [':limit' => $limit, ':offset' => $offset]),
        ]);
    }

    $stmt->execute();
    $productos = $stmt->fetchAll();
} catch (PDOException $e) {
    if ($isSearchRequest) {
        catalogDebugLog('search_main_query_error', [
            'page' => $page,
            'is_ajax' => $isAjaxLoadMore,
            'error' => $e->getMessage(),
            'code' => $e->getCode(),
        ]);
    }
    error_log("Error al cargar catálogo paginado: " . $e->getMessage());
    $productos = [];
}

$pageTitle = 'Catálogo de Productos';
include __DIR__ . '/includes/header.php';
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
                    <?php
                        $allCategoriesUrl = $catalogBaseUrl;
                        if (!empty($catalogBaseParams)) {
                            $allCategoriesUrl .= '?' . http_build_query($catalogBaseParams);
                        }
                    ?>
                    <a href="<?php echo $allCategoriesUrl; ?>" class="collection-item <?php echo empty($categoriaSeleccionada) ? 'active blue darken-4' : 'grey-text text-darken-3'; ?>" style="border-radius: 4px; margin-bottom: 5px; padding: 12px 15px;">
                        Todas las categorías
                    </a>
                    <?php if (empty($categorias)): ?>
                        <p class="grey-text center-align" style="font-size: 0.9rem; padding: 10px;">No hay categorías configuradas.</p>
                    <?php else: ?>
                        <?php foreach ($categorias as $cat): ?>
                            <?php
                                $isSelectedCategory = ($categoriaSeleccionada === $cat['nombre']);
                                $categoryLinkParams = $catalogBaseParams;
                                if (!$isSelectedCategory) {
                                    $categoryLinkParams['categoria'] = $cat['nombre'];
                                }
                                $categoryUrl = $catalogBaseUrl;
                                if (!empty($categoryLinkParams)) {
                                    $categoryUrl .= '?' . http_build_query($categoryLinkParams);
                                }
                            ?>
                            <a href="<?php echo $categoryUrl; ?>"
                               class="collection-item <?php echo $categoriaSeleccionada === $cat['nombre'] ? 'active blue darken-4' : 'grey-text text-darken-3'; ?>"
                               style="border-radius: 4px; margin-bottom: 5px;">
                                <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px;">
                                    <span><?php echo esc($cat['nombre']); ?></span>
                                    <?php if ($isSelectedCategory): ?>
                                        <span style="font-weight: bold; font-size: 1rem; line-height: 1;" title="Quitar filtro">x</span>
                                    <?php endif; ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <?php if (!empty($categoriaSeleccionada)): ?>
                    <p class="grey-text text-darken-1" style="font-size: 0.82rem; margin: 8px 15px 0;">
                        Tip: haz clic en la categoría marcada con x para quitar el filtro.
                    </p>
                <?php endif; ?>
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
                        <div class="input-field col s12" style="margin: 0; border: none; position: relative;">
                            <i class="material-icons prefix blue-text text-darken-4" style="top: 10px;">search</i>
                            <input type="text" name="search" id="search-input" value="<?php echo esc($busqueda); ?>" placeholder="¿Qué estás buscando hoy?" style="border-bottom: none !important; box-shadow: none !important; margin: 0; height: 45px; padding-left: 3.5rem !important;">
                            <i class="material-icons" id="clear-search-btn" style="position: absolute; top: 12px; right: 15px; cursor: pointer; color: #9e9e9e; display: none;">close</i>
                        </div>
                    </form>
                </div>
            </div>

            <h4 class="grey-text text-darken-3" style="font-weight: 300; margin-bottom: 30px;">
                <?php echo empty($categoriaSeleccionada) ? 'Explorar Catálogo' : 'Categoría: ' . esc($categoriaSeleccionada); ?>
            </h4>

            <div id="catalog-meta" data-total-products="<?php echo (int)$totalProductos; ?>" data-items-per-page="<?php echo (int)$itemsPerPage; ?>" style="display:none;"></div>
            
            <div class="row row-products">
                <?php if (empty($productos)): ?>
                    <div class="col s12 center-align" style="padding: 50px;">
                        <i class="material-icons large grey-text lighten-2">inventory_2</i>
                        <p class="grey-text">No se encontraron productos disponibles en este momento.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($productos as $p): ?>
                        <div class="col s12 m6 l4 product-card-container" data-name="<?php echo esc(strtolower($p['nombre'])); ?>" data-sku="<?php echo esc(strtolower($p['sku'] ?? '')); ?>">
                            <a href="<?php echo BASE_URL; ?>product_detail.php?id=<?php echo $p['id_producto']; ?>" class="card-link">
                                <div class="card hoverable border-radius-8" style="height: 420px; display: flex; flex-direction: column;">
                                    <div class="card-image waves-effect waves-block waves-light" style="height: 200px; background: #f9f9f9; display: flex; align-items: center; justify-content: center;">
                                        <?php $imgSrc = getProductImageUrl($p['imagen']); ?>
                                        <img src="<?php echo $imgSrc; ?>" loading="lazy" onerror="this.onerror=null;this.src='<?php echo BASE_URL; ?>assets/img/products/default-product.svg';" style="max-height: 100%; width: auto; object-fit: contain;">
                                    </div>
                                    <div class="card-content" style="flex-grow: 1;">
                                        <span class="card-title grey-text text-darken-4 truncate" style="font-size: 1rem; font-weight: bold;" title="<?php echo esc($p['nombre']); ?>">
                                            <?php echo esc($p['nombre']); ?>
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
                                        <button class="btn blue darken-4 waves-effect waves-light" 
                                                onclick="handleAddToCart(event, <?php echo (int)$p['id_producto']; ?>, '<?php echo addslashes(esc($p['nombre'])); ?>', <?php echo (float)$p['precio_venta']; ?>)">
                                            <i class="material-icons">add_shopping_cart</i>
                                        </button>
                                    </div>
                                </div>
                            </a>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Botón Cargar Más -->
            <?php if ($totalProductos > ($page * $itemsPerPage)): ?>
                <div class="row" id="load-more-container" style="margin-top: 30px;">
                    <div class="col s12 center-align">
                        <button id="load-more-btn" class="btn-large blue darken-4 waves-effect waves-light" style="width: 100%;">
                            Cargar más productos
                        </button>
                        <div class="preloader-wrapper small" id="load-more-spinner" style="display: none; margin-top: 20px;">
                            <div class="spinner-layer spinner-blue-only"><div class="circle-clipper left"><div class="circle"></div></div><div class="gap-patch"><div class="circle"></div></div><div class="circle-clipper right"><div class="circle"></div></div></div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
const searchInput = document.getElementById('search-input');
const clearSearchBtn = document.getElementById('clear-search-btn');
const searchForm = searchInput ? searchInput.closest('form') : null;
const initialSearchQuery = normalizeFilterText(searchInput ? searchInput.value : '');
let lastRequestedQuery = initialSearchQuery;
let searchDebounceTimer = null;
let activeSearchController = null;

function normalizeFilterText(value) {
    return String(value || '').toLowerCase().trim();
}

function applyLiveCatalogFilter() {
    const query = normalizeFilterText(searchInput.value);
    const cards = document.querySelectorAll('.product-card-container');

    cards.forEach(card => {
        const name = normalizeFilterText(card.getAttribute('data-name'));
        const sku = normalizeFilterText(card.getAttribute('data-sku'));
        const matches = query === '' || name.includes(query) || sku.includes(query);
        card.style.display = matches ? '' : 'none';
    });
}

function triggerServerSearch() {
    if (!searchForm || !searchInput) {
        return;
    }

    const query = normalizeFilterText(searchInput.value);
    if (query === lastRequestedQuery) {
        return;
    }

    lastRequestedQuery = query;

    const action = searchForm.getAttribute('action') || window.location.pathname;
    const url = new URL(action, window.location.href);
    const formData = new FormData(searchForm);

    formData.forEach((value, key) => {
        const normalized = String(value || '').trim();
        if (normalized !== '') {
            url.searchParams.set(key, normalized);
        }
    });

    // Siempre reiniciamos al inicio en una nueva búsqueda.
    url.searchParams.set('page', '1');
    url.searchParams.delete('ajax');

    if (activeSearchController) {
        activeSearchController.abort();
    }
    activeSearchController = new AbortController();

    const selectionStart = searchInput.selectionStart;
    const selectionEnd = searchInput.selectionEnd;
    const selectionDirection = searchInput.selectionDirection;
    const currentValue = searchInput.value;

    fetch(url.toString(), { signal: activeSearchController.signal })
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');

            const currentProductsRow = document.querySelector('.row-products');
            const newProductsRow = doc.querySelector('.row-products');
            if (currentProductsRow && newProductsRow) {
                currentProductsRow.innerHTML = newProductsRow.innerHTML;
            }

            const currentMeta = document.getElementById('catalog-meta');
            const newMeta = doc.getElementById('catalog-meta');
            if (currentMeta && newMeta) {
                currentMeta.dataset.totalProducts = newMeta.dataset.totalProducts || '0';
                currentMeta.dataset.itemsPerPage = newMeta.dataset.itemsPerPage || currentMeta.dataset.itemsPerPage || '9';
            }

            const existingLoadMore = document.getElementById('load-more-container');
            if (existingLoadMore) {
                existingLoadMore.remove();
            }

            const newLoadMore = doc.getElementById('load-more-container');
            const productsRow = document.querySelector('.row-products');
            if (newLoadMore && productsRow && productsRow.parentNode) {
                productsRow.parentNode.insertBefore(newLoadMore, productsRow.nextSibling);
            }

            currentPage = 1;
            bindLoadMoreHandler();

            const browserUrl = new URL(window.location.href);
            browserUrl.searchParams.set('page', '1');
            if (query === '') {
                browserUrl.searchParams.delete('search');
            } else {
                browserUrl.searchParams.set('search', query);
            }
            window.history.replaceState({ path: browserUrl.href }, '', browserUrl.href);

            searchInput.focus();
            searchInput.value = currentValue;
            if (selectionStart !== null && selectionEnd !== null) {
                searchInput.setSelectionRange(selectionStart, selectionEnd, selectionDirection || 'none');
            }
        })
        .catch(err => {
            if (err && err.name === 'AbortError') {
                return;
            }
            console.error('Error en búsqueda en vivo:', err);
        });
}

function toggleClearButton() {
    if (searchInput.value.length > 0) {
        clearSearchBtn.style.display = 'block';
    } else {
        clearSearchBtn.style.display = 'none';
    }
}

searchInput.addEventListener('input', function() {
    toggleClearButton();
    applyLiveCatalogFilter();

    if (searchDebounceTimer) {
        clearTimeout(searchDebounceTimer);
    }

    searchDebounceTimer = setTimeout(triggerServerSearch, 380);
});

clearSearchBtn.addEventListener('click', function() {
    searchInput.value = '';
    toggleClearButton();
    searchInput.closest('form').submit(); // Envía el formulario para recargar con la búsqueda vacía
});

let currentPage = <?php echo $page; ?>;

function getCatalogMeta() {
    const meta = document.getElementById('catalog-meta');
    return {
        totalProducts: parseInt(meta?.dataset.totalProducts || '0', 10) || 0,
        itemsPerPage: parseInt(meta?.dataset.itemsPerPage || '<?php echo (int)$itemsPerPage; ?>', 10) || <?php echo (int)$itemsPerPage; ?>,
    };
}

function handleLoadMoreClick() {
    const btn = this;
    const spinner = document.getElementById('load-more-spinner');
    const container = document.getElementById('load-more-container');
    const meta = getCatalogMeta();

    btn.style.display = 'none';
    spinner.style.display = 'block';

    currentPage++;

    const url = new URL(window.location.href);
    url.searchParams.set('page', currentPage);
    url.searchParams.set('ajax', '1'); // Indicador para el servidor

    fetch(url)
        .then(response => response.text())
        .then(html => {
            const parser = new DOMParser();
            const doc = parser.parseFromString(html, 'text/html');
            const newProducts = doc.querySelectorAll('.product-card-container');
            
            const productsRow = document.querySelector('.row-products');
            newProducts.forEach(product => {
                productsRow.appendChild(product);
            });

            // Actualizar estado del botón
            if ((currentPage * meta.itemsPerPage) >= meta.totalProducts) {
                container.remove(); // Ocultar el contenedor del botón si no hay más
            } else {
                btn.style.display = 'block';
                spinner.style.display = 'none';
            }

            // Actualizar URL en el navegador sin recargar
            const browserUrl = new URL(window.location.href);
            browserUrl.searchParams.set('page', currentPage);
            window.history.pushState({path: browserUrl.href}, '', browserUrl.href);
        })
        .catch(err => {
            console.error('Error al cargar más productos:', err);
            M.toast({html: 'Error al cargar más productos', classes: 'red'});
            btn.style.display = 'block';
            spinner.style.display = 'none';
            currentPage--; // Revertir el incremento de página si falló
        });
}

function bindLoadMoreHandler() {
    const btn = document.getElementById('load-more-btn');
    if (!btn) {
        return;
    }
    btn.removeEventListener('click', handleLoadMoreClick);
    btn.addEventListener('click', handleLoadMoreClick);
}

// Si el usuario usa los botones de atrás/adelante del navegador
window.addEventListener('popstate', function(event) {
    // Si el estado guardado tiene una ruta, simplemente recargamos para consistencia.
    // Una implementación más avanzada podría manejar el estado sin recargar.
    if (event.state && event.state.path) {
        window.location.reload();
    }
});

toggleClearButton(); // Ejecutar al cargar por si la página ya tiene un valor de búsqueda
bindLoadMoreHandler();

function handleAddToCart(event, id, nombre, precio) {
    // Detenemos la propagación para que no se active el enlace de la tarjeta
    event.preventDefault();
    event.stopPropagation();

    let cart = JSON.parse(localStorage.getItem('cart') || '[]');
    
    // Buscar si el producto ya está en el carrito
    let item = cart.find(i => i.id_producto === id);
    
    if (item) {
        // Si ya existe, solo incrementamos la cantidad
        item.quantity = (parseInt(item.quantity) || 0) + 1;
    } else {
        // Si no existe, lo agregamos al carrito
        cart.push({
            id_producto: id,
            nombre: nombre,
            precio: precio,
            quantity: 1
        });
    }
    
    localStorage.setItem('cart', JSON.stringify(cart));
    M.toast({html: '🛒 <b>' + nombre + '</b> añadido al carrito', classes: 'green rounded'});
    
    if (typeof updateCartBadge === 'function') {
        updateCartBadge();
    }
}
</script>

<style>
    .border-radius-8 { border-radius: 8px; overflow: hidden; }
    .collection.borderless .collection-item { border: none; }
    .card-link {
        color: inherit; /* Hereda el color del texto para no verse como un link azul */
        display: block; /* Asegura que el enlace ocupe todo el espacio de la tarjeta */
    }
    .card-link:hover { text-decoration: none; } /* Evita el subrayado al pasar el mouse */
    .truncate-3-lines { display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
    .hoverable-item { transition: background-color 0.2s ease; }
    .hoverable-item:hover { background-color: #f5f5f5 !important; }
</style>
<?php include __DIR__ . '/includes/footer.php'; ?>