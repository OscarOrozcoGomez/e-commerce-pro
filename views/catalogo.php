<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/catalogo_utils.php';

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

$categoriaSeleccionada = $_GET['categoria'] ?? '';
$busqueda = $_GET['search'] ?? '';
$page = max(1, (int)($_GET['page'] ?? 1));
$itemsPerPage = 9;
$isAjaxLoadMore = (($_GET['ajax'] ?? '') === '1');
// Por defecto los agotados van al final; con ?agotados=1 se muestran, si no se ocultan.
$incluirAgotados = (($_GET['agotados'] ?? '') === '1');
$isSearchRequest = trim((string)$busqueda) !== '';

if ($isAjaxLoadMore && session_status() === PHP_SESSION_ACTIVE) {
    // Evita bloquear requests paralelos del mismo usuario (ej. log_activity + cargar mas).
    session_write_close();
}

// En carga normal mostramos acumulado hasta la página actual para que un refresh
// no "pierda" los productos ya visibles tras usar Cargar más.
$limit = $isAjaxLoadMore ? $itemsPerPage : ($page * $itemsPerPage);
$offset = $isAjaxLoadMore ? (($page - 1) * $itemsPerPage) : 0;
$categorias = $isAjaxLoadMore ? [] : dbGetCategories();

$catalogBaseUrl = 'catalogo.php';
$catalogBaseParams = [];
if (!empty($busqueda)) {
    $catalogBaseParams['search'] = $busqueda;
}
if ($incluirAgotados) {
    // Se arrastra al navegar entre categorías / búsquedas para no "perder" el toggle.
    $catalogBaseParams['agotados'] = '1';
}

// --- Lógica para obtener y filtrar productos ---
// La SQL en si (imagen/precio_desde/precio_comparacion_desde/total_variantes) vive en
// catalogBuildQueries() -- antes estaba duplicada aqui, con el riesgo de que las dos
// copias se fueran desalineando con el tiempo (y de hecho ya iban desalineadas en
// detalles menores). Un solo punto de verdad para la query principal + la de conteo.
$pdo = getPDO();
$queryParts = catalogBuildQueries($pdo, $categoriaSeleccionada, $busqueda, $incluirAgotados);
$sql = $queryParts['sql_main'];
$sqlCount = $queryParts['sql_count'];
$params = $queryParts['params'];

// --- Conteo de total de productos para paginación ---
$totalProductos = 0;
if (!$isAjaxLoadMore) {
    $stmtCount = $pdo->prepare($sqlCount);

    if ($isSearchRequest) {
        catalogDebugLog('search_count_query', [
            'page' => $page,
            'is_ajax' => $isAjaxLoadMore,
            'sql' => $sqlCount,
            'params' => $params,
        ]);
    }

    try {
        catalogBindNamedParams($stmtCount, $params);
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
}

// --- Aplicar orden y paginación a la consulta principal ---
// El ORDER BY vive en catalogBuildQueries() (disponibles primero, agotados al final).
$sql .= ' ' . $queryParts['order_by'] . ' LIMIT :limit OFFSET :offset';

try {
    $stmt = $pdo->prepare($sql);
    catalogBindNamedParams($stmt, $params);
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

$productos = catalogCollapseProducts($productos);
$productos = catalogAttachStockAvailability($pdo, $productos);

// El icono de compartir por WhatsApp (por producto y el de "todas las ofertas")
// solo se muestra a sesiones iniciadas: cualquier usuario del sistema o cliente.
$puedeCompartir = isAuthenticated();

if ($isAjaxLoadMore) {
    header('Content-Type: text/html; charset=UTF-8');
    if (empty($productos)) {
        echo '<div class="col s12 center-align" style="padding: 50px;">'
            . '<i class="material-icons large grey-text lighten-2">inventory_2</i>'
            . '<p class="grey-text">No se encontraron productos disponibles en este momento.</p>'
            . '</div>';
    } else {
        foreach ($productos as $p) {
            echo catalogRenderProductCard($p, $puedeCompartir);
        }
    }
    exit;
}

// Ofertas para el botón "enviar todas las ofertas en un solo link de WhatsApp".
// Solo en carga normal (el bloque AJAX de arriba ya hizo exit) y SOLO cuando el
// usuario está viendo la categoría de ofertas: fuera de ahí el botón no aplica.
$ofertasCategoriaNombre = '';
foreach ($categorias as $catOferta) {
    $nombreCat = (string) ($catOferta['nombre'] ?? '');
    if (in_array(mb_strtolower($nombreCat, 'UTF-8'), OFERTA_CATEGORIA_NOMBRES, true)) {
        $ofertasCategoriaNombre = $nombreCat;
        break;
    }
}
$viendoCategoriaOfertas = $categoriaSeleccionada !== ''
    && in_array(mb_strtolower($categoriaSeleccionada, 'UTF-8'), OFERTA_CATEGORIA_NOMBRES, true);
$ofertasParaCompartir = ($puedeCompartir && $viendoCategoriaOfertas)
    ? catalogGetOfertasParaCompartir($pdo)
    : [];

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
                        <?php if($incluirAgotados): ?>
                            <input type="hidden" name="agotados" value="1">
                        <?php endif; ?>
                        <div class="input-field col s12" style="margin: 0; border: none; position: relative;">
                            <i class="material-icons prefix blue-text text-darken-4" style="top: 10px;">search</i>
                            <input type="text" name="search" id="search-input" value="<?php echo esc($busqueda); ?>" placeholder="¿Qué estás buscando hoy?" style="border-bottom: none !important; box-shadow: none !important; margin: 0; height: 45px; padding-left: 3.5rem !important;">
                            <i class="material-icons" id="clear-search-btn" style="position: absolute; top: 12px; right: 15px; cursor: pointer; color: #9e9e9e; display: none;">close</i>
                        </div>
                    </form>
                </div>
            </div>

            <?php
                // URLs para prender/apagar "Ver agotados" conservando categoría y búsqueda.
                $agotadosParams = [];
                if (!empty($busqueda)) $agotadosParams['search'] = $busqueda;
                if (!empty($categoriaSeleccionada)) $agotadosParams['categoria'] = $categoriaSeleccionada;
                $urlAgotadosOff = $catalogBaseUrl . (!empty($agotadosParams) ? '?' . http_build_query($agotadosParams) : '');
                $urlAgotadosOn = $catalogBaseUrl . '?' . http_build_query($agotadosParams + ['agotados' => '1']);
            ?>
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px; margin-bottom: 30px;">
                <h4 class="grey-text text-darken-3" style="font-weight: 300; margin: 0;">
                    <?php echo empty($categoriaSeleccionada) ? 'Explorar Catálogo' : 'Categoría: ' . esc($categoriaSeleccionada); ?>
                </h4>
                <?php if (!empty($ofertasParaCompartir)): ?>
                    <button type="button" id="share-ofertas-btn" data-no-track="1"
                            class="btn green waves-effect waves-light"
                            style="text-transform: none;">
                        <i class="fa-brands fa-whatsapp left"></i>
                        Enviar <?php echo count($ofertasParaCompartir); ?> ofertas por WhatsApp
                    </button>
                <?php endif; ?>
                <label class="grey-text text-darken-2" style="display: flex; align-items: center; gap: 6px; font-size: 0.9rem; cursor: pointer;">
                    <input type="checkbox" id="toggle-agotados" class="filled-in" <?php echo $incluirAgotados ? 'checked' : ''; ?>>
                    <span style="padding-left: 26px;">Ver agotados</span>
                </label>
            </div>
            <script>
                document.getElementById('toggle-agotados')?.addEventListener('change', function () {
                    window.location.href = this.checked
                        ? <?php echo json_encode($urlAgotadosOn, JSON_UNESCAPED_SLASHES); ?>
                        : <?php echo json_encode($urlAgotadosOff, JSON_UNESCAPED_SLASHES); ?>;
                });
            </script>

            <div id="catalog-meta" data-total-products="<?php echo (int)$totalProductos; ?>" data-items-per-page="<?php echo (int)$itemsPerPage; ?>" style="display:none;"></div>
            
            <div class="row row-products">
                <?php if (empty($productos)): ?>
                    <div class="col s12 center-align" style="padding: 50px;">
                        <i class="material-icons large grey-text lighten-2">inventory_2</i>
                        <p class="grey-text">No se encontraron productos disponibles en este momento.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($productos as $p): ?>
                        <?php echo catalogRenderProductCard($p, $puedeCompartir); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Botón Cargar Más -->
            <?php if ($totalProductos > ($page * $itemsPerPage)): ?>
                <div class="row" id="load-more-container" style="margin-top: 30px;">
                    <div class="col s12 center-align">
                        <button id="load-more-btn" type="button" data-no-track="1" class="btn-large blue darken-4 waves-effect waves-light" style="width: 100%;">
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
const catalogApiUrl = '<?php echo BASE_URL; ?>api/catalog_products.php';
const catalogShowAgotados = <?php echo $incluirAgotados ? 'true' : 'false'; ?>;
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

    const url = new URL(catalogApiUrl, window.location.href);
    const formData = new FormData(searchForm);

    formData.forEach((value, key) => {
        const normalized = String(value || '').trim();
        if (normalized !== '') {
            url.searchParams.set(key, normalized);
        }
    });

    // Siempre reiniciamos al inicio en una nueva búsqueda.
    url.searchParams.set('page', '1');
    url.searchParams.set('items_per_page', String(getCatalogMeta().itemsPerPage || 9));
    url.searchParams.set('source', 'search');
    if (catalogShowAgotados) url.searchParams.set('agotados', '1');

    if (activeSearchController) {
        activeSearchController.abort();
    }
    activeSearchController = new AbortController();

    const selectionStart = searchInput.selectionStart;
    const selectionEnd = searchInput.selectionEnd;
    const selectionDirection = searchInput.selectionDirection;
    const currentValue = searchInput.value;

    fetch(url.toString(), { signal: activeSearchController.signal })
        .then(response => response.json())
        .then(payload => {
            if (!payload || !payload.success) {
                throw new Error((payload && payload.message) ? payload.message : 'No se pudo cargar el catálogo');
            }

            const currentProductsRow = document.querySelector('.row-products');
            if (currentProductsRow) {
                currentProductsRow.innerHTML = payload.items_html || '';
            }

            const currentMeta = document.getElementById('catalog-meta');
            if (currentMeta && payload.meta) {
                currentMeta.dataset.totalProducts = String(payload.meta.total_products || 0);
                currentMeta.dataset.itemsPerPage = String(payload.meta.items_per_page || currentMeta.dataset.itemsPerPage || 9);
            }

            updateLoadMoreVisibility(Boolean(payload.meta && payload.meta.has_more));

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

    const url = new URL(catalogApiUrl, window.location.href);
    const pageToLoad = currentPage;
    url.searchParams.set('page', String(pageToLoad));
    url.searchParams.set('items_per_page', String(meta.itemsPerPage || 9));
    url.searchParams.set('source', 'load_more');
    if (catalogShowAgotados) url.searchParams.set('agotados', '1');

    const currentSearch = normalizeFilterText(searchInput ? searchInput.value : '');
    if (currentSearch !== '') {
        url.searchParams.set('search', currentSearch);
    }

    const categoriaInput = searchForm ? searchForm.querySelector('input[name="categoria"]') : null;
    const currentCategoria = categoriaInput ? String(categoriaInput.value || '').trim() : '';
    if (currentCategoria !== '') {
        url.searchParams.set('categoria', currentCategoria);
    }

    fetch(url)
        .then(response => response.json())
        .then(payload => {
            if (!payload || !payload.success) {
                throw new Error((payload && payload.message) ? payload.message : 'No se pudieron cargar más productos');
            }

            const parser = new DOMParser();
            const doc = parser.parseFromString(payload.items_html || '', 'text/html');
            const newProducts = doc.querySelectorAll('.product-card-container');
            
            const productsRow = document.querySelector('.row-products');
            newProducts.forEach(product => {
                const incomingGroupKey = (product.getAttribute('data-group-key') || '').trim();
                if (incomingGroupKey !== '') {
                    const duplicate = Array.from(productsRow.querySelectorAll('.product-card-container')).find(card => {
                        return (card.getAttribute('data-group-key') || '').trim() === incomingGroupKey;
                    });

                    if (duplicate) {
                        const currentImg = duplicate.querySelector('.card-image img');
                        const incomingImg = product.querySelector('.card-image img');
                        const currentSrc = currentImg ? String(currentImg.getAttribute('src') || '') : '';
                        const incomingSrc = incomingImg ? String(incomingImg.getAttribute('src') || '') : '';
                        const currentIsDefault = /default-product\.svg/i.test(currentSrc);
                        const incomingIsDefault = /default-product\.svg/i.test(incomingSrc);

                        if (currentImg && incomingImg && currentIsDefault && !incomingIsDefault) {
                            currentImg.setAttribute('src', incomingSrc);
                        }
                        return;
                    }
                }

                productsRow.appendChild(product);
            });

            if (payload.meta && payload.meta.has_more) {
                btn.style.display = 'block';
                spinner.style.display = 'none';
            } else {
                container.remove();
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

function updateLoadMoreVisibility(hasMore) {
    const existingLoadMore = document.getElementById('load-more-container');
    const productsRow = document.querySelector('.row-products');

    if (!hasMore) {
        if (existingLoadMore) {
            existingLoadMore.remove();
        }
        return;
    }

    if (existingLoadMore) {
        return;
    }

    if (!productsRow || !productsRow.parentNode) {
        return;
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'row';
    wrapper.id = 'load-more-container';
    wrapper.style.marginTop = '30px';
    wrapper.innerHTML = `
        <div class="col s12 center-align">
            <button id="load-more-btn" type="button" data-no-track="1" class="btn-large blue darken-4 waves-effect waves-light" style="width: 100%;">
                Cargar más productos
            </button>
            <div class="preloader-wrapper small" id="load-more-spinner" style="display: none; margin-top: 20px;">
                <div class="spinner-layer spinner-blue-only"><div class="circle-clipper left"><div class="circle"></div></div><div class="gap-patch"><div class="circle"></div></div><div class="circle-clipper right"><div class="circle"></div></div></div>
            </div>
        </div>`;
    productsRow.parentNode.insertBefore(wrapper, productsRow.nextSibling);
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

    if (typeof window.bbTrackEvent === 'function') {
        window.bbTrackEvent({ tipo: 'click', url: window.location.href, id: 'add_to_cart', texto: 'Agregar al Carrito', id_producto: id });
    }

    if (typeof updateCartBadge === 'function') {
        updateCartBadge();
    }
}

// --- Compartir por WhatsApp -------------------------------------------------
// En móvil se usa el selector nativo de Android/iOS (navigator.share): ahí el
// usuario elige la app destino, incluyendo WhatsApp y WhatsApp Business como
// opciones separadas. Un link wa.me directo NO deja elegir en Android (abre la
// que esté como predeterminada). En escritorio, sin navigator.share, se cae al
// link wa.me de siempre (abre el selector de chats de WhatsApp Web).
const catalogBaseUrlAbs = window.location.origin + '<?php echo BASE_URL; ?>';
const ofertasParaCompartir = <?php echo json_encode(array_map(
    static fn(array $o): array => ['id' => $o['id_producto'], 'n' => $o['nombre'], 'p' => $o['precio']],
    $ofertasParaCompartir
), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const ofertasCategoriaNombre = <?php echo json_encode($ofertasCategoriaNombre, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

function buildProductUrlAbs(id) {
    return catalogBaseUrlAbs + 'product_detail.php?id=' + encodeURIComponent(id);
}

function formatPrecioCompartir(precio) {
    const n = Number(precio);
    if (!(n > 0)) {
        return '';
    }
    return ' - $' + n.toLocaleString('es-MX', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

async function compartirTexto(texto) {
    if (navigator.share) {
        try {
            await navigator.share({ text: texto });
            return;
        } catch (err) {
            // El usuario cerró el selector a propósito: no abrimos nada más.
            if (err && (err.name === 'AbortError' || err.name === 'NotAllowedError')) {
                return;
            }
            // Cualquier otro error (navegador sin soporte real, etc.): fallback.
        }
    }
    window.open('https://wa.me/?text=' + encodeURIComponent(texto), '_blank');
}

function shareProductoWhatsApp(event, id, nombre, precio) {
    event.preventDefault();
    event.stopPropagation();
    const texto = '*' + nombre + '*' + formatPrecioCompartir(precio) + '\n' + buildProductUrlAbs(id);
    compartirTexto(texto);
}

document.getElementById('share-ofertas-btn')?.addEventListener('click', function () {
    if (!Array.isArray(ofertasParaCompartir) || ofertasParaCompartir.length === 0) {
        return;
    }
    const lineas = ofertasParaCompartir.map(function (o) {
        return '• *' + o.n + '*' + formatPrecioCompartir(o.p) + '\n' + buildProductUrlAbs(o.id);
    });
    let texto = '🔥 *Ofertas disponibles* 🔥\n\n' + lineas.join('\n\n');
    if (ofertasCategoriaNombre) {
        texto += '\n\nVer todas: ' + catalogBaseUrlAbs + 'views/catalogo.php?categoria=' + encodeURIComponent(ofertasCategoriaNombre);
    }
    compartirTexto(texto);
});
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