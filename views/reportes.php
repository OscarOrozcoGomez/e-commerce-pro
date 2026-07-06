<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
requirePermission('ver_reportes', BASE_URL . 'views/dashboard.php');

$pageTitle = 'Reportes del Sistema';
$pdo = getPDO();
$usuario = $_SESSION['usuario'];

// Determinar rango de fechas
$fecha_inicio = isset($_GET['fecha_inicio']) ? htmlspecialchars($_GET['fecha_inicio']) : date('Y-m-01');
$fecha_fin = isset($_GET['fecha_fin']) ? htmlspecialchars($_GET['fecha_fin']) : date('Y-m-d');

$ventas = dbGetSalesReport(
    $fecha_inicio, 
    $fecha_fin, 
    (int)($usuario['id_almacen'] ?? 0), 
    (int)$usuario['id_usuario'], 
    isAdmin()
);

try {
    // Calcular totales
    $total_ventas = 0;
    $cantidad_ventas = 0;
    foreach ($ventas as $venta) {
        $total_ventas += $venta['total'];
        $cantidad_ventas++;
    }
    $promedio_venta = $cantidad_ventas > 0 ? $total_ventas / $cantidad_ventas : 0;

} catch (PDOException $e) {
    $ventas = [];
}

include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="col s12">
            <h4 style="display: inline-block;">Reportes del Sistema</h4>
            <div class="right-align no-print" style="display: inline-block; float: right; margin-top: 20px;">
                <a href="<?php echo BASE_URL; ?>views/export_reports.php?fecha_inicio=<?php echo urlencode($fecha_inicio); ?>&fecha_fin=<?php echo urlencode($fecha_fin); ?>" 
                   class="btn green waves-effect waves-light">
                    Excel <i class="material-icons right">description</i>
                </a>
                     <a href="<?php echo BASE_URL; ?>views/export_reports_pdf.php?fecha_inicio=<?php echo urlencode($fecha_inicio); ?>&fecha_fin=<?php echo urlencode($fecha_fin); ?>"
                         target="_blank" rel="noopener noreferrer"
                   class="btn red waves-effect waves-light">
                    PDF <i class="material-icons right">picture_as_pdf</i>
                </a>
            </div>
        </div>
    </div>

    <!-- Filtros -->
    <div class="row">
        <div class="col s12">
            <div class="card">
                <div class="card-content no-print">
                    <form method="GET" class="row">
                        <div class="input-field col s6 m3">
                            <input type="date" id="fecha_inicio" name="fecha_inicio" value="<?php echo esc($fecha_inicio); ?>">
                            <label for="fecha_inicio">Fecha Inicio</label>
                        </div>
                        <div class="input-field col s6 m3">
                            <input type="date" id="fecha_fin" name="fecha_fin" value="<?php echo esc($fecha_fin); ?>">
                            <label for="fecha_fin">Fecha Fin</label>
                        </div>
                        <div class="col s12 m6" style="padding-top: 20px;">
                            <button type="submit" class="btn waves-effect waves-light blue">
                                Filtrar <i class="material-icons right">search</i>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Resumen -->
    <div class="row">
        <div class="col s12 m6 l4">
            <div class="card teal lighten-2">
                <div class="card-content white-text">
                    <span class="card-title">Total Ventas</span>
                    <p class="display-metric"><?php echo $cantidad_ventas; ?></p>
                </div>
            </div>
        </div>
        <div class="col s12 m6 l4">
            <div class="card blue lighten-2">
                <div class="card-content white-text">
                    <span class="card-title">Monto Total</span>
                    <p class="display-metric">$<?php echo number_format($total_ventas, 2); ?></p>
                </div>
            </div>
        </div>
        <div class="col s12 m6 l4">
            <div class="card purple lighten-2">
                <div class="card-content white-text">
                    <span class="card-title">Promedio Venta</span>
                    <p class="display-metric">$<?php echo number_format($promedio_venta, 2); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabla de Ventas -->
    <div class="row">
        <div class="col s12">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Detalle de Ventas</span>
                    
                    <?php if (empty($ventas)): ?>
                        <p class="center-align">No hay ventas en el período especificado.</p>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table class="striped">
                                <thead>
                                    <tr>
                                        <th>Número Pedido</th>
                                        <th>Vendedor</th>
                                        <th>Almacén</th>
                                        <th>Método</th>
                                        <th>Productos vendidos</th>
                                        <th>Monto</th>
                                        <th>Fecha</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($ventas as $venta): ?>
                                        <?php
                                            $productosRaw = (string)($venta['productos_vendidos'] ?? '');
                                            $productosLista = array_values(array_filter(array_map('trim', explode('|', $productosRaw))));
                                        ?>
                                        <tr>
                                            <td><?php echo esc($venta['numero_pedido']); ?></td>
                                            <td><?php echo esc($venta['vendedor']); ?></td>
                                            <td><?php echo esc($venta['almacen']); ?></td>
                                            <td><?php echo esc($venta['metodo'] ?? 'N/A'); ?></td>
                                            <td style="max-width: 360px; white-space: normal; line-height: 1.4;">
                                                <?php if (empty($productosLista)): ?>
                                                    Sin detalle
                                                <?php else: ?>
                                                    <ul style="margin: 0; padding-left: 18px;">
                                                        <?php foreach ($productosLista as $productoItem): ?>
                                                            <li><?php echo esc($productoItem); ?></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php endif; ?>
                                            </td>
                                            <td>$<?php echo number_format((float)$venta['total'], 2); ?></td>
                                            <td><?php echo date('d/m/Y H:i', strtotime($venta['fecha_creacion'])); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .display-metric {
        font-size: 2rem;
        font-weight: bold;
        margin: 10px 0;
    }
    @media print {
        .no-print, .nav-wrapper, nav, .input-field, form, .btn, footer {
            display: none !important;
        }
        .container { width: 100% !important; max-width: none !important; margin: 0 !important; }
        .card { box-shadow: none !important; border: 1px solid #ddd; }
        .card-title { color: black !important; font-weight: bold; }
        h4 { margin-top: 0; }
        body { background: white !important; }
        table { border: 1px solid #ddd; }
    }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
