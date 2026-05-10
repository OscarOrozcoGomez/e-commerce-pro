<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/auth.php';

$pageTitle = 'Detalles del Producto';
include __DIR__ . '/views/includes/header.php';
?>

<style>
    .breadcrumb-nav { padding: 15px 0; color: #777; font-size: 0.9rem; }
    .breadcrumb-nav a { color: #f06292; } /* Rosado suave del Odoo theme */
    
    .gallery-main { width: 100%; height: 500px; object-fit: contain; background: #fdfdfd; border-radius: 4px; border: 1px solid #eee; margin-bottom: 10px; }
    .gallery-thumbs { display: flex; gap: 10px; overflow-x: auto; padding-bottom: 5px; }
    .gallery-thumbs img { width: 70px; height: 70px; object-fit: contain; border: 1px solid #ddd; cursor: pointer; border-radius: 4px; opacity: 0.6; transition: 0.2s; }
    .gallery-thumbs img:hover, .gallery-thumbs img.active { opacity: 1; border-color: #f06292; }

    .product-title { font-size: 2rem; font-weight: 500; margin-top: 0; color: #333; line-height: 1.2; }
    .ingredients-text { color: #777; font-size: 0.95rem; margin-bottom: 20px; }
    
    .warning-box { background-color: #fef0c7; color: #9c6c0e; padding: 15px; border-radius: 4px; display: flex; align-items: flex-start; gap: 10px; font-size: 0.85rem; font-weight: 500; margin-bottom: 30px; border: 1px solid #fde093; }
    .warning-box i { font-size: 1.2rem; color: #d97706; }

    .price-display { font-size: 2.2rem; font-weight: 400; color: #212121; margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 20px; }
    
    .variant-selector { margin-bottom: 30px; }
    .variant-selector label { font-size: 0.9rem; color: #555; display: block; margin-bottom: 5px; }
    .variant-select { display: block; width: 100%; max-width: 400px; padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 1rem; color: #333; outline: none; }
    .variant-select:focus { border-color: #f06292; }

    .action-bar { border-bottom: 1px solid #eee; padding-bottom: 30px; margin-bottom: 20px; display: flex; align-items: center; gap: 15px; flex-wrap: wrap; }
    
    .qty-selector { display: flex; align-items: center; border: 1px solid #ccc; border-radius: 20px; overflow: hidden; height: 40px; background: #fff; }
    .qty-btn { background: none; border: none; padding: 0 15px; font-size: 1.2rem; cursor: pointer; color: #555; outline: none; }
    .qty-btn:hover { background: #f5f5f5; }
    .qty-input { width: 40px; border: none !important; text-align: center; margin: 0 !important; height: 40px !important; font-size: 1rem; box-shadow: none !important; pointer-events: none; }
    
    .btn-add-cart { background-color: #fce4ec; color: #c2185b; box-shadow: none; font-weight: 600; border-radius: 20px; text-transform: none; padding: 0 24px; height: 40px; display: flex; align-items: center; gap: 8px; border: 1px solid #f8bbd0; }
    .btn-add-cart:hover { background-color: #f8bbd0; color: #c2185b; box-shadow: none; }
    
    .btn-icon { background-color: #fff; border: 1px solid #ccc; color: #777; width: 40px; height: 40px; border-radius: 50%; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.2s; }
    .btn-icon:hover { border-color: #f06292; color: #f06292; }

    .terms-link { color: #888; text-decoration: underline; font-size: 0.85rem; }

    .specs-section { margin-top: 50px; padding-top: 30px; border-top: 1px solid #eee; }
    .specs-title { font-size: 1.5rem; font-weight: 500; margin-bottom: 20px; color: #333; }
    .specs-row { display: flex; padding: 12px 0; border-bottom: 1px solid #f5f5f5; font-size: 0.95rem; }
    .specs-label { width: 30%; color: #666; }
    .specs-value { width: 70%; color: #999; }
</style>

<div class="container" id="pdp-container" style="display: none;">
    <div class="breadcrumb-nav">
        <a href="<?php echo BASE_URL; ?>index.php">Todos los productos</a> / <span id="bread-cat">Categoria</span> / <span id="bread-name" style="color: #999;">Cargando...</span>
    </div>

    <div class="row">
        <!-- Columna Izquierda: Galería -->
        <div class="col s12 m6">
            <img id="main-image" src="https://via.placeholder.com/600x600?text=Cargando" class="gallery-main" alt="Producto">
            <div id="thumb-container" class="gallery-thumbs">
                <!-- Miniaturas dinámicas -->
            </div>
        </div>

        <!-- Columna Derecha: Info -->
        <div class="col s12 m6" style="padding-left: 30px;">
            <h1 id="product-title" class="product-title">Cargando...</h1>
            <div class="ingredients-text">
                <p style="margin: 0;"><strong>Ingredientes:</strong></p>
                <p style="margin: 5px 0 0 0;" id="product-desc">No especificados.</p>
            </div>

            <div class="warning-box">
                <i class="material-icons">warning</i>
                <span>Este producto no es un medicamento. El consumo de este producto es responsabilidad de quien lo recomienda y de quien lo usa.</span>
            </div>

            <div class="price-display">
                <span id="product-price">$ 0.00</span>
                <span id="status-badge-container"></span>
            </div>

            <div class="variant-selector" id="variant-section" style="display: none;">
                <label>Tamaño</label>
                <select id="variant-select" class="variant-select browser-default">
                    <!-- Opciones dinámicas -->
                </select>
            </div>

            <div class="action-bar">
                <div class="qty-selector">
                    <button class="qty-btn" onclick="updateQty(-1)">-</button>
                    <input type="text" id="qty-input" class="qty-input" value="1" readonly>
                    <button class="qty-btn" onclick="updateQty(1)">+</button>
                </div>
                <a href="#" id="btn-add-cart" class="btn btn-add-cart">
                    <i class="material-icons" style="font-size: 1.1rem;">shopping_cart</i> Agregar al carrito
                </a>
                <div class="btn-icon"><i class="material-icons" style="font-size: 1.2rem;">favorite_border</i></div>
                <div class="btn-icon"><i class="material-icons" style="font-size: 1.2rem;">compare_arrows</i></div>
            </div>

            <a href="#" class="terms-link">Términos y condiciones</a>
        </div>
    </div>

    <!-- Especificaciones -->
    <div class="specs-section">
        <h3 class="specs-title">Especificaciones</h3>
        <div class="specs-row">
            <div class="specs-label">Tamaño disponibles</div>
            <div class="specs-value" id="spec-sizes">-</div>
        </div>
        <div class="specs-row">
            <div class="specs-label">SKU</div>
            <div class="specs-value" id="spec-sku">-</div>
        </div>
        <div class="specs-row">
            <div class="specs-label">Categoría</div>
            <div class="specs-value" id="spec-cat">-</div>
        </div>
    </div>
</div>

<script>
    let currentProduct = null;
    let qty = 1;

    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        const productId = urlParams.get('id');
        
        if (productId) {
            loadProductData(productId);
        } else {
            M.toast({html: 'Producto no especificado'});
            setTimeout(() => window.location.href = 'index.php', 2000);
        }

        document.getElementById('variant-select').addEventListener('change', function() {
            const newId = this.value;
            // Cambiar URL sin recargar
            const newUrl = window.location.protocol + "//" + window.location.host + window.location.pathname + '?id=' + newId;
            window.history.pushState({path:newUrl}, '', newUrl);
            loadProductData(newId);
            qty = 1;
            updateQtyDisplay();
        });

        document.getElementById('btn-add-cart').addEventListener('click', function(e) {
            e.preventDefault();
            if(currentProduct) {
                // Integración con tu carrito existente
                let cart = JSON.parse(localStorage.getItem('cart') || '[]');
                const existing = cart.find(item => item.id_producto === currentProduct.id_producto);
                if (existing) {
                    existing.quantity += qty;
                } else {
                    cart.push({
                        id_producto: currentProduct.id_producto,
                        nombre: currentProduct.nombre + (currentProduct.nombre_variante ? ` (${currentProduct.nombre_variante})` : ''),
                        precio: currentProduct.precio_venta,
                        quantity: qty
                    });
                }
                localStorage.setItem('cart', JSON.stringify(cart));
                if (typeof updateCartBadge === 'function') updateCartBadge();
                M.toast({html: `¡${qty}x ${currentProduct.nombre} agregado!`, classes: 'rounded green'});
            }
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
                document.getElementById('pdp-container').style.display = 'block';
            })
            .catch(err => {
                console.error(err);
                M.toast({html: 'Error cargando producto', classes: 'red'});
            });
    }

    function renderProduct(product) {
        // Textos
        const fullName = product.nombre_variante ? `${product.nombre} | ${product.nombre_variante}` : product.nombre;
        document.getElementById('product-title').textContent = fullName;
        document.getElementById('bread-name').textContent = fullName;
        document.getElementById('bread-cat').textContent = product.categoria || 'Catálogo';
        document.getElementById('product-desc').textContent = product.descripcion || 'Sin descripción detallada. (Puede requerir actualización en Odoo)';
        document.getElementById('product-price').textContent = `$ ${parseFloat(product.precio_venta).toFixed(2)}`;
        
        // Stock Badge
        const statusContainer = document.getElementById('status-badge-container');
        if (statusContainer) {
            statusContainer.innerHTML = product.stock > 0 
                ? '<span class="badge green white-text" style="float:none; margin-left:15px; border-radius:4px; padding:4px 8px;">Disponible</span>' 
                : '<span class="badge red white-text" style="float:none; margin-left:15px; border-radius:4px; padding:4px 8px;">Agotado</span>';
        }
        
        // Especificaciones
        document.getElementById('spec-sku').textContent = product.sku;
        document.getElementById('spec-cat').textContent = product.categoria || '-';

        // Galería
        const mainImg = document.getElementById('main-image');
        const thumbContainer = document.getElementById('thumb-container');
        thumbContainer.innerHTML = '';
        
        const images = product.galeria && product.galeria.length > 0 ? product.galeria : ['https://via.placeholder.com/600x600?text=Sin+Imagen'];
        mainImg.src = images[0];

        if (images.length > 1) {
            images.forEach((imgSrc, index) => {
                const thumb = document.createElement('img');
                thumb.src = imgSrc;
                if (index === 0) thumb.className = 'active';
                thumb.onclick = () => {
                    mainImg.src = imgSrc;
                    document.querySelectorAll('.gallery-thumbs img').forEach(i => i.classList.remove('active'));
                    thumb.classList.add('active');
                };
                thumbContainer.appendChild(thumb);
            });
        }

        // Stock Status
        const actionBtn = document.getElementById('btn-add-cart');
        const qtyContainer = document.querySelector('.qty-selector');
        const warningBox = document.querySelector('.warning-box');

        if (product.stock > 0) {
            actionBtn.disabled = false;
            actionBtn.classList.remove('grey');
            actionBtn.innerHTML = '<i class="material-icons">event</i> Reservar Miér/Sáb';
            if(qtyContainer) qtyContainer.style.display = 'flex';
            if(warningBox) warningBox.style.display = 'none';
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
        const variantSelect = document.getElementById('variant-select');
        
        if (product.variantes && product.variantes.length > 1) {
            variantSection.style.display = 'block';
            variantSelect.innerHTML = '';
            
            let sizes = [];
            product.variantes.forEach(v => {
                const opt = document.createElement('option');
                opt.value = v.id_producto;
                // Si este es el producto actual, calcular diferencia de precio
                let priceText = `$ ${parseFloat(v.precio_venta).toFixed(2)}`;
                opt.textContent = `${v.nombre_variante || v.sku} (${priceText})`;
                if (v.id_producto == product.id_producto) {
                    opt.selected = true;
                }
                variantSelect.appendChild(opt);
                sizes.push(v.nombre_variante || 'Normal');
            });
            document.getElementById('spec-sizes').textContent = sizes.join(', ');
        } else {
            variantSection.style.display = 'none';
            document.getElementById('spec-sizes').textContent = product.nombre_variante || 'Única';
        }
    }

    function updateQty(change) {
        const newVal = qty + change;
        if (newVal >= 1) {
            qty = newVal;
            updateQtyDisplay();
        }
    }

    function updateQtyDisplay() {
        document.getElementById('qty-input').value = qty;
    }
</script>

<?php include __DIR__ . '/views/includes/footer.php'; ?>
