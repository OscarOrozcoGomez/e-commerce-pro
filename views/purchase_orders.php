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

// Para el tab "Cargar Pedido": un admin elige sucursal destino; un encargado va
// fijo a la suya.
$poEsAdmin = isAdmin();
$poAlmacenActual = getCurrentAlmacenId();
$poAlmacenes = [];
$poAlmacenActualNombre = '';
if ($poEsAdmin) {
    try {
        $poAlmacenes = getPDO()->query('SELECT id_almacen, nombre FROM almacenes ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $poAlmacenes = [];
    }
} elseif ($poAlmacenActual !== null) {
    try {
        $st = getPDO()->prepare('SELECT nombre FROM almacenes WHERE id_almacen = ?');
        $st->execute([(int) $poAlmacenActual]);
        $poAlmacenActualNombre = (string) $st->fetchColumn();
    } catch (Throwable $e) {
        $poAlmacenActualNombre = '';
    }
}

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
                <li class="tab col s3"><a class="active" href="#tab-lista">Lista de Compra</a></li>
                <li class="tab col s3"><a href="#tab-pospuestos">Pospuestos <span class="new badge blue" data-badge-caption="" id="pospuestos-badge" style="display:none;">0</span></a></li>
                <li class="tab col s3"><a href="#tab-ordenes">Órdenes Abiertas <span class="new badge green" data-badge-caption="" id="ordenes-badge" style="display:none;">0</span></a></li>
                <li class="tab col s3"><a href="#tab-importar">Cargar Pedido</a></li>
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

                                <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px; margin: 10px 0 4px; flex-wrap: wrap;">
                                    <div>
                                        <button type="button" class="btn-flat btn-small" onclick="toggleTodosCheck(true)"><i class="material-icons left">check_box</i>Seleccionar todos</button>
                                        <button type="button" class="btn-flat btn-small" onclick="toggleTodosCheck(false)"><i class="material-icons left">check_box_outline_blank</i>Quitar selección</button>
                                    </div>
                                    <div>
                                        <button type="button" class="btn-flat btn-small" onclick="togglePoGroups(true)"><i class="material-icons left">unfold_more</i>Expandir todo</button>
                                        <button type="button" class="btn-flat btn-small" onclick="togglePoGroups(false)"><i class="material-icons left">unfold_less</i>Colapsar todo</button>
                                    </div>
                                </div>
                                <p class="grey-text" style="font-size: 12.5px; margin: 0 0 8px;">
                                    Marca solo los productos que quieres <strong>pedir ahora</strong>; los demás se quedan en la lista.
                                    Puedes generar la orden con una selección parcial, o posponer varios de un jalón.
                                </p>

                                <!-- Una sección colapsable por sucursal, generada en renderTable() -->
                                <div id="po-groups"></div>

                                <div class="row" style="margin-top: 30px; display: flex; align-items: center; justify-content: flex-end; gap: 20px; flex-wrap: wrap;">
                                    <div class="grey-text text-darken-2">
                                        <h5 style="margin: 0;">Total Inversión (seleccionados): <strong>$<span id="total-inversion-val">0.00</span></strong></h5>
                                    </div>
                                    <div>
                                        <button type="button" onclick="posponerSeleccionados()" class="btn-large red lighten-1 waves-effect waves-light">
                                            <i class="material-icons left">schedule</i> POSPONER SELECCIONADOS
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button" onclick="guardarReglasMasivas()" class="btn-large blue darken-2 waves-effect waves-light">
                                            <i class="material-icons left">settings</i> ACTUALIZAR MÍNIMOS/MÁXIMOS
                                        </button>
                                    </div>
                                    <div>
                                        <button type="button" onclick="generarOrdenCompra()" class="btn-large green darken-2 waves-effect waves-light">
                                            <i class="material-icons left">assignment</i> GENERAR ORDEN DE COMPRA (SELECCIONADOS)
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

    <!-- ================= TAB 4: CARGAR PEDIDO DE PROVEEDOR ================= -->
    <div id="tab-importar">
        <div class="row">
            <div class="col s12">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Cargar pedido de proveedor</span>
                        <p class="grey-text">
                            Pega el texto del correo del proveedor o sube una captura. Se detectan los productos
                            y las cantidades; revisas el mapeo y se surte el inventario. Si un producto está en
                            una orden de compra abierta de la sucursal, esa orden se surte y se cierra; lo demás
                            entra como entrada directa.
                        </p>

                        <?php if ($poEsAdmin): ?>
                            <label>Sucursal destino</label>
                            <select id="import-almacen" class="browser-default" style="max-width: 320px; margin-bottom: 16px;">
                                <?php foreach ($poAlmacenes as $a): ?>
                                    <option value="<?php echo (int) $a['id_almacen']; ?>"<?php echo ((int) $a['id_almacen'] === (int) $poAlmacenActual) ? ' selected' : ''; ?>>
                                        <?php echo htmlspecialchars($a['nombre'], ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <p>Sucursal: <strong><?php echo htmlspecialchars($poAlmacenActualNombre ?: '—', ENT_QUOTES, 'UTF-8'); ?></strong></p>
                            <input type="hidden" id="import-almacen" value="<?php echo (int) $poAlmacenActual; ?>">
                        <?php endif; ?>

                        <div style="margin: 10px 0;">
                            <input type="file" accept="image/*" capture="environment" id="import-imagen" style="display:none;">
                            <button type="button" class="btn-flat waves-effect" onclick="document.getElementById('import-imagen').click()">
                                <i class="material-icons left">photo_camera</i> Escanear imagen
                            </button>
                            <span id="import-ocr-status" class="grey-text"></span>
                        </div>

                        <textarea id="import-texto" class="browser-default"
                            placeholder="Collagen Blend &#215; 2&#10;D3 | Vitamina D3 &#215; 3&#10;Maca Blend &#215; 2"
                            style="width:100%; min-height:170px; padding:10px; font-family:inherit;"></textarea>

                        <div style="margin-top: 12px;">
                            <button type="button" class="btn blue darken-2 waves-effect waves-light" onclick="analizarImport(this)">
                                <i class="material-icons left">search</i> Analizar
                            </button>
                        </div>

                        <div id="import-review" style="margin-top: 20px;"></div>

                        <div id="import-commit-wrapper" style="display:none; margin-top: 16px; text-align:right;">
                            <button type="button" class="btn-large green darken-2 waves-effect waves-light" onclick="cargarYSurtir()">
                                <i class="material-icons left">inventory</i> Cargar y surtir
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col s12">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Importar pedido de mayoreo (B Life)</span>
                        <p class="grey-text">
                            Corre <code>node scripts/mayoreo_pedidos.mjs</code>, haz login una vez, y pega aquí
                            el contenido del archivo <code>scripts/.mayoreo/pedido-BLM….json</code>. Se mapea a
                            tu catálogo (la presentación desempata 60 vs 120 tomas), las líneas "100% OFF" a $0
                            se marcan como regalo, y se registra como <strong>Orden de Compra "por llegar"</strong>:
                            no toca inventario hasta que la surtas desde "Órdenes Abiertas".
                        </p>

                        <textarea id="may-json" class="browser-default"
                            placeholder="Pega aquí el contenido de scripts/.mayoreo/pedido-BLM015728.json"
                            style="width:100%; min-height:140px; padding:10px; font-family:monospace; font-size:12px;"></textarea>

                        <div style="margin-top: 10px;">
                            <button type="button" class="btn blue darken-2 waves-effect waves-light" onclick="analizarMayoreo(this)">
                                <i class="material-icons left">search</i> Analizar pedido de mayoreo
                            </button>
                            <span id="may-meta" class="grey-text" style="margin-left:12px;"></span>
                        </div>

                        <div id="may-review" style="margin-top: 18px;"></div>

                        <div id="may-commit-wrapper" style="display:none; margin-top: 16px; text-align:right;">
                            <button type="button" class="btn-large green darken-2 waves-effect waves-light" onclick="registrarOrdenPorLlegar()">
                                <i class="material-icons left">assignment</i> Registrar orden (por llegar)
                            </button>
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

    function renderRow(item, index) {
        const stockMax = parseInt(item.stock_maximo, 10) || 0;
        const stockActual = parseInt(item.cantidad_actual, 10) || 0;
        const precioCosto = parseFloat(item.precio_costo) || 0;
        const aComprar = Math.max(0, stockMax - stockActual);
        const costoFila = aComprar * precioCosto;
        const idProducto = Number(item.id_producto) || 0;
        const idAlmacen = Number(item.id_almacen) || 0;

        return `
            <tr id="po-row-${index}" class="po-item-row">
                <td class="center-align" style="width: 40px;">
                    <label style="display:block; text-align:center;">
                        <input type="checkbox" class="filled-in po-check" checked onchange="recalculateTotalInversion()">
                        <span></span>
                    </label>
                </td>
                <td><strong>${escHtml(item.nombre)}</strong><br><small class="grey-text">SKU: ${escHtml(item.sku)}</small></td>
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
    }

    function renderTable(items) {
        const cont = document.getElementById('po-groups');

        // Agrupar por sucursal, respetando el orden de aparición.
        const grupos = new Map();
        items.forEach((item, index) => {
            const suc = String(item.sucursal || 'Sin sucursal').trim() || 'Sin sucursal';
            if (!grupos.has(suc)) grupos.set(suc, []);
            grupos.get(suc).push({ item, index });
        });

        const thead = `
            <thead>
                <tr>
                    <th class="center-align" style="width: 40px;"></th>
                    <th>Producto</th>
                    <th>P. Venta</th>
                    <th class="center-align">Stock Actual</th>
                    <th class="center-align" style="width: 180px;">Ajustar Mín/Máx</th>
                    <th class="blue lighten-5 center-align" style="width: 150px;">Cantidad a Pedir</th>
                    <th class="right-align">Subtotal Est.</th>
                    <th class="center-align" style="width: 90px;">Acción</th>
                </tr>
            </thead>`;

        let html = '<ul class="collapsible expandable" data-collapsible="expandable">';
        grupos.forEach((filas, sucursal) => {
            const filasHtml = filas.map(f => renderRow(f.item, f.index)).join('');
            html += `
                <li class="po-group active" data-sucursal="${escHtml(sucursal)}">
                    <div class="collapsible-header">
                        <label class="po-group-selectall" onclick="event.stopPropagation();" style="margin:0 4px 0 0;">
                            <input type="checkbox" class="filled-in po-group-check" checked onclick="event.stopPropagation();" onchange="toggleGrupoCheck(this)">
                            <span></span>
                        </label>
                        <i class="material-icons">store</i>
                        <span class="po-group-name">${escHtml(sucursal)}</span>
                        <span class="new badge grey lighten-1 po-group-count" data-badge-caption="">${filas.length}</span>
                        <span class="po-group-subtotal">$0.00</span>
                    </div>
                    <div class="collapsible-body">
                        <div style="overflow-x: auto; -webkit-overflow-scrolling: touch;">
                            <table class="striped highlight" style="min-width: 680px; margin: 0;">
                                ${thead}
                                <tbody>${filasHtml}</tbody>
                            </table>
                        </div>
                    </div>
                </li>`;
        });
        html += '</ul>';

        cont.innerHTML = html;

        if (typeof M !== 'undefined' && M.Collapsible) {
            M.Collapsible.init(cont.querySelectorAll('.collapsible'), { accordion: false });
        }

        bindQtyRecalculation();
        recalculateTotalInversion();
    }

    window.togglePoGroups = function (abrir) {
        document.querySelectorAll('#po-groups .collapsible').forEach(ul => {
            const inst = (typeof M !== 'undefined' && M.Collapsible) ? M.Collapsible.getInstance(ul) : null;
            ul.querySelectorAll('li').forEach((li, i) => {
                if (!inst) { li.classList.toggle('active', abrir); return; }
                abrir ? inst.open(i) : inst.close(i);
            });
        });
    };

    function bindQtyRecalculation() {
        document.querySelectorAll('input[name$="[cantidad]"]').forEach((input) => {
            input.addEventListener('input', recalculateTotalInversion);
        });
    }

    // ¿Este renglón está marcado para pedirse? (checkbox de su fila)
    function filaSeleccionada(row) {
        return row.querySelector('.po-check')?.checked !== false;
    }

    window.toggleTodosCheck = function (marcar) {
        document.querySelectorAll('#po-groups .po-check').forEach((cb) => { cb.checked = marcar; });
        document.querySelectorAll('#po-groups .po-group-check').forEach((cb) => { cb.checked = marcar; });
        recalculateTotalInversion();
    };

    window.toggleGrupoCheck = function (grupoCheckbox) {
        const li = grupoCheckbox.closest('.po-group');
        if (!li) return;
        li.querySelectorAll('.po-check').forEach((cb) => { cb.checked = grupoCheckbox.checked; });
        recalculateTotalInversion();
    };

    function recalculateTotalInversion() {
        let total = 0;

        document.querySelectorAll('#po-groups .po-item-row').forEach((row) => {
            const qtyInput = row.querySelector('input[name$="[cantidad]"]');
            const costInput = row.querySelector('input[name$="[precio_costo]"]');
            const subtotalCell = row.querySelector('.po-subtotal');

            const qty = Math.max(0, parseInt(qtyInput?.value || '0', 10));
            const unitCost = parseFloat(costInput?.value || '0');
            const subtotal = qty * unitCost;

            if (subtotalCell) {
                subtotalCell.textContent = '$' + subtotal.toFixed(2);
            }

            if (filaSeleccionada(row)) total += subtotal;
        });

        // Subtotal (solo seleccionados) y conteo por sucursal en cada colapsable.
        document.querySelectorAll('#po-groups .po-group').forEach((li) => {
            let sub = 0;
            const filas = li.querySelectorAll('.po-item-row');
            filas.forEach((row) => {
                if (!filaSeleccionada(row)) return;
                const qty = Math.max(0, parseInt(row.querySelector('input[name$="[cantidad]"]')?.value || '0', 10));
                const unitCost = parseFloat(row.querySelector('input[name$="[precio_costo]"]')?.value || '0');
                sub += qty * unitCost;
            });
            const subEl = li.querySelector('.po-group-subtotal');
            if (subEl) subEl.textContent = '$' + sub.toFixed(2);
            const countEl = li.querySelector('.po-group-count');
            if (countEl) countEl.textContent = String(filas.length);
            // El checkbox del grupo refleja si TODOS sus renglones estan marcados.
            const grupoCb = li.querySelector('.po-group-check');
            if (grupoCb) grupoCb.checked = filas.length > 0 && Array.from(filas).every(filaSeleccionada);
        });

        document.getElementById('total-inversion-val').textContent = total.toFixed(2);
    }

    /**
     * @param {boolean} soloSeleccionados si es true, descarta los renglones cuyo
     *   checkbox de fila esta desmarcado.
     */
    function collectListaItems(soloSeleccionados) {
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
        return Object.entries(itemsMap)
            .filter(([index]) => {
                if (!soloSeleccionados) return true;
                const row = document.getElementById('po-row-' + index);
                return row ? filaSeleccionada(row) : true;
            })
            .map(([, item]) => item);
    }

    function generarOrdenCompra() {
        const items = collectListaItems(true).filter(i => parseInt(i.cantidad, 10) > 0);

        if (items.length === 0) {
            poToast('No hay productos seleccionados con cantidad para ordenar', 'orange');
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
                    const grupo = row.closest('.po-group');
                    row.remove();
                    // Si la sucursal se quedó sin productos, quita su sección colapsable.
                    if (grupo && grupo.querySelectorAll('.po-item-row').length === 0) {
                        grupo.remove();
                    }
                    recalculateTotalInversion();
                }

                if (document.querySelectorAll('#po-groups .po-item-row').length === 0) {
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

    /** Posponer de un jalón todos los renglones cuyo checkbox de fila esta marcado. */
    window.posponerSeleccionados = function () {
        const filas = [];
        document.querySelectorAll('#po-groups .po-item-row').forEach((row) => {
            if (!filaSeleccionada(row)) return;
            const idProducto = parseInt(row.querySelector('input[name$="[id_producto]"]')?.value || '0', 10);
            const idAlmacen = parseInt(row.querySelector('input[name$="[id_almacen]"]')?.value || '0', 10);
            if (idProducto > 0 && idAlmacen > 0) filas.push({ row, idProducto, idAlmacen });
        });

        if (filas.length === 0) {
            poToast('Selecciona al menos un producto', 'orange');
            return;
        }

        Swal.fire({
            title: `¿Posponer ${filas.length} producto(s)?`,
            text: 'Se quitan de esta lista y quedan en la pestaña de Pospuestos hasta que los devuelvas.',
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
                    items: filas.map((f) => ({ id_producto: f.idProducto, id_almacen: f.idAlmacen }))
                })
            })
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    poToast('Error: ' + res.message, 'red');
                    return;
                }

                filas.forEach((f) => {
                    const grupo = f.row.closest('.po-group');
                    f.row.remove();
                    if (grupo && grupo.querySelectorAll('.po-item-row').length === 0) {
                        grupo.remove();
                    }
                });
                recalculateTotalInversion();

                if (document.querySelectorAll('#po-groups .po-item-row').length === 0) {
                    document.getElementById('po-form-wrapper').style.display = 'none';
                    document.getElementById('po-list-container').style.display = 'block';
                    document.getElementById('po-list-container').innerHTML = `
                        <div class="center-align" style="padding: 40px;">
                            <i class="material-icons large blue-text">schedule</i>
                            <h5>No hay productos activos en esta ronda</h5>
                            <p>Todos los productos fueron pospuestos o ya están cubiertos.</p>
                        </div>`;
                }

                poToast(res.message || `${filas.length} producto(s) pospuesto(s)`, 'blue');
                cargarPospuestos();
            })
            .catch(() => poToast('Error de conexión. Inténtalo de nuevo.', 'red'));
        });
    };

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
                cargarListaCompra();
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

    // ============================================================
    // TAB 4: CARGAR PEDIDO DE PROVEEDOR (sin IA)
    // ============================================================
    let importRows = [];       // filas devueltas por el preview, indexadas por posición
    let _tesseractLoading = null;

    function importAlmacenId() {
        const el = document.getElementById('import-almacen');
        return el ? (parseInt(el.value, 10) || 0) : 0;
    }

    // OCR local en el navegador (Tesseract.js): sin API, sin key, sin LLM.
    function loadTesseract() {
        if (window.Tesseract) return Promise.resolve(window.Tesseract);
        if (_tesseractLoading) return _tesseractLoading;
        _tesseractLoading = new Promise((resolve, reject) => {
            const s = document.createElement('script');
            s.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js';
            s.onload = () => window.Tesseract ? resolve(window.Tesseract) : reject(new Error('OCR no disponible'));
            s.onerror = () => reject(new Error('No se pudo cargar el OCR (sin conexión). Pega el texto del correo.'));
            document.head.appendChild(s);
        });
        return _tesseractLoading;
    }

    const _importImgInput = document.getElementById('import-imagen');
    if (_importImgInput) {
        _importImgInput.addEventListener('change', async (e) => {
            const file = e.target.files && e.target.files[0];
            e.target.value = '';
            if (!file) return;

            const status = document.getElementById('import-ocr-status');
            status.textContent = ' Cargando OCR...';
            try {
                const T = await loadTesseract();
                status.textContent = ' Leyendo imagen 0%';
                const { data } = await T.recognize(file, 'spa+eng', {
                    logger: m => {
                        if (m.status === 'recognizing text') {
                            status.textContent = ' Leyendo imagen ' + Math.round((m.progress || 0) * 100) + '%';
                        }
                    }
                });
                const ta = document.getElementById('import-texto');
                const nuevo = (data && data.text ? data.text : '').trim();
                ta.value = (ta.value.trim() ? ta.value.trim() + '\n' : '') + nuevo;
                status.textContent = ' Texto extraído. Revísalo y da Analizar.';
            } catch (err) {
                status.textContent = '';
                poToast(err.message || 'No se pudo leer la imagen', 'red');
            }
        });
    }

    function analizarImport(btn) {
        const texto = (document.getElementById('import-texto').value || '').trim();
        if (!texto) {
            poToast('Pega o escanea el pedido primero', 'orange');
            return;
        }
        if (btn) btn.disabled = true;

        fetch(PO_BASE + 'api/purchase_order_import_preview.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: getCsrf(), texto, id_almacen: importAlmacenId() })
        })
        .then(r => r.json())
        .then(res => {
            if (!res.success) {
                poToast('Error: ' + res.message, 'red');
                return;
            }
            renderImportReview(res.rows || [], res.warnings || []);
        })
        .catch(() => poToast('Error de conexión. Inténtalo de nuevo.', 'red'))
        .finally(() => { if (btn) btn.disabled = false; });
    }

    function renderImportReview(rows, warnings) {
        importRows = rows;
        const cont = document.getElementById('import-review');
        const wrapper = document.getElementById('import-commit-wrapper');

        if (rows.length === 0) {
            cont.innerHTML = '<div class="card-panel amber lighten-4">No se detectaron productos en el texto. Revisa el formato (una línea por producto, con la cantidad tipo "× 2").</div>';
            wrapper.style.display = 'none';
            return;
        }

        let warnHtml = '';
        if (warnings.length) {
            warnHtml = '<div class="card-panel amber lighten-4"><strong>Revisa estas líneas:</strong>'
                + '<ul class="browser-default" style="margin:6px 0 0 18px;">'
                + warnings.map(w => '<li>' + escHtml(w.texto)
                    + (w.tipo === 'sin_match' ? ' — sin coincidencia clara en el catálogo' : ' — no se pudo leer la cantidad')
                    + '</li>').join('')
                + '</ul></div>';
        }

        const filas = rows.map((row, i) => {
            const opts = (row.candidatos || []).map(c =>
                `<option value="${c.id_producto}"${c.id_producto === row.sugerido_id_producto ? ' selected' : ''}>`
                + `${escHtml(c.nombre)} (${Math.round(c.score)}%)</option>`
            ).join('');
            const origen = row.id_detalle
                ? `<span class="new badge green" data-badge-caption="">OC ${escHtml(row.referencia || '')}</span>`
                : '<span class="grey-text">Entrada directa</span>';
            return `
                <tr data-i="${i}">
                    <td>${escHtml(row.raw)}</td>
                    <td>
                        <select class="browser-default import-prod" style="min-width:220px;">
                            <option value="0">— ignorar —</option>
                            ${opts}
                        </select>
                    </td>
                    <td style="width:110px;">
                        <input type="number" min="0" value="${parseInt(row.cantidad, 10) || 0}"
                            class="browser-default import-qty" style="width:100%; text-align:center; border:1px solid #9e9e9e; border-radius:4px; padding:5px;">
                    </td>
                    <td>${origen}</td>
                </tr>`;
        }).join('');

        cont.innerHTML = warnHtml + `
            <div style="overflow-x:auto;">
                <table class="striped highlight" style="min-width: 620px;">
                    <thead>
                        <tr><th>Detectado</th><th>Producto</th><th class="center-align">Cantidad</th><th>Origen</th></tr>
                    </thead>
                    <tbody>${filas}</tbody>
                </table>
            </div>`;
        wrapper.style.display = 'block';
    }

    function collectImportRows() {
        const out = [];
        document.querySelectorAll('#import-review tbody tr').forEach(tr => {
            const i = Number(tr.getAttribute('data-i'));
            const pid = parseInt(tr.querySelector('.import-prod').value, 10) || 0;
            const qty = Math.max(0, parseInt(tr.querySelector('.import-qty').value || '0', 10));
            if (pid <= 0 || qty <= 0) return;

            const src = importRows[i] || {};
            // El id_detalle sólo aplica si el producto elegido sigue siendo el sugerido de esa fila.
            const idDetalle = (src.id_detalle && pid === src.sugerido_id_producto) ? src.id_detalle : null;
            out.push({ id_producto: pid, cantidad: qty, id_detalle: idDetalle });
        });
        return out;
    }

    function cargarYSurtir() {
        const rows = collectImportRows();
        if (rows.length === 0) {
            poToast('No hay renglones válidos (elige producto y cantidad)', 'orange');
            return;
        }
        const conOc = rows.filter(r => r.id_detalle).length;

        Swal.fire({
            title: '¿Cargar y surtir?',
            html: `Se aplicarán <strong>${rows.length}</strong> renglón(es) al inventario.`
                + (conOc > 0 ? `<br>${conOc} corresponden a órdenes de compra abiertas, que se cerrarán.` : ''),
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#2e7d32',
            confirmButtonText: 'Sí, surtir'
        }).then((result) => {
            if (!result.isConfirmed) return;

            fetch(PO_BASE + 'api/purchase_order_import_commit.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: getCsrf(), id_almacen: importAlmacenId(), rows })
            })
            .then(r => r.json())
            .then(res => {
                if (!res.success) {
                    poToast('Error: ' + res.message, 'red');
                    return;
                }
                poToast(
                    `Surtido: ${res.entradas_directas} entrada(s) directa(s), `
                    + `${res.ordenes_cerradas} orden(es) cerrada(s), ${res.ignoradas} ignorada(s)`,
                    'green'
                );
                document.getElementById('import-texto').value = '';
                document.getElementById('import-review').innerHTML = '';
                document.getElementById('import-commit-wrapper').style.display = 'none';
                document.getElementById('import-ocr-status').textContent = '';
                importRows = [];
                cargarOrdenes();
                cargarListaCompra();
            })
            .catch(() => poToast('Error de conexión. Inténtalo de nuevo.', 'red'));
        });
    }

    // ============================================================
    // TAB 4 (parte 2): IMPORTAR PEDIDO DE MAYOREO (JSON del script)
    // ============================================================
    let mayRows = [];

    function mayAlmacenId() {
        const el = document.getElementById('import-almacen');
        return el ? (parseInt(el.value, 10) || 0) : 0;
    }
    function money(n) { return '$' + (parseFloat(n) || 0).toFixed(2); }

    function analizarMayoreo(btn) {
        const texto = (document.getElementById('may-json').value || '').trim();
        if (!texto) { poToast('Pega el JSON del pedido de mayoreo', 'orange'); return; }
        if (btn) btn.disabled = true;
        document.getElementById('may-review').innerHTML = '';
        document.getElementById('may-commit-wrapper').style.display = 'none';

        fetch(PO_BASE + 'api/purchase_order_mayoreo_preview.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: getCsrf(), texto, id_almacen: mayAlmacenId() })
        })
        .then(r => r.json())
        .then(res => {
            if (!res.success) { poToast('Error: ' + res.message, 'red'); return; }
            renderMayoreoReview(res);
        })
        .catch(() => poToast('Error de conexión. Inténtalo de nuevo.', 'red'))
        .finally(() => { if (btn) btn.disabled = false; });
    }

    function renderMayoreoReview(res) {
        mayRows = res.rows || [];
        const meta = document.getElementById('may-meta');
        if (meta) {
            meta.textContent = [res.numero ? 'Pedido ' + res.numero : '', res.fecha || '', res.total_txt || '']
                .filter(Boolean).join('  ·  ');
        }

        const cont = document.getElementById('may-review');
        const wrap = document.getElementById('may-commit-wrapper');
        if (!mayRows.length) {
            cont.innerHTML = '<div class="card-panel amber lighten-4">El JSON no trae renglones.</div>';
            wrap.style.display = 'none';
            return;
        }

        const filas = mayRows.map((row, i) => {
            const opts = (row.candidatos || []).map(c => {
                const extra = [c.sku].filter(Boolean).join(' ');
                return `<option value="${c.id_producto}"${c.id_producto === row.sugerido_id_producto ? ' selected' : ''}>`
                    + `${escHtml(c.nombre)}${extra ? ' — ' + escHtml(extra) : ''} (${Math.round(c.score)}%)</option>`;
            }).join('');

            let estado = '';
            if (row.es_regalo) estado = ' <span class="new badge grey" data-badge-caption="">regalo $0</span>';
            else if (!row.sugerido_id_producto) estado = ' <span class="new badge red white-text" data-badge-caption="">sin producto — créalo</span>';
            else if (row.id_detalle) estado = ` <span class="new badge green" data-badge-caption="">OC ${escHtml(row.referencia || '')}</span>`;

            const incluir = (!row.es_regalo && row.sugerido_id_producto) ? 'checked' : '';

            return `
                <tr data-i="${i}">
                    <td><input type="checkbox" class="filled-in may-incluir" id="may-inc-${i}" ${incluir}><label for="may-inc-${i}"></label></td>
                    <td>${escHtml(row.raw)}${row.presentacion ? '<br><small class="grey-text">' + escHtml(row.presentacion) + '</small>' : ''}</td>
                    <td>
                        <select class="browser-default may-prod" style="min-width:240px;">
                            <option value="0">— ignorar —</option>
                            ${opts}
                        </select>${estado}
                    </td>
                    <td style="width:90px;"><input type="number" min="0" value="${parseInt(row.cantidad, 10) || 0}" class="browser-default may-qty" style="width:100%;text-align:center;"></td>
                    <td class="right-align">${money(row.precio_unitario)}</td>
                    <td class="right-align grey-text">${money(row.costo_catalogo)}</td>
                </tr>`;
        }).join('');

        let warnHtml = '';
        if ((res.warnings || []).length) {
            warnHtml = '<div class="card-panel amber lighten-4"><strong>Sin coincidencia en el catálogo:</strong>'
                + '<ul class="browser-default" style="margin:6px 0 0 18px;">'
                + res.warnings.map(w => '<li>' + escHtml(w.texto) + '</li>').join('')
                + '</ul><span class="grey-text" style="font-size:12px;">Créalos en Productos y vuelve a analizar, o déjalos fuera.</span></div>';
        }

        cont.innerHTML = warnHtml + `
            <div style="overflow-x:auto;">
                <table class="striped highlight" style="min-width:720px;">
                    <thead><tr>
                        <th>Pedir</th><th>Detectado</th><th>Producto del catálogo</th>
                        <th class="center-align">Cant.</th><th class="right-align">P. unit. (mayoreo)</th><th class="right-align">Costo catálogo</th>
                    </tr></thead>
                    <tbody>${filas}</tbody>
                </table>
            </div>`;
        wrap.style.display = 'block';
    }

    function collectMayoreoItems() {
        const out = [];
        document.querySelectorAll('#may-review tbody tr').forEach(tr => {
            const inc = tr.querySelector('.may-incluir');
            if (!inc || !inc.checked) return;
            const pid = parseInt(tr.querySelector('.may-prod').value, 10) || 0;
            const qty = Math.max(0, parseInt(tr.querySelector('.may-qty').value || '0', 10));
            if (pid <= 0 || qty <= 0) return;
            // precio_costo 0: se pidio "solo mostrar" el precio de mayoreo, no tocar costos.
            out.push({ id_producto: pid, cantidad: qty, id_almacen: mayAlmacenId(), precio_costo: 0 });
        });
        return out;
    }

    function registrarOrdenPorLlegar() {
        const items = collectMayoreoItems();
        if (!items.length) { poToast('Marca al menos un renglón con producto y cantidad', 'orange'); return; }

        Swal.fire({
            title: '¿Registrar orden "por llegar"?',
            html: `Se crea una Orden de Compra con <strong>${items.length}</strong> producto(s), en estado abierto. `
                + 'No toca el inventario: cuando llegue la mercancía la surtes desde "Órdenes Abiertas".',
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#2e7d32',
            confirmButtonText: 'Sí, registrar'
        }).then((r) => {
            if (!r.isConfirmed) return;
            fetch(PO_BASE + 'api/purchase_order_create.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ csrf_token: getCsrf(), items })
            })
            .then(r => r.json())
            .then(res => {
                if (!res.success) { poToast('Error: ' + res.message, 'red'); return; }
                poToast(res.message || 'Orden registrada', 'green');
                document.getElementById('may-json').value = '';
                document.getElementById('may-review').innerHTML = '';
                document.getElementById('may-commit-wrapper').style.display = 'none';
                setTimeout(() => location.reload(), 1200);
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

    /* --- Agrupado por sucursal (colapsable) --- */
    #po-groups .collapsible { margin: 12px 0; border: none; box-shadow: 0 1px 3px rgba(0,0,0,.12); }
    #po-groups .collapsible-header {
        display: flex; align-items: center; gap: 10px;
        font-weight: 600; background: #eceff1; padding: 12px 18px;
    }
    #po-groups .collapsible-header .material-icons { color: #1a237e; }
    #po-groups .po-group-name { font-size: 1.05rem; }
    #po-groups .po-group-count { margin-left: 4px; }
    #po-groups .po-group-subtotal { margin-left: auto; color: #2e7d32; font-weight: 700; }
    #po-groups .collapsible-body { padding: 0; border-bottom: none; }
    #po-groups .collapsible-body table { margin: 0; }
    @media print {
        #po-groups .collapsible-body { display: block !important; }
    }
</style>
<?php include __DIR__ . '/includes/footer.php'; ?>
