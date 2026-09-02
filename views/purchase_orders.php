<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
if (!isAdmin() && !isEncargado()) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$pageTitle = 'Lista de Compra Sugerida';
include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row" id="po-app" style="display: none;">
        <div class="col s12">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 20px; flex-wrap: wrap; gap: 10px;">
                <h4 style="margin: 0;"><i class="material-icons left" style="font-size: 2.5rem; color: #1a237e;">shopping_cart</i> Compras y Resurtido</h4>
                <a href="<?php echo BASE_URL; ?>views/dashboard.php" class="btn blue darken-4 waves-effect waves-light"><i class="material-icons left">dashboard</i> Volver al Dashboard</a>
            </div>
            <p class="grey-text">Genera órdenes de compra desde la lista sugerida, revisa lo pospuesto y surte lo que llega.</p>
        </div>
        <div class="col s12" id="po-csrf"><?php echo csrfInput(); ?></div>
    </div>

    <div class="row">
        <div class="col s12">
            <ul class="tabs" id="po-tabs">
                <li class="tab col s4"><a class="active" href="#tab-lista">Lista de Compra</a></li>
                <li class="tab col s4"><a href="#tab-pospuestos">Pospuestos <span class="new badge blue" data-badge-caption="" id="pospuestos-badge" style="display:none;">0</span></a></li>
                <li class="tab col s4"><a href="#tab-ordenes">Órdenes Abiertas <span class="new badge green" data-badge-caption="" id="ordenes-badge" style="display:none;">0</span></a></li>
            </ul>
        </div>
    </div>

    <!-- ================= TAB 1: LISTA DE COMPRA ================= -->
    <div id="tab-lista">
        <div class="row">
            <div class="col s12">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Sugerencias de Resurtido</span>
                        <p class="grey-text">Ajusta las cantidades y genera la orden de compra. Los productos ordenados salen de esta lista hasta que se surtan.</p>

                        <div id="po-list-container">
                            <div class="center-align" style="padding: 40px;">
                                <div class="preloader-wrapper small active">
                                    <div class="spinner-layer border-blue">
                                        <div class="circle-clipper left"><div class="circle"></div></div><div class="gap-patch"><div class="circle"></div></div><div class="circle-clipper right"><div class="circle"></div></div>
                                    </div>
                                </div>
                                <p>Calculando sugerencias...</p>
                            </div>
                        </div>

                        <div id="po-form-wrapper" style="display: none;">
                            <form id="form-entrada-masiva">
                                <?php echo csrfInput(); ?>
                                <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                                    <table class="striped highlight responsive-table" style="margin-top: 20px; min-width: 720px;">
                                        <thead>
                                            <tr>
                                                <th>Producto</th>
                                                <th>Sucursal</th>
                                                <th>P. Venta</th>
                                                <th class="center-align">Stock Actual</th>
                                                <th class="center-align" style="width: 180px;">Ajustar Mín/Máx</th>
                                                <th class="blue lighten-5 center-align" style="width: 150px;">Cantidad a Pedir</th>
                                                <th class="right-align">Subtotal Est.</th>
                                                <th class="center-align" style="width: 120px;">Acción</th>
                                            </tr>
                                        </thead>
                                        <tbody id="table-po-body"></tbody>
                                    </table>
                                </div>

                                <div class="row" style="margin-top: 30px; display: flex; align-items: center; justify-content: flex-end; gap: 20px; flex-wrap: wrap;">
                                    <div class="grey-text text-darken-2">
                                        <h5 style="margin: 0;">Total Inversión: <strong>$<span id="total-inversion-val">0.00</span></strong></h5>
                                    </div>
                                    <div>
                                        <button type="button" onclick="guardarReglasMasivas()" class="btn-large blue darken-2 waves-effect waves-light">
                                            <i class="material-icons left">settings</i> ACTUALIZAR MÍNIMOS/MÁXIMOS
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button" onclick="generarOrdenCompra()" class="btn-large green darken-2 waves-effect waves-light">
                                            <i class="material-icons left">assignment</i> GENERAR ORDEN DE COMPRA
                                        </button>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div id="chart-po-row" class="row no-print" style="margin-top: 30px; display: none;">
            <div class="col s12 m6 offset-m3">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title center-align">Distribución de Faltantes por Categoría</span>
                        <div style="max-width: 400px; margin: 0 auto;">
                            <canvas id="chartFaltantes" height="300"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ================= TAB 2: POSPUESTOS ================= -->
    <div id="tab-pospuestos">
        <div class="row">
            <div class="col s12">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Productos Pospuestos</span>
                        <p class="grey-text">Productos que sacaste de la compra. Devuélvelos cuando quieras incluirlos en el siguiente pedido.</p>
                        <div id="pospuestos-container">
                            <div class="center-align" style="padding: 30px;">
                                <div class="preloader-wrapper small active">
                                    <div class="spinner-layer border-blue">
                                        <div class="circle-clipper left"><div class="circle"></div></div><div class="gap-patch"><div class="circle"></div></div><div class="circle-clipper right"><div class="circle"></div></div>
                                    </div>
                                </div>
                                <p>Cargando pospuestos...</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ================= TAB 3: ÓRDENES ABIERTAS ================= -->
    <div id="tab-ordenes">
        <div class="row">
            <div class="col s12">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Órdenes de Compra Abiertas</span>
                        <p class="grey-text">Cuando llegue la mercancía, captura lo recibido y da "Surtir". Lo que no llegó (0) no afecta el inventario.</p>
                        <div id="ordenes-container">
                            <div class="center-align" style="padding: 30px;">
                                <div class="preloader-wrapper small active">
                                    <div class="spinner-layer border-blue">
                                        <div class="circle-clipper left"><div class="circle"></div></div><div class="gap-patch"><div class="circle"></div></div><div class="circle-clipper right"><div class="circle"></div></div>
                                    </div>
                                </div>
                                <p>Cargando órdenes...</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Incluimos librerías necesarias -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>

<script>
    const PO_BASE = '<?php echo BASE_URL; ?>';

    function escHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[c]);
    }

    function getCsrf() {
        const el = document.querySelector('input[name="csrf_token"]');
        return el ? el.value : '';
    }

    function poToast(msg, cls) {
        if (typeof M !== 'undefined' && M.toast) {
            M.toast({ html: escHtml(msg), classes: cls || '' });
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        if (typeof M !== 'undefined' && M.Tabs) {
            M.Tabs.init(document.getElementById('po-tabs'), {});
        }
        document.getElementById('po-app').style.display = 'block';

        cargarListaCompra();
        cargarPospuestos();
        cargarOrdenes();
    });

    // ============================================================
    // TAB 1: LISTA DE COMPRA
    // ============================================================
    function cargarListaCompra() {
        const listContainer = document.getElementById('po-list-container');

        const showError = (msg) => {
            listContainer.style.display = 'block';
            listContainer.innerHTML = `
                <div class="center-align" style="padding: 40px;">
                    <i class="material-icons large red-text">error_outline</i>
                    <h5>No se pudieron calcular las sugerencias</h5>
                    <p class="grey-text">${escHtml(msg)}</p>
                    <button class="btn blue darken-2" onclick="location.reload()">Reintentar</button>
                </div>`;
            poToast('Error: ' + msg, 'red');
        };

        fetch(PO_BASE + 'api/purchase_orders_data.php', { headers: { 'Accept': 'application/json' } })
            .then(async (r) => {
                const text = await r.text();
                try {
                    return JSON.parse(text);
                } catch (e) {
                    throw new Error(`Respuesta no válida del servidor (HTTP ${r.status}). Recarga la página o vuelve a iniciar sesión.`);
                }
            })
            .then(res => {
                if (!res.success) throw new Error(res.message || 'Error desconocido');

                const listaCompra = Array.isArray(res.listaCompra) ? res.listaCompra : [];
                listContainer.style.display = 'none';

                if (listaCompra.length === 0) {
                    document.getElementById('po-form-wrapper').style.display = 'none';
                    listContainer.style.display = 'block';
                    listContainer.innerHTML = `
                        <div class="center-align" style="padding: 40px;">
                            <i class="material-icons large green-text">check_circle</i>
                            <h5>¡Inventario saludable!</h5>
                            <p>No hay productos que necesiten resurtido actualmente.</p>
                        </div>`;
                } else {
                    document.getElementById('po-form-wrapper').style.display = 'block';
                    renderTable(listaCompra);
                    if (res.chartData && res.chartData.length > 0) {
                        document.getElementById('chart-po-row').style.display = 'block';
                        renderChart(res.chartData);
                    }
                }
            })
            .catch(err => showError(err.message));
    }

    function renderTable(items) {
        const tbody = document.getElementById('table-po-body');
        tbody.innerHTML = '';

        items.forEach((item, index) => {
            const stockMax = parseInt(item.stock_maximo, 10) || 0;
            const stockActual = parseInt(item.cantidad_actual, 10) || 0;
            const precioCosto = parseFloat(item.precio_costo) || 0;
            const aComprar = Math.max(0, stockMax - stockActual);
            const costoFila = aComprar * precioCosto;
            const idProducto = Number(item.id_producto) || 0;
            const idAlmacen = Number(item.id_almacen) || 0;

            tbody.innerHTML += `
                <tr id="po-row-${index}">
                    <td><strong>${escHtml(item.nombre)}</strong><br><small class="grey-text">SKU: ${escHtml(item.sku)}</small></td>
                    <td>${escHtml(item.sucursal)}</td>
                    <td>$${(parseFloat(item.precio_venta) || 0).toFixed(2)}</td>
                    <td class="red-text center-align"><strong>${stockActual}</strong></td>
                    <td class="center-align">
                        <div style="display: flex; gap: 5px;">
                            <input type="number" name="items[${index}][stock_minimo]" value="${escHtml(item.stock_minimo)}" class="browser-default qty-input" title="Mínimo" style="width: 50%; padding: 2px;">
                            <input type="number" name="items[${index}][stock_maximo]" value="${escHtml(item.stock_maximo)}" class="browser-default qty-input" title="Máximo" style="width: 50%; padding: 2px;">
                        </div>
                    </td>
                    <td class="blue lighten-5">
                        <input type="hidden" name="items[${index}][id_producto]" value="${idProducto}">
                        <input type="hidden" name="items[${index}][id_almacen]" value="${idAlmacen}">
                        <input type="hidden" name="items[${index}][precio_costo]" value="${precioCosto}">
                        <input type="number" name="items[${index}][cantidad]" value="${aComprar}" min="0" class="browser-default qty-input" style="width: 100%; text-align: center; border: 1px solid #9e9e9e; border-radius: 4px; padding: 5px;">
                    </td>
                    <td class="right-align po-subtotal">$${costoFila.toFixed(2)}</td>
                    <td class="center-align">
                        <button type="button" class="btn-flat red-text" aria-label="Posponer producto" title="Posponer para el siguiente pedido" onclick="posponerItem(${index}, ${idProducto}, ${idAlmacen})">
                            <i class="material-icons">schedule</i>
                        </button>
                    </td>
                </tr>`;
        });

        bindQtyRecalculation();
        recalculateTotalInversion();
    }

    function bindQtyRecalculation() {
        document.querySelectorAll('input[name$="[cantidad]"]').forEach((input) => {
            input.addEventListener('input', recalculateTotalInversion);
        });
    }

    function recalculateTotalInversion() {
        let total = 0;

        document.querySelectorAll('#table-po-body tr').forEach((row) => {
            const qtyInput = row.querySelector('input[name$="[cantidad]"]');
            const costInput = row.querySelector('input[name$="[precio_costo]"]');
            const subtotalCell = row.querySelector('.po-subtotal');

            const qty = Math.max(0, parseInt(qtyInput?.value || '0', 10));
            const unitCost = parseFloat(costInput?.value || '0');
            const subtotal = qty * unitCost;

            if (subtotalCell) {
                subtotalCell.textContent = '$' + subtotal.toFixed(2);
            }

            total += subtotal;
        });

        document.getElementById('total-inversion-val').textContent = total.toFixed(2);
    }

    function collectListaItems() {
        const form = document.getElementById('form-entrada-masiva');
        const formData = new FormData(form);
        const itemsMap = {};
        for (let [key, value] of formData.entries()) {
            if (key.startsWith('items[')) {
                const match = key.match(/items\[(\d+)\]\[(\w+)\]/);
                if (match) {
                    const index = match[1];
                    const field = match[2];
                    if (!itemsMap[index]) itemsMap[index] = {};
                    itemsMap[index][field] = value;
                }
            }
        }
        return Object.values(itemsMap);
    }

    function generarOrdenCompra() {
        const items = collectListaItems().filter(i => parseInt(i.cantidad, 10) > 0);

        if (items.length === 0) {
            poToast('No hay cantidades para ordenar', 'orange');
            return;
        }

        Swal.fire({
            title: '¿Generar orden de compra?',
            text: `Se creará una orden con ${items.length} producto(s). Saldrán de esta lista hasta que se surtan.`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#2e7d32',
            confirmButtonText: 'Sí, generar orden'
        }).then((result) => {
            if (!result.isConfirmed) return;

            fetch(PO_BASE + 'api/purchase_order_create.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: getCsrf(), items })
            })
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    poToast('Error: ' + res.message, 'red');
                    return;
                }
                poToast(res.message || 'Orden de compra generada', 'green');
                setTimeout(() => location.reload(), 1200);
            })
            .catch(() => poToast('Error de conexión. Inténtalo de nuevo.', 'red'));
        });
    }

    function posponerItem(index, idProducto, idAlmacen) {
        Swal.fire({
            title: '¿Posponer producto?',
            text: 'Este producto se quitará de la compra actual y quedará en la pestaña de Pospuestos.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#1565c0',
            confirmButtonText: 'Sí, posponer'
        }).then((result) => {
            if (!result.isConfirmed) return;

            fetch(PO_BASE + 'api/postpone_purchase_items.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token: getCsrf(),
                    items: [{ id_producto: idProducto, id_almacen: idAlmacen }]
                })
            })
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    poToast('Error: ' + res.message, 'red');
                    return;
                }

                const row = document.getElementById('po-row-' + index);
                if (row) {
                    row.remove();
                    recalculateTotalInversion();
                }

                if (document.querySelectorAll('#table-po-body tr').length === 0) {
                    document.getElementById('po-form-wrapper').style.display = 'none';
                    document.getElementById('po-list-container').style.display = 'block';
                    document.getElementById('po-list-container').innerHTML = `
                        <div class="center-align" style="padding: 40px;">
                            <i class="material-icons large blue-text">schedule</i>
                            <h5>No hay productos activos en esta ronda</h5>
                            <p>Todos los productos fueron pospuestos o ya están cubiertos.</p>
                        </div>`;
                }

                poToast(res.message, 'blue');
                cargarPospuestos();
            })
            .catch(() => poToast('Error de conexión. Inténtalo de nuevo.', 'red'));
        });
    }

    function renderChart(data) {
        const ctx = document.getElementById('chartFaltantes').getContext('2d');
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: data.map(d => String(d.categoria ?? '')),
                datasets: [{
                    data: data.map(d => d.total),
                    backgroundColor: ['#1a237e', '#283593', '#303f9f', '#3949ab', '#3f51b5', '#5c6bc0', '#7986cb', '#9fa8da', '#c5cae9', '#e8eaf6']
                }]
            },
            options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
        });
    }

    function guardarReglasMasivas() {
        const items = collectListaItems();

        if (items.length === 0) {
            poToast('No hay productos para actualizar', 'orange');
            return;
        }

        Swal.fire({
            title: '¿Actualizar reglas de stock?',
            text: "Se guardarán los nuevos niveles mínimos y máximos para estos productos.",
            icon: 'info',
            showCancelButton: true,
            confirmButtonText: 'Sí, actualizar'
        }).then((result) => {
            if (!result.isConfirmed) return;

            fetch(PO_BASE + 'api/update_thresholds.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: getCsrf(), items })
            })
            .then(r => r.json())
            .then(res => {
                poToast(res.message, res.success ? 'green' : 'red');
                if (res.success) setTimeout(() => location.reload(), 1000);
            })
            .catch(() => poToast('Error de conexión. Inténtalo de nuevo.', 'red'));
        });
    }

    // ============================================================
    // TAB 2: POSPUESTOS
    // ============================================================
    function cargarPospuestos() {
        const container = document.getElementById('pospuestos-container');

        fetch(PO_BASE + 'api/postponed_items_data.php', { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(res => {
                if (!res.success) throw new Error(res.message || 'Error');
                const filas = Array.isArray(res.pospuestos) ? res.pospuestos : [];

                const badge = document.getElementById('pospuestos-badge');
                if (filas.length > 0) {
                    badge.textContent = filas.length;
                    badge.style.display = '';
                } else {
                    badge.style.display = 'none';
                }

                if (filas.length === 0) {
                    container.innerHTML = `
                        <div class="center-align" style="padding: 30px;">
                            <i class="material-icons large grey-text">inbox</i>
                            <h5>No hay productos pospuestos</h5>
                        </div>`;
                    return;
                }

                let rows = '';
                filas.forEach(f => {
                    const fecha = String(f.pospuesto_en || '').replace('T', ' ').slice(0, 16);
                    rows += `
                        <tr>
                            <td><strong>${escHtml(f.nombre)}</strong><br><small class="grey-text">SKU: ${escHtml(f.sku)}</small></td>
                            <td>${escHtml(f.sucursal)}</td>
                            <td>${escHtml(f.motivo)}</td>
                            <td>${escHtml(f.pospuesto_por || '—')}</td>
                            <td>${escHtml(fecha)}</td>
                            <td class="center-align">
                                <button type="button" class="btn-small blue darken-2 waves-effect waves-light"
                                    onclick="devolverPospuesto(${Number(f.id_postergacion) || 0}, this)">
                                    <i class="material-icons left">undo</i> Devolver
                                </button>
                            </td>
                        </tr>`;
                });

                container.innerHTML = `
                    <div style="overflow-x:auto;">
                        <table class="striped highlight responsive-table" style="min-width: 720px;">
                            <thead>
                                <tr>
                                    <th>Producto</th><th>Sucursal</th><th>Motivo</th>
                                    <th>Pospuesto por</th><th>Fecha</th><th class="center-align">Acción</th>
                                </tr>
                            </thead>
                            <tbody>${rows}</tbody>
                        </table>
                    </div>`;
            })
            .catch(err => {
                container.innerHTML = `<div class="center-align" style="padding:30px;"><p class="red-text">${escHtml(err.message)}</p></div>`;
            });
    }

    function devolverPospuesto(idPostergacion, btn) {
        Swal.fire({
            title: '¿Devolver a la compra?',
            text: 'El producto volverá a aparecer en la Lista de Compra.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#1565c0',
            confirmButtonText: 'Sí, devolver'
        }).then((result) => {
            if (!result.isConfirmed) return;

            fetch(PO_BASE + 'api/postpone_reactivate.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: getCsrf(), id_postergacion: idPostergacion })
            })
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    poToast('Error: ' + res.message, 'red');
                    return;
                }
                const row = btn.closest('tr');
                if (row) row.remove();
                poToast(res.message || 'Producto devuelto', 'green');
                cargarPospuestos();
            })
            .catch(() => poToast('Error de conexión. Inténtalo de nuevo.', 'red'));
        });
    }

    // ============================================================
    // TAB 3: ÓRDENES ABIERTAS
    // ============================================================
    function cargarOrdenes() {
        const container = document.getElementById('ordenes-container');

        fetch(PO_BASE + 'api/purchase_orders_open.php', { headers: { 'Accept': 'application/json' } })
            .then(r => r.json())
            .then(res => {
                if (!res.success) throw new Error(res.message || 'Error');
                const ordenes = Array.isArray(res.ordenes) ? res.ordenes : [];

                const badge = document.getElementById('ordenes-badge');
                if (ordenes.length > 0) {
                    badge.textContent = ordenes.length;
                    badge.style.display = '';
                } else {
                    badge.style.display = 'none';
                }

                if (ordenes.length === 0) {
                    container.innerHTML = `
                        <div class="center-align" style="padding: 30px;">
                            <i class="material-icons large grey-text">assignment_turned_in</i>
                            <h5>No hay órdenes de compra abiertas</h5>
                        </div>`;
                    return;
                }

                container.innerHTML = ordenes.map(renderOrdenCard).join('');
            })
            .catch(err => {
                container.innerHTML = `<div class="center-align" style="padding:30px;"><p class="red-text">${escHtml(err.message)}</p></div>`;
            });
    }

    function renderOrdenCard(orden) {
        const id = Number(orden.id_orden_compra) || 0;
        const lineas = Array.isArray(orden.lineas) ? orden.lineas : [];
        const fecha = String(orden.fecha_creacion || '').replace('T', ' ').slice(0, 16);

        const filas = lineas.map(l => {
            const idDetalle = Number(l.id_detalle) || 0;
            const solicitada = parseInt(l.cantidad_solicitada, 10) || 0;
            return `
                <tr>
                    <td><strong>${escHtml(l.nombre)}</strong><br><small class="grey-text">SKU: ${escHtml(l.sku)}</small></td>
                    <td class="center-align">${solicitada}</td>
                    <td class="center-align" style="width:130px;">
                        <input type="number" min="0" value="${solicitada}" data-id-detalle="${idDetalle}"
                            class="browser-default qty-input po-recibida" style="width:100%; text-align:center; border:1px solid #9e9e9e; border-radius:4px; padding:5px;">
                    </td>
                </tr>`;
        }).join('');

        return `
            <div class="card po-orden-card" data-id-orden="${id}" style="margin-bottom: 20px;">
                <div class="card-content">
                    <span class="card-title">
                        <i class="material-icons left">receipt_long</i> ${escHtml(orden.referencia)}
                        <span class="grey-text" style="font-size: 0.9rem;"> · ${escHtml(orden.sucursal)} · ${escHtml(fecha)}</span>
                    </span>
                    <div style="overflow-x:auto;">
                        <table class="striped" style="min-width: 520px;">
                            <thead>
                                <tr><th>Producto</th><th class="center-align">Solicitado</th><th class="center-align">Recibido</th></tr>
                            </thead>
                            <tbody>${filas}</tbody>
                        </table>
                    </div>
                    <div style="display:flex; gap:10px; justify-content:flex-end; flex-wrap:wrap; margin-top:15px;">
                        <button type="button" class="btn-flat" onclick="surtirTodo(${id})">
                            <i class="material-icons left">done_all</i> Surtir todo
                        </button>
                        <button type="button" class="btn red lighten-1 waves-effect waves-light" onclick="cancelarOrden(${id})">
                            <i class="material-icons left">close</i> Cancelar orden
                        </button>
                        <button type="button" class="btn green darken-2 waves-effect waves-light" onclick="surtirOrden(${id})">
                            <i class="material-icons left">inventory</i> Surtir orden
                        </button>
                    </div>
                </div>
            </div>`;
    }

    function ordenCard(id) {
        return document.querySelector('.po-orden-card[data-id-orden="' + id + '"]');
    }

    function surtirTodo(id) {
        const card = ordenCard(id);
        if (!card) return;
        card.querySelectorAll('.po-recibida').forEach(input => {
            const row = input.closest('tr');
            const solicitada = row ? (parseInt(row.children[1].textContent, 10) || 0) : 0;
            input.value = solicitada;
        });
    }

    function surtirOrden(id) {
        const card = ordenCard(id);
        if (!card) return;

        const lineas = Array.from(card.querySelectorAll('.po-recibida')).map(input => ({
            id_detalle: Number(input.getAttribute('data-id-detalle')) || 0,
            cantidad_recibida: Math.max(0, parseInt(input.value || '0', 10))
        }));

        const faltan = lineas.filter(l => l.cantidad_recibida === 0).length;
        const texto = faltan > 0
            ? `${faltan} producto(s) quedan en 0 y no afectarán el inventario. La orden se cerrará.`
            : 'Se subirá al inventario todo lo recibido y la orden se cerrará.';

        Swal.fire({
            title: '¿Surtir la orden?',
            text: texto,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#2e7d32',
            confirmButtonText: 'Sí, surtir'
        }).then((result) => {
            if (!result.isConfirmed) return;

            fetch(PO_BASE + 'api/purchase_order_receive.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: getCsrf(), id_orden_compra: id, lineas })
            })
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    poToast('Error: ' + res.message, 'red');
                    return;
                }
                card.remove();
                poToast(res.message || 'Orden surtida', 'green');
                cargarOrdenes();
                cargarListaCompra();
            })
            .catch(() => poToast('Error de conexión. Inténtalo de nuevo.', 'red'));
        });
    }

    function cancelarOrden(id) {
        Swal.fire({
            title: '¿Cancelar la orden?',
            text: 'Los productos volverán a la Lista de Compra. No se toca el inventario.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#c62828',
            confirmButtonText: 'Sí, cancelar orden'
        }).then((result) => {
            if (!result.isConfirmed) return;

            fetch(PO_BASE + 'api/purchase_order_cancel.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: getCsrf(), id_orden_compra: id })
            })
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    poToast('Error: ' + res.message, 'red');
                    return;
                }
                const card = ordenCard(id);
                if (card) card.remove();
                poToast(res.message || 'Orden cancelada', 'blue');
                cargarOrdenes();
                cargarListaCompra();
            })
            .catch(() => poToast('Error de conexión. Inténtalo de nuevo.', 'red'));
        });
    }
</script>

<style>
    @media print {
        .btn-flat, .btn, .nav-wrapper, .delivery-banner { display: none !important; }
        body { background: white; }
        .card { box-shadow: none; border: 1px solid #eee; }
    }
    .qty-input:focus {
        border: 2px solid #2196f3 !important;
        outline: none;
        background-color: #fff;
    }
    #po-csrf { padding: 0; height: 0; overflow: hidden; }
</style>
<?php include __DIR__ . '/includes/footer.php'; ?>
