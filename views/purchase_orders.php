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
$pdo = getPDO();
$usuario = $_SESSION['usuario'];
$idAlmacen = $usuario['id_almacen'];

// Obtener productos bajo el stock mínimo incluyendo el ID del almacén para el proceso
$sql = "SELECT p.id_producto, p.nombre, p.sku, p.precio_costo, p.precio_venta, ia.cantidad_actual, ia.stock_minimo, ia.stock_maximo, a.nombre as sucursal, ia.id_almacen
        FROM productos p
        JOIN inventario_almacen ia ON p.id_producto = ia.id_producto
        JOIN almacenes a ON ia.id_almacen = a.id_almacen
        WHERE ia.cantidad_actual <= ia.stock_minimo AND p.estado = 'activo'";

if (!isAdmin()) {
    $sql .= " AND ia.id_almacen = " . (int)$idAlmacen;
}

$sql .= " ORDER BY a.nombre, p.nombre";
$stmt = $pdo->query($sql);
$listaCompra = $stmt->fetchAll();

// Obtener datos para el gráfico de categorías con faltantes (Agrupado)
$sqlChart = "SELECT COALESCE(c.nombre, 'Sin Categoría') as categoria, COUNT(DISTINCT p.id_producto) as total
             FROM productos p
             JOIN inventario_almacen ia ON p.id_producto = ia.id_producto
             LEFT JOIN producto_categorias pc ON p.id_producto = pc.id_producto
             LEFT JOIN categorias c ON pc.id_categoria = c.id_categoria
             WHERE ia.cantidad_actual <= ia.stock_minimo AND p.estado = 'activo'";

if (!isAdmin()) {
    $sqlChart .= " AND ia.id_almacen = " . (int)$idAlmacen;
}
$sqlChart .= " GROUP BY categoria ORDER BY total DESC";
$chartData = $pdo->query($sqlChart)->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="col s12">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 20px; flex-wrap: wrap; gap: 10px;">
                <h4 style="margin: 0;"><i class="material-icons left" style="font-size: 2.5rem; color: #1a237e;">shopping_cart</i> Lista de Compra Sugerida</h4>
                <a href="dashboard.php" class="btn blue darken-4 waves-effect waves-light"><i class="material-icons left">dashboard</i> Volver al Dashboard</a>
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

                    <?php if (empty($listaCompra)): ?>
                        <div class="center-align" style="padding: 40px;">
                            <i class="material-icons large green-text">check_circle</i>
                            <h5>¡Inventario saludable!</h5>
                            <p>No hay productos que necesiten resurtido actualmente.</p>
                        </div>
                    <?php else: ?>
                        <form id="form-entrada-masiva">
                            <?php echo csrfInput(); ?>
                            <table class="striped highlight responsive-table" style="margin-top: 20px;">
                                <thead>
                                    <tr>
                                        <th>Producto</th>
                                        <th>Sucursal</th>
                                        <th>P. Venta</th>
                                        <th class="center-align">Stock Actual</th>
                                        <th class="center-align" style="width: 180px;">Ajustar Mín/Máx</th>
                                        <th class="blue lighten-5 center-align" style="width: 150px;">Cantidad Recibida</th>
                                        <th class="right-align">Subtotal Est.</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $totalInversion = 0;
                                    foreach ($listaCompra as $index => $item): 
                                        $aComprar = max(0, $item['stock_maximo'] - $item['cantidad_actual']);
                                        $costoFila = $aComprar * (float)$item['precio_costo'];
                                        $totalInversion += $costoFila;
                                    ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo esc($item['nombre']); ?></strong><br>
                                                <small class="grey-text">SKU: <?php echo esc($item['sku']); ?></small>
                                            </td>
                                            <td><?php echo esc($item['sucursal']); ?></td>
                                            <td>$<?php echo number_format((float)$item['precio_venta'], 2); ?></td>
                                            <td class="red-text center-align"><strong><?php echo $item['cantidad_actual']; ?></strong></td>
                                            <td class="center-align">
                                                <div style="display: flex; gap: 5px;">
                                                    <input type="number" name="items[<?php echo $index; ?>][stock_minimo]" value="<?php echo $item['stock_minimo']; ?>" class="browser-default qty-input" title="Mínimo" style="width: 50%; padding: 2px;">
                                                    <input type="number" name="items[<?php echo $index; ?>][stock_maximo]" value="<?php echo $item['stock_maximo']; ?>" class="browser-default qty-input" title="Máximo" style="width: 50%; padding: 2px;">
                                                </div>
                                            </td>
                                            <td class="blue lighten-5">
                                                <input type="hidden" name="items[<?php echo $index; ?>][id_producto]" value="<?php echo $item['id_producto']; ?>">
                                                <input type="hidden" name="items[<?php echo $index; ?>][id_almacen]" value="<?php echo $item['id_almacen']; ?>">
                                                <input type="number" 
                                                       name="items[<?php echo $index; ?>][cantidad]" 
                                                       value="<?php echo $aComprar; ?>" 
                                                       min="0" 
                                                       class="browser-default qty-input" 
                                                       style="width: 100%; text-align: center; border: 1px solid #9e9e9e; border-radius: 4px; padding: 5px;">
                                            </td>
                                            <td class="right-align">$<?php echo number_format($costoFila, 2); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>

                            <div class="row" style="margin-top: 30px; display: flex; align-items: center; justify-content: flex-end; gap: 20px; flex-wrap: wrap;">
                                <div class="grey-text text-darken-2">
                                    <h5 style="margin: 0;">Total Inversión: <strong>$<?php echo number_format($totalInversion, 2); ?></strong></h5>
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

                        <div class="right-align" style="margin-top: 10px;">
                            <button onclick="window.print()" class="btn waves-effect waves-light blue darken-4">
                                <i class="material-icons left">print</i> Imprimir Lista de Trabajo
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Sección del Gráfico de Faltantes -->
    <?php if (!empty($chartData) && !empty($listaCompra)): ?>
    <div class="row no-print" style="margin-top: 30px;">
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
    <?php endif; ?>
</div>

<!-- Incluimos librerías necesarias -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
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
                });
            }
        });
    }

    // Inicializar Gráfico de Faltantes
    <?php if (!empty($chartData)): ?>
    const ctx = document.getElementById('chartFaltantes').getContext('2d');
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode(array_column($chartData, 'categoria')); ?>,
            datasets: [{
                data: <?php echo json_encode(array_column($chartData, 'total')); ?>,
                backgroundColor: [
                    '#1a237e', '#283593', '#303f9f', '#3949ab', '#3f51b5',
                    '#5c6bc0', '#7986cb', '#9fa8da', '#c5cae9', '#e8eaf6'
                ]
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'bottom' } }
        }
    });
    <?php endif; ?>
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
