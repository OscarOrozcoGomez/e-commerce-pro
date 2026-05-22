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
            <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 15px; border-bottom: 2px solid #e0e0e0; padding-bottom: 5px;">
                <ul id="ventas-tabs" class="tabs" style="background: transparent; height: 45px; overflow-x: auto; overflow-y: hidden;">
                    <!-- Las pestañas se generan aquí dinámicamente -->
                </ul>
                <button type="button" onclick="nuevaVenta()" class="btn-floating btn-small waves-effect waves-light indigo" title="Atender otro cliente" style="margin-left: 10px;">
                    <i class="material-icons">add</i>
                </button>
            </div>
        </div>
    </div>

    <div id="ventas-containers">
        <!-- Los formularios de cada venta se insertarán aquí -->
    </div>
</div>

<!-- Template para nueva venta -->
<template id="venta-template">
    <div id="venta-{{id}}" class="row animated fadeIn venta-context" style="margin-top: 20px;">
        <div class="col s12 m8">
            <div class="card">
                <div class="card-content">
                    <form class="formulario-venta" method="POST" action="<?php echo BASE_URL; ?>api/ventas.php">
                        <?php echo csrfInput(); ?>
                        
                        <div class="row">
                            <div class="input-field col s12">
                                <i class="material-icons prefix">person_outline</i>
                                <input type="text" class="cliente_nombre" name="cliente_nombre" 
                                       placeholder="Ej: Juan Pérez / Pedido Urgente" 
                                       oninput="actualizarTituloTab('{{id}}', this.value)" autocomplete="off">
                                <label class="active">Nombre del Cliente / Referencia (Opcional)</label>
                            </div>
                        </div>

                        <div class="row" style="background: #f9f9f9; padding: 20px; border-radius: 8px; border: 1px dashed #ccc; margin-bottom: 20px;">
                            <div class="input-field col s12">
                                <i class="material-icons prefix">search</i>
                                <input type="text" class="buscador-producto autocomplete" placeholder="Escribe el nombre o escanea código de barras..." autocomplete="off">
                                <label class="active">Buscar Producto (Nombre o SKU)</label>
                                <span class="helper-text">Presiona Enter para agregar por código de barras</span>
                            </div>
                        </div>

                        <div class="carrito-items">
                            <!-- Los productos seleccionados aparecerán aquí -->
                        </div>
                        
                        <div class="sin-productos center-align grey-text" style="padding: 20px;">
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
                                <input type="number" class="descuento" name="descuento" step="0.01" value="0" min="0" oninput="actualizarTotal('{{id}}')">
                                <label for="descuento">Descuento</label>
                            </div>
                        </div>

                        <div class="input-field">
                            <textarea name="observaciones" class="materialize-textarea observaciones"></textarea>
                            <label>Observaciones</label>
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
                            <div class="col s6 right-align">$<span class="subtotal-val">0.00</span></div>
                        </div>
                        <div class="row" style="margin-bottom: 5px;">
                            <div class="col s6">Descuento:</div>
                            <div class="col s6 right-align text-red">-$<span class="descuento-total-val">0.00</span></div>
                        </div>
                        <div class="divider" style="background: rgba(255,255,255,0.2); margin: 10px 0;"></div>
                        <div class="row" style="font-size: 1.8rem; font-weight: bold;">
                            <div class="col s4">Total:</div>
                            <div class="col s8 right-align">$<span class="total-venta-val">0.00</span></div>
                        </div>
                        <div class="divider" style="background: rgba(255,255,255,0.2); margin: 10px 0;"></div>
                        
                        <!-- Calculadora de Cambio -->
                        <div class="row" style="margin-bottom: 5px;">
                            <div class="col s12">
                                <label class="white-text">Pago con:</label>
                                <input type="number" class="pago-con white-text" step="0.01" oninput="actualizarTotal('{{id}}')" style="font-size: 1.5rem; border-bottom: 1px solid white !important; margin-bottom: 5px;">
                            </div>
                        </div>
                        <div class="row" style="margin-bottom: 0; color: #81c784;">
                            <div class="col s6">Cambio:</div>
                            <div class="col s6 right-align cambio-container" style="font-size: 1.5rem; font-weight: bold;">$<span class="cambio-val">0.00</span></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
    .tabs .tab a { display: flex; align-items: center; padding: 0 15px; text-transform: none; font-weight: 500; }
    .tabs .tab a i.close-tab { margin-left: 10px; font-size: 16px; cursor: pointer; color: #9e9e9e; }
    .tabs .tab a i.close-tab:hover { color: #f44336; }
    
    /* Colores por posición de pestaña */
    .tab-color-0 .active { border-bottom: 3px solid #2196f3 !important; color: #2196f3 !important; }
    .tab-color-1 .active { border-bottom: 3px solid #4caf50 !important; color: #4caf50 !important; }
    .tab-color-2 .active { border-bottom: 3px solid #9c27b0 !important; color: #9c27b0 !important; }
    .tab-color-3 .active { border-bottom: 3px solid #ff9800 !important; color: #ff9800 !important; }

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
    let tabCount = 0;
    let productoIndex = 0;
    const productMap = {};
    const autocompleteData = {};

    // Función para prevenir pérdida de datos al cerrar pestaña
    const prevenirCierre = (e) => {
        const items = document.querySelectorAll('.producto-item');
        if (items.length > 0) {
            e.preventDefault();
            e.returnValue = ''; 
        }
    };

    document.addEventListener('DOMContentLoaded', function() {
        // 1. Preparar datos de productos una sola vez para mejorar rendimiento
        productosDisponibles.forEach(p => {
            const imgSrc = p.imagen 
                ? (p.imagen.startsWith('data:') ? p.imagen : `data:image/jpeg;base64,${p.imagen}`)
                : null;

            let label = p.nombre;
            if (p.sku && !p.nombre.includes(`[${p.sku}]`)) {
                label = `[${p.sku}] ${p.nombre}`;
            }
            if (p.nombre_variante) {
                label += ` ${p.nombre_variante}`;
            }
            
            autocompleteData[label] = imgSrc;
            productMap[label.toLowerCase()] = p;
        });

        const selects = document.querySelectorAll('select');
        M.FormSelect.init(selects);

        window.addEventListener('beforeunload', prevenirCierre);
        
        nuevaVenta(); // Iniciar con la primera venta
    });

    function nuevaVenta() {
        tabCount++;
        const id = 'v' + tabCount;

        // 1. Crear la pestaña física en la lista superior
        const colorIdx = (tabCount - 1) % 4;
        const tabsUl = document.getElementById('ventas-tabs');
        const li = document.createElement('li');
        li.id = `tab-li-${id}`;
        li.className = `tab tab-color-${colorIdx}`;
        li.innerHTML = `
            <a href="#venta-${id}">
                <span class="tab-title">Venta ${tabCount}</span>
                <i class="material-icons close-tab" onclick="cerrarVenta('${id}', event)">close</i>
            </a>`;
        tabsUl.appendChild(li);

        // 2. Insertar el contenedor del formulario desde el <template>
        const containers = document.getElementById('ventas-containers');
        const template = document.getElementById('venta-template').innerHTML;
        containers.insertAdjacentHTML('beforeend', template.replace(/{{id}}/g, id));

        // 3. Obtener el contexto del nuevo formulario
        const context = document.getElementById(`venta-${id}`);

        // Inicializar pestañas y selects de Materialize para el nuevo contenido
        M.Tabs.init(tabsUl);
        M.FormSelect.init(context.querySelectorAll('select'));

        // 4. Configurar el buscador Autocomplete
        const buscador = context.querySelector('.buscador-producto');
        const instance = M.Autocomplete.init(buscador, {
            data: autocompleteData,
            onAutocomplete: function(val) {
                const prod = productMap[val.toLowerCase()];
                if (prod) {
                    agregarProductoALista(id, prod);
                    buscador.value = '';
                    setTimeout(() => buscador.focus(), 100);
                }
            },
            limit: 10,
            minLength: 1
        });

        // Manejar Enter y Tab
        buscador.addEventListener('keydown', function(e) {
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
                    agregarProductoALista(id, prod);
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

        // Manejar envío del formulario
        context.querySelector('.formulario-venta').addEventListener('submit', (e) => procesarVenta(e, id));

        // Seleccionar la nueva pestaña automáticamente
        const tabsInstance = M.Tabs.getInstance(tabsUl);
        tabsInstance.select(`venta-${id}`);
    }

    function actualizarTituloTab(id, nombre) {
        const tabTitle = document.querySelector(`#tab-li-${id} .tab-title`);
        if (tabTitle) {
            tabTitle.textContent = nombre.trim() !== '' ? nombre.substring(0, 15) : `Venta ${id.substring(1)}`;
        }
    }

    function cerrarVenta(id, event) {
        event.stopPropagation();
        const context = document.getElementById(`venta-${id}`);
        if (context.querySelectorAll('.producto-item').length > 0) {
            if (!confirm('Esta venta tiene productos. ¿Seguro que quieres cerrarla?')) return;
        }
        document.getElementById(`tab-li-${id}`).remove();
        context.remove();
        M.Tabs.init(document.getElementById('ventas-tabs'));
    }

    function agregarProductoALista(tabId, product) {
        const context = document.getElementById(`venta-${tabId}`);
        const sinProd = context.querySelector('.sin-productos');
        if (sinProd) sinProd.style.display = 'none';
        
        const existente = context.querySelector(`.producto-item[data-id="${product.id_producto}"]`);
        if (existente) {
            const cantInput = existente.querySelector('.cantidad');
            cantInput.value = parseInt(cantInput.value) + 1;
            actualizarTotal(tabId);
            M.toast({html: `+1 ${product.nombre}`, classes: 'blue lighten-3'});
            return;
        }

        const label = product.nombre_variante ? `${product.nombre} - ${product.nombre_variante}` : product.nombre;
        const imgSrc = product.imagen 
            ? (product.imagen.startsWith('data:') ? product.imagen : `data:image/jpeg;base64,${product.imagen}`)
            : '../assets/img/no-product.png';
        
        const html = `
            <div class="row producto-item animated fadeIn" data-id="${product.id_producto}" style="padding: 15px; margin: 10px 0; border-radius: 4px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); border-left: 4px solid #4caf50;">
                <input type="hidden" name="productos[]" value="${product.id_producto}">
                
                <div class="col s12 m2 center-align">
                    <img src="${imgSrc}" class="responsive-img materialboxed" style="max-height: 80px; border-radius: 4px;">
                </div>

                <div class="col s12 m3">
                    <p style="margin: 0; font-weight: bold; font-size: 1.1rem;">${label}</p>
                    <small class="grey-text">SKU: ${product.sku}</small>
                </div>
                
                <div class="input-field col s4 m2" style="margin: 0;">
                    <input type="number" class="cantidad" name="cantidades[]" value="1" min="1" oninput="actualizarTotal('${tabId}')" style="height: 2.5rem; margin: 0; font-weight: bold; text-align: center;">
                    <label class="active">Cant.</label>
                </div>
                
                <div class="input-field col s5 m3" style="margin: 0;">
                    <input type="number" class="precio-unitario" name="precios[]" value="${product.precio_venta}" oninput="actualizarTotal('${tabId}')" style="height: 2.5rem; margin: 0; color: #2e7d32; font-weight: bold;">
                    <label class="active">Precio Unit.</label>
                </div>
                
                <div class="col s3 m2 right-align" style="padding-top: 5px;">
                    <button type="button" class="btn-floating btn-small waves-effect waves-light red" onclick="eliminarProducto(this, '${tabId}')">
                        <i class="material-icons">delete</i>
                    </button>
                </div>
            </div>
        `;
        const itemsContainer = context.querySelector('.carrito-items');
        itemsContainer.insertAdjacentHTML('afterbegin', html);
        M.Materialbox.init(itemsContainer.querySelectorAll('.materialboxed'));

        productoIndex++;
        actualizarTotal(tabId);
        M.toast({html: `Agregado: ${product.nombre}`, classes: 'green'});
    }

    function actualizarTotal(tabId) {
        const context = document.getElementById(`venta-${tabId}`);
        let subtotal = 0;
        let itemsTotales = 0;
        context.querySelectorAll('.producto-item').forEach(item => {
            const precio = parseFloat(item.querySelector('.precio-unitario').value) || 0;
            const cantidad = parseInt(item.querySelector('.cantidad').value) || 0;
            subtotal += precio * cantidad;
            itemsTotales += cantidad;
        });
        
        const descuento = parseFloat(context.querySelector('.descuento').value) || 0;
        const total = subtotal - descuento;
        
        context.querySelector('.subtotal-val').textContent = subtotal.toFixed(2);
        context.querySelector('.descuento-total-val').textContent = descuento.toFixed(2);
        context.querySelector('.total-venta-val').textContent = total.toFixed(2);

        const pagoCon = parseFloat(context.querySelector('.pago-con').value) || 0;
        const cambio = pagoCon > 0 ? (pagoCon - total) : 0;
        context.querySelector('.cambio-val').textContent = Math.max(0, cambio).toFixed(2);
        
        const container = context.querySelector('.cambio-container');
        container.style.color = (pagoCon > 0 && pagoCon < total) ? '#ef5350' : '#81c784';
        
        actualizarTituloTab(tabId, context.querySelector('.cliente_nombre').value);
    }

    function eliminarProducto(btn, tabId) {
        const context = document.getElementById(`venta-${tabId}`);
        btn.closest('.producto-item').remove();
        if (context.querySelectorAll('.producto-item').length === 0) {
            context.querySelector('.sin-productos').style.display = 'block';
        }
        actualizarTotal(tabId);
    }

    function procesarVenta(e, tabId) {
        e.preventDefault();
        const context = document.getElementById(`venta-${tabId}`);
        const items = context.querySelectorAll('.producto-item');
        if (items.length === 0) {
            M.toast({html: 'Debes agregar al menos un producto', classes: 'red darken-2'});
            return;
        }

        const form = e.target;
        const submitButton = form.querySelector('button[type="submit"]');
        submitButton.disabled = true;
        submitButton.innerHTML = 'Procesando...';

        const nombreCliente = context.querySelector('.cliente_nombre').value.trim();
        const obsField = context.querySelector('.observaciones');
        if (nombreCliente) obsField.value = `Cliente: ${nombreCliente}. ${obsField.value}`;

        const formData = new FormData(form);
        fetch(form.action, { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                window.removeEventListener('beforeunload', prevenirCierre);
                M.toast({html: 'Venta realizada con éxito', classes: 'green darken-2'});
                document.getElementById(`tab-li-${tabId}`).remove();
                context.remove();
                if (document.querySelectorAll('.tab').length === 0) location.reload();
            } else {
                M.toast({html: data.message || 'Error al procesar la venta', classes: 'red darken-2'});
                submitButton.disabled = false;
                submitButton.innerHTML = 'Procesar Venta <i class="material-icons right">payment</i>';
            }
        })
        .catch(error => {
            console.error(error);
            submitButton.disabled = false;
            submitButton.innerHTML = 'Procesar Venta <i class="material-icons right">payment</i>';
        });
    }
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
