<?php
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
if (!isAdmin() && !isEncargado()) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$idOrden = intval($_GET['id'] ?? 0);
if (!$idOrden) {
    header('Location: purchase_orders.php');
    exit;
}

$pageTitle = 'Procesar Entrada de Mercancía';
$pdo = getPDO();

// Obtener cabecera
$stmt = $pdo->prepare("SELECT * FROM ordenes_compra WHERE id_orden_compra = ?");
$stmt->execute([$idOrden]);
$orden = $stmt->fetch();

if (!$orden || $orden['estado'] !== 'enviada') {
    die("La orden no existe o ya fue procesada.");
}

// Obtener detalles
$stmtDetails = $pdo->prepare("SELECT d.*, p.nombre, p.sku 
                             FROM detalle_orden_compra d 
                             JOIN productos p ON d.id_producto = p.id_producto 
                             WHERE d.id_orden_compra = ?");
$stmtDetails->execute([$idOrden]);
$items = $stmtDetails->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="container" style="margin-top: 30px;">
    <div class="row">
        <div class="col s12">
            <a href="purchase_orders.php" class="btn-flat waves-effect"><i class="material-icons left">arrow_back</i> Volver</a>
            <h4>Recibir Mercancía: <?php echo $orden['referencia']; ?></h4>
            <p>Confirma las cantidades reales que llegaron. Si un producto no llegó, cambia su cantidad a 0.</p>
        </div>
    </div>

    <div class="row">
        <div class="col s12">
            <div class="card">
                <div class="card-content">
                    <form id="form-process-inbound">
                        <input type="hidden" name="id_orden_compra" value="<?php echo $idOrden; ?>">
                        <table class="striped">
                            <thead>
                                <tr>
                                    <th>Producto</th>
                                    <th>SKU</th>
                                    <th>Solicitado</th>
                                    <th>Recibido (Confirmar)</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($items as $item): ?>
                                    <tr id="row_<?php echo $item['id_detalle']; ?>">
                                        <td><?php echo esc($item['nombre']); ?></td>
                                        <td><?php echo esc($item['sku']); ?></td>
                                        <td><?php echo $item['cantidad_solicitada']; ?></td>
                                        <td>
                                            <input type="number" name="recibido_<?php echo $item['id_producto']; ?>" 
                                                   value="<?php echo $item['cantidad_solicitada']; ?>" 
                                                   min="0" class="browser-default" style="width: 80px; padding: 5px;">
                                        </td>
                                        <td>
                                            <button type="button" onclick="eliminarFila(<?php echo $item['id_detalle']; ?>)" class="btn-small red waves-effect"><i class="material-icons">delete</i></button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <div class="right-align" style="margin-top: 30px;">
                            <button type="button" onclick="procesarEntrada()" class="btn-large green waves-effect waves-light">
                                <i class="material-icons left">check_circle</i> Autorizar e Ingresar al Inventario
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function eliminarFila(id) {
        if(confirm('¿Seguro que no llegó este producto?')) {
            const row = document.getElementById('row_' + id);
            row.querySelector('input').value = 0;
            row.style.opacity = '0.5';
            row.style.background = '#ffebee';
        }
    }

    function procesarEntrada() {
        if(!confirm('¿Confirmas que toda la mercancía listada es correcta? Se actualizará el inventario inmediatamente.')) return;
        
        const formData = new FormData(document.getElementById('form-process-inbound'));
        
        fetch('<?php echo BASE_URL; ?>api/process_inbound.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                Swal.fire('¡Éxito!', 'Inventario actualizado correctamente.', 'success').then(() => {
                    window.location.href = 'purchase_orders.php';
                });
            } else {
                M.toast({html: 'Error: ' + data.error, classes: 'red'});
            }
        });
    }
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
