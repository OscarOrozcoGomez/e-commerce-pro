<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
if (!canManageDeliveryOrders()) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$pageTitle = 'Agendar Pedido a Domicilio';
$pdo = getPDO();
$error = '';

$id_almacen_actual = resolveSalesWarehouseId($pdo);
$almacenActualNombre = '';

if ($id_almacen_actual > 0) {
    $stmtSucursal = $pdo->prepare('SELECT nombre FROM almacenes WHERE id_almacen = ? LIMIT 1');
    $stmtSucursal->execute([$id_almacen_actual]);
    $almacenActualNombre = (string)($stmtSucursal->fetchColumn() ?: '');
}

if (!$id_almacen_actual) {
    $error = 'Error: No tienes una sucursal asignada o no se seleccionó ninguna.';
}

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
            WHERE p.estado = 'activo'
            ORDER BY p.nombre ASC, p.nombre_variante ASC";
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

try {
    $stmtMeta = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cliente_direcciones'");
    $stmtMeta->execute();
    $hasClienteDireccionesTable = ((int)$stmtMeta->fetchColumn()) > 0;

    $sql = "SELECT c.id_cliente, c.nombre, COALESCE(c.telefono, '') AS telefono
            FROM clientes c
            WHERE c.estado = 'activo'
            ORDER BY c.nombre ASC
            LIMIT 500";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $clientesActivos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($clientesActivos as &$cliente) {
        $cliente['direccion'] = '';
        $cliente['maps_link'] = '';
        $cliente['direcciones'] = [];
        foreach (['nombre', 'telefono'] as $campo) {
            $valor = (string)($cliente[$campo] ?? '');
            if ($valor !== '' && function_exists('piiIsEncryptedValue') && function_exists('piiDecryptValue') && piiIsEncryptedValue($valor)) {
                $cliente[$campo] = (string)piiDecryptValue($valor);
            }
        }
    }
    unset($cliente);

    if ($hasClienteDireccionesTable && !empty($clientesActivos)) {
        $idsCliente = array_values(array_filter(array_map(static function (array $cliente): int {
            return (int)($cliente['id_cliente'] ?? 0);
        }, $clientesActivos), static function (int $idCliente): bool {
            return $idCliente > 0;
        }));

        if (!empty($idsCliente)) {
            $placeholders = implode(', ', array_fill(0, count($idsCliente), '?'));
            $stmtDir = $pdo->prepare("SELECT id_direccion, id_cliente, alias, direccion, maps_link, es_default FROM cliente_direcciones WHERE id_cliente IN ({$placeholders}) ORDER BY id_cliente ASC, es_default DESC, id_direccion ASC");
            $stmtDir->execute($idsCliente);
            $direccionesRaw = $stmtDir->fetchAll(PDO::FETCH_ASSOC);

            $direccionesPorCliente = [];
            foreach ($direccionesRaw as $direccion) {
                foreach (['alias', 'direccion', 'maps_link'] as $campo) {
                    $valor = (string)($direccion[$campo] ?? '');
                    if ($valor !== '' && function_exists('piiIsEncryptedValue') && function_exists('piiDecryptValue') && piiIsEncryptedValue($valor)) {
                        $direccion[$campo] = (string)piiDecryptValue($valor);
                    }
                }

                $idClienteDireccion = (int)($direccion['id_cliente'] ?? 0);
                if ($idClienteDireccion <= 0) {
                    continue;
                }

                $direccionesPorCliente[$idClienteDireccion][] = [
                    'id_direccion' => (int)($direccion['id_direccion'] ?? 0),
                    'alias' => (string)($direccion['alias'] ?? ''),
                    'direccion' => (string)($direccion['direccion'] ?? ''),
                    'maps_link' => (string)($direccion['maps_link'] ?? ''),
                    'es_default' => ((int)($direccion['es_default'] ?? 0)) === 1,
                ];
            }

            foreach ($clientesActivos as &$cliente) {
                $idCliente = (int)($cliente['id_cliente'] ?? 0);
                $direccionesCliente = $direccionesPorCliente[$idCliente] ?? [];
                $cliente['direcciones'] = $direccionesCliente;
                if (!empty($direccionesCliente)) {
                    $cliente['direccion'] = (string)($direccionesCliente[0]['direccion'] ?? '');
                    $cliente['maps_link'] = (string)($direccionesCliente[0]['maps_link'] ?? '');
                }
            }
            unset($cliente);
        }
    }
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
                    <span>Sucursal operativa:</span>
                    <strong class="sales-warehouse-name"><?php echo esc($almacenActualNombre !== '' ? $almacenActualNombre : 'Sin asignar'); ?></strong>
                </div>
                <ul id="ventas-tabs" class="tabs sales-tabs" style="background: transparent; height: 45px; overflow-x: auto; overflow-y: hidden;"></ul>
                <button type="button" onclick="nuevaVenta()" class="btn-floating btn-small waves-effect waves-light indigo sales-new-tab-btn" title="Atender otro cliente" style="margin-left: 10px;">
                    <i class="material-icons">add</i>
                </button>
                <a href="<?php echo BASE_URL; ?>views/dashboard.php" class="btn waves-effect waves-light blue darken-3 z-depth-1 sales-back-dashboard-btn" title="Volver al dashboard" style="margin-left: 10px;">
                    <i class="material-icons left">dashboard</i>Volver al Dashboard
                </a>
            </div>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="card-panel red lighten-4 red-text text-darken-4"><?php echo esc($error); ?></div>
    <?php endif; ?>

    <div id="ventas-containers"></div>
</div>

<div id="modal-cerrar-venta" class="modal" style="max-width: 520px;">
    <div class="modal-content">
        <h5 style="margin-top: 0;">Cerrar pestaña de pedido</h5>
        <p>Esta pestaña tiene datos del cliente, entrega o productos.</p>
        <p class="grey-text text-darken-1" style="margin-bottom: 0;">Si la cierras, perderás esta información no guardada.</p>
    </div>
    <div class="modal-footer">
        <a href="#!" class="modal-close waves-effect waves-grey btn-flat">Cancelar</a>
        <a href="#!" id="btn-confirmar-cerrar-venta" class="waves-effect waves-light btn red darken-2">Sí, cerrar pestaña</a>
    </div>
</div>

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
                                <input type="hidden" class="cliente_id" name="id_cliente" value="">
                                <input type="text" class="cliente_nombre" name="cliente_nombre" placeholder="Busca un cliente existente" oninput="actualizarTituloTab('{{id}}', this.value)" autocomplete="off">
                                <label class="active">Cliente</label>
                                <span class="helper-text">Selecciona un cliente existente. Si no existe, registralo en <a href="<?php echo BASE_URL; ?>views/manage_customers.php" target="_blank" rel="noopener noreferrer">Administrar Clientes</a>.</span>
                                <div class="selected-client-status grey-text text-darken-1" style="font-size:0.85rem; margin-top:4px;"></div>
                            </div>
                            <div class="input-field col s12 m5">
                                <i class="material-icons prefix">phone</i>
                                <input type="tel" class="cliente_telefono" name="cliente_telefono" placeholder="Telefono del cliente seleccionado" maxlength="19" inputmode="numeric" autocomplete="tel-national" required readonly>
                                <label class="active">Telefono</label>
                                <span class="helper-text">Obligatorio para la entrega a domicilio.</span>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col s12 customer-address-block" style="display:none; margin-bottom: 10px;">
                                <label style="display:block; margin-bottom:8px; font-weight:600; color:#37474f;">Domicilios guardados del cliente</label>
                                <select class="browser-default customer-address-select" name="customer_address_id" style="border: 1px solid #cfd8dc; border-radius: 4px; padding: 10px; height: auto; width: 100%;">
                                    <option value="">-- Selecciona un domicilio --</option>
                                </select>
                                <span class="helper-text">Usa el alias para identificar Casa, Trabajo, Mama u otras direcciones guardadas.</span>
                            </div>
                        </div>

                        <div class="row">
                            <div class="input-field col s12 m8">
                                <i class="material-icons prefix">place</i>
                                <textarea class="materialize-textarea direccion_entrega" name="direccion_entrega" required placeholder="Se llena al elegir un domicilio guardado" readonly></textarea>
                                <label class="active">Direccion exacta de entrega</label>
                                <span class="helper-text">Se toma de la direccion guardada del cliente y queda reflejada para el repartidor.</span>
                                <div class="delivery-map-link" style="display:none; margin-top:8px;">
                                    <a href="#" target="_blank" rel="noopener noreferrer" class="btn-small blue darken-2 waves-effect waves-light delivery-map-link-anchor">
                                        <i class="material-icons left">map</i><span class="delivery-map-link-text">Abrir ubicación</span>
                                    </a>
                                </div>
                            </div>
                            <div class="input-field col s12 m4">
                                <i class="material-icons prefix">map</i>
                                <input type="url" class="maps_link_entrega" name="maps_link_entrega" placeholder="Link guardado del domicilio" autocomplete="off" readonly>
                                <label class="active">Link de Google Maps</label>
                                <span class="helper-text">Opcional. Si el cliente ya lo tiene guardado, aparecerá aquí.</span>
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

                        <div class="carrito-items"></div>

                        <div class="sin-productos center-align grey-text" style="padding: 20px;">
                            <i class="material-icons style-large">shopping_basket</i>
                            <p>No hay productos en el pedido. Usa el buscador de arriba para agregar.</p>
                        </div>

                        <hr>

                        <div class="row">
                            <div class="input-field col s12 m6">
                                <select name="id_metodo_pago">
                                    <option value="" selected>Se define al entregar</option>
                                    <option value="1">Efectivo</option>
                                    <option value="2">Transferencia Bancaria</option>
                                    <option value="3">Tarjeta</option>
                                    <option value="4">Cheque</option>
                                </select>
                                <label>Método de Pago Estimado (Opcional)</label>
                            </div>
                            <div class="col s12 m6" style="display:flex; align-items:center; min-height:70px;">
                                <div class="card-panel teal lighten-5 teal-text text-darken-4" style="margin:0; width:100%; padding:12px 16px;">
                                    No se aplican incentivos automáticos de sucursal. Los descuentos se capturan manualmente por producto.
                                </div>
                            </div>
                        </div>

                        <div class="input-field">
                            <textarea name="observaciones" class="materialize-textarea observaciones"></textarea>
                            <label>Observaciones</label>
                        </div>

                        <button type="submit" class="btn waves-effect waves-light green btn-large w-100">
                            Agendar Pedido <i class="material-icons right">local_shipping</i>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col s12 m4">
            <div class="card blue-grey darken-1">
                <div class="card-content white-text">
                    <span class="card-title">Resumen del Pedido</span>
                    <div style="margin-top: 20px;">
                        <div class="row" style="margin-bottom: 5px;">
                            <div class="col s6">Subtotal:</div>
                            <div class="col s6 right-align">$<span class="subtotal-val">0.00</span></div>
                        </div>
                        <div class="row" style="margin-bottom: 5px;">
                            <div class="col s6">Descuento manual:</div>
                            <div class="col s6 right-align text-red">-$<span class="descuento-total-val">0.00</span></div>
                        </div>
                        <div class="divider" style="background: rgba(255,255,255,0.2); margin: 10px 0;"></div>
                        <div class="row" style="font-size: 1.8rem; font-weight: bold;">
                            <div class="col s4">Total:</div>
                            <div class="col s8 right-align">$<span class="total-venta-val">0.00</span></div>
                        </div>
                        <div class="divider" style="background: rgba(255,255,255,0.2); margin: 10px 0;"></div>
                        <div class="row" style="margin-bottom: 0; color: #c5e1a5;">
                            <div class="col s12">
                                El cobro se realizará al momento de la entrega por el repartidor asignado.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style>
    .sales-toolbar { flex-wrap: wrap; gap: 8px; }
    .sales-warehouse-chip { display: inline-flex; align-items: center; gap: 6px; white-space: nowrap; flex: 0 0 auto; }
    .sales-warehouse-name { white-space: nowrap; }
    .sales-tabs { flex: 1 1 260px; min-width: 220px; }
    .sales-new-tab-btn { flex: 0 0 auto; }
    .tabs .tab a { display: flex; align-items: center; padding: 0 15px; text-transform: none; font-weight: 500; }
    .tabs .tab a i.close-tab { margin-left: 10px; font-size: 16px; cursor: pointer; color: #9e9e9e; }
    .tabs .tab a i.close-tab:hover { color: #f44336; }
    .tab-color-0 .active { border-bottom: 3px solid #2196f3 !important; color: #2196f3 !important; }
    .tab-color-1 .active { border-bottom: 3px solid #4caf50 !important; color: #4caf50 !important; }
    .tab-color-2 .active { border-bottom: 3px solid #9c27b0 !important; color: #9c27b0 !important; }
    .tab-color-3 .active { border-bottom: 3px solid #ff9800 !important; color: #ff9800 !important; }
    .producto-item { background: #fff; transition: all 0.3s; border-left: 4px solid #4caf50; }
    .producto-item:hover { background: #f5f5f5; }
    .w-100 { width: 100%; }
    .autocomplete-content img { width: 40px; height: 40px; margin: 5px; }
    .style-large { font-size: 4rem; opacity: 0.2; margin-top: 20px; }
    .animated { animation-duration: 0.5s; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
    .fadeIn { animation-name: fadeIn; }
    .sales-qty-control { display: flex; align-items: center; gap: 8px; }
    .sales-qty-control input { margin: 0 !important; text-align: center; }
    .sales-line-total { font-size: 1.15rem; font-weight: 700; color: #2e7d32; padding-top: 18px; }
</style>

<script>
    const productosDisponibles = <?php echo json_encode($productos, JSON_UNESCAPED_UNICODE); ?>;
    const clientesActivos = <?php echo json_encode($clientesActivos, JSON_UNESCAPED_UNICODE); ?>;
    const SALES_TABS_STORAGE_KEY = 'sales_tabs_draft_v4';
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
        if (!rawImage) return '../assets/img/no-product.png';
        const value = String(rawImage).trim();
        if (value === '') return '../assets/img/no-product.png';
        if (value.startsWith('data:') || value.startsWith('http://') || value.startsWith('https://') || value.startsWith('/') || value.startsWith('../') || value.startsWith('./')) {
            return value;
        }
        return `data:image/jpeg;base64,${value}`;
    }

    function hasVentaData(context) {
        if (!context) return false;
        const hasItems = context.querySelectorAll('.producto-item').length > 0;
        const clienteNombre = (context.querySelector('.cliente_nombre')?.value || '').trim();
        const clienteTelefono = (context.querySelector('.cliente_telefono')?.value || '').trim();
        const direccionEntrega = (context.querySelector('.direccion_entrega')?.value || '').trim();
        const observaciones = (context.querySelector('.observaciones')?.value || '').trim();
        const hasDiscount = Array.from(context.querySelectorAll('.descuento-linea')).some((input) => (parseFloat(input.value || '0') || 0) > 0);
        return hasItems || clienteNombre !== '' || clienteTelefono !== '' || direccionEntrega !== '' || observaciones !== '' || hasDiscount;
    }

    function getCurrentSalesDraft() {
        const tabs = [];
        const contexts = Array.from(document.querySelectorAll('.venta-context'));
        contexts.forEach((context) => {
            const id = String(context.id || '').replace('venta-', '');
            if (!id) return;
            const productos = Array.from(context.querySelectorAll('.producto-item')).map((item) => ({
                id_producto: parseInt(item.dataset.id || '0', 10) || 0,
                cantidad: parseInt(item.querySelector('.cantidad')?.value || '0', 10) || 0,
                precio_unitario: parseFloat(item.querySelector('.precio-unitario')?.value || '0') || 0,
                descuento_linea: parseFloat(item.querySelector('.descuento-linea')?.value || '0') || 0,
            })).filter((p) => p.id_producto > 0 && p.cantidad > 0);

            tabs.push({
                id,
                id_cliente: String(context.querySelector('.cliente_id')?.value || ''),
                cliente_nombre: (context.querySelector('.cliente_nombre')?.value || '').trim(),
                cliente_telefono: (context.querySelector('.cliente_telefono')?.value || '').trim(),
                customer_address_id: String(context.querySelector('.customer-address-select')?.value || ''),
                direccion_entrega: (context.querySelector('.direccion_entrega')?.value || '').trim(),
                maps_link_entrega: (context.querySelector('.maps_link_entrega')?.value || '').trim(),
                id_metodo_pago: String(context.querySelector('select[name="id_metodo_pago"]')?.value || ''),
                observaciones: (context.querySelector('.observaciones')?.value || '').trim(),
                productos,
            });
        });

        const activeTab = document.querySelector('#ventas-tabs .tab a.active');
        const activeHref = activeTab?.getAttribute('href') || '';
        const activeTabId = activeHref.startsWith('#venta-') ? activeHref.replace('#venta-', '') : null;

        return { version: 4, saved_at: Date.now(), active_tab_id: activeTabId, tabs };
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
        if (document.querySelectorAll('.venta-context').length === 0) {
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

    function setSelectedCustomer(context, cliente, overrideFields = true) {
        if (!context || !cliente) return;
        const clienteIdInput = context.querySelector('.cliente_id');
        const clienteNombreInput = context.querySelector('.cliente_nombre');
        const clienteTelefonoInput = context.querySelector('.cliente_telefono');
        const direccionEntregaInput = context.querySelector('.direccion_entrega');
        const mapsLinkEntregaInput = context.querySelector('.maps_link_entrega');
        const statusNode = context.querySelector('.selected-client-status');

        if (clienteIdInput) clienteIdInput.value = String(cliente.id_cliente || '');
        if (clienteNombreInput) {
            if (overrideFields) clienteNombreInput.value = String(cliente.nombre || '');
            clienteNombreInput.dataset.selectedClientName = String(cliente.nombre || '');
        }
        if (clienteTelefonoInput && overrideFields) clienteTelefonoInput.value = String(cliente.telefono || '');
        if (direccionEntregaInput && overrideFields && (!direccionEntregaInput.value || direccionEntregaInput.value.trim() === '')) {
            direccionEntregaInput.value = String(cliente.direccion || '');
        }
        if (mapsLinkEntregaInput && overrideFields && (!mapsLinkEntregaInput.value || mapsLinkEntregaInput.value.trim() === '')) {
            mapsLinkEntregaInput.value = String(cliente.maps_link || '');
        }
        context.dataset.customerMapsLink = String(cliente.maps_link || '');
        context.dataset.customerAddress = String(cliente.direccion || '');
        context.dataset.selectedCustomerId = String(cliente.id_cliente || '');
        if (statusNode) statusNode.textContent = cliente.id_cliente ? `Cliente existente seleccionado: #${cliente.id_cliente}` : '';
        renderCustomerAddressOptions(context, cliente);
        updateDeliveryMapLink(context);
    }

    function clearSelectedCustomer(context, wipeFields = false) {
        if (!context) return;
        const clienteIdInput = context.querySelector('.cliente_id');
        const clienteNombreInput = context.querySelector('.cliente_nombre');
        const clienteTelefonoInput = context.querySelector('.cliente_telefono');
        const direccionEntregaInput = context.querySelector('.direccion_entrega');
        const mapsLinkEntregaInput = context.querySelector('.maps_link_entrega');
        const statusNode = context.querySelector('.selected-client-status');

        if (clienteIdInput) clienteIdInput.value = '';
        if (clienteNombreInput) {
            clienteNombreInput.dataset.selectedClientName = '';
            if (wipeFields) clienteNombreInput.value = '';
        }
        if (clienteTelefonoInput && wipeFields) clienteTelefonoInput.value = '';
        if (direccionEntregaInput && wipeFields) direccionEntregaInput.value = '';
        if (mapsLinkEntregaInput && wipeFields) mapsLinkEntregaInput.value = '';
        context.dataset.customerMapsLink = '';
        context.dataset.customerAddress = '';
        context.dataset.selectedCustomerId = '';
        if (statusNode) statusNode.innerHTML = 'Busca un cliente existente. Si necesitas darlo de alta, hazlo en <a href="<?php echo BASE_URL; ?>views/manage_customers.php" target="_blank" rel="noopener noreferrer">Administrar Clientes</a>.';
        renderCustomerAddressOptions(context, null);
        updateDeliveryMapLink(context);
    }

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function getCustomerSavedAddresses(cliente) {
        if (!cliente || !Array.isArray(cliente.direcciones)) return [];
        return cliente.direcciones.map((direccion) => ({
            id_direccion: parseInt(direccion.id_direccion || 0, 10) || 0,
            alias: String(direccion.alias || '').trim(),
            direccion: String(direccion.direccion || '').trim(),
            maps_link: String(direccion.maps_link || '').trim(),
            es_default: !!direccion.es_default,
        })).filter((direccion) => direccion.id_direccion > 0 && direccion.direccion !== '');
    }

    function renderCustomerAddressOptions(context, cliente, preferredValue = '') {
        if (!context) return;
        const block = context.querySelector('.customer-address-block');
        const select = context.querySelector('.customer-address-select');
        if (!block || !select) return;

        const addresses = getCustomerSavedAddresses(cliente);
        context.__customerAddresses = addresses;

        if (!cliente || !cliente.id_cliente || addresses.length === 0) {
            block.style.display = cliente && cliente.id_cliente ? 'block' : 'none';
            select.innerHTML = '<option value="">-- Sin direcciones guardadas --</option>';
            const direccionInput = context.querySelector('.direccion_entrega');
            const mapsInput = context.querySelector('.maps_link_entrega');
            if (direccionInput) direccionInput.value = '';
            if (mapsInput) mapsInput.value = '';
            context.dataset.addressMode = 'none';
            const statusNode = context.querySelector('.selected-client-status');
            if (statusNode && cliente && cliente.id_cliente) {
                statusNode.innerHTML = `Cliente existente seleccionado: #${cliente.id_cliente}. Este cliente no tiene direcciones guardadas. Agregalas en <a href="<?php echo BASE_URL; ?>views/manage_customers.php" target="_blank" rel="noopener noreferrer">Administrar Clientes</a>.`;
            }
            updateDeliveryMapLink(context);
            return;
        }

        const options = ['<option value="">-- Selecciona un domicilio --</option>'];
        addresses.forEach((direccion) => {
            const suffix = direccion.es_default ? ' (Predeterminada)' : '';
            options.push(
                `<option value="${direccion.id_direccion}">${escapeHtml(direccion.alias || `Direccion ${direccion.id_direccion}`)}${suffix}: ${escapeHtml(direccion.direccion)}</option>`
            );
        });
        select.innerHTML = options.join('');
        block.style.display = 'block';

        if (preferredValue !== '') {
            select.value = preferredValue;
        }
        if (!select.value) {
            const selectedDefault = addresses.find((direccion) => direccion.es_default);
            select.value = selectedDefault ? String(selectedDefault.id_direccion) : String(addresses[0].id_direccion || '');
        }

        const selectedAddress = addresses.find((direccion) => String(direccion.id_direccion) === String(select.value));
        context.dataset.addressMode = selectedAddress ? 'saved' : 'none';
        if (selectedAddress) {
            const direccionInput = context.querySelector('.direccion_entrega');
            const mapsInput = context.querySelector('.maps_link_entrega');
            if (direccionInput) {
                direccionInput.value = selectedAddress.direccion || '';
                M.textareaAutoResize(direccionInput);
            }
            if (mapsInput) mapsInput.value = selectedAddress.maps_link || '';
            context.dataset.customerMapsLink = String(selectedAddress.maps_link || '');
            context.dataset.customerAddress = String(selectedAddress.direccion || '');
        }
        updateDeliveryMapLink(context);
        M.updateTextFields();
    }

    function updateDeliveryMapLink(context) {
        if (!context) return;

        const wrapper = context.querySelector('.delivery-map-link');
        const anchor = context.querySelector('.delivery-map-link-anchor');
        const text = context.querySelector('.delivery-map-link-text');
        const direccionActual = String(context.querySelector('.direccion_entrega')?.value || '').trim();
        const manualMapsLink = String(context.querySelector('.maps_link_entrega')?.value || '').trim();
        const savedMapsLink = String(context.dataset.customerMapsLink || '').trim();
        const savedAddress = String(context.dataset.customerAddress || '').trim();

        if (!wrapper || !anchor || !text) return;

        let href = '';
        let label = 'Abrir ubicación';

        if (manualMapsLink !== '') {
            href = manualMapsLink;
            label = 'Abrir link capturado';
        } else if (savedMapsLink !== '' && (direccionActual === '' || savedAddress === '' || direccionActual === savedAddress)) {
            href = savedMapsLink;
            label = 'Abrir ubicación guardada';
        } else if (direccionActual !== '') {
            href = 'https://www.google.com/maps/dir/?api=1&destination=' + encodeURIComponent(direccionActual);
            label = savedMapsLink !== '' ? 'Abrir ruta con dirección actual' : 'Abrir dirección en Google Maps';
        }

        if (href === '') {
            wrapper.style.display = 'none';
            anchor.setAttribute('href', '#');
            text.textContent = 'Abrir ubicación';
            return;
        }

        anchor.setAttribute('href', href);
        text.textContent = label;
        wrapper.style.display = 'block';
    }

    const prevenirCierre = (e) => {
        const contexts = Array.from(document.querySelectorAll('.venta-context'));
        if (contexts.some((ctx) => hasVentaData(ctx))) {
            e.preventDefault();
            e.returnValue = '';
        }
    };

    document.addEventListener('DOMContentLoaded', () => {
        productosDisponibles.forEach((p) => {
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
            const idCliente = parseInt(c.id_cliente || 0, 10) || 0;
            const nombre = String(c.nombre || '').trim();
            const telefono = String(c.telefono || '').trim();
            const direccion = String(c.direccion || '').trim();
            const mapsLink = String(c.maps_link || '').trim();
            const direcciones = Array.isArray(c.direcciones) ? c.direcciones : [];
            if (nombre === '') return;
            const label = telefono !== '' ? `${nombre} (${telefono})` : nombre;
            customerAutocompleteData[label] = null;
            customerMap[label.toLowerCase()] = { id_cliente: idCliente, nombre, telefono, direccion, maps_link: mapsLink, direcciones, label };
        });

        M.FormSelect.init(document.querySelectorAll('select'));
        const closeModalNode = document.getElementById('modal-cerrar-venta');
        closeVentaModalInstance = closeModalNode ? M.Modal.init(closeModalNode, { dismissible: true }) : null;

        document.getElementById('btn-confirmar-cerrar-venta')?.addEventListener('click', () => {
            if (!pendingCloseVentaId) return;
            const targetId = pendingCloseVentaId;
            pendingCloseVentaId = null;
            if (closeVentaModalInstance) closeVentaModalInstance.close();
            ejecutarCierreVenta(targetId);
        });

        window.addEventListener('beforeunload', prevenirCierre);
        const ventaContainers = document.getElementById('ventas-containers');
        ventaContainers.addEventListener('input', scheduleSalesDraftSave);
        ventaContainers.addEventListener('change', scheduleSalesDraftSave);

        if (!restoreSalesDrafts()) {
            nuevaVenta();
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

        const colorIdx = (tabCount - 1) % 4;
        const tabsUl = document.getElementById('ventas-tabs');
        const li = document.createElement('li');
        li.id = `tab-li-${id}`;
        li.className = `tab tab-color-${colorIdx}`;
        li.innerHTML = `<a href="#venta-${id}"><span class="tab-title">Pedido ${tabCount}</span><i class="material-icons close-tab" onclick="cerrarVenta('${id}', event)">close</i></a>`;
        tabsUl.appendChild(li);

        const containers = document.getElementById('ventas-containers');
        const template = document.getElementById('venta-template').innerHTML;
        containers.insertAdjacentHTML('beforeend', template.replace(/{{id}}/g, id));

        const context = document.getElementById(`venta-${id}`);
        let tabsInstance = M.Tabs.getInstance(tabsUl);
        if (tabsInstance) tabsInstance.destroy();
        tabsInstance = M.Tabs.init(tabsUl);

        M.FormSelect.init(context.querySelectorAll('select'));
        M.updateTextFields();
        clearSelectedCustomer(context, false);

        const buscador = context.querySelector('.buscador-producto');
        const clienteNombreInput = context.querySelector('.cliente_nombre');
        const clienteIdInput = context.querySelector('.cliente_id');
        const clienteTelefonoInput = context.querySelector('.cliente_telefono');
        const customerAddressSelect = context.querySelector('.customer-address-select');
        const direccionEntregaInput = context.querySelector('.direccion_entrega');
        const mapsLinkEntregaInput = context.querySelector('.maps_link_entrega');

        direccionEntregaInput?.addEventListener('input', () => updateDeliveryMapLink(context));
        mapsLinkEntregaInput?.addEventListener('input', () => updateDeliveryMapLink(context));
        customerAddressSelect?.addEventListener('change', () => {
            const addresses = Array.isArray(context.__customerAddresses) ? context.__customerAddresses : [];
            const selectedAddress = addresses.find((direccion) => String(direccion.id_direccion) === String(customerAddressSelect.value));
            renderCustomerAddressOptions(context, customerMap[String(clienteNombreInput?.value || '').toLowerCase()] || null, String(customerAddressSelect.value || ''));
            if (!selectedAddress) {
                M.toast({ html: 'Selecciona una direccion valida del cliente.', classes: 'orange' });
            }
        });

        if (clienteNombreInput) {
            M.Autocomplete.init(clienteNombreInput, {
                data: customerAutocompleteData,
                limit: 8,
                minLength: 1,
                onAutocomplete: function(val) {
                    const cliente = customerMap[String(val || '').toLowerCase()];
                    if (!cliente) return;
                    setSelectedCustomer(context, cliente);
                    if (clienteTelefonoInput) clienteTelefonoInput.value = cliente.telefono || clienteTelefonoInput.value || '';
                    if (direccionEntregaInput && (!direccionEntregaInput.value || direccionEntregaInput.value.trim() === '')) {
                        direccionEntregaInput.value = cliente.direccion || '';
                    }
                    renderCustomerAddressOptions(context, cliente);
                    actualizarTituloTab(id, cliente.nombre);
                    M.updateTextFields();
                }
            });

            clienteNombreInput.addEventListener('input', () => {
                const selectedId = String(clienteIdInput?.value || '').trim();
                const selectedName = String(clienteNombreInput.dataset.selectedClientName || '').trim();
                if (selectedId !== '' && selectedName !== '' && clienteNombreInput.value.trim() !== selectedName) {
                    clearSelectedCustomer(context, false);
                }
            });
        }

        const instance = M.Autocomplete.init(buscador, {
            data: autocompleteData,
            limit: 10,
            minLength: 1,
            onAutocomplete: function(val) {
                const prod = productMap[val.toLowerCase()];
                if (!prod) return;
                agregarProductoALista(id, prod);
                buscador.value = '';
                setTimeout(() => buscador.focus(), 100);
            }
        });

        buscador.addEventListener('keydown', function(e) {
            if (e.key !== 'Enter' && e.key !== 'Tab') return;
            const value = this.value.trim();
            if (value === '') return;
            const valueLower = value.toLowerCase();
            let prod = productosDisponibles.find((p) => p.codigo_barras && p.codigo_barras.toLowerCase() === valueLower);
            if (!prod) prod = productMap[valueLower];
            if (prod) {
                e.preventDefault();
                agregarProductoALista(id, prod);
                this.value = '';
                if (instance) instance.close();
            } else if (e.key === 'Enter') {
                const firstSuggestion = document.querySelector('.autocomplete-content li');
                if (firstSuggestion) {
                    firstSuggestion.click();
                    e.preventDefault();
                } else {
                    M.toast({ html: 'Producto no encontrado', classes: 'orange' });
                }
            }
        });

        context.querySelector('.formulario-venta').addEventListener('submit', (e) => procesarVenta(e, id));

        if (draftTab && typeof draftTab === 'object') {
            if (clienteIdInput) clienteIdInput.value = String(draftTab.id_cliente || '');
            if (clienteNombreInput) clienteNombreInput.value = String(draftTab.cliente_nombre || '');
            if (clienteTelefonoInput) clienteTelefonoInput.value = String(draftTab.cliente_telefono || '');
            if (customerAddressSelect) customerAddressSelect.value = String(draftTab.customer_address_id || '');
            if (direccionEntregaInput) direccionEntregaInput.value = String(draftTab.direccion_entrega || '');
            if (mapsLinkEntregaInput) mapsLinkEntregaInput.value = String(draftTab.maps_link_entrega || '');

            const observacionesInput = context.querySelector('.observaciones');
            if (observacionesInput) observacionesInput.value = String(draftTab.observaciones || '');

            const metodoPagoSelect = context.querySelector('select[name="id_metodo_pago"]');
            if (metodoPagoSelect) {
                metodoPagoSelect.value = String(draftTab.id_metodo_pago || '');
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
                    const discountInput = itemNode.querySelector('.descuento-linea');
                    const stockDisponible = parseInt(product.cantidad_actual || 0, 10) || 0;
                    if (qtyInput) {
                        const draftQty = parseInt(prodDraft.cantidad || 1, 10) || 1;
                        qtyInput.value = String(Math.max(1, Math.min(draftQty, stockDisponible > 0 ? stockDisponible : draftQty)));
                    }
                    if (priceInput) priceInput.value = String(parseFloat(prodDraft.precio_unitario || product.precio_venta || 0) || 0);
                    if (discountInput) discountInput.value = String(parseFloat(prodDraft.descuento_linea || 0) || 0);
                });
            }

            if (clienteIdInput && clienteIdInput.value !== '') {
                const selected = clientesActivos.find((c) => String(c.id_cliente) === String(clienteIdInput.value));
                if (selected) {
                    setSelectedCustomer(context, {
                        id_cliente: parseInt(selected.id_cliente, 10) || 0,
                        nombre: String(selected.nombre || ''),
                        telefono: String(selected.telefono || ''),
                        direccion: String(selected.direccion || ''),
                        maps_link: String(selected.maps_link || ''),
                        direcciones: Array.isArray(selected.direcciones) ? selected.direcciones : [],
                    }, false);
                    renderCustomerAddressOptions(context, selected, String(draftTab.customer_address_id || ''));
                }
            }

            M.updateTextFields();
            actualizarTotal(id);
            actualizarTituloTab(id, clienteNombreInput ? clienteNombreInput.value : '');
        }

        updateDeliveryMapLink(context);

        if (tabsInstance) tabsInstance.select(`venta-${id}`);
        setTimeout(() => buscador.focus(), 200);
        scheduleSalesDraftSave();
    }

    function actualizarTituloTab(id, nombre = '') {
        const tabTitle = document.querySelector(`#tab-li-${id} .tab-title`);
        if (tabTitle) tabTitle.textContent = nombre.trim() !== '' ? nombre.substring(0, 15) : `Pedido ${id.substring(1)}`;
    }

    function abrirModalCerrarVenta(id) {
        pendingCloseVentaId = id;
        if (closeVentaModalInstance) {
            closeVentaModalInstance.open();
            return;
        }
        if (confirm('Esta pestaña tiene datos del pedido. Si la cierras, perderás esa información. ¿Deseas continuar?')) {
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
        const autoProducto = M.Autocomplete.getInstance(context.querySelector('.buscador-producto'));
        if (autoProducto) autoProducto.destroy();
        const autoCustomer = M.Autocomplete.getInstance(context.querySelector('.cliente_nombre'));
        if (autoCustomer) autoCustomer.destroy();
        context.remove();

        const tabsUl = document.getElementById('ventas-tabs');
        let tabsInstance = M.Tabs.getInstance(tabsUl);
        if (tabsInstance) tabsInstance.destroy();
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
        context.querySelector('.sin-productos').style.display = 'none';
        const stockDisponible = parseInt(product.cantidad_actual || 0, 10) || 0;

        const existente = context.querySelector(`.producto-item[data-id="${product.id_producto}"]`);
        if (existente) {
            const cantInput = existente.querySelector('.cantidad');
            const nuevaCant = (parseInt(cantInput.value || '0', 10) || 0) + 1;
            if (stockDisponible > 0 && nuevaCant > stockDisponible) {
                if (!silent) M.toast({ html: `No hay más stock disponible de ${product.nombre} (${stockDisponible} max)`, classes: 'red' });
                return;
            }
            cantInput.value = String(nuevaCant);
            actualizarTotal(tabId);
            if (!silent) M.toast({ html: `+1 ${product.nombre}`, classes: 'blue lighten-3' });
            return;
        }

        if (stockDisponible <= 0) {
            if (!silent) M.toast({ html: `${product.nombre} está agotado en esta sucursal`, classes: 'red darken-2' });
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
                    <small class="grey-text">Cod: ${product.codigo_barras || 'N/A'}</small>
                </div>
                <div class="col s12 m2">
                    <label class="active">Cant.</label>
                    <div class="sales-qty-control">
                        <button type="button" class="btn-small grey lighten-1 black-text waves-effect" onclick="decrementarCantidad(this, '${tabId}')">-</button>
                        <input type="number" class="cantidad" name="cantidad_${productoIndex}" value="1" min="1" max="${stockDisponible}" oninput="actualizarTotal('${tabId}')" style="height: 2.5rem; font-weight: bold; text-align: center;">
                        <button type="button" class="btn-small grey lighten-1 black-text waves-effect" onclick="incrementarCantidad(this, '${tabId}')">+</button>
                    </div>
                </div>
                <div class="input-field col s6 m2" style="margin: 0;">
                    <input type="number" class="precio-unitario" name="precio_${productoIndex}" value="${product.precio_venta}" min="0.01" step="0.01" oninput="actualizarTotal('${tabId}')" style="height: 2.5rem; margin: 0; color: #2e7d32; font-weight: bold;">
                    <label class="active">Precio Unit.</label>
                </div>
                <div class="input-field col s6 m2" style="margin: 0;">
                    <input type="number" class="descuento-linea" name="descuento_linea_${productoIndex}" value="0" min="0" step="0.01" oninput="actualizarTotal('${tabId}')" style="height: 2.5rem; margin: 0; color: #c62828; font-weight: bold;">
                    <label class="active">Desc. $</label>
                </div>
                <div class="col s8 m2 right-align sales-line-total">$<span class="line-subtotal">0.00</span></div>
                <div class="col s4 m1 right-align" style="padding-top: 5px;">
                    <button type="button" class="btn-floating btn-small waves-effect waves-light red" onclick="eliminarProducto(this, '${tabId}')"><i class="material-icons">delete</i></button>
                </div>
            </div>
        `;
        context.querySelector('.carrito-items').insertAdjacentHTML('afterbegin', html);
        M.Materialbox.init(context.querySelectorAll('.materialboxed'));

        productoIndex++;
        actualizarTotal(tabId);
        if (!silent) M.toast({ html: `Agregado: ${product.nombre}`, classes: 'green' });
    }

    function incrementarCantidad(btn, tabId) {
        const item = btn.closest('.producto-item');
        if (!item) return;
        const input = item.querySelector('.cantidad');
        const max = parseInt(input?.getAttribute('max') || '0', 10) || 0;
        const current = parseInt(input?.value || '0', 10) || 0;
        if (max > 0 && current >= max) {
            M.toast({ html: 'No hay más stock disponible para este producto.', classes: 'orange' });
            return;
        }
        input.value = String(current + 1);
        actualizarTotal(tabId);
    }

    function decrementarCantidad(btn, tabId) {
        const item = btn.closest('.producto-item');
        if (!item) return;
        const input = item.querySelector('.cantidad');
        const current = parseInt(input?.value || '0', 10) || 0;
        if (current <= 1) {
            eliminarProducto(btn, tabId);
            return;
        }
        input.value = String(current - 1);
        actualizarTotal(tabId);
    }

    function actualizarTotal(tabId) {
        const context = document.getElementById(`venta-${tabId}`);
        let subtotal = 0;
        let descuentoManual = 0;
        context.querySelectorAll('.producto-item').forEach((item) => {
            const id = item.dataset.id;
            const prodData = productosDisponibles.find((p) => p.id_producto == id);
            const stockMax = parseInt(prodData?.cantidad_actual || 0, 10) || 0;

            let cantidad = parseInt(item.querySelector('.cantidad').value, 10) || 0;
            if (stockMax > 0 && cantidad > stockMax) {
                M.toast({ html: `Stock superado para ${prodData.nombre}. Ajustando a ${stockMax}`, classes: 'orange' });
                cantidad = stockMax;
                item.querySelector('.cantidad').value = String(cantidad);
            }
            if (cantidad < 1) {
                cantidad = 1;
                item.querySelector('.cantidad').value = '1';
            }

            const precio = parseFloat(item.querySelector('.precio-unitario').value) || 0;
            const descuentoInput = item.querySelector('.descuento-linea');
            const subtotalBase = precio * cantidad;
            let descuentoLinea = parseFloat(descuentoInput?.value || '0') || 0;

            if (descuentoLinea < 0) {
                descuentoLinea = 0;
                if (descuentoInput) descuentoInput.value = '0';
            }
            if (descuentoLinea > subtotalBase) {
                descuentoLinea = subtotalBase;
                if (descuentoInput) descuentoInput.value = subtotalBase.toFixed(2);
                M.toast({ html: 'El descuento no puede superar el subtotal del producto.', classes: 'orange' });
            }

            subtotal += subtotalBase;
            descuentoManual += descuentoLinea;
            const subtotalLinea = Math.max(0, subtotalBase - descuentoLinea);
            const lineSubtotal = item.querySelector('.line-subtotal');
            if (lineSubtotal) lineSubtotal.textContent = subtotalLinea.toFixed(2);
        });

        const total = Math.max(0, subtotal - descuentoManual);
        context.querySelector('.subtotal-val').textContent = subtotal.toFixed(2);
        context.querySelector('.descuento-total-val').textContent = descuentoManual.toFixed(2);
        context.querySelector('.total-venta-val').textContent = total.toFixed(2);

        actualizarTituloTab(tabId, context.querySelector('.cliente_nombre').value);
        scheduleSalesDraftSave();
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
        if (context.querySelectorAll('.producto-item').length === 0) {
            M.toast({ html: 'Debes agregar al menos un producto', classes: 'red darken-2' });
            return;
        }

        const clienteId = String(context.querySelector('.cliente_id')?.value || '').trim();
        const telefonoCliente = (context.querySelector('.cliente_telefono')?.value || '').trim();
        const customerAddressId = String(context.querySelector('.customer-address-select')?.value || '').trim();
        const direccionEntrega = (context.querySelector('.direccion_entrega')?.value || '').trim();

        if (clienteId === '') {
            M.toast({ html: 'Selecciona un cliente existente.', classes: 'red darken-2' });
            return;
        }
        if (telefonoCliente === '') {
            M.toast({ html: 'El cliente seleccionado no tiene telefono. Actualizalo en Administrar Clientes.', classes: 'red darken-2' });
            return;
        }
        if (!/^\d+$/.test(customerAddressId)) {
            M.toast({ html: 'Selecciona una direccion guardada del cliente.', classes: 'red darken-2' });
            return;
        }
        if (direccionEntrega === '') {
            M.toast({ html: 'La direccion seleccionada no es valida. Revisa el cliente en Administrar Clientes.', classes: 'red darken-2' });
            return;
        }

        const form = e.target;
        const submitButton = form.querySelector('button[type="submit"]');
        submitButton.disabled = true;
        submitButton.innerHTML = 'Procesando...';

        fetch(form.action, { method: 'POST', body: new FormData(form) })
            .then((response) => response.json())
            .then((data) => {
                if (data.success) {
                    M.toast({ html: data.message || 'Pedido agendado con éxito', classes: 'green darken-2' });
                    document.getElementById(`tab-li-${tabId}`).remove();
                    context.remove();
                    scheduleSalesDraftSave();
                    if (document.querySelectorAll('.tab').length === 0) location.reload();
                } else {
                    M.toast({ html: data.message || 'Error al procesar el pedido', classes: 'red darken-2' });
                    submitButton.disabled = false;
                    submitButton.innerHTML = 'Agendar Pedido <i class="material-icons right">local_shipping</i>';
                }
            })
            .catch((error) => {
                console.error(error);
                submitButton.disabled = false;
                submitButton.innerHTML = 'Agendar Pedido <i class="material-icons right">local_shipping</i>';
            });
    }
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>