<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/auth.php';

$pageTitle = 'Mis Favoritos';
include __DIR__ . '/views/includes/header.php';
?>

<div class="container" style="margin-top: 30px; margin-bottom: 50px;">
    <div class="row">
        <div class="col s12">
            <h4><i class="material-icons left red-text">favorite</i> Mis Productos Favoritos</h4>
            <p class="grey-text">Aquí encontrarás los productos que has guardado para ver más tarde.</p>
        </div>
    </div>

    <div class="row" id="favorites-list">
        <!-- Los productos favoritos se renderizarán aquí con JavaScript -->
    </div>

    <div id="no-favorites" class="center-align" style="display: none; padding: 50px;">
        <i class="material-icons large grey-text text-lighten-2">favorite_border</i>
        <h5 class="grey-text">Tu lista de favoritos está vacía.</h5>
        <p>Haz clic en el corazón de los productos que te gusten para guardarlos aquí.</p>
        <a href="<?php echo BASE_URL; ?>views/catalogo.php" class="btn-large blue darken-4 waves-effect waves-light" style="margin-top: 20px;">Explorar Catálogo</a>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    renderFavorites();
});

function getFavorites() {
    return JSON.parse(localStorage.getItem('favorites') || '[]');
}

function renderFavorites() {
    const favorites = getFavorites();
    const container = document.getElementById('favorites-list');
    const noFavoritesMessage = document.getElementById('no-favorites');

    if (favorites.length === 0) {
        container.innerHTML = '';
        noFavoritesMessage.style.display = 'block';
        return;
    }

    noFavoritesMessage.style.display = 'none';
    container.innerHTML = ''; // Limpiar antes de renderizar

    favorites.forEach((item, index) => {
        const detailUrl = `<?php echo BASE_URL; ?>product_detail.php?id=${item.id_producto}`;
        const imgSrc = item.imagen || '<?php echo BASE_URL; ?>assets/img/products/default-product.svg';

        const cardHtml = `
            <div class="col s12 m6 l4">
                <div class="card hoverable" style="border-radius: 8px; overflow: hidden;">
                    <div class="card-image">
                        <a href="${detailUrl}">
                            <img src="${imgSrc}" style="height: 220px; object-fit: contain; background: #f9f9f9;">
                        </a>
                    </div>
                    <div class="card-content" style="padding-bottom: 10px;">
                        <a href="${detailUrl}" class="black-text">
                            <span class="card-title truncate" style="font-size: 1.1rem; font-weight: bold;">${item.nombre}</span>
                        </a>
                        <p class="blue-text text-darken-4" style="font-size: 1.4rem; margin: 10px 0;">
                            $${parseFloat(item.precio).toFixed(2)}
                        </p>
                    </div>
                    <div class="card-action" style="display: flex; justify-content: space-between; align-items: center;">
                        <button class="btn-flat red-text" onclick="removeFromFavorites(${index})">
                            <i class="material-icons left">delete</i>Quitar
                        </button>
                        <button class="btn blue darken-4 waves-effect waves-light" onclick="addToCartFromFav(${item.id_producto}, '${item.nombre.replace(/'/g, "\\'")}', ${item.precio})">
                            <i class="material-icons">add_shopping_cart</i>
                        </button>
                    </div>
                </div>
            </div>
        `;
        container.innerHTML += cardHtml;
    });
}

function removeFromFavorites(index) {
    let favorites = getFavorites();
    favorites.splice(index, 1);
    localStorage.setItem('favorites', JSON.stringify(favorites));
    
    if (typeof updateFavoritesBadge === 'function') {
        updateFavoritesBadge();
    }
    
    renderFavorites();
    M.toast({html: 'Producto eliminado de favoritos', classes: 'orange rounded'});
}

function addToCartFromFav(id, nombre, precio) {
    let cart = JSON.parse(localStorage.getItem('cart') || '[]');
    let item = cart.find(i => i.id_producto === id);

    if (item) {
        item.quantity = (parseInt(item.quantity) || 0) + 1;
    } else {
        cart.push({
            id_producto: id,
            nombre: nombre,
            precio: precio,
            quantity: 1
        });
    }

    localStorage.setItem('cart', JSON.stringify(cart));
    M.toast({html: 'Añadido al carrito', classes: 'green rounded'});

    if (typeof updateCartBadge === 'function') {
        updateCartBadge();
    }
}

</script>

<?php include __DIR__ . '/views/includes/footer.php'; ?>