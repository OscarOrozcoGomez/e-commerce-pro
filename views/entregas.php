<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
requirePermission('ver_entregas', BASE_URL . 'views/dashboard.php');

$pageTitle = 'Mis Entregas';
$pdo = getPDO();
$usuario = $_SESSION['usuario'];
$error = '';
$success = '';

// Procesar cambio de estado de reparto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'], $_POST['id_pedido'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF inválido.';
    } else {
        $id_pedido = intval($_POST['id_pedido']);
        if ($_POST['accion'] === 'en_camino') {
            try {
                $stmt = $pdo->prepare("UPDATE pedidos SET estado = 'en_reparto' WHERE id_pedido = ? AND id_repartidor = ? AND estado IN ('pendiente_pago','pagado')");
                $stmt->execute([$id_pedido, $usuario['id_usuario']]);
                if ($stmt->rowCount() > 0) {
                    logAudit('PEDIDO_EN_CAMINO', 'pedidos', $id_pedido, 'Pedido marcado en camino por repartidor');
                    $success = 'Pedido marcado como en camino.';
                }
            } catch (PDOException $e) {
                $error = 'Error al actualizar el pedido.';
            }
        }

        if ($_POST['accion'] === 'entregar') {
            try {
                // Confirma entrega y cobro simultáneamente (pago contra entrega)
                $stmt = $pdo->prepare("UPDATE pedidos SET estado = 'entregado', fecha_entrega = NOW(), fecha_pago = NOW() WHERE id_pedido = ? AND id_repartidor = ? AND estado IN ('pendiente_pago','pagado','en_reparto')");
                $stmt->execute([$id_pedido, $usuario['id_usuario']]);
                if ($stmt->rowCount() > 0) {
                    logAudit('PEDIDO_ENTREGADO', 'pedidos', $id_pedido, 'Pedido marcado como entregado por repartidor');
                    $success = 'Pedido entregado correctamente.';
                }
            } catch (PDOException $e) {
                $error = 'Error al actualizar el pedido.';
            }
        }
    }
}

// Obtener entregas pendientes asignadas a este repartidor
try {
    $sql = "SELECT p.*, c.nombre as cliente, c.direccion, c.telefono, c.ubicacion_mapa
            FROM pedidos p
            LEFT JOIN clientes c ON p.id_cliente = c.id_cliente
            WHERE p.id_repartidor = :repartidor AND p.estado IN ('pendiente_pago','pagado','en_reparto')
            ORDER BY p.fecha_entrega_programada ASC, p.fecha_creacion DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':repartidor' => $usuario['id_usuario']]);
    $entregas = $stmt->fetchAll();
} catch (PDOException $e) {
    $error = 'Error al obtener entregas: ' . $e->getMessage();
    $entregas = [];
}

include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="col s12">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 20px; flex-wrap: wrap; gap: 10px;">
                <h4 style="margin: 0;">Mis Entregas Asignadas</h4>
                <a href="dashboard.php" class="btn blue darken-4 waves-effect waves-light"><i class="material-icons left">dashboard</i> Volver al Dashboard</a>
            </div>
            <p class="grey-text">Aquí aparecen los pedidos que debes entregar hoy.</p>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="card green lighten-4 green-text text-darken-4" style="padding: 10px;">
            <i class="material-icons left">check_circle</i> <?php echo esc($success); ?>
        </div>
    <?php endif; ?>

    <div class="row">
        <?php if (empty($entregas)): ?>
            <div class="col s12 center-align" style="padding: 50px;">
                <i class="material-icons grey-text" style="font-size: 5rem;">local_shipping</i>
                <p class="grey-text">No tienes entregas pendientes por ahora.</p>
            </div>
        <?php else: ?>
            <?php foreach ($entregas as $ent): ?>
                <div class="col s12 m6">
                    <div class="card hoverable border-delivery">
                        <div class="card-content">
                            <span class="card-title indigo-text"><strong><?php echo esc($ent['numero_pedido']); ?></strong></span>
                            <div class="divider"></div>
                            
                            <div class="section-info">
                                <p><i class="material-icons tiny indigo-text">person</i> <strong>Cliente:</strong> <?php echo esc($ent['cliente'] ?? 'N/A'); ?></p>
                                <p>
                                    <i class="material-icons tiny indigo-text">phone</i> <strong>Teléfono:</strong> <?php echo esc($ent['telefono'] ?? 'N/A'); ?>
                                    <a href="https://wa.me/52<?php echo preg_replace('/\D/', '', $ent['telefono']); ?>" target="_blank" class="green-text" style="margin-left: 10px;">
                                        (WhatsApp <i class="material-icons tiny">chat</i>)
                                    </a>
                                </p>
                                <p><i class="material-icons tiny indigo-text">place</i> <strong>Dirección:</strong> <?php echo esc($ent['direccion'] ?? 'No especificada'); ?></p>
                                
                                <?php if ($ent['ubicacion_mapa']): ?>
                                    <div style="margin-top: 10px;">
                                        <a href="<?php echo $ent['ubicacion_mapa']; ?>" target="_blank" class="btn-small waves-effect waves-light blue">
                                            <i class="material-icons left">map</i> Ver en Google Maps
                                        </a>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="section-products grey lighten-4" style="padding: 10px; margin-top: 15px; border-radius: 4px;">
                                <h6><strong>Productos a llevar:</strong></h6>
                                <ul style="margin: 0; padding-left: 20px;">
                                    <?php
                                    // Obtener detalles del pedido
                                    $stmtD = $pdo->prepare("SELECT dp.cantidad, p.nombre, p.nombre_variante 
                                                           FROM detalle_pedidos dp 
                                                           JOIN productos p ON dp.id_producto = p.id_producto 
                                                           WHERE dp.id_pedido = ?");
                                    $stmtD->execute([$ent['id_pedido']]);
                                    while($d = $stmtD->fetch()):
                                        $pName = $d['nombre'] . ($d['nombre_variante'] ? " - " . $d['nombre_variante'] : "");
                                    ?>
                                        <li><?php echo $d['cantidad']; ?>x <?php echo esc($pName); ?></li>
                                    <?php endwhile; ?>
                                </ul>
                            </div>
                            
                            <?php if ($ent['fecha_entrega_programada']): ?>
                                <p class="orange-text" style="margin-top: 10px;">
                                    <i class="material-icons tiny">event</i> Programado para: <?php echo date('d/m/Y H:i', strtotime($ent['fecha_entrega_programada'])); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                        <div class="card-action center-align">
                            <form method="POST">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="id_pedido" value="<?php echo $ent['id_pedido']; ?>">
                                <?php if (in_array($ent['estado'] ?? '', ['pendiente_pago', 'pagado'])): ?>
                                    <input type="hidden" name="accion" value="en_camino">
                                    <?php if (($ent['estado'] ?? '') === 'pendiente_pago'): ?>
                                        <p class="orange-text" style="font-size:0.85rem; margin-bottom:8px;">
                                            <i class="material-icons tiny">attach_money</i> Cobrar al entregar: <strong>$<?php echo number_format((float)$ent['total'], 2); ?></strong>
                                        </p>
                                    <?php endif; ?>
                                    <button type="submit" class="btn orange darken-3 waves-effect waves-light w-100" onclick="return confirm('¿Salir a entregar este pedido?')">
                                        SALIR A ENTREGAR <i class="material-icons right">local_shipping</i>
                                    </button>
                                <?php else: ?>
                                    <input type="hidden" name="accion" value="entregar">
                                    <p class="orange-text" style="font-size:0.85rem; margin-bottom:8px;">
                                        <i class="material-icons tiny">attach_money</i> Cobrar al entregar: <strong>$<?php echo number_format((float)$ent['total'], 2); ?></strong>
                                    </p>
                                    <button type="submit" class="btn green waves-effect waves-light w-100" onclick="return confirm('¿Confirmar entrega y cobro del pedido?')">
                                        ENTREGADO Y COBRADO <i class="material-icons right">done_all</i>
                                    </button>
                                <?php endif; ?>
                            </form>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<style>
    .border-delivery {
        border-top: 5px solid #3f51b5;
    }
    .section-info p {
        margin: 8px 0;
        display: flex;
        align-items: center;
    }
    .section-info .material-icons {
        margin-right: 8px;
    }
    .w-100 { width: 100%; }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
