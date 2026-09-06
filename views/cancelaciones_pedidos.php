<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/alex_insights_utils.php';

requireAuth();
// Permiso 'gestionar_cancelaciones' abre esta vista; el rol se mantiene como respaldo.
if (!hasPermission('gestionar_cancelaciones') && !isAdmin() && !isEncargado()) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$pageTitle = 'Cancelaciones de Pedidos';
$pdo = getPDO();

$resumen = [];
$cancelaciones = [];
$totalCancelaciones = 0;
$cancelacionesRepartidor = [];
$senalesAlex = ['confirmadas' => [], 'resueltas_no_canceladas' => [], 'sin_confirmar' => []];

try {
    $stmtResumen = $pdo->query(
        "SELECT COALESCE(m.motivo, 'Sin motivo especificado') AS motivo, COUNT(*) AS total
         FROM pedido_cancelaciones pc
         LEFT JOIN motivos_cancelacion_pedido m ON m.id_motivo = pc.id_motivo
         GROUP BY COALESCE(m.motivo, 'Sin motivo especificado')
         ORDER BY total DESC"
    );
    $resumen = $stmtResumen->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $totalCancelaciones = array_sum(array_map(static fn(array $r) => (int)$r['total'], $resumen));

    $stmtDetalle = $pdo->query(
        "SELECT pc.motivo_detalle, pc.fecha_cancelacion,
                p.id_pedido, p.numero_pedido, p.total,
                cl.nombre AS cliente_nombre_raw,
                COALESCE(m.motivo, 'Sin motivo especificado') AS motivo
         FROM pedido_cancelaciones pc
         JOIN pedidos p ON p.id_pedido = pc.id_pedido
         LEFT JOIN clientes cl ON cl.id_cliente = p.id_cliente
         LEFT JOIN motivos_cancelacion_pedido m ON m.id_motivo = pc.id_motivo
         ORDER BY pc.fecha_cancelacion DESC
         LIMIT 300"
    );
    $cancelaciones = $stmtDetalle->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($cancelaciones as &$fila) {
        $fila['cliente_nombre'] = alexInsightsDecryptClienteNombre($fila['cliente_nombre_raw'] ?? null);
        unset($fila['cliente_nombre_raw']);
    }
    unset($fila);

    $cancelacionesRepartidor = alexInsightsGetRepartidorCancellations($pdo);
    $senalesAlex = alexInsightsGetAlexCancellationSignals($pdo);
} catch (PDOException $e) {
    error_log('Error en cancelaciones_pedidos: ' . $e->getMessage());
}

$maxMotivoTotal = 0;
foreach ($resumen as $r) {
    $maxMotivoTotal = max($maxMotivoTotal, (int)$r['total']);
}

include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="col s12">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-top:20px;">
                <h4 style="margin:0;"><i class="material-icons left">cancel</i> Cancelaciones de Pedidos</h4>
                <a href="<?php echo BASE_URL; ?>views/dashboard.php" class="btn blue darken-4 waves-effect waves-light"><i class="material-icons left">dashboard</i> Volver al Dashboard</a>
            </div>
            <p class="grey-text" style="margin-top: 4px;">
                Motivos que los clientes seleccionan al cancelar sus propios pedidos, para entender por que suceden y reducirlas.
            </p>
        </div>
    </div>

    <div class="row">
        <div class="col s12 m4">
            <div class="card red darken-1">
                <div class="card-content white-text">
                    <span class="card-title">Total de Cancelaciones</span>
                    <p style="font-size: 2.4rem; margin: 0; font-weight: bold;"><?php echo (int)$totalCancelaciones; ?></p>
                </div>
            </div>
        </div>
        <div class="col s12 m8">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Motivos mas frecuentes</span>
                    <?php if (empty($resumen)): ?>
                        <p class="grey-text" style="margin-top: 10px;">Aun no hay cancelaciones registradas.</p>
                    <?php else: ?>
                        <div style="margin-top: 14px;">
                            <?php foreach ($resumen as $r):
                                $pct = $maxMotivoTotal > 0 ? round(((int)$r['total'] / $maxMotivoTotal) * 100) : 0;
                            ?>
                                <div style="margin-bottom: 12px;">
                                    <div style="display:flex; justify-content:space-between; font-size: 0.9rem;">
                                        <span><?php echo esc((string)$r['motivo']); ?></span>
                                        <strong><?php echo (int)$r['total']; ?></strong>
                                    </div>
                                    <div style="background:#eee; border-radius: 4px; height: 10px; overflow: hidden;">
                                        <div style="background:#c62828; width: <?php echo $pct; ?>%; height: 100%;"></div>
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
                    <span class="card-title">Historial de Cancelaciones</span>
                    <?php if (empty($cancelaciones)): ?>
                        <p class="center-align">No hay cancelaciones registradas.</p>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table class="striped">
                                <thead>
                                    <tr>
                                        <th>Pedido</th>
                                        <th>Cliente</th>
                                        <th>Motivo</th>
                                        <th>Detalle</th>
                                        <th>Total</th>
                                        <th>Fecha de cancelacion</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cancelaciones as $c): ?>
                                        <tr>
                                            <td><a href="detalle_compra.php?id=<?php echo (int)$c['id_pedido']; ?>"><?php echo esc((string)$c['numero_pedido']); ?></a></td>
                                            <td><?php echo esc((string)$c['cliente_nombre']); ?></td>
                                            <td><?php echo esc((string)$c['motivo']); ?></td>
                                            <td style="max-width: 300px; white-space: normal;"><?php echo esc((string)($c['motivo_detalle'] ?? '')); ?></td>
                                            <td>$<?php echo number_format((float)$c['total'], 2); ?></td>
                                            <td><?php echo date('d/m/Y H:i', strtotime((string)$c['fecha_cancelacion'])); ?></td>
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

    <div class="row">
        <div class="col s12">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Cancelaciones por Repartidor (entrega no realizada)</span>
                    <p class="grey-text" style="margin-top: 0;">
                        Pedidos que el repartidor no pudo entregar, con el motivo que registro al marcarlo.
                    </p>
                    <?php if (empty($cancelacionesRepartidor)): ?>
                        <p class="center-align">No hay cancelaciones de repartidor registradas.</p>
                    <?php else: ?>
                        <div style="overflow-x: auto;">
                            <table class="striped">
                                <thead>
                                    <tr>
                                        <th>Pedido</th>
                                        <th>Cliente</th>
                                        <th>Motivo</th>
                                        <th>Total</th>
                                        <th>Fecha</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($cancelacionesRepartidor as $cr): ?>
                                        <tr>
                                            <td><a href="detalle_compra.php?id=<?php echo (int)$cr['id_pedido']; ?>"><?php echo esc((string)$cr['numero_pedido']); ?></a></td>
                                            <td><?php echo esc((string)$cr['cliente_nombre']); ?></td>
                                            <td><?php echo esc((string)$cr['motivo']); ?></td>
                                            <td>$<?php echo number_format((float)$cr['total'], 2); ?></td>
                                            <td><?php echo date('d/m/Y H:i', strtotime((string)$cr['fecha_creacion'])); ?></td>
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

    <div class="row">
        <div class="col s12">
            <div class="card">
                <div class="card-content">
                    <span class="card-title"><i class="material-icons left">smart_toy</i>Señales de Alex (WhatsApp)</span>
                    <p class="grey-text" style="margin-top: 0;">
                        Intenciones de cancelar que Alex detecto en conversaciones de WhatsApp. Esta vista es
                        parcial: se pierde el dato cuando un admin reactiva el bot de una conversacion ya atendida.
                    </p>

                    <?php if (!empty($senalesAlex['confirmadas'])): ?>
                        <h6>Confirmadas (el pedido si quedo cancelado)</h6>
                        <ul class="collection">
                            <?php foreach ($senalesAlex['confirmadas'] as $s): ?>
                                <li class="collection-item">
                                    <a href="detalle_compra.php?id=<?php echo (int)$s['pedido']['id_pedido']; ?>"><?php echo esc((string)$s['pedido']['numero_pedido']); ?></a>
                                    &mdash; <?php echo esc($s['motivo']); ?>
                                    <span class="grey-text" style="font-size: 0.8rem;"> (<?php echo esc($s['contacto']); ?>)</span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if (!empty($senalesAlex['resueltas_no_canceladas'])): ?>
                        <h6>Pidieron cancelar pero el pedido NO quedo cancelado (posible "salvada")</h6>
                        <ul class="collection">
                            <?php foreach ($senalesAlex['resueltas_no_canceladas'] as $s): ?>
                                <li class="collection-item">
                                    <a href="detalle_compra.php?id=<?php echo (int)$s['pedido']['id_pedido']; ?>"><?php echo esc((string)$s['pedido']['numero_pedido']); ?></a>
                                    <span class="chip green lighten-4">Estado actual: <?php echo esc(ucfirst((string)$s['pedido']['estado'])); ?></span>
                                    &mdash; <?php echo esc($s['motivo']); ?>
                                    <span class="grey-text" style="font-size: 0.8rem;"> (<?php echo esc($s['contacto']); ?>)</span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if (!empty($senalesAlex['sin_confirmar'])): ?>
                        <h6>Detectado por Alex (sin pedido identificado)</h6>
                        <ul class="collection">
                            <?php foreach ($senalesAlex['sin_confirmar'] as $s): ?>
                                <li class="collection-item">
                                    <?php echo esc($s['motivo']); ?>
                                    <span class="grey-text" style="font-size: 0.8rem;"> (<?php echo esc($s['contacto']); ?>)</span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>

                    <?php if (empty($senalesAlex['confirmadas']) && empty($senalesAlex['resueltas_no_canceladas']) && empty($senalesAlex['sin_confirmar'])): ?>
                        <p class="center-align">No hay senales de cancelacion detectadas por Alex por ahora.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
