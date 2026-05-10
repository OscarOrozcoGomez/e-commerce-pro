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
                    <span class="card-title">Confirmar Reserva</span>
                    <div class="card-panel blue lighten-5">
                        <p class="blue-text text-darken-4" style="margin-bottom: 10px;">
                            <i class="material-icons left">account_balance</i> <strong>Datos de Transferencia:</strong><br>
                            Banco: [Nombre del Banco]<br>
                            Cuenta: [Tu Cuenta]<br>
                            CLABE: [Tu CLABE]
                        </p>
                        <p class="orange-text text-darken-4" style="font-weight: bold;">
                            <i class="material-icons left">report_problem</i> Importante: Envía tu comprobante de anticipo de $50 vía WhatsApp para confirmar tu lugar en la entrega.
                        </p>
                    </div>
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
            const price = parseFloat(item.precio) || 0;
            const subtotal = price * item.quantity;
            
            // Si el item es corrupto (undefined o NaN), ofrecer eliminarlo o saltarlo
            if (!item.nombre || isNaN(subtotal)) {
                return; // Ignorar items corruptos del error anterior
            }

            total += subtotal;
            tbody.innerHTML += `
                <tr>
                    <td>${item.nombre}</td>
                    <td>$${price.toFixed(2)}</td>
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
                
                // Construir mensaje de WhatsApp
                let msg = `*NUEVA RESERVA #${data.pedido}*\n`;
                msg += `Cliente: ${formData.cliente.nombre}\n`;
                msg += `--------------------------\n`;
                cart.forEach(item => {
                    msg += `- ${item.quantity}x ${item.nombre} ($${(item.precio * item.quantity).toFixed(2)})\n`;
                });
                msg += `--------------------------\n`;
                msg += `*Total a confirmar: $${document.getElementById('cart-total-display').textContent}*\n\n`;
                msg += `Hola, acabo de hacer mi reserva en el sitio. ¿Me podrían dar los datos para mi anticipo de $50 y confirmar mi entrega?`;
                
                const whatsappNumber = '521XXXXXXXXXX'; // Reemplazar con el número real
                const waUrl = `https://api.whatsapp.org/send?phone=${whatsappNumber}&text=${encodeURIComponent(msg)}`;
                
                Swal.fire({
                    title: '¡Reserva Registrada!',
                    text: 'Te estamos redirigiendo a WhatsApp para confirmar tu pedido con un asesor.',
                    icon: 'success',
                    timer: 3000,
                    showConfirmButton: false
                }).then(() => {
                    window.location.href = waUrl;
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