<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/auth.php';

$requestedProductId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);

$productExists = false;
$dbCheckFailed = false;

if ($requestedProductId !== false && $requestedProductId !== null) {
    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare("SELECT id_producto FROM productos WHERE id_producto = ? AND estado = 'activo' LIMIT 1");
        $stmt->execute([$requestedProductId]);
        $productExists = (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        $dbCheckFailed = true;
        error_log('Error validando producto en product_detail.php: ' . $e->getMessage());
    }
}

if (!$productExists) {
    http_response_code($dbCheckFailed ? 500 : 404);
    $pageTitle = $dbCheckFailed ? 'Error al cargar producto' : 'Producto no encontrado';
    include __DIR__ . '/views/includes/header.php';
    ?>
    <div class="container" style="margin-top: 40px; margin-bottom: 40px; max-width: 900px;">
        <div class="card-panel z-depth-1" style="padding: 30px; border-radius: 12px;">
            <h4 class="blue-text text-darken-4" style="margin-top: 0;">
                <?php echo $dbCheckFailed ? 'No fue posible cargar el producto' : 'Producto no encontrado'; ?>
            </h4>
            <p class="grey-text text-darken-2" style="font-size: 1.05rem; margin-bottom: 25px;">
                <?php echo $dbCheckFailed
                    ? 'Ocurrió un problema al consultar la base de datos. Intenta de nuevo en unos minutos.'
                    : 'El producto que buscas no existe o ya no está disponible.'; ?>
            </p>
            <a href="<?php echo BASE_URL; ?>index.php" class="btn blue darken-4 waves-effect waves-light">
                <i class="material-icons left">arrow_back</i> Volver al catálogo
            </a>
        </div>
    </div>
    <?php
    include __DIR__ . '/views/includes/footer.php';
    exit;
}

$pageTitle = 'Detalles del Producto';
include __DIR__ . '/views/includes/header.php';
?>

<style>
    .breadcrumb-nav { padding: 15px 0; color: #777; font-size: 0.9rem; }
    .breadcrumb-nav a { color: #f06292; } /* Rosado suave del Odoo theme */
    
    /* Gallery & Zoom Styles */
    .main-img-viewer {
        position: relative;
        width: 100%;
        height: 650px;
        background: #fff;
        border-radius: 8px;
        border: 1px solid #eee;
        overflow: hidden;
        cursor: zoom-in;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 15px;
    }
    .main-img-viewer img {
        max-width: 100%;
        max-height: 100%;
        object-fit: contain;
        transition: transform 0.1s ease-out;
        transform-origin: center;
    }
    .main-img-viewer:hover img {
        transform: scale(2.5);
    }

    /* Navigation Arrows */
    .nav-arrow {
        position: absolute;
        top: 50%;
        transform: translateY(-50%);
        background: rgba(255, 255, 255, 0.2);
        backdrop-filter: blur(8px);
        border: 1px solid rgba(255, 255, 255, 0.3);
        color: #1a237e;
        width: 45px;
        height: 45px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        z-index: 10;
        transition: 0.3s;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    }
    .nav-arrow:hover { background: #1a237e; color: white; transform: translateY(-50%) scale(1.1); }
    .nav-arrow.prev { left: 15px; }
    .nav-arrow.next { right: 15px; }

    .gallery-strip {
        display: flex;
        gap: 10px;
        overflow-x: auto;
        padding: 5px 0;
    }
    .gallery-strip::-webkit-scrollbar { height: 4px; }
    .gallery-strip::-webkit-scrollbar-thumb { background: #ddd; border-radius: 10px; }
    
    .thumb-item {
        width: 100px;
        height: 100px;
        flex-shrink: 0;
        border: 2px solid #eee;
        border-radius: 6px;
        cursor: pointer;
        transition: 0.3s;
        background: white;
    }
    .thumb-item img { width: 100%; height: 100%; object-fit: contain; padding: 5px; }
    .thumb-item:hover, .thumb-item.active { border-color: #1a237e; opacity: 1; transform: translateY(-2px); }
    .thumb-item { opacity: 0.6; }

    .product-title { font-size: 3rem; font-weight: 600; margin-top: 0; color: #1a237e; line-height: 1.1; margin-bottom: 20px; }
    .ingredients-text { color: #555; font-size: 1.1rem; margin-bottom: 30px; line-height: 1.6; }
    
    /* Estilos para Pestañas e Información Nutrimental */
    .tabs .tab a { font-weight: bold; transition: color 0.3s; }
    .tabs .indicator { background-color: #1a237e; }
    .tab-content-box { padding: 30px 15px; font-size: 1.1rem; color: #444; line-height: 1.7; background: #fff; border: 1px solid #eee; border-top: none; border-radius: 0 0 8px 8px; }

    .nutritional-title { font-weight: 700; color: #1a237e; margin-top: 50px; margin-bottom: 20px; border-left: 5px solid #8a9a5b; padding-left: 15px; }
    .nutritional-table { width: 100%; border-collapse: collapse; background: #fff; margin-bottom: 10px; }
    .nutritional-table thead th { background-color: #f1f8e9; color: #558b2f; padding: 15px; border-bottom: 2px solid #dcedc8; text-transform: uppercase; font-size: 0.9rem; letter-spacing: 0.5px; }
    .nutritional-table tbody td { padding: 12px 15px; border-bottom: 1px solid #f0f0f0; color: #333; }
    .nutritional-table tbody tr:last-child td { border-bottom: none; }
    .nutritional-table .row-label { font-weight: bold; color: #2c3e50; }
    
    .legal-disclaimer-sub { text-align: center; color: #9e9e9e; font-size: 0.8rem; margin-top: 15px; font-style: italic; }

    /* Nuevo Estilo para la Leyenda Legal (Amarillo Llamativo) */
    .legal-box {
        background-color: #fff9c4; /* Amarillo claro */
        color: #e65100; /* Naranja oscuro para contraste */
        padding: 20px;
        border-radius: 12px;
        display: flex;
        align-items: center;
        gap: 15px;
        font-size: 1rem;
        font-weight: bold;
        margin-bottom: 25px;
        border: 2px solid #fbc02d;
        box-shadow: 0 4px 10px rgba(0,0,0,0.05);
    }
    .legal-box i { font-size: 2.5rem; color: #fbc02d; }

    .warning-box { background-color: #fef0c7; color: #9c6c0e; padding: 15px; border-radius: 4px; display: flex; align-items: flex-start; gap: 10px; font-size: 0.85rem; font-weight: 500; margin-bottom: 30px; border: 1px solid #fde093; }
    .warning-box i { font-size: 1.2rem; color: #d97706; }

    .price-display { font-size: 2.2rem; font-weight: 400; color: #212121; margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 20px; }
    
    /* Estilo Odoo para Variantes (Pills) */
    .variant-selector { margin-bottom: 35px; }
    .variant-selector label { font-size: 0.95rem; color: #1a237e; font-weight: bold; display: block; margin-bottom: 10px; text-transform: uppercase; letter-spacing: 1px; }
    .variant-pills { display: flex; gap: 12px; flex-wrap: wrap; }
    .variant-pill { 
        padding: 10px 20px; border: 2px solid #e0e0e0; border-radius: 8px; cursor: pointer; 
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); font-weight: 500; background: #fff; color: #555; font-size: 0.95rem;
    }
    .variant-pill:hover { border-color: #1a237e; color: #1a237e; background-color: #f5f5f5; }
    .variant-pill.active { background: #1a237e; color: #fff; border-color: #1a237e; box-shadow: 0 4px 12px rgba(26, 35, 126, 0.2); }

    .action-bar { border-bottom: 1px solid #eee; padding-bottom: 40px; margin-bottom: 30px; display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
    
    .qty-selector { display: flex; align-items: center; border: 1px solid #ccc; border-radius: 30px; overflow: hidden; height: 55px; background: #fff; }
    .qty-btn { background: none; border: none; padding: 0 20px; font-size: 1.5rem; cursor: pointer; color: #555; outline: none; }
    .qty-btn:hover { background: #f5f5f5; }
    .qty-input { width: 50px; border: none !important; text-align: center; margin: 0 !important; height: 55px !important; font-size: 1.2rem; font-weight: bold; box-shadow: none !important; pointer-events: none; }
    
    .btn-add-cart { background-color: #1a237e; color: #fff; box-shadow: 0 4px 15px rgba(26, 35, 126, 0.3); font-weight: 600; border-radius: 30px; text-transform: uppercase; padding: 0 40px; height: 55px; display: inline-flex; align-items: center; gap: 10px; border: none; font-size: 1.1rem; cursor: pointer; }
    .btn-add-cart:hover { background-color: #f8bbd0; color: #c2185b; box-shadow: none; }
    .btn-add-cart:disabled {
        background-color: #bdbdbd !important;
        cursor: not-allowed;
        box-shadow: none;
    }
    
    .btn-icon { background-color: #fff; border: 1px solid #ccc; color: #777; width: 55px; height: 55px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.2s; }
    .btn-icon:hover { border-color: #f06292; color: #f06292; }
    .btn-favorite {
        width: 64px;
        height: 64px;
        border-width: 2px;
    }
    .btn-favorite i {
        font-size: 1.7rem !important;
    }
    .btn-favorite.active-favorite {
        color: #e53935;
        border-color: #ef9a9a;
        background: #fff5f5;
        box-shadow: 0 0 0 4px rgba(229, 57, 53, 0.14);
        animation: heartBeat 1.15s ease-in-out infinite;
    }
    .btn-favorite.active-favorite:hover {
        color: #d32f2f;
        border-color: #e57373;
        background: #ffecec;
    }
    .btn-favorite.pulse-once {
        animation: heartPop 280ms ease-out;
    }

    @keyframes heartBeat {
        0% { transform: scale(1); }
        12% { transform: scale(1.12); }
        24% { transform: scale(1); }
        36% { transform: scale(1.1); }
        50% { transform: scale(1); }
        100% { transform: scale(1); }
    }

    @keyframes heartPop {
        0% { transform: scale(1); }
        45% { transform: scale(1.25); }
        100% { transform: scale(1); }
    }

    .terms-link { color: #888; text-decoration: underline; font-size: 0.85rem; }
</style>

<div class="container" id="pdp-container" style="display: none; width: 90%; max-width: 1600px;">
    <div class="breadcrumb-nav">
        <a href="<?php echo BASE_URL; ?>index.php">Todos los productos</a> / <span id="bread-cat">Categoria</span> / <span id="bread-name" style="color: #999;">Cargando...</span>
    </div>

    <div class="row">
        <!-- Columna Izquierda: Galería -->
        <div class="col s12 m7">
            <div class="main-img-viewer" id="zoom-container">
                <img id="main-image" src="https://via.placeholder.com/600x600?text=Cargando" alt="Producto">
                <div class="nav-arrow prev" onclick="moveSlide(-1, event)"><i class="material-icons">chevron_left</i></div>
                <div class="nav-arrow next" onclick="moveSlide(1, event)"><i class="material-icons">chevron_right</i></div>
            </div>
            
            <div id="thumb-container" class="gallery-strip">
                <!-- Miniaturas dinámicas -->
            </div>
        </div>

        <!-- Columna Derecha: Info -->
        <div class="col s12 m5" style="padding-left: 50px;">
            <h1 id="product-title" class="product-title">Cargando...</h1>

            <!-- Leyenda Legal Estática (Siempre visible) -->
            <div class="legal-box">
                <i class="material-icons">report_problem</i>
                <span>Este producto no es un medicamento. El consumo de este producto es responsabilidad de quien lo recomienda y de quien lo usa.</span>
            </div>

            <!-- Recuadro para avisos dinámicos (Stock/Estado) -->
            <div class="warning-box" id="stock-warning-box" style="display: none;">
                <!-- Se llena vía JS -->
            </div>

            <div class="price-display">
                <span id="product-price">$ 0.00</span>
                <span id="status-badge-container"></span>
            </div>

            <div class="variant-selector" id="variant-section" style="display: none;">
                <label id="variant-label">Presentación</label>
                <div id="variant-pills-container" class="variant-pills"></div>
            </div>

            <div class="action-bar">
                <div class="qty-selector">
                    <button class="qty-btn" onclick="updateQty(-1)">-</button>
                    <input type="text" id="qty-input" class="qty-input" value="1" readonly>
                    <button class="qty-btn" onclick="updateQty(1)">+</button>
                </div>
                <button type="button" id="btn-add-cart" class="btn-add-cart">
                    <i class="material-icons" style="font-size: 1.1rem;">shopping_cart</i> Agregar al carrito
                </button>
                <button type="button" id="btn-favorite" class="btn-icon btn-favorite" onclick="toggleFavorite()" title="Agregar a favoritos">
                    <i class="material-icons">favorite_border</i>
                </button>
            </div>

            <a href="<?php echo BASE_URL; ?>views/terminos.php" class="terms-link">Términos y condiciones</a>
        </div>
    </div>

    <!-- SECCIÓN INFERIOR: DETALLES Y TABLA NUTRIMENTAL -->
    <div class="row" style="margin-top: 40px;">
        <div class="col s12">
            <ul class="tabs tabs-fixed-width z-depth-1" style="border-radius: 8px 8px 0 0; overflow: hidden;">
                <li class="tab"><a class="active blue-text text-darken-4" href="#tab-uso">MODO DE USO</a></li>
                <li class="tab"><a class="blue-text text-darken-4" href="#tab-ingredientes">INGREDIENTES</a></li>
            </ul>
            <div id="tab-uso" class="tab-content-box">
                <!-- Se llena vía JS -->
                Cargando modo de uso...
            </div>
            <div id="tab-ingredientes" class="tab-content-box">
                <!-- Se llena vía JS -->
                Cargando ingredientes...
            </div>
        </div>

        <div class="col s12">
            <h5 class="nutritional-title">Información Nutrimental</h5>
            <div class="card-panel z-depth-1" style="padding: 0; border-radius: 8px; overflow: hidden;">
                <table class="nutritional-table highlight">
                    <thead>
                        <tr>
                            <th>Nutriente / Información</th>
                            <th>Contenido por Porción</th>
                            <th>Por 100 g</th>
                        </tr>
                    </thead>
                    <tbody id="nutritional-body">
                        <!-- Se genera dinámicamente con JS -->
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
    const PDP_IS_AUTHENTICATED = <?php echo isAuthenticated() ? 'true' : 'false'; ?>;
    const PDP_DEFAULT_PRODUCT_IMAGE = '<?php echo BASE_URL; ?>assets/img/products/default-product.svg';
    const PDP_FAVORITES_API_URL = (typeof FAVORITES_API_URL !== 'undefined')
        ? FAVORITES_API_URL
        : '<?php echo BASE_URL; ?>api/favorites.php';

    let currentProduct = null;
    let qty = 1;
    
    // Variables para Galería
    let galleryImages = [];
    let currentSlide = 0;

    // Variables para el Zoom
    const zoomContainer = document.getElementById('zoom-container');
    const mainImg = document.getElementById('main-image');

    if (mainImg) {
        mainImg.addEventListener('error', () => {
            if (!Array.isArray(galleryImages) || galleryImages.length === 0) {
                mainImg.onerror = null;
                mainImg.src = PDP_DEFAULT_PRODUCT_IMAGE;
                return;
            }

            if (currentSlide < galleryImages.length - 1) {
                currentSlide += 1;
                updateGalleryUI();
                return;
            }

            mainImg.onerror = null;
            mainImg.src = PDP_DEFAULT_PRODUCT_IMAGE;
        });
    }

    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        const productId = urlParams.get('id');
        
        if (productId) {
            loadProductData(productId);
        } else {
            M.toast({html: 'Producto no especificado'});
            setTimeout(() => window.location.href = 'index.php', 2000);
        }

        document.getElementById('btn-add-cart').addEventListener('click', function(e) {
            e.preventDefault();
            if(currentProduct) {
                if (currentProduct.stock <= 0) {
                    M.toast({html: '❌ Producto agotado', classes: 'red rounded'});
                    return;
                }

                const stockDisponible = Math.max(0, parseInt(currentProduct.stock, 10) || 0);
                if (qty > stockDisponible) {
                    M.toast({html: 'Solo hay ' + stockDisponible + ' unidad(es) disponibles.', classes: 'orange darken-2 rounded'});
                    qty = Math.max(1, stockDisponible);
                    updateQtyDisplay();
                    return;
                }

                // Integración con tu carrito existente
                let cart = JSON.parse(localStorage.getItem('cart') || '[]');
                const existing = cart.find(item => item.id_producto === currentProduct.id_producto);
                if (existing) {
                    if ((existing.quantity + qty) > stockDisponible) {
                        M.toast({html: 'No puedes apartar más de ' + stockDisponible + ' unidad(es).', classes: 'orange darken-2 rounded'});
                        return;
                    }
                    existing.quantity += qty;
                } else {
                    cart.push({
                        id_producto: currentProduct.id_producto,
                        nombre: currentProduct.display_cart_name || currentProduct.nombre,
                        precio: currentProduct.precio_venta,
                        quantity: qty
                    });
                }
                localStorage.setItem('cart', JSON.stringify(cart));
                if (typeof updateCartBadge === 'function') updateCartBadge();
                M.toast({html: `¡${qty}x ${currentProduct.nombre} agregado!`, classes: 'rounded green'});
            }
        });

        // Lógica de seguimiento para el zoom
        zoomContainer.addEventListener('mousemove', (e) => {
            const { left, top, width, height } = zoomContainer.getBoundingClientRect();
            const x = ((e.pageX - left - window.scrollX) / width) * 100;
            const y = ((e.pageY - top - window.scrollY) / height) * 100;
            mainImg.style.transformOrigin = `${x}% ${y}%`;
        });

        zoomContainer.addEventListener('mouseleave', () => {
            mainImg.style.transformOrigin = 'center';
        });
    });

    function loadProductData(id) {
        fetch(`<?php echo BASE_URL; ?>api/product_detail.php?id=${id}`)
            .then(response => {
                if(!response.ok) throw new Error('Producto no encontrado');
                return response.json();
            })
            .then(data => {
                currentProduct = data;
                renderProduct(data);
                // Inicializar tabs de Materialize después de renderizar el contenido
                var tabs = document.querySelectorAll('.tabs');
                M.Tabs.init(tabs);
                checkIfFavorite(); // Revisar si el producto ya es favorito al cargar
                document.getElementById('pdp-container').style.display = 'block';
            })
            .catch(err => {
                console.error(err);
                M.toast({html: 'Error cargando producto', classes: 'red'});
            });
    }

    function renderProduct(product) {
        // Textos
        const fullName = product.display_cart_name || product.nombre;
        document.getElementById('product-title').textContent = fullName;
        document.getElementById('bread-name').textContent = fullName;
        document.getElementById('bread-cat').textContent = product.categoria || 'Catálogo';
        
        // Contenido de Pestañas (innerHTML para soportar saltos de línea de la DB)
        document.getElementById('tab-uso').innerHTML = (product.modo_uso || 'Consultar el empaque para instrucciones de uso.').replace(/\n/g, '<br>');
        document.getElementById('tab-ingredientes').innerHTML = (product.ingredientes || 'No especificados en la ficha técnica.').replace(/\n/g, '<br>');

        // Renderizar Tabla Nutrimental Dinámica
        const nutBody = document.getElementById('nutritional-body');
        const nutTitle = document.querySelector('.nutritional-title');
        const nutContainer = nutBody ? nutBody.closest('.col.s12') : null;
        
        if (nutBody) nutBody.innerHTML = '';

        if (product.mostrar_tabla == 1) {
            try {
                let nutData = typeof product.tabla_nutrimental === 'string' 
                    ? JSON.parse(product.tabla_nutrimental) 
                    : product.tabla_nutrimental;

                let list = [];
                // Normalizador inteligente de datos
                if (Array.isArray(nutData)) {
                    list = nutData.map(item => ({
                        label: item.label || item.name || item.nutrient || item.nutrient_name || '-',
                        porcion: item.porcion || item.portion || item.amount_per_serving || item.serving || '-',
                        total: item.total || item.amount_per_100g || '-'
                    }));
                } else if (nutData && nutData.rows && Array.isArray(nutData.rows)) {
                    // Caso para datos raw de la API de B-Life
                    list = nutData.rows.map(r => ({
                        label: (r[0]?.value || '-').replace(/\n/g, '<br>'),
                        porcion: (r[1]?.value || '-').replace(/\n/g, '<br>'),
                        total: (r[2]?.value || '-').replace(/\n/g, '<br>')
                    }));
                }

                if (list.length > 0 && nutBody) {
                    list.forEach(row => {
                        // Limpieza: No repetir ingredientes en la tabla si ya están arriba
                        const lbl = String(row.label).toLowerCase();
                        if (lbl.includes('ingredientes') || lbl.includes('modo de uso')) return;
                        
                        const tr = document.createElement('tr');
                        tr.innerHTML = `
                            <td class="row-label">${row.label}</td>
                            <td>${row.porcion}</td>
                            <td>${row.total || '-'}</td>
                        `;
                        nutBody.appendChild(tr);
                    });
                    if (nutTitle) nutTitle.style.display = 'block';
                    if (nutContainer) nutContainer.style.display = 'block';
                } else {
                    if (nutTitle) nutTitle.style.display = 'none';
                    if (nutContainer) nutContainer.style.display = 'none';
                }
            } catch (e) {
                console.error("Error procesando tabla nutrimental", e);
            }
        } else {
            if (nutTitle) nutTitle.style.display = 'none';
            if (nutContainer) nutContainer.style.display = 'none';
        }

        let priceHtml = `$ ${parseFloat(product.precio_venta).toFixed(2)}`;
        if (parseFloat(product.precio_comparacion) > 0) {
            priceHtml += ` <span class="grey-text" style="text-decoration: line-through; font-size: 1.2rem; margin-left: 15px;">$ ${parseFloat(product.precio_comparacion).toFixed(2)}</span>`;
        }
        document.getElementById('product-price').innerHTML = priceHtml;
        
        // Stock Badge
        const statusContainer = document.getElementById('status-badge-container');
        if (statusContainer) {
            statusContainer.innerHTML = product.stock > 0 
                ? '<span class="badge green white-text" style="float:none; margin-left:15px; border-radius:4px; padding:4px 8px;">Disponible</span>' 
                : '<span class="badge red white-text" style="float:none; margin-left:15px; border-radius:4px; padding:4px 8px;">Agotado</span>';
        }
        
        // Galería
        const thumbContainer = document.getElementById('thumb-container');
        thumbContainer.innerHTML = '';
        
        galleryImages = Array.isArray(product.galeria) && product.galeria.length > 0
            ? [...new Set(product.galeria.filter(Boolean))]
            : [PDP_DEFAULT_PRODUCT_IMAGE];
        currentSlide = 0;
        mainImg.src = galleryImages[0];

        galleryImages.forEach((imgSrc, index) => {
            const thumb = document.createElement('div');
            thumb.className = 'thumb-item' + (index === 0 ? ' active' : '');
            thumb.innerHTML = `<img src="${imgSrc}" onerror="this.onerror=null;this.src='${PDP_DEFAULT_PRODUCT_IMAGE}';">`;
            thumb.onclick = () => {
                currentSlide = index;
                updateGalleryUI();
                document.querySelectorAll('.thumb-item').forEach(t => t.classList.remove('active'));
                thumb.classList.add('active');
            };
            thumbContainer.appendChild(thumb);
        });

        // Stock Status
        const actionBtn = document.getElementById('btn-add-cart');
        const qtyContainer = document.querySelector('.qty-selector');
        const warningBox = document.getElementById('stock-warning-box');

        if (product.stock > 0) {
            actionBtn.disabled = false;
            actionBtn.classList.remove('grey');
            actionBtn.innerHTML = '<i class="material-icons">event</i> Reservar Miér/Sáb';
            if(qtyContainer) qtyContainer.style.display = 'flex';
            if(warningBox) warningBox.style.display = 'none';
            const stockDisponible = Math.max(1, parseInt(product.stock, 10) || 1);
            if (qty > stockDisponible) {
                qty = stockDisponible;
                updateQtyDisplay();
            }
        } else {
            actionBtn.disabled = true;
            actionBtn.classList.add('grey');
            actionBtn.innerHTML = 'Producto Agotado';
            if(qtyContainer) qtyContainer.style.display = 'none';
            if(warningBox) {
                warningBox.style.display = 'flex';
                warningBox.innerHTML = '<i class="material-icons">info</i> Temporalmente sin existencias. ¡Vuelve pronto!';
            }
        }

        // Variantes
        const variantSection = document.getElementById('variant-section');
        const pillsContainer = document.getElementById('variant-pills-container');
        
        if (product.variantes && product.variantes.length > 1) {
            variantSection.style.display = 'block';
            pillsContainer.innerHTML = '';
            
            product.variantes.forEach(v => {
                const pill = document.createElement('button');
                pill.type = 'button';
                pill.className = 'variant-pill' + (v.id_producto == product.id_producto ? ' active' : '');
                
                // Combinar valor (ej: 120) con unidad (ej: Cápsulas, Tomas, Porciones)
                let variantText = v.display_nombre_variante || v.nombre_variante || '';
                const unitText = v.display_unidad || v.unidad || '';
                
                // Si el nombre es solo un número o no contiene la unidad, la agregamos
                if (variantText && unitText && !variantText.toLowerCase().includes(unitText.toLowerCase())) {
                    variantText = `${variantText} ${unitText}`;
                } else if (!variantText) {
                    variantText = unitText || v.sku;
                }
                
                pill.textContent = variantText;
                
                pill.onclick = () => {
                    if (pill.classList.contains('active')) return;

                    // 1. Cambio visual inmediato (Pills)
                    document.querySelectorAll('.variant-pill').forEach(p => p.classList.remove('active'));
                    pill.classList.add('active');

                    // 2. Actualización de precio inmediata (sin esperar al server)
                    let priceHtml = `$ ${parseFloat(v.precio_venta).toFixed(2)}`;
                    if (parseFloat(v.precio_comparacion) > 0) {
                        priceHtml += ` <span class="grey-text" style="text-decoration: line-through; font-size: 1.2rem; margin-left: 15px;">$ ${parseFloat(v.precio_comparacion).toFixed(2)}</span>`;
                    }
                    document.getElementById('product-price').innerHTML = priceHtml;
                    document.getElementById('product-title').textContent = product.display_cart_name || `${product.nombre}${variantText ? ' | ' + variantText : ''}`;

                    // 3. Cambiar URL y cargar datos pesados (stock, galería, tabla)
                    const newUrl = window.location.protocol + "//" + window.location.host + window.location.pathname + '?id=' + v.id_producto;
                    window.history.pushState({path:newUrl}, '', newUrl);
                    
                    loadProductData(v.id_producto);
                    qty = 1;
                    updateQtyDisplay();
                };

                if (v.id_producto == product.id_producto) {
                    pill.classList.add('active');
                }
                pillsContainer.appendChild(pill);
            });
        } else {
            variantSection.style.display = 'none';
        }
    }

    function moveSlide(direction, event) {
        if(event) event.stopPropagation();
        if(galleryImages.length <= 1) return;

        currentSlide += direction;
        if (currentSlide >= galleryImages.length) currentSlide = 0;
        if (currentSlide < 0) currentSlide = galleryImages.length - 1;
        
        updateGalleryUI();
    }

    function updateGalleryUI() {
        mainImg.src = galleryImages[currentSlide] || PDP_DEFAULT_PRODUCT_IMAGE;
        
        // Actualizar miniaturas
        const thumbs = document.querySelectorAll('.thumb-item');
        thumbs.forEach((t, i) => {
            if (i === currentSlide) t.classList.add('active');
            else t.classList.remove('active');
        });
    }
    function updateQty(change) {
        const newVal = qty + change;
        if (newVal >= 1) {
            const stockDisponible = currentProduct ? Math.max(0, parseInt(currentProduct.stock, 10) || 0) : 0;
            if (stockDisponible > 0 && newVal > stockDisponible) {
                M.toast({html: 'Stock máximo disponible: ' + stockDisponible, classes: 'orange darken-2 rounded'});
                return;
            }
            qty = newVal;
            updateQtyDisplay();
        }
    }

    function updateQtyDisplay() {
        document.getElementById('qty-input').value = qty;
    }

    function applyFavoriteVisualState(isFav) {
        const favoriteBtn = document.getElementById('btn-favorite');
        const favoriteIcon = document.querySelector('#btn-favorite i');

        if (favoriteIcon) {
            favoriteIcon.textContent = isFav ? 'favorite' : 'favorite_border';
        }
        if (favoriteBtn) {
            favoriteBtn.classList.toggle('active-favorite', isFav);
        }
    }

    async function toggleFavorite() {
        if (!currentProduct) return;

        if (!PDP_IS_AUTHENTICATED) {
            M.toast({html: 'Inicia sesion para guardar favoritos', classes: 'orange rounded'});
            setTimeout(() => {
                window.location.href = '<?php echo BASE_URL; ?>views/login.php';
            }, 600);
            return;
        }

        const favoriteBtn = document.getElementById('btn-favorite');
        if (favoriteBtn) favoriteBtn.disabled = true;

        try {
            const response = await fetch(PDP_FAVORITES_API_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'toggle',
                    id_producto: currentProduct.id_producto
                })
            });

            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'No fue posible actualizar favoritos');
            }

            const isFav = !!data.is_favorite;
            applyFavoriteVisualState(isFav);

            if (isFav && favoriteBtn) {
                favoriteBtn.classList.remove('pulse-once');
                void favoriteBtn.offsetWidth;
                favoriteBtn.classList.add('pulse-once');
                setTimeout(() => favoriteBtn.classList.remove('pulse-once'), 320);
            }

            if (typeof updateFavoritesBadge === 'function') {
                updateFavoritesBadge(data.count);
            }

            M.toast({
                html: isFav ? 'Guardado en tus favoritos' : 'Eliminado de tus favoritos',
                classes: isFav ? 'green rounded' : 'orange rounded'
            });
        } catch (error) {
            console.error(error);
            M.toast({html: 'No se pudo actualizar favoritos', classes: 'red rounded'});
        } finally {
            if (favoriteBtn) favoriteBtn.disabled = false;
        }
    }

    async function checkIfFavorite() {
        if (!currentProduct) return;

        if (!PDP_IS_AUTHENTICATED) {
            applyFavoriteVisualState(false);
            return;
        }

        try {
            const response = await fetch(`${PDP_FAVORITES_API_URL}?mode=status&id_producto=${encodeURIComponent(currentProduct.id_producto)}`);
            const data = await response.json();

            if (!response.ok || !data.success) {
                throw new Error(data.error || 'No fue posible consultar favoritos');
            }

            applyFavoriteVisualState(!!data.is_favorite);
            if (typeof updateFavoritesBadge === 'function') {
                updateFavoritesBadge(data.count);
            }
        } catch (error) {
            console.error(error);
            applyFavoriteVisualState(false);
        }
    }
</script>

<?php include __DIR__ . '/views/includes/footer.php'; ?>
