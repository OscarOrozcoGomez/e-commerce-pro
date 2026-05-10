<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/auth.php';

$pageTitle = 'Catálogo de Productos';
include __DIR__ . '/views/includes/header.php';
?>

    <div class="container">
        <div class="row search-container">
            <div class="col s12">
                <div class="input-field">
                    <i class="material-icons prefix">search</i>
                    <input type="text" id="search-input" placeholder="Buscar por nombre o SKU...">
                    <label for="search-input">Buscar productos</label>
                </div>
            </div>
        </div>

        <div id="products-container" class="row">
            <!-- Los productos se cargarán aquí vía AJAX -->
        </div>
    </div>

    <style>
        #products-container {
            display: flex;
            flex-wrap: wrap;
        }
        .product-card {
            height: 100%;
            display: flex;
            flex-direction: column;
            margin: 1rem 0.5rem;
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            border-radius: 8px;
        }
        .product-card:hover {
            transform: translateY(-8px);
            box-shadow: 0 12px 20px rgba(0,0,0,0.1);
        }
        .product-card .card-content {
            flex-grow: 1;
        }
        .product-card .card-image {
            height: 200px;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f5f5f5;
        }
        .product-card .product-image {
            max-height: 100%;
            width: auto;
            object-fit: contain;
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('search-input');
            const productsContainer = document.getElementById('products-container');

            function loadProducts(search = '') {
                fetch('<?php echo BASE_URL; ?>api/products.php?search=' + encodeURIComponent(search))
                    .then(response => response.json())
                    .then(data => {
                        productsContainer.innerHTML = '';
                        if (data.products && data.products.length > 0) {
                            data.products.forEach(product => {
                                const card = createProductCard(product);
                                productsContainer.appendChild(card);
                            });
                        } else {
                            productsContainer.innerHTML = '<div class="col s12"><p class="center-align">No se encontraron productos.</p></div>';
                        }
                        
                        // Restaurar posición del scroll si existe
                        const savedScroll = sessionStorage.getItem('catalogScroll');
                        if (savedScroll && search === '') { // Solo restaurar si no estamos en una búsqueda activa
                            // Pequeño timeout para permitir que el DOM se dibuje
                            setTimeout(() => {
                                window.scrollTo(0, parseInt(savedScroll));
                                sessionStorage.removeItem('catalogScroll');
                            }, 50);
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        productsContainer.innerHTML = '<div class="col s12"><p class="center-align red-text">Error al cargar productos.</p></div>';
                    });
            }

            function createProductCard(product) {
                const col = document.createElement('div');
                col.className = 'col s12 m6 l4';

                const card = document.createElement('div');
                card.className = 'card product-card';

                const cardImage = document.createElement('div');
                cardImage.className = 'card-image';

                const img = document.createElement('img');
                img.src = product.imagen || 'https://via.placeholder.com/300x150?text=Sin+Imagen';
                img.className = 'product-image';
                img.alt = product.nombre;

                const imgLink = document.createElement('a');
                imgLink.href = `product_detail.php?id=${product.id_producto}`;
                imgLink.onclick = () => saveScrollPosition();
                imgLink.style.display = 'flex';
                imgLink.style.alignItems = 'center';
                imgLink.style.justifyContent = 'center';
                imgLink.style.height = '100%';
                imgLink.appendChild(img);

                const stockBadge = document.createElement('span');
                stockBadge.className = `badge white-text ${product.stock > 0 ? 'green' : 'red'}`;
                stockBadge.textContent = product.stock > 0 ? `Stock: ${product.stock}` : 'Sin stock';
                stockBadge.style.cssText = 'position: absolute; top: 10px; right: 10px;';

                cardImage.appendChild(imgLink);
                cardImage.appendChild(stockBadge);

                const cardContent = document.createElement('div');
                cardContent.className = 'card-content';

                const titleLink = document.createElement('a');
                titleLink.href = `product_detail.php?id=${product.id_producto}`;
                titleLink.onclick = () => saveScrollPosition();
                titleLink.className = 'card-title black-text';
                titleLink.style.display = 'block';
                titleLink.style.fontWeight = '500';
                titleLink.style.marginBottom = '10px';
                titleLink.textContent = product.nombre;

                const sku = document.createElement('p');
                sku.className = 'grey-text text-darken-1';
                sku.style.fontSize = '0.9rem';
                sku.textContent = `SKU: ${product.sku}`;

                const price = document.createElement('p');
                price.className = 'green-text text-darken-2';
                price.style.fontSize = '1.2rem';
                price.style.fontWeight = 'bold';
                price.textContent = `$${parseFloat(product.precio_venta).toFixed(2)}`;

                cardContent.appendChild(titleLink);
                cardContent.appendChild(sku);
                cardContent.appendChild(price);

                const cardAction = document.createElement('div');
                cardAction.className = 'card-action';

                const addButton = document.createElement('a');
                addButton.href = '#';
                addButton.className = 'btn-small blue waves-effect waves-light';
                addButton.textContent = 'Agregar al carrito';
                addButton.onclick = () => addToCart(product.id_producto);

                cardAction.appendChild(addButton);

                card.appendChild(cardImage);
                card.appendChild(cardContent);
                card.appendChild(cardAction);

                col.appendChild(card);
                return col;
            }

            window.addToCart = function(product) {
                let cart = JSON.parse(localStorage.getItem('cart') || '[]');
                
                // Verificar si ya existe para aumentar cantidad
                const existing = cart.find(item => item.id_producto === product.id_producto);
                if (existing) {
                    existing.quantity += 1;
                } else {
                    cart.push({
                        id_producto: product.id_producto,
                        nombre: product.nombre,
                        precio: product.precio_venta,
                        quantity: 1
                    });
                }
                
                localStorage.setItem('cart', JSON.stringify(cart));
                if (typeof updateCartBadge === 'function') updateCartBadge();
                M.toast({html: '¡' + product.nombre + ' agregado!', classes: 'rounded'});
            }

            // Cargar productos iniciales
            loadProducts();

            // Buscar en tiempo real
            let searchTimeout;
            searchInput.addEventListener('input', function() {
                const query = this.value; // capturar antes del timeout para no perder el contexto
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    loadProducts(query);
                }, 300);
            });
        });



        window.saveScrollPosition = function() {
            sessionStorage.setItem('catalogScroll', window.scrollY);
        };
    </script>
<?php include __DIR__ . '/views/includes/footer.php'; ?>