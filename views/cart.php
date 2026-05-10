<?php
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

$pageTitle = 'Mi Carrito de Compras';
$usuarioLogueado = $_SESSION['usuario'] ?? null;
include __DIR__ . '/includes/header.php';
?>

<div class="container" style="margin-top: 30px;">
    <div class="row">
        <div class="col s12 m8">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Resumen de Compra</span>
                    <table class="highlight">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>Precio</th>
                                <th>Cant.</th>
                                <th>Subtotal</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="cart-table-body">
                            <!-- Se llena con JS -->
                        </tbody>
                    </table>
                    <h5 class="right-align">Total: $<span id="cart-total-display">0.00</span></h5>
                </div>
            </div>
        </div>

        <div class="col s12 m4">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Datos de Entrega</span>
                    <p class="orange-text"><i class="material-icons tiny">info</i> Pago en efectivo contra entrega.</p>
                    <form id="form-checkout">
                        <div class="input-field">
                            <input type="text" id="nombre" name="nombre" required value="<?php echo esc($usuarioLogueado['nombre'] ?? ''); ?>">
                            <label for="nombre">Nombre Completo</label>
                        </div>
                        <div class="input-field">
                            <input type="text" id="telefono" name="telefono" required>
                            <label for="telefono">Teléfono de contacto</label>
                        </div>
                        <div class="input-field">
                            <input type="text" id="whatsapp" name="whatsapp" required>
                            <label for="whatsapp">WhatsApp</label>
                        </div>
                        <div class="input-field">
                            <textarea id="direccion" name="direccion" class="materialize-textarea" required></textarea>
                            <label for="direccion">Dirección exacta de domicilio</label>
                        </div>
                        <button type="submit" class="btn-large green waves-effect waves-light btn-block" style="width: 100%;">
                            Confirmar Pedido <i class="material-icons right">check</i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function renderCart() {
        const cart = JSON.parse(localStorage.getItem('cart') || '[]');
        const tbody = document.getElementById('cart-table-body');
        let total = 0;
        
        tbody.innerHTML = cart.length === 0 ? '<tr><td colspan="5" class="center">El carrito está vacío</td></tr>' : '';

        cart.forEach((item, index) => {
            const subtotal = item.precio * item.quantity;
            total += subtotal;
            tbody.innerHTML += `
                <tr>
                    <td>${item.nombre}</td>
                    <td>$${parseFloat(item.precio).toFixed(2)}</td>
                    <td>${item.quantity}</td>
                    <td>$${subtotal.toFixed(2)}</td>
                    <td><a href="#" onclick="removeItem(${index})" class="red-text"><i class="material-icons">delete_forever</i></a></td>
                </tr>`;
        });

        document.getElementById('cart-total-display').textContent = total.toFixed(2);
    }

    function removeItem(index) {
        let cart = JSON.parse(localStorage.getItem('cart') || '[]');
        cart.splice(index, 1);
        localStorage.setItem('cart', JSON.stringify(cart));
        renderCart();
        updateCartBadge();
    }

    document.getElementById('form-checkout').addEventListener('submit', function(e) {
        e.preventDefault();
        const cart = JSON.parse(localStorage.getItem('cart') || '[]');
        if (cart.length === 0) return M.toast({html: 'Tu carrito está vacío'});

        const btn = this.querySelector('button');
        btn.disabled = true;
        btn.textContent = 'Procesando...';

        const formData = {
            cliente: {
                nombre: document.getElementById('nombre').value,
                telefono: document.getElementById('telefono').value,
                whatsapp: document.getElementById('whatsapp').value,
                direccion: document.getElementById('direccion').value
            },
            items: cart
        };

        fetch('<?php echo BASE_URL; ?>api/public_orders.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(formData)
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                localStorage.removeItem('cart');
                Swal.fire('¡Pedido Recibido!', data.message, 'success').then(() => {
                    window.location.href = '<?php echo BASE_URL; ?>';
                });
            } else {
                M.toast({html: 'Error: ' + data.message});
                btn.disabled = false;
                btn.textContent = 'Confirmar Pedido';
            }
        });
    });

    renderCart();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>