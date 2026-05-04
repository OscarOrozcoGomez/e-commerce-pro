<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/auth.php';

requireAuth();

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

<?php
include __DIR__ . '/../views/includes/footer.php';
?>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('search-input');
            const productsContainer = document.getElementById('products-container');

            function loadProducts(search = '') {
                fetch('../api/products.php?search=' + encodeURIComponent(search))
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

                const stockBadge = document.createElement('span');
                stockBadge.className = `badge white-text ${product.stock > 0 ? 'green' : 'red'}`;
                stockBadge.textContent = product.stock > 0 ? `Stock: ${product.stock}` : 'Sin stock';
                stockBadge.style.cssText = 'position: absolute; top: 10px; right: 10px;';

                cardImage.appendChild(img);
                cardImage.appendChild(stockBadge);

                const cardContent = document.createElement('div');
                cardContent.className = 'card-content';

                const title = document.createElement('span');
                title.className = 'card-title';
                title.textContent = product.nombre;

                const sku = document.createElement('p');
                sku.textContent = `SKU: ${product.sku}`;

                const price = document.createElement('p');
                price.className = 'green-text';
                price.textContent = `Precio: $${product.precio_venta}`;

                cardContent.appendChild(title);
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

            function addToCart(productId) {
                // Implementar lógica del carrito aquí
                M.toast({html: 'Producto agregado al carrito'});
            }

            // Cargar productos iniciales
            loadProducts();

            // Buscar en tiempo real
            let searchTimeout;
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(() => {
                    loadProducts(this.value);
                }, 300);
            });
        });
    </script>

<?php include __DIR__ . '/views/includes/footer.php'; ?>