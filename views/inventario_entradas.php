<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
if (!isAdmin() && !isEncargado()) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$pageTitle = 'Entradas de Inventario';
$pdo = getPDO();
$usuario = $_SESSION['usuario'];

// Lógica de sucursal: Admin puede elegir vía GET, Encargado usa su sesión
$almacenId = $usuario['id_almacen'] ?: (int)($_GET['id_almacen'] ?? 0);

$error = '';
$success = '';

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
                <a href="dashboard.php" class="btn blue darken-4 waves-effect waves-light"><i class="material-icons left">dashboard</i> Volver al Dashboard</a>
            </div>
            <p class="grey-text">Registra la llegada de mercancía al almacén. Puedes hacerlo uno por uno o en la lista rápida.</p>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="card green lighten-4 green-text text-darken-4" style="padding: 10px;">
            <i class="material-icons left">check_circle</i> <?php echo esc($success); ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="card red lighten-4 red-text text-darken-4" style="padding: 10px;">
            <i class="material-icons left">error</i> <?php echo esc($error); ?>
        </div>
    <?php endif; ?>

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
                            <input type="text" id="buscador-inbound" class="autocomplete" autocomplete="off">
                            <label for="buscador-inbound">Buscar Producto (SKU o Nombre)</label>
                            <input type="hidden" name="id_producto" id="id_producto_inbound">
                        </div>
                        
                        <div class="input-field">
                            <input type="number" name="cantidad" id="cantidad_inbound" min="1" required>
                            <label for="cantidad_inbound">Cantidad a Ingresar</label>
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
                                            <small class="grey-text">SKU: <?php echo esc($p['sku']); ?></small>
                                        </td>
                                        <td class="center-align">
                                            <span class="badge <?php echo $p['cantidad_actual'] <= $p['stock_minimo'] ? 'red white-text' : 'grey lighten-2'; ?>" style="float: none;">
                                                <?php echo $p['cantidad_actual']; ?>
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

    document.addEventListener('DOMContentLoaded', function() {
        const productos = <?php echo json_encode($productos); ?>;
        initAutocomplete(productos);

        // Manejar envío individual
        document.getElementById('form-inbound-manual').addEventListener('submit', function(e) {
            e.preventDefault();
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
        const qty = document.getElementById('qty_' + id).value;
        if (!qty || qty <= 0) {
            M.toast({html: 'Ingresa una cantidad válida', classes: 'red'});
            return;
        }

        // Crear un form temporal para enviar vía POST
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <?php echo csrfInput(); ?>
            <input type="hidden" name="accion" value="entrada_individual">
            <input type="hidden" name="id_producto" value="${id}">
            <input type="hidden" name="cantidad" value="${qty}">
            <input type="hidden" name="observacion" value="Carga rápida de inventario">
        `;
        document.body.appendChild(form);
        form.submit();
    }
</script>

<style>
    .w-100 { width: 100%; }
    .qty-input { text-align: center; font-weight: bold; }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
