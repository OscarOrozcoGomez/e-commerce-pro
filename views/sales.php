<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
requirePermission('realizar_ventas', BASE_URL . 'views/dashboard.php');

$pageTitle = 'Realizar Venta';
$pdo = getPDO();
$usuario = $_SESSION['usuario'];
$isAdminUser = isAdmin();
$showIncentivoDetails = true;
$pickupOfferSettings = getPickupOfferSettings($pdo);
$error = '';
$success = '';

$id_almacen_actual = resolveSalesWarehouseId($pdo);
$almacenActualNombre = '';

if ($id_almacen_actual > 0) {
    $stmtSucursal = $pdo->prepare('SELECT nombre FROM almacenes WHERE id_almacen = ? LIMIT 1');
    $stmtSucursal->execute([$id_almacen_actual]);
    $almacenActualNombre = (string)($stmtSucursal->fetchColumn() ?: '');
}

if (!$id_almacen_actual) {
    $error = "Error: No tienes una sucursal asignada o no se seleccionó ninguna.";
}

// Obtener productos disponibles
try {
    $sql = "SELECT p.*, ia.cantidad_actual,
            COALESCE(
                (SELECT pi.ruta_archivo
                 FROM producto_imagenes pi
                 INNER JOIN productos p_img ON pi.id_producto = p_img.id_producto
                 WHERE (p_img.id_producto = p.id_producto OR p_img.id_padre = p.id_producto)
                 ORDER BY (p_img.id_producto = p.id_producto) DESC, pi.orden ASC
                 LIMIT 1),
                p.imagen,
                p.imagen_url
            ) as imagen_fuente
            FROM productos p 
            LEFT JOIN inventario_almacen ia ON p.id_producto = ia.id_producto AND ia.id_almacen = :almacen
            WHERE p.estado = 'activo' ORDER BY p.nombre ASC, p.nombre_variante ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':almacen' => $id_almacen_actual]);
    $productos = $stmt->fetchAll();

    foreach ($productos as &$producto) {
        $producto['imagen_resuelta'] = getProductImageUrl((string)($producto['imagen_fuente'] ?? ''));
    }
    unset($producto);
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

// Obtener clientes activos para autocompletar en venta de mostrador
try {
    $sql = "SELECT id_cliente, nombre, COALESCE(telefono, '') AS telefono
            FROM clientes
            WHERE estado = 'activo'
            ORDER BY nombre ASC
            LIMIT 500";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $clientesActivos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $clientesActivos = [];
}

include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="col s12">
            <div class="sales-toolbar" style="display: flex; align-items: center; justify-content: space-between; margin-top: 15px; border-bottom: 2px solid #e0e0e0; padding-bottom: 5px;">
                <div class="chip blue lighten-5 blue-text text-darken-4 sales-warehouse-chip" style="margin: 0 10px 0 0;">
                    <span>Sucursal de venta:</span>
                    <strong class="sales-warehouse-name"><?php echo esc($almacenActualNombre !== '' ? $almacenActualNombre : 'Sin asignar'); ?></strong>
                </div>
                <ul id="ventas-tabs" class="tabs sales-tabs" style="background: transparent; height: 45px; overflow-x: auto; overflow-y: hidden;">
                    <!-- Las pestañas se generan aquí dinámicamente -->
                </ul>
                <button type="button" onclick="nuevaVenta()" class="btn-floating btn-small waves-effect waves-light indigo sales-new-tab-btn" title="Atender otro cliente" style="margin-left: 10px;">
                    <i class="material-icons">add</i>
                </button>
                <a href="<?php echo BASE_URL; ?>views/dashboard.php" class="btn waves-effect waves-light blue darken-3 z-depth-1 sales-back-dashboard-btn" title="Volver al dashboard" style="margin-left: 10px;">
                    <i class="material-icons left">dashboard</i>Volver al Dashboard
                </a>
            </div>
        </div>
    </div>

    <div id="ventas-containers">
        <!-- Los formularios de cada venta se insertarán aquí -->
    </div>
</div>

<div id="modal-cerrar-venta" class="modal" style="max-width: 520px;">
    <div class="modal-content">
        <h5 style="margin-top: 0;">Cerrar pestaña de venta</h5>
        <p>Esta pestaña tiene datos del ticket, cliente o venta.</p>
        <p class="grey-text text-darken-1" style="margin-bottom: 0;">Si la cierras, perderás esta información no guardada.</p>
    </div>
    <div class="modal-footer">
        <a href="#!" class="modal-close waves-effect waves-grey btn-flat">Cancelar</a>
        <a href="#!" id="btn-confirmar-cerrar-venta" class="waves-effect waves-light btn red darken-2">Sí, cerrar pestaña</a>
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
                            <div class="input-field col s12 m7">
                                <i class="material-icons prefix">person_outline</i>
                                <input type="text" class="cliente_nombre" name="cliente_nombre" 
                                       placeholder="Ej: Juan Perez" 
                                       oninput="actualizarTituloTab('{{id}}', this.value)" autocomplete="off">
                                <label class="active">Nombre del Cliente (Opcional)</label>
                                <span class="helper-text">Escribe para buscar cliente existente y autocompletar telefono.</span>
                            </div>
                            <div class="input-field col s12 m5">
                                <i class="material-icons prefix">phone</i>
                                <input type="tel" class="cliente_telefono" name="cliente_telefono" placeholder="Ej: (331) - 863 - 5185" maxlength="19" inputmode="numeric" autocomplete="tel-national">
                                <label class="active">Telefono (Opcional)</label>
                                <span class="helper-text">Si se captura, se enlaza/crea cliente para historial.</span>
                            </div>
                        </div>

                        <div class="row" style="background: #f9f9f9; padding: 20px; border-radius: 8px; border: 1px dashed #ccc; margin-bottom: 20px;">
                            <div class="input-field col s12">
                                <i class="material-icons prefix">search</i>
                                <input type="text" class="buscador-producto autocomplete" placeholder="Escribe el nombre o escanea código de barras..." autocomplete="off">
                                <label class="active">Buscar Producto</label>
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
                                    <option value="1" selected>Efectivo</option>
                                    <option value="2">Transferencia Bancaria</option>
                                    <option value="3">Tarjeta</option>
                                    <option value="4">Cheque</option>
                                </select>
                                <label>Método de Pago</label>
                            </div>
                            
                            <div class="input-field col s12 m6">
                                <input type="number" id="descuento-{{id}}" class="descuento" name="descuento" step="0.01" value="0" min="0" oninput="actualizarTotal('{{id}}')" <?php echo $isAdminUser ? '' : 'readonly'; ?>>
                                <label for="descuento-{{id}}" class="active">Descuento</label>
                                <?php if (!$isAdminUser): ?>
                                    <span class="helper-text">El descuento manual esta bloqueado para tu perfil.</span>
                                <?php endif; ?>
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
                            <?php if ($showIncentivoDetails): ?>
                        <div class="row" style="margin-bottom: 5px;">
                            <div class="col s6">Cuenta sin descuento/incentivo:</div>
                            <div class="col s6 right-align">$<span class="base-total-val">0.00</span></div>
                        </div>
                        <div class="row" style="margin-bottom: 5px;">
                            <div class="col s6">Incentivo Sucursal:</div>
                            <div class="col s6 right-align" style="color:#80cbc4;">-$<span class="incentivo-total-val">0.00</span></div>
                        </div>
                        <div class="row" style="margin-bottom: 5px;">
                            <div class="col s6">Descuento manual:</div>
                            <div class="col s6 right-align text-red">-$<span class="descuento-total-val">0.00</span></div>
                        </div>
                            <?php endif; ?>
                        <div class="divider" style="background: rgba(255,255,255,0.2); margin: 10px 0;"></div>
                        <div class="row" style="font-size: 1.8rem; font-weight: bold;">
                            <div class="col s4">Total:</div>
                            <div class="col s8 right-align">$<span class="total-venta-val">0.00</span></div>
                        </div>
                            <?php if ($showIncentivoDetails): ?>
                                <div class="card-panel amber lighten-4 incentivo-banner" style="display:none; margin:10px 0 0 0; padding:10px; border-left:4px solid #ff8f00; color:#6d4c41;"></div>
                            <?php endif; ?>
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
    .sales-toolbar {
        flex-wrap: wrap;
        gap: 8px;
    }
    .sales-warehouse-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        white-space: nowrap;
        flex: 0 0 auto;
    }
    .sales-warehouse-name {
        white-space: nowrap;
    }
    .sales-tabs {
        flex: 1 1 260px;
        min-width: 220px;
    }
    .sales-new-tab-btn {
        flex: 0 0 auto;
    }
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
    const clientesActivos = <?php echo json_encode($clientesActivos, JSON_UNESCAPED_UNICODE); ?>;
    const PICKUP_OFFER_SETTINGS = <?php echo json_encode($pickupOfferSettings, JSON_UNESCAPED_UNICODE); ?>;
    const SHOW_INCENTIVO_DETAILS = <?php echo $showIncentivoDetails ? 'true' : 'false'; ?>;
    const SALES_TABS_STORAGE_KEY = 'sales_tabs_draft_v1';
    let tabCount = 0;
    let productoIndex = 0;
    const productMap = {};
    const autocompleteData = {};
    const customerMap = {};
    const customerAutocompleteData = {};
    let salesDraftSaveTimer = null;
    let isRestoringDrafts = false;
    let pendingCloseVentaId = null;
    let closeVentaModalInstance = null;

    function resolveProductImageSrc(rawImage) {
        if (!rawImage) {
            return '../assets/img/no-product.png';
        }

        const value = String(rawImage).trim();
        if (value === '') {
            return '../assets/img/no-product.png';
        }

        // URL completa, data URI o ruta relativa/absoluta ya válida
        if (
            value.startsWith('data:') ||
            value.startsWith('http://') ||
            value.startsWith('https://') ||
            value.startsWith('/') ||
            value.startsWith('../') ||
            value.startsWith('./')
        ) {
            return value;
        }

        // Si no parece ruta/URL, asumimos base64 de imagen JPEG
        return `data:image/jpeg;base64,${value}`;
    }

    function hasVentaData(context) {
        if (!context) return false;

        const hasItems = context.querySelectorAll('.producto-item').length > 0;
        const clienteNombre = (context.querySelector('.cliente_nombre')?.value || '').trim();
        const clienteTelefono = (context.querySelector('.cliente_telefono')?.value || '').trim();
        const observaciones = (context.querySelector('.observaciones')?.value || '').trim();
        const descuento = parseFloat(context.querySelector('.descuento')?.value || '0') || 0;

        return hasItems || clienteNombre !== '' || clienteTelefono !== '' || observaciones !== '' || descuento > 0;
    }

    function getCurrentSalesDraft() {
        const tabs = [];
        const contexts = Array.from(document.querySelectorAll('.venta-context'));

        contexts.forEach((context) => {
            const id = String(context.id || '').replace('venta-', '');
            if (!id) return;

            const productos = Array.from(context.querySelectorAll('.producto-item')).map((item) => {
                const idProducto = parseInt(item.dataset.id || '0', 10) || 0;
                const cantidad = parseInt(item.querySelector('.cantidad')?.value || '0', 10) || 0;
                const precioUnitario = parseFloat(item.querySelector('.precio-unitario')?.value || '0') || 0;
                return {
                    id_producto: idProducto,
                    cantidad,
                    precio_unitario: precioUnitario
                };
            }).filter((p) => p.id_producto > 0 && p.cantidad > 0);

            tabs.push({
                id,
                cliente_nombre: (context.querySelector('.cliente_nombre')?.value || '').trim(),
                cliente_telefono: (context.querySelector('.cliente_telefono')?.value || '').trim(),
                id_metodo_pago: String(context.querySelector('select[name="id_metodo_pago"]')?.value || '1'),
                descuento: parseFloat(context.querySelector('.descuento')?.value || '0') || 0,
                observaciones: (context.querySelector('.observaciones')?.value || '').trim(),
                pago_con: parseFloat(context.querySelector('.pago-con')?.value || '0') || 0,
                productos
            });
        });

        const activeTab = document.querySelector('#ventas-tabs .tab a.active');
        const activeHref = activeTab?.getAttribute('href') || '';
        const activeTabId = activeHref.startsWith('#venta-') ? activeHref.replace('#venta-', '') : null;

        return {
            version: 1,
            saved_at: Date.now(),
            active_tab_id: activeTabId,
            tabs
        };
    }

    function saveSalesDraftNow() {
        if (isRestoringDrafts) return;

        try {
            const draft = getCurrentSalesDraft();
            if (!draft.tabs || draft.tabs.length === 0) {
                localStorage.removeItem(SALES_TABS_STORAGE_KEY);
                return;
            }
            localStorage.setItem(SALES_TABS_STORAGE_KEY, JSON.stringify(draft));
        } catch (err) {
            console.warn('No se pudo guardar borrador de ventas:', err);
        }
    }

    function scheduleSalesDraftSave() {
        if (isRestoringDrafts) return;
        if (salesDraftSaveTimer) clearTimeout(salesDraftSaveTimer);
        salesDraftSaveTimer = setTimeout(saveSalesDraftNow, 250);
    }

    function clearSalesDraftIfEmpty() {
        const hasTabs = document.querySelectorAll('.venta-context').length > 0;
        if (!hasTabs) {
            try {
                localStorage.removeItem(SALES_TABS_STORAGE_KEY);
            } catch (err) {
                console.warn('No se pudo limpiar borrador de ventas:', err);
            }
        }
    }

    function restoreSalesDrafts() {
        let parsed;
        try {
            const raw = localStorage.getItem(SALES_TABS_STORAGE_KEY);
            if (!raw) return false;
            parsed = JSON.parse(raw);
        } catch (err) {
            console.warn('No se pudo leer borrador de ventas:', err);
            return false;
        }

        if (!parsed || !Array.isArray(parsed.tabs) || parsed.tabs.length === 0) {
            return false;
        }

        isRestoringDrafts = true;
        parsed.tabs.forEach((draftTab) => nuevaVenta(draftTab));
        isRestoringDrafts = false;

        const activeTabId = String(parsed.active_tab_id || '').trim();
        if (activeTabId !== '') {
            const tabsUl = document.getElementById('ventas-tabs');
            const tabsInstance = M.Tabs.getInstance(tabsUl);
            if (tabsInstance && document.getElementById(`venta-${activeTabId}`)) {
                tabsInstance.select(`venta-${activeTabId}`);
            }
        }

        scheduleSalesDraftSave();
        return true;
    }

    // Función para prevenir pérdida de datos al cerrar pestaña/recargar
    const prevenirCierre = (e) => {
        const contexts = Array.from(document.querySelectorAll('.venta-context'));
        const hasData = contexts.some((ctx) => hasVentaData(ctx));
        if (hasData) {
            e.preventDefault();
            e.returnValue = ''; 
        }
    };

    document.addEventListener('DOMContentLoaded', function() {
        // 1. Preparar datos de productos una sola vez para mejorar rendimiento
        productosDisponibles.forEach(p => {
            const imgSrc = resolveProductImageSrc(p.imagen_resuelta || p.imagen_fuente || p.imagen || p.imagen_url);

            let label = p.nombre;
            if (p.codigo_barras && !p.nombre.includes(`[${p.codigo_barras}]`)) {
                label = `[${p.codigo_barras}] ${p.nombre}`;
            }
            if (p.nombre_variante) {
                label += ` ${p.nombre_variante}`;
            }
            
            autocompleteData[label] = imgSrc;
            productMap[label.toLowerCase()] = p;
        });

        clientesActivos.forEach((c) => {
            const nombre = String(c.nombre || '').trim();
            const telefono = String(c.telefono || '').trim();
            if (nombre === '') return;

            const label = telefono !== '' ? `${nombre} (${telefono})` : nombre;
            customerAutocompleteData[label] = null;
            customerMap[label.toLowerCase()] = { nombre, telefono };
        });

        const selects = document.querySelectorAll('select');
        M.FormSelect.init(selects);
        const closeModalNode = document.getElementById('modal-cerrar-venta');
        closeVentaModalInstance = closeModalNode ? M.Modal.init(closeModalNode, { dismissible: true }) : null;

        const btnConfirmCloseVenta = document.getElementById('btn-confirmar-cerrar-venta');
        if (btnConfirmCloseVenta) {
            btnConfirmCloseVenta.addEventListener('click', () => {
                if (!pendingCloseVentaId) return;
                const targetId = pendingCloseVentaId;
                pendingCloseVentaId = null;
                if (closeVentaModalInstance) closeVentaModalInstance.close();
                ejecutarCierreVenta(targetId);
            });
        }

        window.addEventListener('beforeunload', prevenirCierre);

        const ventaContainers = document.getElementById('ventas-containers');
        ventaContainers.addEventListener('input', scheduleSalesDraftSave);
        ventaContainers.addEventListener('change', scheduleSalesDraftSave);
        
        const restored = restoreSalesDrafts();
        if (!restored) {
            nuevaVenta(); // Iniciar con la primera venta
        }
    });

    function nuevaVenta(draftTab = null) {
        let id = '';
        if (draftTab && typeof draftTab.id === 'string' && /^v\d+$/i.test(draftTab.id)) {
            id = draftTab.id.toLowerCase();
            const parsedNum = parseInt(id.substring(1), 10);
            if (Number.isInteger(parsedNum) && parsedNum > tabCount) {
                tabCount = parsedNum;
            }
        } else {
            tabCount++;
            id = 'v' + tabCount;
        }

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
        let tabsInstance = M.Tabs.getInstance(tabsUl);
        if (tabsInstance) {
            tabsInstance.destroy(); // Destruir instancia previa para evitar conflictos de estado
        }
        tabsInstance = M.Tabs.init(tabsUl);
        
        M.FormSelect.init(context.querySelectorAll('select'));
        M.updateTextFields();

        // 4. Configurar el buscador Autocomplete
        const buscador = context.querySelector('.buscador-producto');
        if (!buscador) return;

        // 4.1 Configurar autocompletado de cliente en el campo nombre
        const clienteNombreInput = context.querySelector('.cliente_nombre');
        const clienteTelefonoInput = context.querySelector('.cliente_telefono');
        if (clienteNombreInput) {
            M.Autocomplete.init(clienteNombreInput, {
                data: customerAutocompleteData,
                limit: 8,
                minLength: 1,
                onAutocomplete: function(val) {
                    const cliente = customerMap[String(val || '').toLowerCase()];
                    if (!cliente) return;

                    clienteNombreInput.value = cliente.nombre;
                    if (clienteTelefonoInput && (!clienteTelefonoInput.value || clienteTelefonoInput.value.trim() === '')) {
                        clienteTelefonoInput.value = cliente.telefono || '';
                    }

                    actualizarTituloTab(id, cliente.nombre);
                    M.updateTextFields();
                }
            });
        }

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

                // 1. Intentar coincidencia exacta por Código de Barras (Escáner)
                let prod = productosDisponibles.find(p => 
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

        if (draftTab && typeof draftTab === 'object') {
            if (clienteNombreInput) clienteNombreInput.value = String(draftTab.cliente_nombre || '');
            if (clienteTelefonoInput) clienteTelefonoInput.value = String(draftTab.cliente_telefono || '');
            const descuentoInput = context.querySelector('.descuento');
            if (descuentoInput) descuentoInput.value = String(draftTab.descuento ?? '0');
            const observacionesInput = context.querySelector('.observaciones');
            if (observacionesInput) observacionesInput.value = String(draftTab.observaciones || '');
            const pagoConInput = context.querySelector('.pago-con');
            if (pagoConInput) pagoConInput.value = String(draftTab.pago_con ?? '0');

            const metodoPagoSelect = context.querySelector('select[name="id_metodo_pago"]');
            if (metodoPagoSelect) {
                metodoPagoSelect.value = String(draftTab.id_metodo_pago || '1');
                M.FormSelect.init(metodoPagoSelect);
            }

            if (Array.isArray(draftTab.productos)) {
                draftTab.productos.forEach((prodDraft) => {
                    const product = productosDisponibles.find((p) => String(p.id_producto) === String(prodDraft.id_producto));
                    if (!product) return;

                    agregarProductoALista(id, product, { silent: true });

                    const itemNode = context.querySelector(`.producto-item[data-id="${product.id_producto}"]`);
                    if (!itemNode) return;

                    const qtyInput = itemNode.querySelector('.cantidad');
                    const priceInput = itemNode.querySelector('.precio-unitario');
                    const stockDisponible = parseInt(product.cantidad_actual || 0, 10) || 0;

                    if (qtyInput) {
                        const draftQty = parseInt(prodDraft.cantidad || 1, 10) || 1;
                        const finalQty = Math.max(1, Math.min(draftQty, stockDisponible > 0 ? stockDisponible : draftQty));
                        qtyInput.value = String(finalQty);
                    }

                    if (priceInput) {
                        const draftPrice = parseFloat(prodDraft.precio_unitario || product.precio_venta || 0) || 0;
                        priceInput.value = String(draftPrice);
                    }
                });
            }

            M.updateTextFields();
            actualizarTotal(id);
            actualizarTituloTab(id, clienteNombreInput ? clienteNombreInput.value : '');
        }

        // Seleccionar la nueva pestaña automáticamente
        if (tabsInstance) {
            tabsInstance.select(`venta-${id}`);
        }
        setTimeout(() => buscador.focus(), 200); // Dar foco al buscador automáticamente
        scheduleSalesDraftSave();
    }

    function actualizarTituloTab(id, nombre = '') {
        const tabTitle = document.querySelector(`#tab-li-${id} .tab-title`);
        if (tabTitle) {
            tabTitle.textContent = nombre.trim() !== '' ? nombre.substring(0, 15) : `Venta ${id.substring(1)}`;
        }
    }

    function abrirModalCerrarVenta(id) {
        pendingCloseVentaId = id;
        if (closeVentaModalInstance) {
            closeVentaModalInstance.open();
            return;
        }

        // Fallback seguro por si el modal no inicializa en algún navegador.
        if (confirm('Esta pestaña tiene datos del ticket/cliente/venta. Si la cierras, perderás esa información. ¿Deseas continuar?')) {
            const targetId = pendingCloseVentaId;
            pendingCloseVentaId = null;
            if (targetId) ejecutarCierreVenta(targetId);
        } else {
            pendingCloseVentaId = null;
        }
    }

    function cerrarVenta(id, event) {
        if (event) event.stopPropagation();
        const context = document.getElementById(`venta-${id}`);
        if (hasVentaData(context)) {
            abrirModalCerrarVenta(id);
            return;
        }

        ejecutarCierreVenta(id);
    }

    function ejecutarCierreVenta(id) {
        const context = document.getElementById(`venta-${id}`);
        if (!context) return;

        const tabLi = document.getElementById(`tab-li-${id}`);
        if (tabLi) tabLi.remove();
        if (context) {
            // Limpiar instancias de Materialize antes de remover del DOM
            const auto = M.Autocomplete.getInstance(context.querySelector('.buscador-producto'));
            if (auto) auto.destroy();
            const autoCustomer = M.Autocomplete.getInstance(context.querySelector('.cliente_nombre'));
            if (autoCustomer) autoCustomer.destroy();
            context.remove();
        }

        const tabsUl = document.getElementById('ventas-tabs');
        
        // Destruir instancia de pestañas antes de re-inicializar
        let tabsInstance = M.Tabs.getInstance(tabsUl);
        if (tabsInstance) tabsInstance.destroy();

        // Si era la última pestaña, crear una nueva automáticamente para no romper el flujo
        if (tabsUl.children.length === 0) {
            nuevaVenta();
        } else {
            M.Tabs.init(tabsUl);
        }

        clearSalesDraftIfEmpty();
        scheduleSalesDraftSave();
    }

    function agregarProductoALista(tabId, product, options = {}) {
        const silent = !!options.silent;
        const context = document.getElementById(`venta-${tabId}`);
        const sinProd = context.querySelector('.sin-productos');
        if (sinProd) sinProd.style.display = 'none';
        
        // VALIDACIÓN DE STOCK
        const stockDisponible = parseInt(product.cantidad_actual) || 0;

        const existente = context.querySelector(`.producto-item[data-id="${product.id_producto}"]`);
        if (existente) {
            const cantInput = existente.querySelector('.cantidad');
            const nuevaCant = parseInt(cantInput.value) + 1;
            
            if (nuevaCant > stockDisponible) {
                if (!silent) M.toast({html: `No hay más stock disponible de ${product.nombre} (${stockDisponible} max)`, classes: 'red'});
                return;
            }
            
            cantInput.value = nuevaCant;
            actualizarTotal(tabId);
            if (!silent) M.toast({html: `+1 ${product.nombre}`, classes: 'blue lighten-3'});
            return;
        }

        if (stockDisponible <= 0) {
            if (!silent) M.toast({html: `${product.nombre} está AGOTADO en esta sucursal`, classes: 'red darken-2'});
            return;
        }

        const label = product.nombre_variante ? `${product.nombre} - ${product.nombre_variante}` : product.nombre;
        const imgSrc = resolveProductImageSrc(product.imagen_resuelta || product.imagen_fuente || product.imagen || product.imagen_url);
        
        const html = `
            <div class="row producto-item animated fadeIn" data-id="${product.id_producto}" style="padding: 15px; margin: 10px 0; border-radius: 4px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); border-left: 4px solid #4caf50;">
                <input type="hidden" name="producto_${productoIndex}" value="${product.id_producto}">
                
                <div class="col s12 m2 center-align">
                    <img src="${imgSrc}" class="responsive-img materialboxed" style="max-height: 80px; border-radius: 4px;">
                </div>

                <div class="col s12 m3">
                    <p style="margin: 0; font-weight: bold; font-size: 1.1rem;">${label}</p>
                    <small class="grey-text">Cod: ${product.codigo_barras}</small>
                </div>
                
                <div class="input-field col s4 m2" style="margin: 0;">
                    <input type="number" class="cantidad" name="cantidad_${productoIndex}" value="1" min="1" max="${stockDisponible}" oninput="actualizarTotal('${tabId}')" style="height: 2.5rem; margin: 0; font-weight: bold; text-align: center;">
                    <label class="active">Cant.</label>
                </div>
                
                <div class="input-field col s5 m3" style="margin: 0;">
                    <input type="number" class="precio-unitario" name="precio_${productoIndex}" value="${product.precio_venta}" oninput="actualizarTotal('${tabId}')" style="height: 2.5rem; margin: 0; color: #2e7d32; font-weight: bold;">
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
        if (!silent) M.toast({html: `Agregado: ${product.nombre}`, classes: 'green'});
    }

    function actualizarTotal(tabId) {
        const context = document.getElementById(`venta-${tabId}`);
        let subtotal = 0;
        let itemsTotales = 0;
        context.querySelectorAll('.producto-item').forEach(item => {
            const id = item.dataset.id;
            const prodData = productosDisponibles.find(p => p.id_producto == id);
            const stockMax = parseInt(prodData?.cantidad_actual || 0);

            let cantidad = parseInt(item.querySelector('.cantidad').value) || 0;
            if (cantidad > stockMax) {
                M.toast({html: `Stock superado para ${prodData.nombre}. Ajustando a ${stockMax}`, classes: 'orange'});
                cantidad = stockMax;
                item.querySelector('.cantidad').value = cantidad;
            }

            const precio = parseFloat(item.querySelector('.precio-unitario').value) || 0;
            subtotal += precio * cantidad;
            itemsTotales += cantidad;
        });
        
        const descuentoManual = parseFloat(context.querySelector('.descuento').value) || 0;
        const incentivoCalc = calculatePickupOfferClient(subtotal, itemsTotales, PICKUP_OFFER_SETTINGS);
        const descuentoIncentivo = incentivoCalc.elegible ? incentivoCalc.ahorro : 0;
        const descuentoTotal = descuentoManual + descuentoIncentivo;
        const total = Math.max(0, subtotal - descuentoTotal);
        
        context.querySelector('.subtotal-val').textContent = subtotal.toFixed(2);
        const baseTotalEl = context.querySelector('.base-total-val');
        if (baseTotalEl) baseTotalEl.textContent = subtotal.toFixed(2);
        const incentivoEl = context.querySelector('.incentivo-total-val');
        if (incentivoEl) incentivoEl.textContent = descuentoIncentivo.toFixed(2);
        const descuentoEl = context.querySelector('.descuento-total-val');
        if (descuentoEl) descuentoEl.textContent = descuentoManual.toFixed(2);
        context.querySelector('.total-venta-val').textContent = total.toFixed(2);

        const banner = context.querySelector('.incentivo-banner');
        if (banner && SHOW_INCENTIVO_DETAILS) {
            if (descuentoIncentivo > 0) {
                const porPieza = incentivoCalc.descuentoPorPieza || 0;
                banner.innerHTML = `<strong>Incentivo de sucursal activo:</strong> descuento de <strong>$${porPieza.toFixed(2)}</strong> por pieza (${itemsTotales} pieza(s)), ahorro total <strong>$${descuentoIncentivo.toFixed(2)}</strong>.`;
                banner.style.display = 'block';
            } else if (PICKUP_OFFER_SETTINGS && PICKUP_OFFER_SETTINGS.activo) {
                const faltante = resolveMissingPiecesForFirstTier(itemsTotales, PICKUP_OFFER_SETTINGS);
                if (faltante > 0) {
                    banner.innerHTML = `<strong>Incentivo disponible:</strong> agrega ${faltante} pieza(s) mas para activar descuento por sucursal.`;
                    banner.style.display = 'block';
                } else {
                    banner.style.display = 'none';
                }
            } else {
                banner.style.display = 'none';
            }
        } else if (banner) {
            banner.style.display = 'none';
        }

        const pagoCon = parseFloat(context.querySelector('.pago-con').value) || 0;
        const cambio = pagoCon > 0 ? (pagoCon - total) : 0;
        context.querySelector('.cambio-val').textContent = Math.max(0, cambio).toFixed(2);
        
        const container = context.querySelector('.cambio-container');
        container.style.color = (pagoCon > 0 && pagoCon < total) ? '#ef5350' : '#81c784';
        
        actualizarTituloTab(tabId, context.querySelector('.cliente_nombre').value);
        scheduleSalesDraftSave();
    }

    function resolvePieceTierDiscountClient(pieces, settings) {
        const source = settings?.descuentos_por_pieza;
        if (!source || typeof source !== 'object') return 0;

        const tiers = Object.entries(source)
            .map(([qty, discount]) => ({
                qty: parseInt(qty, 10),
                discount: Math.max(0, parseFloat(discount) || 0)
            }))
            .filter((tier) => Number.isInteger(tier.qty) && tier.qty > 0 && tier.discount > 0)
            .sort((a, b) => a.qty - b.qty);

        if (tiers.length === 0) return 0;

        const exact = tiers.find((tier) => tier.qty === pieces);
        if (exact) return exact.discount;

        let fallback = 0;
        tiers.forEach((tier) => {
            if (pieces >= tier.qty) fallback = tier.discount;
        });

        return fallback;
    }

    function resolveMissingPiecesForFirstTier(pieces, settings) {
        const source = settings?.descuentos_por_pieza;
        if (!source || typeof source !== 'object') return 0;

        const tiers = Object.keys(source)
            .map((qty) => parseInt(qty, 10))
            .filter((qty) => Number.isInteger(qty) && qty > 0)
            .sort((a, b) => a - b);

        if (tiers.length === 0) return 0;
        return Math.max(0, tiers[0] - Math.max(0, parseInt(pieces, 10) || 0));
    }

    function calculatePickupOfferClient(subtotal, pieces, settings) {
        const subtotalNum = Math.max(0, parseFloat(subtotal) || 0);
        const piezasNum = Math.max(0, parseInt(pieces, 10) || 0);
        const activo = !!settings?.activo;
        const descuentoPorPieza = resolvePieceTierDiscountClient(piezasNum, settings);
        const elegible = activo && descuentoPorPieza > 0 && piezasNum > 0;

        let ahorro = 0;
        if (elegible) {
            ahorro = Math.min(+(descuentoPorPieza * piezasNum).toFixed(2), subtotalNum);
        }

        return {
            elegible,
            descuentoPorPieza: +descuentoPorPieza.toFixed(2),
            ahorro: +ahorro.toFixed(2),
            totalSucursal: +(subtotalNum - ahorro).toFixed(2)
        };
    }

    function eliminarProducto(btn, tabId) {
        const context = document.getElementById(`venta-${tabId}`);
        btn.closest('.producto-item').remove();
        if (context.querySelectorAll('.producto-item').length === 0) {
            context.querySelector('.sin-productos').style.display = 'block';
        }
        actualizarTotal(tabId);
        scheduleSalesDraftSave();
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
        const telefonoCliente = (context.querySelector('.cliente_telefono')?.value || '').trim();
        const obsField = context.querySelector('.observaciones');
        if (nombreCliente || telefonoCliente) {
            const headerCliente = nombreCliente
                ? (`Cliente: ${nombreCliente}` + (telefonoCliente ? ` | Tel: ${telefonoCliente}` : ''))
                : (`Cliente: Mostrador | Tel: ${telefonoCliente}`);
            obsField.value = `${headerCliente}. ${obsField.value}`;
        }

        const formData = new FormData(form);
        fetch(form.action, { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                M.toast({html: 'Venta realizada con éxito', classes: 'green darken-2'});
                document.getElementById(`tab-li-${tabId}`).remove();
                context.remove();
                scheduleSalesDraftSave();
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
