<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
if (!isAdmin()) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$pageTitle = 'Análisis y Predicciones';
$pdo = getPDO();

// 1. Ventas por Mes (Tendencia Anual)
$sqlVentasMes = "SELECT 
                    MONTH(fecha_creacion) as mes, 
                    SUM(total) as total 
                FROM pedidos 
                WHERE estado != 'cancelado' 
                AND YEAR(fecha_creacion) = YEAR(NOW())
                GROUP BY mes 
                ORDER BY mes";
$ventasMesRaw = $pdo->query($sqlVentasMes)->fetchAll(PDO::FETCH_KEY_PAIR);

// Preparar datos para Chart.js (rellenar meses vacíos)
$labelsMeses = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
$dataVentasMes = [];
for ($i = 1; $i <= 12; $i++) {
    $dataVentasMes[] = $ventasMesRaw[$i] ?? 0;
}

// 2. Top 10 Productos más vendidos
$sqlTopProductos = "SELECT p.nombre, SUM(dp.cantidad) as cantidad 
                    FROM detalle_pedidos dp 
                    JOIN productos p ON dp.id_producto = p.id_producto 
                    GROUP BY dp.id_producto 
                    ORDER BY cantidad DESC 
                    LIMIT 10";
$topProductos = $pdo->query($sqlTopProductos)->fetchAll();

// 3. Predicción de Inventario (Días de stock restantes)
// Calculamos el promedio de ventas de los últimos 30 días para predecir
$sqlPrediccion = "SELECT 
                    p.nombre, 
                    ia.cantidad_actual,
                    COALESCE(ventas.total_30d, 0) as ventas_30d,
                    (COALESCE(ventas.total_30d, 0) / 30) as promedio_diario
                  FROM productos p
                  JOIN inventario_almacen ia ON p.id_producto = ia.id_producto
                  LEFT JOIN (
                      SELECT id_producto, SUM(cantidad) as total_30d 
                      FROM detalle_pedidos dp
                      JOIN pedidos pe ON dp.id_pedido = pe.id_pedido
                      WHERE pe.fecha_creacion >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      GROUP BY id_producto
                  ) ventas ON p.id_producto = ventas.id_producto
                  WHERE p.estado = 'activo'
                  ORDER BY ia.cantidad_actual ASC
                  LIMIT 15";
$predicciones = $pdo->query($sqlPrediccion)->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid" style="padding: 20px;">
    <div class="row">
        <div class="col s12">
            <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; margin-bottom: 10px;">
                <h4 style="margin: 0;"><i class="material-icons left" style="font-size: 2.5rem; color: #1a237e;">analytics</i> Inteligencia de Negocio</h4>
                <a href="dashboard.php" class="btn indigo darken-4 waves-effect waves-light"><i class="material-icons left">dashboard</i> Volver al Dashboard</a>
            </div>
            <p class="grey-text">Análisis de tendencias de venta y predicción de abastecimiento basada en datos históricos.</p>
        </div>
    </div>

    <!-- Gráficos Principales -->
    <div class="row">
        <div class="col s12 m8">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Tendencia de Ventas (Anual)</span>
                    <canvas id="chartVentas" height="150"></canvas>
                </div>
            </div>
        </div>
        <div class="col s12 m4">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Top Productos</span>
                    <canvas id="chartTop" height="310"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Predicción de Inventario -->
    <div class="row">
        <div class="col s12">
            <div class="card">
                <div class="card-content">
                    <span class="card-title"><i class="material-icons left indigo-text">precision_manufacturing</i> Predicción de Abastecimiento</span>
                    <p class="grey-text" style="margin-bottom: 20px;">Basado en el promedio de ventas de los últimos 30 días.</p>
                    
                    <table class="striped highlight">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>Stock Actual</th>
                                <th>Ventas (30d)</th>
                                <th>Promedio Diario</th>
                                <th>Días Restantes (Est.)</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($predicciones as $p): 
                                $promedio = (float)$p['promedio_diario'];
                                $diasRestantes = $promedio > 0 ? floor($p['cantidad_actual'] / $promedio) : '∞';
                                
                                $colorStatus = 'green-text';
                                $labelStatus = 'Abastecido';
                                if ($diasRestantes !== '∞') {
                                    if ($diasRestantes < 7) { $colorStatus = 'red-text'; $labelStatus = 'CRÍTICO'; }
                                    elseif ($diasRestantes < 15) { $colorStatus = 'orange-text'; $labelStatus = 'Reabastecer pronto'; }
                                }
                            ?>
                                <tr>
                                    <td><strong><?php echo esc($p['nombre']); ?></strong></td>
                                    <td><?php echo $p['cantidad_actual']; ?></td>
                                    <td><?php echo $p['ventas_30d']; ?></td>
                                    <td><?php echo number_format($promedio, 2); ?></td>
                                    <td class="center-align <?php echo $colorStatus; ?>" style="font-weight: bold; font-size: 1.2rem;">
                                        <?php echo $diasRestantes; ?>
                                    </td>
                                    <td class="<?php echo $colorStatus; ?> font-weight-bold"><?php echo $labelStatus; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Scripts para Gráficos -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Gráfico de Ventas Mensuales
    const ctxVentas = document.getElementById('chartVentas').getContext('2d');
    new Chart(ctxVentas, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($labelsMeses); ?>,
            datasets: [{
                label: 'Ventas ($)',
                data: <?php echo json_encode($dataVentasMes); ?>,
                borderColor: '#1a237e',
                backgroundColor: 'rgba(26, 35, 126, 0.1)',
                tension: 0.4,
                fill: true,
                borderWidth: 3
            }]
        },
        options: {
            responsive: true,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { callback: value => '$' + value } } }
        }
    });

    // Gráfico de Top Productos
    const ctxTop = document.getElementById('chartTop').getContext('2d');
    new Chart(ctxTop, {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode(array_column($topProductos, 'nombre')); ?>,
            datasets: [{
                data: <?php echo json_encode(array_column($topProductos, 'cantidad')); ?>,
                backgroundColor: [
                    '#1a237e', '#283593', '#303f9f', '#3949ab', '#3f51b5',
                    '#5c6bc0', '#7986cb', '#9fa8da', '#c5cae9', '#e8eaf6'
                ]
            }]
        },
        options: {
            responsive: true,
            plugins: { 
                legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } } 
            }
        }
    });
</script>

<style>
    .font-weight-bold { font-weight: bold; }
    canvas { width: 100% !important; }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
