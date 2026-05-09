<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
requirePermission('realizar_ventas', BASE_URL . 'views/dashboard.php');

$pageTitle = 'Realizar Venta';
$pdo = getPDO();
$usuario = $_SESSION['usuario'];
$error = '';
$success = '';

// Obtener productos disponibles
try {
    $sql = "SELECT p.id_producto, p.nombre, p.precio_venta, 
                   ia.cantidad_actual 
            FROM productos p
            LEFT JOIN inventario_almacen ia ON p.id_producto = ia.id_producto 
                AND ia.id_almacen = :almacen
            WHERE p.estado = 'activo'
            ORDER BY p.nombre";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':almacen' => $usuario['id_almacen']]);
    $productos = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = 'Error al obtener productos: ' . $e->getMessage();
    $productos = [];
}

// Obtener últimas ventas del usuario
try {
    $sql = "SELECT p.numero_pedido, p.total, p.fecha_creacion, p.estado
            FROM pedidos p
            WHERE p.id_usuario = :usuario
            ORDER BY p.fecha_creacion DESC
            LIMIT 10";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':usuario' => $usuario['id_usuario']]);
    $ventasRecientes = $stmt->fetchAll();
} catch (PDOException $e) {
    $ventasRecientes = [];
}

include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="col s12">
            <h4>Realizar Venta</h4>
        </div>
    </div>

    <div class="row">
        <div class="col s12 m8">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Nueva Venta</span>
                    
                    <form id="formulario-venta" method="POST" action="<?php echo BASE_URL; ?>api/ventas.php">
                        <?php echo csrfInput(); ?>
                        <div class="row">
                            <div class="col s12">
                                <label>Selecciona Productos</label>
                            </div>
                        </div>
                        
                        <div id="carrito-items"></div>
                        
                        <div class="row">
                            <div class="col s12">
                                <button type="button" class="btn" onclick="agregarProducto()">
                                    Agregar Producto <i class="material-icons right">add</i>
                                </button>
                            </div>
                        </div>

                        <hr>

                        <div class="row">
                            <div class="input-field col s12 m6">
                                <select name="id_metodo_pago" required>
                                    <option value="">-- Selecciona método de pago --</option>
                                    <option value="1">Efectivo</option>
                                    <option value="2">Transferencia Bancaria</option>
                                    <option value="3">Tarjeta</option>
                                    <option value="4">Cheque</option>
                                </select>
                                <label>Método de Pago</label>
                            </div>
                            
                            <div class="input-field col s12 m6">
                                <input type="number" id="descuento" name="descuento" step="0.01" value="0" min="0">
                                <label for="descuento">Descuento</label>
                            </div>
                        </div>

                        <div class="input-field">
                            <textarea id="observaciones" name="observaciones" class="materialize-textarea"></textarea>
                            <label for="observaciones">Observaciones</label>
                        </div>

                        <button type="submit" class="btn waves-effect waves-light green btn-large">
                            Procesar Venta <i class="material-icons right">payment</i>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col s12 m4">
            <div class="card blue lighten-2">
                <div class="card-content white-text">
                    <span class="card-title">Resumen</span>
                    <div style="margin-top: 20px;">
                        <p><strong>Subtotal:</strong> $<span id="subtotal">0.00</span></p>
                        <p><strong>Descuento:</strong> $<span id="descuento-total">0.00</span></p>
                        <p style="font-size: 1.3rem; border-top: 2px solid white; padding-top: 10px;">
                            <strong>Total:</strong> $<span id="total-venta">0.00</span>
                        </p>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-content">
                    <span class="card-title text-small">Últimas Ventas</span>
                    <div style="max-height: 400px; overflow-y: auto;">
                        <?php foreach ($ventasRecientes as $venta): ?>
                            <div class="divider"></div>
                            <p>
                                <strong><?php echo esc($venta['numero_pedido']); ?></strong><br>
                                $<?php echo number_format($venta['total'], 2); ?><br>
                                <small><?php echo esc($venta['estado']); ?></small>
                            </p>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const productosDisponibles = <?php echo json_encode($productos); ?>;
    
    let productoIndex = 0;

    function agregarProducto() {
        const html = `
            <div class="row producto-item" data-index="${productoIndex}" style="border: 1px solid #ccc; padding: 10px; margin: 10px 0; border-radius: 4px;">
                <div class="input-field col s6 m4">
                    <select class="producto-select" name="producto_${productoIndex}" onchange="actualizarPrecio(this)">
                        <option value="">Selecciona producto</option>
                        ${productosDisponibles.map(p => `<option value="${p.id_producto}" data-precio="${p.precio_venta}">${p.nombre}</option>`).join('')}
                    </select>
                </div>
                <div class="input-field col s4 m3">
                    <input type="number" class="cantidad" name="cantidad_${productoIndex}" value="1" min="1" onchange="actualizarTotal()">
                    <label>Cantidad</label>
                </div>
                <div class="input-field col s2 m3">
                    <input type="number" class="precio-unitario" name="precio_${productoIndex}" readonly>
                    <label>Precio Unit.</label>
                </div>
                <div class="col s12 m2" style="padding-top: 30px;">
                    <button type="button" class="btn-small red" onclick="eliminarProducto(this)">
                        <i class="material-icons">delete</i>
                    </button>
                </div>
            </div>
        `;
        document.getElementById('carrito-items').insertAdjacentHTML('beforeend', html);
        productoIndex++;
        M.updateTextFields();
        M.FormSelect.init(document.querySelectorAll('.producto-select'));
    }
    
    function actualizarPrecio(select) {
        const precio = select.options[select.selectedIndex].dataset.precio;
        const item = select.closest('.producto-item');
        const index = item.dataset.index;
        item.querySelector('.precio-unitario').value = precio || 0;
        item.querySelector('input[name="precio_' + index + '"]').value = precio || 0;
        actualizarTotal();
    }

    function actualizarTotal() {
        let subtotal = 0;
        document.querySelectorAll('.producto-item').forEach(item => {
            const precio = parseFloat(item.querySelector('.precio-unitario').value) || 0;
            const cantidad = parseInt(item.querySelector('.cantidad').value) || 0;
            subtotal += precio * cantidad;
        });
        
        const descuento = parseFloat(document.getElementById('descuento').value) || 0;
        const total = subtotal - descuento;
        
        document.getElementById('subtotal').textContent = subtotal.toFixed(2);
        document.getElementById('descuento-total').textContent = descuento.toFixed(2);
        document.getElementById('total-venta').textContent = total.toFixed(2);
    }

    function eliminarProducto(btn) {
        btn.closest('.producto-item').remove();
        actualizarTotal();
    }

    document.getElementById('descuento').addEventListener('change', actualizarTotal);

    document.getElementById('formulario-venta').addEventListener('submit', function(e) {
        e.preventDefault();

        const items = document.querySelectorAll('.producto-item');
        if (items.length === 0) {
            M.toast({html: 'Debes agregar al menos un producto', classes: 'red darken-2'});
            return;
        }

        const form = e.target;
        const submitButton = form.querySelector('button[type="submit"]');
        submitButton.disabled = true;
        submitButton.innerHTML = 'Procesando...';

        const formData = new FormData(form);
        fetch(form.action, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                M.toast({html: data.message, classes: 'green darken-2'});
                setTimeout(() => window.location.href = '<?php echo BASE_URL; ?>views/dashboard.php', 1500);
            } else {
                M.toast({html: data.message || 'Error al procesar la venta', classes: 'red darken-2'});
                submitButton.disabled = false;
                submitButton.innerHTML = 'Procesar Venta <i class="material-icons right">payment</i>';
            }
        })
        .catch(error => {
            console.error(error);
            M.toast({html: 'Error de comunicación con el servidor', classes: 'red darken-2'});
            submitButton.disabled = false;
            submitButton.innerHTML = 'Procesar Venta <i class="material-icons right">payment</i>';
        });
    });

    document.addEventListener('DOMContentLoaded', function() {
        const selects = document.querySelectorAll('select');
        M.FormSelect.init(selects);
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
