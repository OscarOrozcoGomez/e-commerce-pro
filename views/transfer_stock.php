<?php

declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
// Fase 4: el permiso 'transferir_stock' abre esta vista; el rol admin se mantiene como respaldo.
if (!hasPermission('transferir_stock') && !isAdmin()) {
    header('Location: dashboard.php');
    exit;
}

$pageTitle = 'Transferencia entre Almacenes';
$pdo = getPDO();

$almacenes = $pdo->query("SELECT id_almacen, nombre FROM almacenes WHERE estado = 'activo' ORDER BY nombre ASC")->fetchAll();

// productos.sku no existe en todos los entornos (ver migracion 20260812_000001): lo pedimos solo si esta.
$colsProducto = $pdo->query('SHOW COLUMNS FROM productos')->fetchAll(PDO::FETCH_COLUMN);
$selectSku = in_array('sku', $colsProducto, true) ? 'sku' : 'NULL AS sku';
$productos = $pdo->query(
    "SELECT id_producto, nombre, nombre_variante, codigo_barras, {$selectSku}
     FROM productos WHERE estado = 'activo' ORDER BY nombre ASC"
)->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<style>
    .transfer-search-wrap { position: relative; }
    .transfer-dropdown {
        position: absolute; top: 100%; left: 0; right: 0; z-index: 40;
        background: #fff; border: 1px solid #cfd8dc; border-top: none;
        max-height: 320px; overflow-y: auto; display: none;
        box-shadow: 0 6px 18px rgba(0,0,0,0.12);
    }
    .transfer-dropdown.abierto { display: block; }
    .transfer-dropdown .item {
        padding: 10px 14px; cursor: pointer; border-bottom: 1px solid #eee;
    }
    .transfer-dropdown .item:hover,
    .transfer-dropdown .item.resaltado { background: #ede7f6; }
    .transfer-dropdown .item .nombre { font-weight: 600; color: #37474f; }
    .transfer-dropdown .item .sub { font-size: 0.82rem; color: #78909c; }
    .transfer-dropdown .vacio { padding: 12px 14px; color: #90a4ae; }
    #tabla-lineas td, #tabla-lineas th { padding: 8px 10px; }
    .linea-cantidad-input { width: 70px; margin: 0; height: 2rem; text-align: center; }
</style>

<div class="container">
    <div class="row">
        <div class="col s12">
            <div style="margin-top: 20px;">
                <a href="<?php echo BASE_URL; ?>views/dashboard.php" class="btn blue darken-4 waves-effect waves-light">
                    <i class="material-icons left">dashboard</i> Volver al Dashboard
                </a>
            </div>
            <h4><i class="material-icons left" style="font-size: 2.5rem; color: #5e35b1;">swap_horiz</i> Transferencia de Mercancía</h4>
            <p class="grey-text">Arma una lista de productos y transfiérelos de una sucursal a otra en una sola operación.</p>
        </div>
    </div>

    <div class="row">
        <div class="col s12 m10 offset-m1">
            <div class="card">
                <div class="card-content">

                    <div class="row">
                        <div class="input-field col s12 m6">
                            <select id="id_origen" required class="browser-default" style="border: 1px solid #ccc; padding: 8px;">
                                <option value="" disabled selected>-- Almacén Origen --</option>
                                <?php foreach ($almacenes as $alm): ?>
                                    <option value="<?php echo (int) $alm['id_almacen']; ?>"><?php echo esc($alm['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="active">Desde:</label>
                        </div>
                        <div class="input-field col s12 m6">
                            <select id="id_destino" required class="browser-default" style="border: 1px solid #ccc; padding: 8px;">
                                <option value="" disabled selected>-- Almacén Destino --</option>
                                <?php foreach ($almacenes as $alm): ?>
                                    <option value="<?php echo (int) $alm['id_almacen']; ?>"><?php echo esc($alm['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label class="active">Hacia:</label>
                        </div>
                    </div>

                    <div class="divider" style="margin: 6px 0 18px;"></div>

                    <div class="row" style="margin-bottom: 0;">
                        <div class="input-field col s12 m7 transfer-search-wrap">
                            <i class="material-icons prefix">search</i>
                            <input type="text" id="p-search" autocomplete="off" placeholder="Escribe el nombre, SKU o código de barras...">
                            <label for="p-search" class="active">Buscar producto</label>
                            <div class="transfer-dropdown" id="p-dropdown"></div>
                        </div>
                        <div class="input-field col s6 m2">
                            <input type="number" id="p-cantidad" min="1" value="1">
                            <label for="p-cantidad" class="active">Cantidad</label>
                        </div>
                        <div class="col s6 m3" style="margin-top: 22px;">
                            <button type="button" id="btn-agregar-linea" class="btn waves-effect waves-light indigo" style="width: 100%;">
                                <i class="material-icons left">add</i> Agregar
                            </button>
                        </div>
                    </div>

                    <p id="producto-elegido" class="grey-text" style="margin: 0 0 14px; min-height: 18px;"></p>

                    <table class="striped" id="tabla-lineas">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th style="width: 110px;">Cantidad</th>
                                <th style="width: 60px;"></th>
                            </tr>
                        </thead>
                        <tbody id="lineas-body">
                            <tr id="fila-vacia"><td colspan="3" class="center grey-text">Aún no has agregado productos a la lista.</td></tr>
                        </tbody>
                    </table>

                    <div class="row" style="margin-top: 18px;">
                        <div class="input-field col s12">
                            <input type="text" id="observacion" placeholder="Ej: Resurtido semanal">
                            <label for="observacion" class="active">Motivo de la transferencia</label>
                        </div>
                    </div>

                    <div class="center-align" style="margin-top: 10px;">
                        <button type="button" id="btn-ejecutar" class="btn-large deep-purple darken-1 waves-effect waves-light" style="width: 100%;" disabled>
                            EJECUTAR TRANSFERENCIA (<span id="conteo-lineas">0</span>) <i class="material-icons right">send</i>
                        </button>
                    </div>

                    <?php echo csrfInput(); ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const PRODUCTOS = <?php echo json_encode($productos, JSON_UNESCAPED_UNICODE); ?>;
        const CSRF = document.querySelector('input[name="csrf_token"]')?.value || '';
        const ENDPOINT = '<?php echo BASE_URL; ?>api/transfer_stock.php';

        const searchInput = document.getElementById('p-search');
        const dropdown = document.getElementById('p-dropdown');
        const cantidadInput = document.getElementById('p-cantidad');
        const btnAgregar = document.getElementById('btn-agregar-linea');
        const btnEjecutar = document.getElementById('btn-ejecutar');
        const elegidoLabel = document.getElementById('producto-elegido');
        const cuerpoLineas = document.getElementById('lineas-body');
        const filaVacia = document.getElementById('fila-vacia');
        const conteoLineas = document.getElementById('conteo-lineas');
        const origenSel = document.getElementById('id_origen');
        const destinoSel = document.getElementById('id_destino');

        let productoElegido = null;
        let resaltado = -1;
        let items = []; // [{ id_producto, nombre, cantidad }]

        function normalizar(v) {
            return String(v || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
        }
        function escapeHtml(v) {
            return String(v ?? '').replace(/[&<>"']/g, (c) => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
            }[c]));
        }
        function etiquetaProducto(p) {
            return p.nombre_variante ? `${p.nombre} - ${p.nombre_variante}` : p.nombre;
        }

        function cerrarDropdown() {
            dropdown.classList.remove('abierto');
            dropdown.innerHTML = '';
            resaltado = -1;
        }

        function render(query) {
            const q = normalizar(query);
            if (q === '') { cerrarDropdown(); return; }

            const matches = PRODUCTOS.filter((p) => {
                const texto = normalizar(`${p.nombre} ${p.nombre_variante || ''} ${p.sku || ''} ${p.codigo_barras || ''}`);
                return texto.includes(q);
            }).slice(0, 15);

            if (matches.length === 0) {
                dropdown.innerHTML = '<div class="vacio">Sin resultados</div>';
                dropdown.classList.add('abierto');
                return;
            }

            dropdown.innerHTML = matches.map((p, i) => `
                <div class="item" data-idx="${PRODUCTOS.indexOf(p)}" data-pos="${i}">
                    <div class="nombre">${escapeHtml(etiquetaProducto(p))}</div>
                    <div class="sub">${escapeHtml(p.sku || p.codigo_barras || 'Sin SKU')}</div>
                </div>
            `).join('');
            dropdown.classList.add('abierto');
            resaltado = -1;

            dropdown.querySelectorAll('.item').forEach((el) => {
                el.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    elegir(PRODUCTOS[Number(el.dataset.idx)]);
                });
            });
        }

        function elegir(p) {
            productoElegido = p;
            searchInput.value = etiquetaProducto(p);
            elegidoLabel.innerHTML = `Producto seleccionado: <strong>${escapeHtml(etiquetaProducto(p))}</strong>`;
            cerrarDropdown();
            cantidadInput.focus();
            cantidadInput.select();
        }

        function refrescarResaltado(pos) {
            const els = [...dropdown.querySelectorAll('.item')];
            els.forEach((el) => el.classList.remove('resaltado'));
            if (pos >= 0 && pos < els.length) {
                els[pos].classList.add('resaltado');
                els[pos].scrollIntoView({ block: 'nearest' });
            }
            resaltado = pos;
        }

        searchInput.addEventListener('input', () => {
            productoElegido = null;
            elegidoLabel.textContent = '';
            render(searchInput.value);
        });
        searchInput.addEventListener('focus', () => {
            if (searchInput.value.trim() !== '' && !productoElegido) render(searchInput.value);
        });
        searchInput.addEventListener('blur', () => setTimeout(cerrarDropdown, 150));
        searchInput.addEventListener('keydown', (e) => {
            const els = [...dropdown.querySelectorAll('.item')];
            if (e.key === 'ArrowDown') { e.preventDefault(); refrescarResaltado(Math.min(resaltado + 1, els.length - 1)); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); refrescarResaltado(Math.max(resaltado - 1, 0)); }
            else if (e.key === 'Enter') {
                if (resaltado >= 0 && els[resaltado]) {
                    e.preventDefault();
                    elegir(PRODUCTOS[Number(els[resaltado].dataset.idx)]);
                }
            } else if (e.key === 'Escape') {
                cerrarDropdown();
            }
        });

        function pintarLineas() {
            filaVacia.style.display = items.length ? 'none' : '';
            cuerpoLineas.querySelectorAll('tr.linea').forEach((tr) => tr.remove());

            items.forEach((it, idx) => {
                const tr = document.createElement('tr');
                tr.className = 'linea';
                tr.innerHTML = `
                    <td>${escapeHtml(it.nombre)}</td>
                    <td>
                        <input type="number" min="1" value="${it.cantidad}" class="linea-cantidad-input" data-idx="${idx}">
                    </td>
                    <td>
                        <a href="#!" class="red-text quitar-linea" data-idx="${idx}" title="Quitar"><i class="material-icons">delete</i></a>
                    </td>
                `;
                cuerpoLineas.appendChild(tr);
            });

            cuerpoLineas.querySelectorAll('.quitar-linea').forEach((a) => {
                a.addEventListener('click', (e) => {
                    e.preventDefault();
                    items.splice(Number(a.dataset.idx), 1);
                    pintarLineas();
                });
            });
            cuerpoLineas.querySelectorAll('.linea-cantidad-input').forEach((inp) => {
                inp.addEventListener('change', () => {
                    const n = Math.floor(Number(inp.value));
                    if (!Number.isFinite(n) || n < 1) { inp.value = items[Number(inp.dataset.idx)].cantidad; return; }
                    items[Number(inp.dataset.idx)].cantidad = n;
                });
            });

            conteoLineas.textContent = String(items.length);
            btnEjecutar.disabled = items.length === 0;
        }

        btnAgregar.addEventListener('click', () => {
            if (!productoElegido) {
                return M.toast({ html: 'Elige un producto de la lista primero.', classes: 'red darken-1' });
            }
            const cantidad = Math.floor(Number(cantidadInput.value));
            if (!Number.isFinite(cantidad) || cantidad < 1) {
                return M.toast({ html: 'La cantidad debe ser mayor a cero.', classes: 'red darken-1' });
            }

            const existente = items.find((it) => it.id_producto === productoElegido.id_producto);
            if (existente) {
                existente.cantidad += cantidad;
            } else {
                items.push({
                    id_producto: productoElegido.id_producto,
                    nombre: etiquetaProducto(productoElegido),
                    cantidad,
                });
            }

            productoElegido = null;
            searchInput.value = '';
            cantidadInput.value = '1';
            elegidoLabel.textContent = '';
            searchInput.focus();
            pintarLineas();
        });

        btnEjecutar.addEventListener('click', () => {
            const idOrigen = origenSel.value;
            const idDestino = destinoSel.value;

            if (!idOrigen || !idDestino) {
                return M.toast({ html: 'Elige el almacén de origen y el de destino.', classes: 'red darken-1' });
            }
            if (idOrigen === idDestino) {
                return M.toast({ html: 'El origen y el destino no pueden ser iguales.', classes: 'red darken-1' });
            }
            if (items.length === 0) {
                return M.toast({ html: 'Agrega al menos un producto a la lista.', classes: 'red darken-1' });
            }

            const fd = new FormData();
            fd.append('csrf_token', CSRF);
            fd.append('id_origen', idOrigen);
            fd.append('id_destino', idDestino);
            fd.append('observacion', document.getElementById('observacion').value || '');
            fd.append('items', JSON.stringify(items.map((it) => ({
                id_producto: it.id_producto,
                cantidad: it.cantidad,
            }))));

            btnEjecutar.disabled = true;

            fetch(ENDPOINT, { method: 'POST', body: fd })
                .then((r) => r.json())
                .then((res) => {
                    if (res.success) {
                        Swal.fire('¡Transferencia realizada!', res.message, 'success').then(() => location.reload());
                    } else {
                        btnEjecutar.disabled = false;
                        Swal.fire('Error', res.message || 'No se pudo completar la transferencia.', 'error');
                    }
                })
                .catch(() => {
                    btnEjecutar.disabled = false;
                    Swal.fire('Error', 'Fallo de conexión. Revisa tu red e inténtalo de nuevo.', 'error');
                });
        });
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
