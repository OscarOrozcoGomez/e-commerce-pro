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

// Obtener reporte de ventas según el rol
try {
    if (isAdmin()) {
        // Admin: todas las ventas
        $sql = "SELECT p.numero_pedido, p.total, p.fecha_creacion, u.nombre as vendedor, a.nombre as almacen
                FROM pedidos p
                JOIN usuarios u ON p.id_usuario = u.id_usuario
                JOIN almacenes a ON p.id_almacen = a.id_almacen
                WHERE DATE(p.fecha_creacion) BETWEEN :inicio AND :fin
                AND p.estado != 'cancelado'
                ORDER BY p.fecha_creacion DESC
                LIMIT 100";
    } else {
        // Encargado/Vendedor: sus ventas
        $sql = "SELECT p.numero_pedido, p.total, p.fecha_creacion, u.nombre as vendedor, a.nombre as almacen
                FROM pedidos p
                JOIN usuarios u ON p.id_usuario = u.id_usuario
                JOIN almacenes a ON p.id_almacen = a.id_almacen
                WHERE p.id_usuario = :usuario
                AND DATE(p.fecha_creacion) BETWEEN :inicio AND :fin
                AND p.estado != 'cancelado'
                ORDER BY p.fecha_creacion DESC
                LIMIT 100";
    }

    $stmt = $pdo->prepare($sql);
    
    if (isAdmin()) {
        $stmt->execute([':inicio' => $fecha_inicio, ':fin' => $fecha_fin]);
    } else {
        $stmt->execute([
            ':usuario' => $usuario['id_usuario'],
            ':inicio' => $fecha_inicio,
            ':fin' => $fecha_fin,
        ]);
    }
    
    $ventas = $stmt->fetchAll();

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
            <h4>Reportes del Sistema</h4>
        </div>
    </div>

    <!-- Filtros -->
    <div class="row">
        <div class="col s12">
            <div class="card">
                <div class="card-content">
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
                                        <th>Monto</th>
                                        <th>Fecha</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($ventas as $venta): ?>
                                        <tr>
                                            <td><?php echo esc($venta['numero_pedido']); ?></td>
                                            <td><?php echo esc($venta['vendedor']); ?></td>
                                            <td><?php echo esc($venta['almacen']); ?></td>
                                            <td>$<?php echo number_format($venta['total'], 2); ?></td>
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
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
