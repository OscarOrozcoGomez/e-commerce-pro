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

$pageTitle = 'Entradas de Inventario';
$pdo = getPDO();
$usuario = $_SESSION['usuario'];

// Lógica de sucursal: Admin puede elegir vía GET, Encargado usa su sesión
$almacenId = (int)($usuario['id_almacen'] ?: ($_GET['id_almacen'] ?? 0));

// Si es Admin y no hay ID, buscar el primero
if (isAdmin() && !$almacenId) {
    $res = $pdo->query("SELECT id_almacen FROM almacenes WHERE estado = 'activo' LIMIT 1")->fetch();
    $almacenId = $res ? (int)$res['id_almacen'] : 0;
}

if (!$almacenId) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$productos = dbGetInventoryProducts((int)$almacenId);

include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="col s12">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 20px; flex-wrap: wrap; gap: 10px;">
                <h4 style="margin: 0;"><i class="material-icons left" style="font-size: 2.5rem; color: #2e7d32;">add_business</i> Entradas de Inventario</h4>
                <a href="<?php echo BASE_URL; ?>views/dashboard.php" class="btn blue darken-4 waves-effect waves-light"><i class="material-icons left">dashboard</i> Volver al Dashboard</a>
            </div>
            <p class="grey-text">Registra la llegada de mercancía al almacén. Puedes hacerlo uno por uno o en la lista rápida.</p>
        </div>
    </div>

    <div class="row">
        <!-- Entrada Individual -->
        <div class="col s12 m4">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Entrada Individual</span>
                    <form id="form-inbound-manual">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="accion" value="entrada_individual">
                        <input type="hidden" name="id_almacen" value="<?php echo (int)$almacenId; ?>">
                        
                        <div class="input-field">
                            <!-- no-autoinit: views/includes/footer.php llama M.AutoInit() en cada pagina, que
                                 reinicializa CUALQUIER .autocomplete despues de que initAutocomplete() (abajo) ya lo
                                 inicializo con los productos reales -- la segunda inicializacion trae data:{} por
                                 defecto y deja caer el dropdown vacio (la resolucion por blur seguia funcionando,
                                 pero sin sugerencias visibles). no-autoinit excluye este input de ese segundo pase. -->
                            <input type="text" id="buscador-inbound" class="autocomplete no-autoinit" autocomplete="off">
                            <label for="buscador-inbound">Buscar Producto (SKU o Nombre)</label>
                            <input type="hidden" name="id_producto" id="id_producto_inbound">
                        </div>
                        
                        <div class="input-field">
                            <input type="number" name="cantidad" id="cantidad_inbound" min="1" required>
                            <label for="cantidad_inbound">Cantidad a Ingresar</label>
                        </div>

                        <div class="row" style="margin-bottom:0;">
                            <div class="input-field col s6">
                                <input type="text" name="codigo_lote" id="lote_inbound">
                                <label for="lote_inbound">Lote (opcional)</label>
                            </div>
                            <div class="input-field col s6">
                                <input type="date" name="fecha_caducidad" id="caducidad_inbound">
                                <label for="caducidad_inbound" class="active">Caducidad</label>
                            </div>
                        </div>

                        <div class="input-field">
                            <input type="text" name="observacion" id="obs_inbound" value="Entrada de mercancía">
                            <label for="obs_inbound">Observación / Factura</label>
                        </div>
                        
                        <button type="submit" class="btn green waves-effect waves-light w-100">
                            REGISTRAR ENTRADA
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Lista Rápida / Masiva -->
        <div class="col s12 m8">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Carga Rápida de Inventario</span>
                    <p class="small grey-text">Escribe la cantidad que llegó y presiona el botón verde de cada fila.</p>

                    <div class="input-field" style="margin-top: 20px;">
                        <i class="material-icons prefix">search</i>
                        <input type="text" id="filtro-lista-rapida" placeholder="Buscar por nombre o SKU en esta lista...">
                    </div>
                    
                    <div style="max-height: 500px; overflow-y: auto; margin-top: 20px;">
                        <table class="striped condensed">
                            <thead>
                                <tr>
                                    <th>Producto</th>
                                    <th>Stock Actual</th>
                                    <th>Ingresar</th>
                                    <th>Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($productos as $p): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc($p['nombre']); ?></strong><br>
                                            <small class="grey-text">SKU: <?php echo esc($p['sku'] ?? ''); ?></small>
                                        </td>
                                        <td class="center-align">
                                            <span class="badge <?php echo $p['cantidad_actual'] <= $p['stock_minimo'] ? 'red white-text' : 'grey lighten-2'; ?>" style="float: none;">
                                                <?php echo esc((string)$p['cantidad_actual']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <input type="number" class="qty-input" id="qty_<?php echo $p['id_producto']; ?>" min="1" style="height: 1.5rem; width: 60px; margin: 0;">
                                        </td>
                                        <td>
                                            <button type="button" class="btn-floating btn-small green waves-effect waves-light" onclick="registrarEntradaRapida(<?php echo $p['id_producto']; ?>)">
                                                <i class="material-icons">add</i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const API_INV = '<?php echo BASE_URL; ?>api/inventory_handler.php';
    const ALMACEN_ID_INV = <?php echo (int)$almacenId; ?>;
    let resolverProductoInbound = () => null;

    // Autocompletado del buscador de "Entrada Individual" (#buscador-inbound):
    // resuelve por nombre o SKU y llena el input oculto #id_producto_inbound.
    // Mismo patron (M.Autocomplete + resolucion por blur) que usan otros
    // buscadores del proyecto, p.ej. el de cliente en views/sales.php.
    function initAutocomplete(productos) {
        const el = document.getElementById('buscador-inbound');
        const hiddenId = document.getElementById('id_producto_inbound');
        if (!el || !hiddenId) return;

        const data = {};
        const lookup = {};
        productos.forEach((p) => {
            const nombre = String(p.nombre || '').trim();
            if (nombre === '') return;
            const sku = String(p.sku || '').trim();
            const label = sku !== '' ? `${nombre} (${sku})` : nombre;
            data[label] = null;
            lookup[label.toLowerCase()] = p;
            lookup[nombre.toLowerCase()] = p;
            if (sku !== '') lookup[sku.toLowerCase()] = p;
        });

        function resolveProducto(value) {
            return lookup[String(value || '').trim().toLowerCase()] || null;
        }
        resolverProductoInbound = resolveProducto;

        function seleccionarProducto(p) {
            hiddenId.value = p.id_producto;
        }

        try {
            M.Autocomplete.init(el, {
                data,
                limit: 10,
                minLength: 1,
                onAutocomplete: function (val) {
                    const producto = resolveProducto(val);
                    if (producto) seleccionarProducto(producto);
                }
            });
        } catch (err) {
            console.warn('No se pudo inicializar autocomplete de inventario:', err);
        }

        el.addEventListener('input', () => { hiddenId.value = ''; });
        el.addEventListener('blur', () => {
            const producto = resolveProducto(el.value);
            if (producto) seleccionarProducto(producto);
        });
    }

    // Envia la entrada individual a api/inventory_handler.php (accion=entrada_individual).
    function enviarEntrada(formData) {
        const form = document.getElementById('form-inbound-manual');
        const btn = form ? form.querySelector('button[type="submit"]') : null;
        if (btn) btn.disabled = true;

        fetch(API_INV, { method: 'POST', body: formData })
            .then((response) => response.json())
            .then((data) => {
                if (data.success) {
                    M.toast({ html: data.message || 'Entrada registrada con éxito', classes: 'green' });
                    if (form) form.reset();
                    const hiddenId = document.getElementById('id_producto_inbound');
                    if (hiddenId) hiddenId.value = '';
                } else {
                    M.toast({ html: data.message || 'Error al registrar la entrada', classes: 'red' });
                }
            })
            .catch((err) => {
                console.error(err);
                M.toast({ html: 'Error de conexión. Inténtalo de nuevo.', classes: 'red' });
            })
            .finally(() => {
                if (btn) btn.disabled = false;
            });
    }

    document.addEventListener('DOMContentLoaded', function() {
        const productos = <?php echo json_encode($productos, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        initAutocomplete(productos);

        // Manejar envío individual
        document.getElementById('form-inbound-manual').addEventListener('submit', function(e) {
            e.preventDefault();
            const buscador = document.getElementById('buscador-inbound');
            const hiddenId = document.getElementById('id_producto_inbound');
            if (hiddenId && !hiddenId.value && buscador) {
                const producto = resolverProductoInbound(buscador.value);
                if (producto) hiddenId.value = producto.id_producto;
            }
            if (hiddenId && !hiddenId.value) {
                M.toast({ html: 'Selecciona un producto válido de la lista', classes: 'red' });
                return;
            }
            const formData = new FormData(this);
            enviarEntrada(formData);
        });

        // Lógica de búsqueda/filtro para la lista rápida
        document.getElementById('filtro-lista-rapida').addEventListener('keyup', function() {
            const filter = this.value.toLowerCase();
            const table = this.closest('.card-content').querySelector('table');
            const rows = table.querySelectorAll('tbody tr');
            
            rows.forEach(row => {
                const text = row.innerText.toLowerCase();
                row.style.display = text.includes(filter) ? '' : 'none';
            });
        });
    });

    function registrarEntradaRapida(id) {
        const input = document.getElementById('qty_' + id);
        const qty = parseInt(input ? input.value : '', 10);
        if (!Number.isInteger(qty) || qty <= 0) {
            M.toast({html: 'Ingresa una cantidad válida', classes: 'red'});
            return;
        }

        const row = input ? input.closest('tr') : null;
        const btn = row ? row.querySelector('button') : null;
        if (btn) btn.disabled = true;

        // Reutiliza csrf_token y accion del formulario real; sobreescribe los datos de esta fila.
        const formData = new FormData(document.getElementById('form-inbound-manual'));
        formData.set('id_producto', String(id));
        formData.set('cantidad', String(qty));
        formData.set('observacion', 'Carga rápida de inventario');
        formData.set('id_almacen', String(ALMACEN_ID_INV));

        fetch(API_INV, { method: 'POST', body: formData })
            .then((response) => response.json())
            .then((data) => {
                if (data.success) {
                    M.toast({ html: data.message || 'Entrada registrada con éxito', classes: 'green' });
                    if (input) input.value = '';
                    const badge = row ? row.querySelector('.badge') : null;
                    if (badge) {
                        badge.textContent = String((parseInt(badge.textContent, 10) || 0) + qty);
                    }
                } else {
                    M.toast({ html: data.message || 'Error al registrar la entrada', classes: 'red' });
                }
            })
            .catch((err) => {
                console.error(err);
                M.toast({ html: 'Error de conexión. Inténtalo de nuevo.', classes: 'red' });
            })
            .finally(() => {
                if (btn) btn.disabled = false;
            });
    }
</script>

<style>
    .w-100 { width: 100%; }
    .qty-input { text-align: center; font-weight: bold; }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
