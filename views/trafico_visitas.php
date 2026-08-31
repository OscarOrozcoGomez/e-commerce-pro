<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/ventas_features.php';

requireAuth();
// Permiso 'ver_trafico_campanas' abre esta vista; el admin entra siempre (short-circuit).
if (!hasPermission('ver_trafico_campanas') && !isAdmin()) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$pageTitle = 'Tráfico y Campañas';
$pdo = getPDO();

$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-d', strtotime('-30 days'));
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');

$whereClause = " WHERE tipo_accion = 'visit' AND DATE(fecha_creacion) >= :inicio AND DATE(fecha_creacion) <= :fin";
$params = [':inicio' => $fecha_inicio, ':fin' => $fecha_fin];

$totalStmt = $pdo->prepare("SELECT COUNT(*) AS total, COUNT(DISTINCT visitor_id) AS unicos FROM logs_actividad" . $whereClause);
$totalStmt->execute($params);
$totales = $totalStmt->fetch();

$porPlataformaStmt = $pdo->prepare(
    "SELECT COALESCE(NULLIF(plataforma, ''), 'sin_clasificar') AS plataforma,
            COUNT(*) AS total,
            COUNT(DISTINCT visitor_id) AS unicos
     FROM logs_actividad" . $whereClause . "
     GROUP BY plataforma
     ORDER BY total DESC"
);
$porPlataformaStmt->execute($params);
$porPlataforma = $porPlataformaStmt->fetchAll();

$porCampanaStmt = $pdo->prepare(
    "SELECT utm_source, utm_campaign,
            COUNT(*) AS total,
            COUNT(DISTINCT visitor_id) AS unicos
     FROM logs_actividad" . $whereClause . "
       AND utm_campaign IS NOT NULL AND utm_campaign != ''
     GROUP BY utm_source, utm_campaign
     ORDER BY total DESC
     LIMIT 20"
);
$porCampanaStmt->execute($params);
$porCampana = $porCampanaStmt->fetchAll();

$atribucionVentasActiva = ventasFeatureIsActive($pdo, 'atribucion_ventas');
$ventasPorPlataforma = [];
if ($atribucionVentasActiva) {
    $ventasWhere = " WHERE estado != 'cancelado' AND DATE(fecha_creacion) >= :inicio AND DATE(fecha_creacion) <= :fin";
    $ventasStmt = $pdo->prepare(
        "SELECT COALESCE(NULLIF(plataforma, ''), 'sin_atribucion') AS plataforma,
                COUNT(*) AS pedidos,
                SUM(total) AS monto_total
         FROM pedidos" . $ventasWhere . "
         GROUP BY plataforma
         ORDER BY monto_total DESC"
    );
    $ventasStmt->execute($params);
    $ventasPorPlataforma = $ventasStmt->fetchAll();
}

$platformLabels = [
    'google_ads' => 'Google Ads',
    'facebook_ads' => 'Facebook Ads',
    'organic' => 'Orgánico (buscadores)',
    'referral' => 'Referido',
    'direct' => 'Directo',
    'sin_clasificar' => 'Sin clasificar',
    'sin_atribucion' => 'Sin atribución (pedido sin visita previa registrada)',
];

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid" style="padding: 20px;">
    <div class="row">
        <div class="col s12">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; flex-wrap: wrap; gap: 10px;">
                <h4 style="margin: 0;"><i class="material-icons left" style="font-size: 2.5rem;">public</i> Tráfico y Campañas</h4>
                <a href="dashboard.php" class="btn blue darken-4 waves-effect waves-light"><i class="material-icons left">dashboard</i> Volver al Dashboard</a>
            </div>
            <p class="grey-text">De dónde vienen tus visitas: plataforma de origen y campaña.</p>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card">
        <div class="card-content">
            <form method="GET" class="row" style="margin-bottom: 0;">
                <div class="input-field col s6 m3">
                    <input type="date" name="fecha_inicio" id="fecha_inicio" value="<?php echo esc($fecha_inicio); ?>">
                    <label for="fecha_inicio" class="active">Desde</label>
                </div>
                <div class="input-field col s6 m3">
                    <input type="date" name="fecha_fin" id="fecha_fin" value="<?php echo esc($fecha_fin); ?>">
                    <label for="fecha_fin" class="active">Hasta</label>
                </div>
                <div class="col s12 m2" style="padding-top: 15px;">
                    <button type="submit" class="btn indigo waves-effect waves-light">Filtrar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Totales -->
    <div class="row">
        <div class="col s12 m6">
            <div class="card indigo darken-3">
                <div class="card-content white-text">
                    <span class="card-title">Total de Visitas</span>
                    <p class="display-metric"><?php echo number_format((int) ($totales['total'] ?? 0)); ?></p>
                </div>
            </div>
        </div>
        <div class="col s12 m6">
            <div class="card blue-grey darken-2">
                <div class="card-content white-text">
                    <span class="card-title">Visitantes Únicos</span>
                    <p class="display-metric"><?php echo number_format((int) ($totales['unicos'] ?? 0)); ?></p>
                </div>
            </div>
        </div>
    </div>

    <!-- Por plataforma -->
    <div class="card">
        <div class="card-content">
            <span class="card-title">Por Plataforma</span>
            <?php if (empty($porPlataforma)): ?>
                <p class="grey-text">No hay visitas registradas en el rango seleccionado.</p>
            <?php else: ?>
                <table class="striped">
                    <thead>
                        <tr>
                            <th>Plataforma</th>
                            <th>Visitas</th>
                            <th>Visitantes únicos</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($porPlataforma as $row): ?>
                            <tr>
                                <td><?php echo esc($platformLabels[$row['plataforma']] ?? $row['plataforma']); ?></td>
                                <td><?php echo number_format((int) $row['total']); ?></td>
                                <td><?php echo number_format((int) $row['unicos']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Ventas por plataforma (atribucion) -->
    <?php if ($atribucionVentasActiva): ?>
        <div class="card">
            <div class="card-content">
                <span class="card-title">Ventas por Plataforma</span>
                <p class="grey-text" style="margin-top: -8px;">Pedidos reales (no cancelados) atribuidos a la última visita conocida de cada cliente antes de comprar.</p>
                <?php if (empty($ventasPorPlataforma)): ?>
                    <p class="grey-text">No hay pedidos registrados en el rango seleccionado.</p>
                <?php else: ?>
                    <table class="striped">
                        <thead>
                            <tr>
                                <th>Plataforma</th>
                                <th>Pedidos</th>
                                <th>Monto total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ventasPorPlataforma as $row): ?>
                                <tr>
                                    <td><?php echo esc($platformLabels[$row['plataforma']] ?? $row['plataforma']); ?></td>
                                    <td><?php echo number_format((int) $row['pedidos']); ?></td>
                                    <td>$<?php echo number_format((float) $row['monto_total'], 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    <?php else: ?>
        <div class="card-panel amber lighten-4">
            La sección "Ventas por Plataforma" está apagada. Actívala en <a href="ventas_features_config.php">Nuevas Iniciativas de Ventas</a>.
        </div>
    <?php endif; ?>

    <!-- Por campaña -->
    <div class="card">
        <div class="card-content">
            <span class="card-title">Top Campañas (utm_campaign)</span>
            <?php if (empty($porCampana)): ?>
                <p class="grey-text">No hay campañas con utm_campaign registradas en el rango seleccionado.</p>
            <?php else: ?>
                <table class="striped">
                    <thead>
                        <tr>
                            <th>Origen</th>
                            <th>Campaña</th>
                            <th>Visitas</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($porCampana as $row): ?>
                            <tr>
                                <td><small><?php echo esc($row['utm_source'] ?? ''); ?></small></td>
                                <td><small><?php echo esc($row['utm_campaign']); ?></small></td>
                                <td><?php echo number_format((int) $row['total']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<style>
    .display-metric { font-size: 2.5rem; font-weight: bold; margin: 0; }
    input[type="date"] { margin-bottom: 0 !important; }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
