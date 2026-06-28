<?php
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

$pageTitle = 'Mi Carrito de Compras';
$usuarioLogueado = $_SESSION['usuario'] ?? null;
$isUserAuthenticated = isAuthenticated(); // Obtener el estado de autenticación de PHP

$direcciones = [];
if ($isUserAuthenticated && isCliente()) {
    $pdo = getPDO();
    $stmtDir = $pdo->prepare("SELECT * FROM cliente_direcciones WHERE id_cliente = ? ORDER BY es_default DESC");
    $stmtDir->execute([$usuarioLogueado['id_cliente']]);
    $direcciones = $stmtDir->fetchAll();
}

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
                    <div style="margin-top: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                        <button type="button" onclick="clearCart()" class="btn-flat red-text waves-effect" id="btn-empty-cart" style="display: none; font-weight: bold;">
                            <i class="material-icons left">delete_sweep</i> VACIAR CARRITO
                        </button>
                        <h5 style="margin: 0; font-weight: bold;">Total: $<span id="cart-total-display">0.00</span></h5>
                    </div>
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
                        <!-- PASO 1: Tipo de entrega -->
                        <div style="margin-bottom: 20px;">
                            <label style="display: block; margin-bottom: 8px; font-weight: bold; color: #333; font-size: 0.9rem;">¿Cómo deseas recibir tu pedido?</label>
                            <select id="tipo_entrega" name="tipo_entrega" required class="browser-default" style="border: 1px solid #9e9e9e; border-radius: 4px; padding: 10px; height: auto; width: 100%;">
                                <option value="" disabled selected>Selecciona método de entrega</option>
                                <option value="Sucursal">Recoger en Sucursal (Gratis)</option>
                                <option value="Domicilio">Entrega a Domicilio (Miércoles y Sábados)</option>
                            </select>
                        </div>

                        <!-- Info sucursal (visible solo cuando se elige Sucursal) -->
                        <div id="info-sucursal" style="display:none; margin-bottom: 20px;">
                            <div class="card-panel blue lighten-5" style="margin:0; border-radius:8px;">
                                <p style="margin:0 0 8px 0; font-weight:bold; color:#0d47a1;">
                                    <i class="material-icons tiny">store</i> Punto de Venta
                                </p>
                                <p style="margin:0 0 4px 0; color:#333;">
                                    Tabachín 248, Bosques de Tonalá,<br>45400 Tonalá, Jal.
                                </p>
                                <a href="https://maps.app.goo.gl/gasKXxJgcHsvG3qM6" target="_blank"
                                   class="btn-small blue darken-4 waves-effect waves-light"
                                   style="margin-top:10px; margin-bottom:14px; width:100%; text-align:center;">
                                    <i class="material-icons left">navigation</i> Cómo Llegar
                                </a>
                                <p style="margin:0 0 4px 0; font-weight:bold; color:#333; font-size:0.85rem;">
                                    <i class="material-icons tiny">schedule</i> Horarios:
                                </p>
                                <table style="width:100%; font-size:0.82rem; border-collapse:collapse;">
                                    <tr><td style="padding:2px 6px;">Lunes – Miércoles</td><td style="padding:2px 6px; color:#2e7d32;">7:30 AM – 8:00 PM</td></tr>
                                    <tr><td style="padding:2px 6px;">Jueves</td><td style="padding:2px 6px; color:#2e7d32;">7:30 AM – 1:50 PM</td></tr>
                                    <tr><td style="padding:2px 6px;">Viernes</td><td style="padding:2px 6px; color:#2e7d32;">7:30 AM – 8:00 PM</td></tr>
                                    <tr><td style="padding:2px 6px;">Sábado</td><td style="padding:2px 6px; color:#2e7d32;">9:00 AM – 3:00 PM</td></tr>
                                    <tr><td style="padding:2px 6px;">Domingo</td><td style="padding:2px 6px; color:#c62828;">Cerrado</td></tr>
                                </table>
                            </div>
                        </div>

                        <!-- PASO 2: Mis Direcciones (solo domicilio) -->
                        <?php if (!empty($direcciones)): ?>
                        <div id="wrapper-select-direccion" style="display:none; margin-bottom: 20px;">
                            <label style="display: block; margin-bottom: 8px; font-weight: bold; color: #333; font-size: 0.9rem;">Mis Direcciones</label>
                            <select id="select_direccion" class="browser-default" style="border: 1px solid #9e9e9e; border-radius: 4px; padding: 10px; height: auto; width: 100%;">
                                <option value="">-- Seleccionar dirección guardada --</option>
                                <?php foreach ($direcciones as $d): ?>
                                    <option value="<?php echo esc($d['direccion']); ?>" <?php echo $d['es_default'] ? 'selected' : ''; ?>>
                                        <?php echo esc($d['alias']); ?>: <?php echo esc($d['direccion']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="input-field">
                            <input type="text" id="nombre" name="nombre" required value="<?php echo esc($usuarioLogueado['nombre'] ?? ''); ?>">
                            <label for="nombre">Nombre Completo</label>
                        </div>
                        <div class="input-field">
                            <input type="text" id="telefono" name="telefono" required>
                            <label for="telefono">Teléfono de contacto</label>
                        </div>
                        <div id="direccion-container">
                            <div class="input-field">
                                <textarea id="direccion" name="direccion" class="materialize-textarea" required><?php echo esc($direcciones[0]['direccion'] ?? ''); ?></textarea>
                                <label for="direccion">Dirección exacta de domicilio</label>
                            </div>
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

<!-- SweetAlert2 para mensajes emergentes -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    function clearCart() {
        Swal.fire({
            title: '¿Vaciar el carrito?',
            text: "Esta acción eliminará todos los productos que has seleccionado.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'Sí, vaciar',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                localStorage.removeItem('cart');
                renderCart();
                updateCartBadge();
                M.toast({html: 'Carrito vaciado', classes: 'grey darken-3 rounded'});
            }
        });
    }

    function renderCart() {
        const cart = getCart();
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

        // Mostrar u ocultar el botón de vaciar según si hay items
        const btnEmpty = document.getElementById('btn-empty-cart');
        if (btnEmpty) btnEmpty.style.display = cart.length > 0 ? 'inline-block' : 'none';
    }

    function removeItem(index) {
        let cart = getCart();
        cart.splice(index, 1);
        localStorage.setItem('cart', JSON.stringify(cart));
        renderCart();
        updateCartBadge();
    }

    document.getElementById('form-checkout').addEventListener('submit', function(e) {
        e.preventDefault();
        const cart = getCart();
        if (cart.length === 0) return M.toast({html: 'Tu carrito está vacío'});

        // Bloquear confirmación si el usuario no ha iniciado sesión
        if (!<?php echo json_encode($isUserAuthenticated); ?>) {
            Swal.fire({
                title: '¡Identifícate primero!',
                text: 'Para poder registrar tu pedido y que aparezca en tu historial, necesitas iniciar sesión o crear una cuenta.',
                icon: 'info',
                showCancelButton: true,
                confirmButtonText: 'Iniciar Sesión',
                cancelButtonText: 'Crear Cuenta',
                confirmButtonColor: '#0d47a1',
                cancelButtonColor: '#2e7d32'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'login.php?redirect=views/cart.php';
                } else if (result.dismiss === Swal.DismissReason.cancel) {
                    window.location.href = 'register.php';
                }
            });
            return;
        }

        const btn = this.querySelector('button');
        btn.disabled = true;
        btn.textContent = 'Procesando...';

        const formData = {
            tipo_entrega: document.getElementById('tipo_entrega').value,
            cliente: {
                nombre: document.getElementById('nombre').value,
                telefono: document.getElementById('telefono').value,
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
                Swal.fire({
                    title: '¡Pedido Confirmado!',
                    text: 'Tu pedido ha sido registrado con éxito. Puedes consultar el estado en tu sección de compras.',
                    icon: 'success',
                    confirmButtonText: 'Continuar',
                    confirmButtonColor: '#0d47a1'
                }).then(() => {
                    localStorage.removeItem('cart');
                    const idPedido = Number.parseInt(data.id_pedido, 10);
                    if (Number.isInteger(idPedido) && idPedido > 0) {
                        window.location.href = `gracias.php?id=${idPedido}`;
                    } else {
                        window.location.href = 'mis_compras.php';
                    }
                });
            } else {
                M.toast({html: 'Error: ' + data.message});
                btn.disabled = false;
                btn.textContent = 'Confirmar Pedido';
            }
        })
        .catch(err => {
            console.error('Error en la petición:', err);
            M.toast({html: 'Error de conexión. Inténtalo de nuevo.'});
            btn.disabled = false;
            btn.textContent = 'Confirmar Pedido';
        });
    });

    // Lógica para mostrar/ocultar dirección según el tipo de entrega
    const tipoEntrega = document.getElementById('tipo_entrega');
    const direccionContainer = document.getElementById('direccion-container');
    const wrapperSelect = document.getElementById('wrapper-select-direccion');
    const inputDireccion = document.getElementById('direccion');
    const infoSucursal = document.getElementById('info-sucursal');

    function aplicarModoEntrega(valor) {
        if (valor === 'Sucursal') {
            if (infoSucursal) infoSucursal.style.display = 'block';
            if (direccionContainer) direccionContainer.style.display = 'none';
            if (wrapperSelect) wrapperSelect.style.display = 'none';
            if (inputDireccion) { inputDireccion.required = false; inputDireccion.value = 'Tabachín 248, Bosques de Tonalá, 45400 Tonalá, Jal.'; }
        } else if (valor === 'Domicilio') {
            if (infoSucursal) infoSucursal.style.display = 'none';
            if (direccionContainer) direccionContainer.style.display = 'block';
            if (wrapperSelect) wrapperSelect.style.display = 'block';
            if (inputDireccion) inputDireccion.required = true;
        } else {
            if (infoSucursal) infoSucursal.style.display = 'none';
            if (direccionContainer) direccionContainer.style.display = 'block';
            if (wrapperSelect) wrapperSelect.style.display = 'none';
        }
    }

    tipoEntrega.addEventListener('change', function() { aplicarModoEntrega(this.value); });

    document.getElementById('select_direccion')?.addEventListener('change', function() {
        if (this.value) {
            document.getElementById('direccion').value = this.value;
            M.textareaAutoResize(document.getElementById('direccion'));
            M.updateTextFields();
        }
    });

    document.addEventListener('DOMContentLoaded', () => {
        renderCart();
        updateCartBadge();
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>