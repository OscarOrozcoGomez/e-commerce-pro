<?php
/**
 * Burbuja flotante de carrito: solo se incluye en catalogo.php y
 * product_detail.php (no en header.php, que es sitio-wide). Reutiliza las
 * clases .cart-mini-dropdown-items / .cart-mini-total-amount que ya rellena
 * renderCartMiniDropdown() en header.php, asi que no hay que duplicar el
 * render del contenido: solo el bubble + el panel que se expande.
 */
?>
<div class="floating-cart-widget">
    <button type="button" class="floating-cart-bubble" onclick="toggleFloatingCartPanel()" title="Ver carrito">
        <i class="material-icons">shopping_cart</i>
        <span class="floating-cart-bubble-badge">0</span>
    </button>
    <div class="floating-cart-panel">
        <div class="cart-mini-dropdown-header">
            <span>Mi Carrito</span>
            <i class="material-icons" style="cursor:pointer; font-size:20px;" onclick="toggleFloatingCartPanel()">close</i>
        </div>
        <div class="cart-mini-dropdown-items"></div>
        <div class="cart-mini-dropdown-footer">
            <div class="cart-mini-total">
                <span>Total:</span>
                <span>$<span class="cart-mini-total-amount">0.00</span></span>
            </div>
            <a href="<?php echo BASE_URL; ?>views/cart.php" class="btn blue darken-4 waves-effect waves-light" style="width:100%;">Ver carrito completo</a>
        </div>
    </div>
</div>

<style>
    /* bottom se deja alto a proposito: footer.php ya tiene un boton flotante
       "Ir arriba" (#scroll-to-top) en la misma esquina inferior derecha
       (bottom:30px/20px en movil, ~56px/45px de alto) -- si esta burbuja se
       pone mas abajo de esa cota, se encima con el al hacer scroll. */
    .floating-cart-widget {
        position: fixed;
        bottom: 104px;
        right: 24px;
        z-index: 998;
    }
    .floating-cart-bubble {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        background: #01579b;
        color: #fff;
        border: none;
        box-shadow: 0 4px 14px rgba(0, 0, 0, 0.3);
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        position: relative;
        transition: transform 0.15s ease;
        -webkit-tap-highlight-color: transparent;
    }
    .floating-cart-bubble:hover {
        transform: scale(1.06);
    }
    .floating-cart-bubble i {
        font-size: 28px;
    }
    .floating-cart-bubble-badge {
        position: absolute;
        top: -4px;
        right: -4px;
        background: #e53935;
        color: #fff;
        border-radius: 50%;
        min-width: 20px;
        height: 20px;
        line-height: 20px;
        font-size: 0.72rem;
        font-weight: bold;
        text-align: center;
        padding: 0 4px;
        display: none;
    }
    .floating-cart-panel {
        display: none;
        flex-direction: column;
        position: absolute;
        bottom: 72px;
        right: 0;
        width: 320px;
        max-width: calc(100vw - 40px);
        max-height: min(460px, calc(100vh - 190px));
        background: #fff;
        color: #333;
        border-radius: 8px;
        box-shadow: 0 6px 24px rgba(0, 0, 0, 0.3);
        text-align: left;
    }
    .floating-cart-panel.active {
        display: flex;
    }
    /* La lista interna es la que hace scroll; header y footer del panel
       quedan siempre visibles (mas importante aun en pantallas cortas). */
    .floating-cart-panel .cart-mini-dropdown-items {
        flex: 1 1 auto;
        max-height: none;
    }
    .floating-cart-panel .cart-mini-dropdown-header,
    .floating-cart-panel .cart-mini-dropdown-footer {
        flex-shrink: 0;
    }
    @media (max-width: 600px) {
        .floating-cart-widget {
            bottom: 84px;
            right: 16px;
        }
        .floating-cart-bubble {
            width: 52px;
            height: 52px;
        }
        .floating-cart-bubble i {
            font-size: 24px;
        }
        .floating-cart-panel {
            width: calc(100vw - 32px);
            max-height: calc(100vh - 160px);
            bottom: 64px;
        }
    }
</style>

<script>
    function toggleFloatingCartPanel() {
        const panel = document.querySelector('.floating-cart-panel');
        if (!panel) return;

        const willOpen = !panel.classList.contains('active');
        panel.classList.toggle('active', willOpen);
        if (willOpen && typeof renderCartMiniDropdown === 'function') {
            renderCartMiniDropdown();
        }
    }

    function updateFloatingCartBubbleBadge() {
        const badge = document.querySelector('.floating-cart-bubble-badge');
        if (!badge || typeof getCart !== 'function') return;

        const cart = getCart();
        const totalItems = cart.reduce((sum, item) => sum + (parseInt(item.quantity, 10) || 0), 0);
        badge.textContent = totalItems;
        badge.style.display = totalItems > 0 ? 'block' : 'none';
    }

    document.addEventListener('DOMContentLoaded', updateFloatingCartBubbleBadge);

    document.addEventListener('click', function (event) {
        const widget = document.querySelector('.floating-cart-widget');
        const panel = document.querySelector('.floating-cart-panel');
        if (widget && panel && panel.classList.contains('active') && !widget.contains(event.target)) {
            panel.classList.remove('active');
        }
    });
</script>
