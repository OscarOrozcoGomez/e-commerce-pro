<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/alex_insights_utils.php';

requireAuth();
// Permiso 'ver_insights_ia' abre esta vista; el rol se mantiene como respaldo.
if (!hasPermission('ver_insights_ia') && !isAdmin() && !isEncargado()) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$pageTitle = 'Alex Insights (WhatsApp)';
$pdo = getPDO();

$categoriaEtiquetas = [
    'descuento' => 'Pidieron descuento / precio',
    'friccion_checkout' => 'Friccion al comprar (direccion, pago)',
    'seguridad' => 'Intentos de acceso indebido',
    'sistema' => 'Mensajes de sistema / intervencion manual',
    'otro' => 'Otro',
];

$senalesNoCancelacion = ['descuento' => [], 'friccion_checkout' => [], 'seguridad' => [], 'sistema' => [], 'otro' => []];
$topBusquedas = [];
$embudoVentas = ['intentos' => 0, 'exitos' => [], 'fallos' => []];
$distribucionEtiquetas = [];

try {
    $senalesNoCancelacion = alexInsightsGetNonCancellationSignals($pdo);
    $topBusquedas = alexInsightsGetTopInventoryQueries($pdo);
    $embudoVentas = alexInsightsGetSalesFunnel($pdo);
    $distribucionEtiquetas = alexInsightsGetTagDistribution($pdo);
} catch (PDOException $e) {
    error_log('Error en alex_insights: ' . $e->getMessage());
}

$maxBusqueda = $topBusquedas === [] ? 0 : max($topBusquedas);
$maxEtiqueta = 0;
foreach ($distribucionEtiquetas as $et) {
    $maxEtiqueta = max($maxEtiqueta, (int)$et['total']);
}

$exitosVentas = $embudoVentas['exitos'];
$exitosSostenidos = 0;
$exitosCancelados = 0;
foreach ($exitosVentas as $ev) {
    if ((string)$ev['estado'] === 'cancelado') {
        $exitosCancelados++;
    } else {
        $exitosSostenidos++;
    }
}
$totalFallos = array_sum($embudoVentas['fallos']);

include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="col s12">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-top:20px;">
                <h4 style="margin:0;"><i class="material-icons left">smart_toy</i> Alex Insights (WhatsApp)</h4>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <a href="<?php echo BASE_URL; ?>views/cancelaciones_pedidos.php" class="btn-flat waves-effect">Ver Cancelaciones</a>
                    <a href="<?php echo BASE_URL; ?>views/dashboard.php" class="btn blue darken-4 waves-effect waves-light"><i class="material-icons left">dashboard</i> Volver al Dashboard</a>
                </div>
            </div>
            <p class="grey-text" style="margin-top: 4px;">
                Datos que Alex ya genera al atender WhatsApp (sin gastar tokens extra): que piden los
                clientes cuando lo transfieren a un humano, que buscan en el catalogo, cuantas ventas
                cierra solo, y como etiqueta a los clientes por su cuenta.
            </p>
        </div>
    </div>

    <div class="row">
        <div class="col s12 m6">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Embudo de Ventas Asistidas por Alex</span>
                    <p style="margin: 10px 0 4px;">Intentos de agendar venta: <strong><?php echo (int)$embudoVentas['intentos']; ?></strong></p>
                    <p style="margin: 4px 0;">Ventas creadas: <strong class="green-text text-darken-2"><?php echo count($exitosVentas); ?></strong></p>
                    <p style="margin: 4px 0 4px 20px; font-size: 0.85rem;">
                        &bull; Siguen activas/entregadas: <strong><?php echo $exitosSostenidos; ?></strong><br>
                        &bull; Se cancelaron despues: <strong class="red-text"><?php echo $exitosCancelados; ?></strong>
                    </p>
                    <p style="margin: 4px 0;">Intentos fallidos: <strong class="orange-text text-darken-2"><?php echo $totalFallos; ?></strong></p>
                    <?php if (!empty($embudoVentas['fallos'])): ?>
                        <ul class="collection" style="margin-top: 8px;">
                            <?php foreach ($embudoVentas['fallos'] as $mensaje => $veces): ?>
                                <li class="collection-item" style="font-size: 0.85rem;">
                                    <?php echo esc((string)$mensaje); ?>
                                    <span class="badge">x<?php echo (int)$veces; ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col s12 m6">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Etiquetas que Alex asigna solo</span>
                    <?php if (empty($distribucionEtiquetas)): ?>
                        <p class="grey-text" style="margin-top: 10px;">Aun no hay etiquetas asignadas por Alex.</p>
                    <?php else: ?>
                        <div style="margin-top: 14px;">
                            <?php foreach ($distribucionEtiquetas as $et):
                                $pct = $maxEtiqueta > 0 ? round(((int)$et['total'] / $maxEtiqueta) * 100) : 0;
                            ?>
                                <div style="margin-bottom: 12px;">
                                    <div style="display:flex; justify-content:space-between; font-size: 0.9rem;">
                                        <span class="chip <?php echo esc((string)$et['color']); ?>" style="margin: 0;"><?php echo esc((string)$et['nombre']); ?></span>
                                        <strong><?php echo (int)$et['total']; ?></strong>
                                    </div>
                                    <div style="background:#eee; border-radius: 4px; height: 10px; overflow: hidden;">
                                        <div style="background:#546e7a; width: <?php echo $pct; ?>%; height: 100%;"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col s12">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Top busquedas de clientes via Alex</span>
                    <p class="grey-text" style="margin-top: 0;">
                        Que le piden buscar en el catalogo -- util para planear stock, incluso de lo que no se vendio.
                    </p>
                    <?php if (empty($topBusquedas)): ?>
                        <p class="center-align">Aun no hay busquedas registradas.</p>
                    <?php else: ?>
                        <div style="margin-top: 14px;">
                            <?php foreach ($topBusquedas as $termino => $veces):
                                $pct = $maxBusqueda > 0 ? round(($veces / $maxBusqueda) * 100) : 0;
                            ?>
                                <div style="margin-bottom: 12px;">
                                    <div style="display:flex; justify-content:space-between; font-size: 0.9rem;">
                                        <span><?php echo esc((string)$termino); ?></span>
                                        <strong><?php echo (int)$veces; ?></strong>
                                    </div>
                                    <div style="background:#eee; border-radius: 4px; height: 10px; overflow: hidden;">
                                        <div style="background:#1565c0; width: <?php echo $pct; ?>%; height: 100%;"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col s12">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Señales para estrategia de ventas</span>
                    <p class="grey-text" style="margin-top: 0;">
                        Motivos por los que Alex transfirio la conversacion a un humano (sin contar las
                        cancelaciones, que estan en el reporte de Cancelaciones). Clasificado por palabras
                        clave -- lectura rapida, no un dato exacto.
                    </p>

                    <?php foreach ($categoriaEtiquetas as $categoria => $etiqueta): ?>
                        <?php if (!empty($senalesNoCancelacion[$categoria])): ?>
                            <h6><?php echo esc($etiqueta); ?> (<?php echo count($senalesNoCancelacion[$categoria]); ?>)</h6>
                            <ul class="collection">
                                <?php foreach ($senalesNoCancelacion[$categoria] as $s): ?>
                                    <li class="collection-item" style="font-size: 0.9rem;">
                                        <?php echo esc($s['motivo']); ?>
                                        <span class="grey-text" style="font-size: 0.8rem;"> (<?php echo esc($s['contacto']); ?>)</span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    <?php endforeach; ?>

                    <?php if (array_sum(array_map('count', $senalesNoCancelacion)) === 0): ?>
                        <p class="center-align">No hay senales registradas por ahora.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
