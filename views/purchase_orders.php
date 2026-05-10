<?php
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
if (!isAdmin() && !isEncargado()) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$pageTitle = 'Gestión de Resurtido';
$pdo = getPDO();
$usuario = $_SESSION['usuario'];
$idAlmacen = $usuario['id_almacen'];

// 1. Obtener productos con stock bajo (Sugerencia de pedido)
$sqlSugerencia = "SELECT p.id_producto, p.nombre, p.sku, p.precio_costo, ia.cantidad_actual, ia.stock_minimo, ia.stock_maximo
                  FROM productos p
                  JOIN inventario_almacen ia ON p.id_producto = ia.id_producto
                  WHERE ia.id_almacen = :almacen AND ia.cantidad_actual <= ia.stock_minimo
                  ORDER BY p.nombre ASC";
$stmt = $pdo->prepare($sqlSugerencia);
$stmt->execute([':almacen' => $idAlmacen]);
$sugerencias = $stmt->fetchAll();

// 2. Obtener órdenes de compra pendientes
$sqlOrdenes = "SELECT oc.*, u.nombre as creador 
               FROM ordenes_compra oc 
               JOIN usuarios u ON oc.id_usuario = u.id_usuario
               WHERE oc.id_almacen = :almacen AND oc.estado IN ('borrador', 'enviada')
               ORDER BY oc.fecha_creacion DESC";
$stmt = $pdo->prepare($sqlOrdenes);
$stmt->execute([':almacen' => $idAlmacen]);
$ordenesPendientes = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="container" style="margin-top: 30px;">
    <div class="row">
        <div class="col s12">
            <h3>Módulo de Resurtido y Compras</h3>
            <p class="grey-text text-darken-1">Genera listas de pedido basadas en tu stock mínimo y gestiona la llegada de mercancía.</p>
        </div>
    </div>

    <div class="row">
        <!-- SUGERENCIA DE PEDIDO -->
        <div class="col s12 l8">
            <div class="card">
                <div class="card-content">
                    <span class="card-title"><strong>1. Sugerencia de Resurtido</strong></span>
                    <p class="text-small orange-text"><i class="material-icons left tiny">warning</i> Productos por debajo del mínimo.</p>
                    
                    <form id="form-generar-orden">
                        <table class="striped responsive-table" style="margin-top: 20px;">
                            <thead>
                                <tr>
                                    <th>Producto</th>
                                    <th>SKU</th>
                                    <th>Stock Actual</th>
                                    <th>Faltante (Max)</th>
                                    <th>A Pedir</th>
                                    <th>Costo Est.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($sugerencias)): ?>
                                    <tr><td colspan="6" class="center">¡Todo en orden! No hay productos con stock bajo.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($sugerencias as $s): 
                                        $faltante = $s['stock_maximo'] - $s['cantidad_actual'];
                                        if ($faltante < 0) $faltante = 0;
                                    ?>
                                        <tr>
                                            <td><?php echo esc($s['nombre']); ?></td>
                                            <td><?php echo esc($s['sku']); ?></td>
                                            <td><span class="badge orange white-text" style="float:none;"><?php echo $s['cantidad_actual']; ?></span></td>
                                            <td><?php echo $faltante; ?></td>
                                            <td style="width: 100px;">
                                                <input type="number" name="cant_<?php echo $s['id_producto']; ?>" value="<?php echo $faltante; ?>" min="0" class="browser-default" style="width: 60px; padding: 5px; border: 1px solid #ccc;">
                                            </td>
                                            <td>$ <?php echo number_format($s['precio_costo'], 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>

                        <?php if (!empty($sugerencias)): ?>
                            <div class="right-align" style="margin-top: 20px;">
                                <button type="button" onclick="exportarLista()" class="btn blue darken-2 waves-effect"><i class="material-icons left">file_download</i> Descargar Lista</button>
                                <button type="button" onclick="crearOrdenCompra()" class="btn green darken-1 waves-effect"><i class="material-icons left">send</i> Generar Orden de Compra</button>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        </div>

        <!-- ÓRDENES PENDIENTES -->
        <div class="col s12 l4">
            <div class="card indigo darken-4 white-text">
                <div class="card-content">
                    <span class="card-title">Órdenes en Tránsito</span>
                    <p>Mercancía solicitada pendiente de recibir.</p>
                    
                    <ul class="collection black-text" style="border: none;">
                        <?php foreach ($ordenesPendientes as $oc): ?>
                            <li class="collection-item avatar" style="border-bottom: 1px solid #eee;">
                                <i class="material-icons circle orange">local_shipping</i>
                                <span class="title">Ref: <?php echo $oc['referencia']; ?></span>
                                <p><?php echo date('d/m/Y', strtotime($oc['fecha_creacion'])); ?><br>
                                   Est: <strong>$ <?php echo number_format($oc['total_estimado'], 2); ?></strong>
                                </p>
                                <a href="process_inbound.php?id=<?php echo $oc['id_orden_compra']; ?>" class="secondary-content btn-small green">Recibir</a>
                            </li>
                        <?php endforeach; ?>
                        <?php if (empty($ordenesPendientes)): ?>
                            <li class="collection-item center grey-text">No hay órdenes pendientes.</li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    function exportarLista() {
        const rows = [];
        rows.push(["Producto", "SKU", "Cantidad a Pedir", "Costo Unitario Est."]);
        
        document.querySelectorAll('#form-generar-orden tbody tr').forEach(tr => {
            const cells = tr.querySelectorAll('td');
            if (cells.length < 5) return;
            const input = tr.querySelector('input');
            if (input && input.value > 0) {
                rows.push([
                    cells[0].innerText,
                    cells[1].innerText,
                    input.value,
                    cells[5].innerText.replace('$ ', '')
                ]);
            }
        });

        let csvContent = "data:text/csv;charset=utf-8," + rows.map(e => e.join(",")).join("\n");
        const encodedUri = encodeURI(csvContent);
        const link = document.createElement("a");
        link.setAttribute("href", encodedUri);
        link.setAttribute("download", "pedido_resurtido_" + new Date().toLocaleDateString() + ".csv");
        document.body.appendChild(link);
        link.click();
    }

    function crearOrdenCompra() {
        const formData = new FormData(document.getElementById('form-generar-orden'));
        
        fetch('<?php echo BASE_URL; ?>api/create_po.php', {
            method: 'POST',
            body: formData
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                M.toast({html: 'Orden de compra generada!', classes: 'green'});
                setTimeout(() => location.reload(), 1500);
            } else {
                M.toast({html: 'Error: ' + data.error, classes: 'red'});
            }
        });
    }
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
