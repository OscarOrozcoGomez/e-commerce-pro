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
    $sql = "SELECT p.*, ia.cantidad_actual 
            FROM productos p 
            LEFT JOIN inventario_almacen ia ON p.id_producto = ia.id_producto AND ia.id_almacen = :almacen
            WHERE p.estado = 'activo' ORDER BY p.nombre ASC, p.nombre_variante ASC";
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
                        
                        <!-- Buscador de Productos -->
                        <div class="row" style="background: #f9f9f9; padding: 20px; border-radius: 8px; border: 1px dashed #ccc; margin-bottom: 20px;">
                            <div class="input-field col s12">
                                <i class="material-icons prefix">search</i>
                                <input type="text" id="buscador-producto" class="autocomplete" placeholder="Escribe el nombre o escanea código de barras..." autocomplete="off">
                                <label for="buscador-producto">Buscar Producto (Nombre o SKU)</label>
                                <span class="helper-text">Presiona Enter para agregar por código de barras</span>
                            </div>
                        </div>

                        <div id="carrito-items">
                            <!-- Los productos seleccionados aparecerán aquí -->
                        </div>
                        
                        <div id="sin-productos" class="center-align grey-text" style="padding: 20px;">
                            <i class="material-icons style-large">shopping_basket</i>
                            <p>No hay productos en la venta. Usa el buscador de arriba para agregar.</p>
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

                        <button type="submit" class="btn waves-effect waves-light green btn-large w-100">
                            Procesar Venta <i class="material-icons right">payment</i>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col s12 m4">
            <div class="card blue-grey darken-1">
                <div class="card-content white-text">
                    <span class="card-title">Resumen de Venta</span>
                    <div style="margin-top: 20px;">
                        <div class="row" style="margin-bottom: 5px;">
                            <div class="col s6">Subtotal:</div>
                            <div class="col s6 right-align">$<span id="subtotal">0.00</span></div>
                        </div>
                        <div class="row" style="margin-bottom: 5px;">
                            <div class="col s6">Descuento:</div>
                            <div class="col s6 right-align text-red">-$<span id="descuento-total">0.00</span></div>
                        </div>
                        <div class="divider" style="background: rgba(255,255,255,0.2); margin: 10px 0;"></div>
                        <div class="row" style="font-size: 1.8rem; font-weight: bold;">
                            <div class="col s4">Total:</div>
                            <div class="col s8 right-align">$<span id="total-venta">0.00</span></div>
                        </div>
                        <div class="divider" style="background: rgba(255,255,255,0.2); margin: 10px 0;"></div>
                        
                        <!-- Calculadora de Cambio -->
                        <div class="row" style="margin-bottom: 5px;">
                            <div class="col s12">
                                <label class="white-text">Pago con:</label>
                                <input type="number" id="pago-con" step="0.01" class="white-text" style="font-size: 1.5rem; border-bottom: 1px solid white !important; margin-bottom: 5px;">
                            </div>
                        </div>
                        <div class="row" style="margin-bottom: 0;">
                            <div class="col s6">Cambio:</div>
                            <div class="col s6 right-align" style="font-size: 1.5rem; font-weight: bold; color: #81c784;">$<span id="cambio">0.00</span></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-content">
                    <span class="card-title" style="font-size: 1.1rem;">Últimas Ventas</span>
                    <div style="max-height: 300px; overflow-y: auto;">
                        <?php foreach ($ventasRecientes as $venta): ?>
                            <div class="divider"></div>
                            <div style="padding: 10px 0;">
                                <div class="row" style="margin-bottom: 0;">
                                    <div class="col s7"><strong><?php echo esc($venta['numero_pedido']); ?></strong></div>
                                    <div class="col s5 right-align">$<?php echo number_format((float)$venta['total'], 2); ?></div>
                                </div>
                                <small class="grey-text"><?php echo date('d/m/Y H:i', strtotime($venta['fecha_creacion'])); ?> - <?php echo esc($venta['estado']); ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .producto-item {
        background: #fff;
        transition: all 0.3s;
        border-left: 4px solid #4caf50;
    }
    .producto-item:hover {
        background: #f5f5f5;
    }
    .w-100 { width: 100%; }
    .autocomplete-content img { width: 40px; height: 40px; margin: 5px; }
    .style-large { font-size: 4rem; opacity: 0.2; margin-top: 20px; }
    #pago-con::placeholder { color: rgba(255,255,255,0.5); }
    .animated { animation-duration: 0.5s; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    .fadeIn { animation-name: fadeIn; }
</style>

<script>
    const productosDisponibles = <?php echo json_encode($productos); ?>;
    let productoIndex = 0;

    document.addEventListener('DOMContentLoaded', function() {
        const selects = document.querySelectorAll('select');
        M.FormSelect.init(selects);

        const elem = document.getElementById('buscador-producto');
        
        // Mapeo de labels a objetos de producto para búsqueda rápida
        const productMap = {};
        const autocompleteData = {};
        
        productosDisponibles.forEach(p => {
            // Evitar duplicar el SKU si el nombre ya lo trae entre corchetes (común en Odoo)
            let label = p.nombre;
            if (p.sku && !p.nombre.includes(`[${p.sku}]`)) {
                label = `[${p.sku}] ${p.nombre}`;
            }
            if (p.nombre_variante) {
                label += ` ${p.nombre_variante}`;
            }
            
            autocompleteData[label] = null;
            productMap[label.toLowerCase()] = p; // Usar minúsculas para búsqueda robusta
        });

        const instance = M.Autocomplete.init(elem, {
            data: autocompleteData,
            onAutocomplete: function(val) {
                const prod = productMap[val.toLowerCase()];
                if (prod) {
                    agregarProductoALista(prod);
                    elem.value = '';
                    setTimeout(() => elem.focus(), 100);
                }
            },
            limit: 10,
            minLength: 1
        });

        // Manejar Enter y Tab
        elem.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === 'Tab') {
                const value = this.value.trim();
                if (value === '') return;

                const valueLower = value.toLowerCase();

                // 1. Intentar coincidencia exacta por SKU o Código de Barras (Escáner)
                // Buscamos ignorando mayúsculas/minúsculas en el SKU
                let prod = productosDisponibles.find(p => 
                    (p.sku && p.sku.toLowerCase() === valueLower) || 
                    (p.codigo_barras && p.codigo_barras.toLowerCase() === valueLower)
                );
                
                // 2. Si no, intentar coincidencia por el Label exacto
                if (!prod) {
                    prod = productMap[valueLower];
                }

                // 3. Si se encontró, agregar y limpiar
                if (prod) {
                    e.preventDefault();
                    agregarProductoALista(prod);
                    this.value = '';
                    if (instance) instance.close();
                } 
                else if (e.key === 'Enter') {
                    // Si no hubo match exacto, ver si hay alguna sugerencia activa y tomar la primera
                    const firstSuggestion = document.querySelector('.autocomplete-content li');
                    if (firstSuggestion) {
                        firstSuggestion.click();
                        e.preventDefault();
                    } else {
                        M.toast({html: 'Producto no encontrado', classes: 'orange'});
                    }
                }
            }
        });
    });

    function agregarProductoALista(product) {
        const sinProd = document.getElementById('sin-productos');
        if (sinProd) sinProd.style.display = 'none';
        
        const existente = document.querySelector(`.producto-item[data-id="${product.id_producto}"]`);
        if (existente) {
            const cantInput = existente.querySelector('.cantidad');
            cantInput.value = parseInt(cantInput.value) + 1;
            actualizarTotal();
            M.toast({html: `+1 ${product.nombre}`, classes: 'blue lighten-3'});
            return;
        }

        const label = product.nombre_variante ? `${product.nombre} - ${product.nombre_variante}` : product.nombre;
        
        const html = `
            <div class="row producto-item animated fadeIn" data-index="${productoIndex}" data-id="${product.id_producto}" style="padding: 15px; margin: 10px 0; border-radius: 4px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); border-left: 4px solid #4caf50;">
                <input type="hidden" name="producto_${productoIndex}" value="${product.id_producto}">
                
                <div class="col s12 m5">
                    <p style="margin: 0; font-weight: bold; font-size: 1.1rem;">${label}</p>
                    <small class="grey-text">SKU: ${product.sku}</small>
                </div>
                
                <div class="input-field col s4 m2" style="margin: 0;">
                    <input type="number" class="cantidad" name="cantidad_${productoIndex}" value="1" min="1" onchange="actualizarTotal()" style="height: 2.5rem; margin: 0; font-weight: bold; text-align: center;">
                    <label class="active">Cant.</label>
                </div>
                
                <div class="input-field col s5 m3" style="margin: 0;">
                    <input type="number" class="precio-unitario" name="precio_${productoIndex}" value="${product.precio_venta}" onchange="actualizarTotal()" style="height: 2.5rem; margin: 0; color: #2e7d32; font-weight: bold;">
                    <label class="active">Precio Unit.</label>
                </div>
                
                <div class="col s3 m2 right-align" style="padding-top: 5px;">
                    <button type="button" class="btn-floating btn-small waves-effect waves-light red" onclick="eliminarProducto(this)">
                        <i class="material-icons">delete</i>
                    </button>
                </div>
            </div>
        `;
        document.getElementById('carrito-items').insertAdjacentHTML('afterbegin', html);
        productoIndex++;
        actualizarTotal();
        M.toast({html: `Agregado: ${product.nombre}`, classes: 'green'});
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

        // Calcular cambio
        const pagoCon = parseFloat(document.getElementById('pago-con').value) || 0;
        const cambio = pagoCon > 0 ? (pagoCon - total) : 0;
        document.getElementById('cambio').textContent = Math.max(0, cambio).toFixed(2);
        
        // Estilo visual del cambio
        const cambioElem = document.getElementById('cambio');
        if (pagoCon > 0 && pagoCon < total) {
            cambioElem.parentElement.style.color = '#ef5350'; // Rojo si falta dinero
        } else {
            cambioElem.parentElement.style.color = '#81c784'; // Verde si es suficiente
        }
    }

    document.getElementById('descuento').addEventListener('input', actualizarTotal);
    document.getElementById('pago-con').addEventListener('input', actualizarTotal);

    function eliminarProducto(btn) {
        btn.closest('.producto-item').remove();
        if (document.querySelectorAll('.producto-item').length === 0) {
            document.getElementById('sin-productos').style.display = 'block';
        }
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
