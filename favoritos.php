<?php
require_once __DIR__ . '/core/config.php';
require_once __DIR__ . '/core/auth.php';

requireAuth();

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

const FAVORITES_API_URL_PAGE = (typeof FAVORITES_API_URL !== 'undefined')
    ? FAVORITES_API_URL
    : '<?php echo BASE_URL; ?>api/favorites.php';

async function renderFavorites() {
    const container = document.getElementById('favorites-list');
    const noFavoritesMessage = document.getElementById('no-favorites');

    let favorites = [];
    try {
        const response = await fetch(`${FAVORITES_API_URL_PAGE}?mode=list`);
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.error || 'No fue posible cargar favoritos');
        }
        favorites = Array.isArray(data.items) ? data.items : [];
    } catch (error) {
        console.error(error);
        M.toast({html: 'No se pudieron cargar tus favoritos', classes: 'red rounded'});
    }

    if (favorites.length === 0) {
        container.innerHTML = '';
        noFavoritesMessage.style.display = 'block';
        if (typeof updateFavoritesBadge === 'function') {
            updateFavoritesBadge(0);
        }
        return;
    }

    noFavoritesMessage.style.display = 'none';
    container.innerHTML = ''; // Limpiar antes de renderizar

    favorites.forEach((item) => {
        const detailUrl = `<?php echo BASE_URL; ?>product_detail.php?id=${item.id_producto}`;
        const imgSrc = item.imagen || '<?php echo getDefaultProductImageUrl(); ?>';
        const safeName = String(item.nombre || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");
        const safePrice = parseFloat(item.precio || 0);

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
                            $${safePrice.toFixed(2)}
                        </p>
                    </div>
                    <div class="card-action" style="display: flex; justify-content: space-between; align-items: center;">
                        <button class="btn-flat red-text" onclick="removeFromFavorites(${item.id_producto})">
                            <i class="material-icons left">delete</i>Quitar
                        </button>
                        <button class="btn blue darken-4 waves-effect waves-light" onclick="addToCartFromFav(${item.id_producto}, '${safeName}', ${safePrice})">
                            <i class="material-icons">add_shopping_cart</i>
                        </button>
                    </div>
                </div>
            </div>
        `;
        container.innerHTML += cardHtml;
    });

    if (typeof updateFavoritesBadge === 'function') {
        updateFavoritesBadge(favorites.length);
    }
}

async function removeFromFavorites(productId) {
    try {
        const response = await fetch(FAVORITES_API_URL_PAGE, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'remove',
                id_producto: productId
            })
        });
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.error || 'No fue posible eliminar favorito');
        }

        if (typeof updateFavoritesBadge === 'function') {
            updateFavoritesBadge(data.count);
        }

        await renderFavorites();
        M.toast({html: 'Producto eliminado de favoritos', classes: 'orange rounded'});
    } catch (error) {
        console.error(error);
        M.toast({html: 'No se pudo eliminar el favorito', classes: 'red rounded'});
    }
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