<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
// Fase 4: el permiso 'inventario' abre esta vista; el rol se mantiene como respaldo.
if (!hasPermission('inventario') && !isAdmin() && !isEncargado()) {
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
                <h4 style="margin: 0;"><i class="material-icons left" style="font-size: 2.5rem; color: #1a237e;">shopping_cart</i> Lista de Compra Sugerida</h4>
                <a href="<?php echo BASE_URL; ?>views/dashboard.php" class="btn blue darken-4 waves-effect waves-light"><i class="material-icons left">dashboard</i> Volver al Dashboard</a>
            </div>
            <p class="grey-text">Estos productos han alcanzado su nivel mínimo y necesitan ser resurtidos.</p>
        </div>
    </div>

    <div class="row">
        <div class="col s12">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Sugerencias de Resurtido y Recepción</span>
                    <p class="grey-text">Ajusta las cantidades según lo recibido y confirma para subir al inventario.</p>

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
                                            <th class="blue lighten-5 center-align" style="width: 150px;">Cantidad Recibida</th>
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
                                    <button type="button" onclick="confirmarEntradaMasiva()" class="btn-large green darken-2 waves-effect waves-light">
                                        <i class="material-icons left">inventory</i> CONFIRMAR RECEPCIÓN DE MERCANCÍA
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

<!-- Incluimos librerías necesarias -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4"></script>

<script>
    function escHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        })[c]);
    }

    document.addEventListener('DOMContentLoaded', () => {
        const listContainer = document.getElementById('po-list-container');

        const showError = (msg) => {
            document.getElementById('po-app').style.display = 'block';
            listContainer.style.display = 'block';
            listContainer.innerHTML = `
                <div class="center-align" style="padding: 40px;">
                    <i class="material-icons large red-text">error_outline</i>
                    <h5>No se pudieron calcular las sugerencias</h5>
                    <p class="grey-text">${escHtml(msg)}</p>
                    <button class="btn blue darken-2" onclick="location.reload()">Reintentar</button>
                </div>`;
            if (typeof M !== 'undefined' && M.toast) {
                M.toast({html: 'Error: ' + msg, classes: 'red'});
            }
        };

        fetch('<?php echo BASE_URL; ?>api/purchase_orders_data.php', { headers: { 'Accept': 'application/json' } })
            .then(async (r) => {
                const text = await r.text();
                try {
                    return JSON.parse(text);
                } catch (e) {
                    // El servidor devolvió HTML (p. ej. redirect a views/error.php o sesión expirada).
                    throw new Error(`Respuesta no válida del servidor (HTTP ${r.status}). Recarga la página o vuelve a iniciar sesión.`);
                }
            })
            .then(res => {
                if (!res.success) throw new Error(res.message || 'Error desconocido');

                const listaCompra = Array.isArray(res.listaCompra) ? res.listaCompra : [];

                document.getElementById('po-app').style.display = 'block';
                listContainer.style.display = 'none';

                if (listaCompra.length === 0) {
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
    });

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

    function posponerItem(index, idProducto, idAlmacen) {
        const form = document.getElementById('form-entrada-masiva');
        const formData = new FormData(form);
        const csrfToken = formData.get('csrf_token');

        Swal.fire({
            title: '¿Posponer producto?',
            text: 'Este producto se quitará de la compra actual y quedará pendiente para el siguiente pedido.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#1565c0',
            confirmButtonText: 'Sí, posponer'
        }).then((result) => {
            if (!result.isConfirmed) {
                return;
            }

            fetch('<?php echo BASE_URL; ?>api/postpone_purchase_items.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token: csrfToken,
                    items: [{ id_producto: idProducto, id_almacen: idAlmacen }]
                })
            })
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    M.toast({html: 'Error: ' + res.message, classes: 'red'});
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

                M.toast({html: res.message, classes: 'blue'});
            })
            .catch(() => {
                M.toast({html: 'Error de conexión. Inténtalo de nuevo.', classes: 'red'});
            });
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
    function confirmarEntradaMasiva() {
        const form = document.getElementById('form-entrada-masiva');
        const formData = new FormData(form);

        // Convertir FormData a un objeto estructurado para JSON
        const data = {
            csrf_token: formData.get('csrf_token'),
            items: []
        };

        // Recorrer los campos para agrupar los items
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
        data.items = Object.values(itemsMap).filter(i => parseInt(i.cantidad) > 0);

        if (data.items.length === 0) {
            M.toast({html: 'No hay cantidades para ingresar', classes: 'orange'});
            return;
        }

        Swal.fire({
            title: '¿Confirmar entrada?',
            text: `Se actualizará el stock de ${data.items.length} productos en el inventario.`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#2e7d32',
            confirmButtonText: 'Sí, ingresar productos'
        }).then((result) => {
            if (result.isConfirmed) {
                fetch('<?php echo BASE_URL; ?>api/batch_inbound.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                })
                .then(r => r.json())
                .then(res => {
                    if (res.success) {
                        M.toast({html: 'Inventario actualizado correctamente', classes: 'green'});
                        setTimeout(() => location.reload(), 1500);
                    } else {
                        M.toast({html: 'Error: ' + res.message, classes: 'red'});
                    }
                })
                .catch(() => {
                    M.toast({html: 'Error de conexión. Inténtalo de nuevo.', classes: 'red'});
                });
            }
        });
    }

    function guardarReglasMasivas() {
        const form = document.getElementById('form-entrada-masiva');
        const formData = new FormData(form);
        const data = {
            csrf_token: formData.get('csrf_token'),
            items: []
        };

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
        data.items = Object.values(itemsMap);

        if (data.items.length === 0) {
            M.toast({html: 'No hay productos para actualizar', classes: 'orange'});
            return;
        }

        Swal.fire({
            title: '¿Actualizar reglas de stock?',
            text: "Se guardarán los nuevos niveles mínimos y máximos para estos productos.",
            icon: 'info',
            showCancelButton: true,
            confirmButtonText: 'Sí, actualizar'
        }).then((result) => {
            if (result.isConfirmed) {
                fetch('<?php echo BASE_URL; ?>api/update_thresholds.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                })
                .then(r => r.json())
                .then(res => {
                    M.toast({html: res.message, classes: res.success ? 'green' : 'red'});
                    if(res.success) setTimeout(() => location.reload(), 1000);
                })
                .catch(() => {
                    M.toast({html: 'Error de conexión. Inténtalo de nuevo.', classes: 'red'});
                });
            }
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
</style>
<?php include __DIR__ . '/includes/footer.php'; ?>
