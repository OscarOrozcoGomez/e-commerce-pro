<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
if (!isAdmin()) {
    header('Location: dashboard.php');
    exit;
}

$pageTitle = 'Transferencia entre Almacenes';
$pdo = getPDO();

$almacenes = $pdo->query("SELECT * FROM almacenes WHERE estado = 'activo' ORDER BY nombre ASC")->fetchAll();
$productos = $pdo->query("SELECT id_producto, nombre, sku FROM productos WHERE estado = 'activo' ORDER BY nombre ASC")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="col s12">
            <div style="margin-top: 20px;">
                <a href="dashboard.php" class="btn-flat waves-effect grey-text text-darken-2" style="padding: 0;">
                    <i class="material-icons left">arrow_back</i> Volver al Dashboard
                </a>
            </div>
            <h4><i class="material-icons left" style="font-size: 2.5rem; color: #5e35b1;">swap_horiz</i> Transferencia de Mercancía</h4>
            <p class="grey-text">Mueve productos de una sucursal a otra de forma inmediata.</p>
        </div>
    </div>

    <div class="row">
        <div class="col s12 m8 offset-m2">
            <div class="card">
                <div class="card-content">
                    <form id="form-transferencia">
                        <?php echo csrfInput(); ?>
                        
                        <div class="row">
                            <div class="input-field col s12">
                                <i class="material-icons prefix">search</i>
                                <input type="text" id="p-search" class="autocomplete" autocomplete="off" required>
                                <label for="p-search">Buscar Producto (SKU o Nombre)</label>
                                <input type="hidden" name="id_producto" id="id_producto_transfer">
                            </div>
                        </div>

                        <div class="row">
                            <div class="input-field col s12 m6">
                                <select name="id_origen" id="id_origen" required class="browser-default" style="border: 1px solid #ccc; padding: 5px;">
                                    <option value="" disabled selected>-- Almacén Origen --</option>
                                    <?php foreach ($almacenes as $alm): ?>
                                        <option value="<?php echo $alm['id_almacen']; ?>"><?php echo esc($alm['nombre']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label class="active">Desde:</label>
                            </div>
                            <div class="input-field col s12 m6">
                                <select name="id_destino" id="id_destino" required class="browser-default" style="border: 1px solid #ccc; padding: 5px;">
                                    <option value="" disabled selected>-- Almacén Destino --</option>
                                    <?php foreach ($almacenes as $alm): ?>
                                        <option value="<?php echo $alm['id_almacen']; ?>"><?php echo esc($alm['nombre']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <label class="active">Hacia:</label>
                            </div>
                        </div>

                        <div class="row">
                            <div class="input-field col s12 m4">
                                <input type="number" name="cantidad" id="cantidad" min="1" required>
                                <label for="cantidad">Cantidad a mover</label>
                            </div>
                            <div class="input-field col s12 m8">
                                <input type="text" name="observacion" id="observacion" placeholder="Ej: Resurtido semanal">
                                <label for="observacion">Motivo de transferencia</label>
                            </div>
                        </div>

                        <div class="center-align" style="margin-top: 20px;">
                            <button type="submit" class="btn-large deep-purple darken-1 waves-effect waves-light" style="width: 100%;">
                                EJECUTAR TRANSFERENCIA <i class="material-icons right">send</i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        const prods = <?php echo json_encode($productos); ?>;
        const dataAC = {};
        const map = {};
        prods.forEach(p => {
            const label = `[${p.sku}] ${p.nombre}`;
            dataAC[label] = null;
            map[label] = p.id_producto;
        });

        M.Autocomplete.init(document.getElementById('p-search'), {
            data: dataAC,
            onAutocomplete: (val) => document.getElementById('id_producto_transfer').value = map[val]
        });

        document.getElementById('form-transferencia').addEventListener('submit', function(e) {
            e.preventDefault();
            const formData = new FormData(this);
            
            if(formData.get('id_origen') === formData.get('id_destino')) {
                return M.toast({html: 'El origen y destino no pueden ser iguales', classes: 'red'});
            }

            fetch('<?php echo BASE_URL; ?>api/transfer_stock.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(res => {
                if(res.success) {
                    Swal.fire('¡Éxito!', res.message, 'success').then(() => location.reload());
                } else {
                    Swal.fire('Error', res.message, 'error');
                }
            });
        });
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>