<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();

$pageTitle = 'Mis Compras';
$pdo = getPDO();
$usuario = $_SESSION['usuario'];
$canViewPurchases = isCliente();

// Obtener los pedidos asociados a este usuario a través de la tabla clientes
$compras = [];
if ($canViewPurchases) {
    try {
        $stmtMetaPickup = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pickup_notificaciones'");
        $stmtMetaPickup->execute();
        $hasPickupTable = ((int)$stmtMetaPickup->fetchColumn()) > 0;

        $hasPickupFechaApartada = false;
        if ($hasPickupTable) {
            $stmtMetaPickupApartada = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pickup_notificaciones' AND COLUMN_NAME = 'fecha_apartada'");
            $stmtMetaPickupApartada->execute();
            $hasPickupFechaApartada = ((int)$stmtMetaPickupApartada->fetchColumn()) > 0;
        }

        $pickupSelect = $hasPickupTable
            ? "pn.estado AS pickup_estado,
                   pn.fecha_vista AS pickup_fecha_vista,
                   " . ($hasPickupFechaApartada ? 'pn.fecha_apartada' : 'NULL') . " AS pickup_fecha_apartada,
                   pn.fecha_atendida AS pickup_fecha_atendida,"
            : "NULL AS pickup_estado,
                   NULL AS pickup_fecha_vista,
                   NULL AS pickup_fecha_apartada,
                   NULL AS pickup_fecha_atendida,";

        $pickupJoin = $hasPickupTable ? "LEFT JOIN pickup_notificaciones pn ON pn.id_pedido = p.id_pedido" : "";

        $sql = "SELECT p.*, a.nombre as almacen_nombre,
                   {$pickupSelect}
                       (SELECT COUNT(*) FROM detalle_pedidos dp0 WHERE dp0.id_pedido = p.id_pedido AND dp0.cantidad <= 0) AS items_liberados,
                       (SELECT COUNT(*) FROM detalle_pedidos dp1 WHERE dp1.id_pedido = p.id_pedido AND dp1.cantidad > 0) AS items_activos
                FROM pedidos p 
                JOIN clientes c ON p.id_cliente = c.id_cliente 
                LEFT JOIN almacenes a ON p.id_almacen = a.id_almacen
                {$pickupJoin}
                WHERE c.id_usuario = :id_usuario 
                ORDER BY p.fecha_creacion DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id_usuario' => (int)$usuario['id_usuario']]);
        $compras = $stmt->fetchAll();
    } catch (PDOException $e) {
        $compras = [];
    }
}

function getStatusBadge(string $status): string {
    switch ($status) {
        case 'pendiente_pago': return '<span class="badge grey white-text">Pendiente de Pago</span>';
        case 'pagado': return '<span class="badge blue white-text">Pagado / Confirmado</span>';
        case 'en_reparto': return '<span class="badge orange white-text">En Reparto <i class="material-icons tiny">local_shipping</i></span>';
        case 'entregado': return '<span class="badge green white-text">Entregado</span>';
        case 'apartado': return '<span class="badge purple white-text">Apartado</span>';
        case 'cancelado': return '<span class="badge red white-text">Cancelado</span>';
        default: return '<span class="badge grey">'.$status.'</span>';
    }
}

$motivosCancelacion = $canViewPurchases ? dbGetActiveCancellationReasons() : [];

function esPedidoCancelablePorCliente(array $pedido): bool {
    return in_array((string)($pedido['estado'] ?? ''), PEDIDO_CANCELABLE_ESTADOS, true)
        && (string)($pedido['pickup_estado'] ?? '') !== 'atendida';
}

function getPickupStatusBadge(?string $status): string {
    $normalized = trim((string)$status);
    switch ($normalized) {
        case 'nueva':
            return '<span class="badge deep-orange white-text">Pickup: Nueva</span>';
        case 'vista':
            return '<span class="badge amber darken-2 white-text">Pickup: Vista por sucursal</span>';
        case 'apartada':
            return '<span class="badge blue darken-2 white-text">Pickup: Apartada en sucursal</span>';
        case 'atendida':
            return '<span class="badge green darken-2 white-text">Pickup: Atendida</span>';
        default:
            return '';
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="col s12">
            <h4><i class="material-icons left" style="font-size: 2.5rem; color: #1a237e;">shopping_bag</i> Mis Compras</h4>
            <p class="grey-text">Consulta el estado de tus pedidos y tu historial de compras.</p>
        </div>
    </div>

    <div class="row">
        <?php if (!$canViewPurchases): ?>
            <div class="col s12">
                <div class="card-panel amber lighten-5 amber-text text-darken-4">
                    <i class="material-icons left">info</i>
                    Esta seccion solo esta disponible para cuentas con rol cliente. Si deseas comprar, inicia sesion con una cuenta cliente.
                </div>
            </div>
        <?php elseif (empty($compras)): ?>
            <div class="col s12 center-align" style="padding: 50px;">
                <i class="material-icons grey-text" style="font-size: 5rem;">sentiment_dissatisfied</i>
                <h5>Aún no tienes ninguna compra.</h5>
                <p>Explora nuestro catálogo y realiza tu primer pedido.</p>
                <a href="<?php echo BASE_URL; ?>" class="btn-large indigo darken-4 waves-effect waves-light">VER PRODUCTOS</a>
            </div>
        <?php else: ?>
            <?php foreach ($compras as $c): ?>
                <?php
                    $isLiberadoParcial = ((int)($c['items_liberados'] ?? 0) > 0) && ((int)($c['items_activos'] ?? 0) > 0) && ($c['estado'] !== 'cancelado');
                ?>
                <div class="col s12">
                    <div class="card horizontal hoverable border-status-<?php echo $c['estado']; ?> <?php echo $isLiberadoParcial ? 'border-status-liberado-parcial' : ''; ?>">
                        <div class="card-stacked">
                            <div class="card-content">
                                <div class="row" style="margin-bottom: 0;">
                                    <div class="col s12 m6">
                                        <span class="card-title"><strong>Pedido: <?php echo esc($c['numero_pedido']); ?></strong></span>
                                        <p class="grey-text"><?php echo date('d/m/Y H:i', strtotime($c['fecha_creacion'])); ?></p>
                                        <p style="margin-top: 10px;"><i class="material-icons tiny">store</i> Sucursal: <?php echo esc($c['almacen_nombre'] ?? 'Principal'); ?></p>
                                    </div>
                                    <div class="col s12 m6 right-align">
                                        <div style="margin-bottom: 10px;">
                                            <?php echo getStatusBadge($c['estado']); ?>
                                            <?php if (!empty($c['pickup_estado'])): ?>
                                                <?php echo getPickupStatusBadge((string)$c['pickup_estado']); ?>
                                            <?php endif; ?>
                                            <?php if ($isLiberadoParcial): ?>
                                                <span class="badge amber darken-2 white-text" style="margin-left: 6px;">Liberado parcial</span>
                                            <?php endif; ?>
                                        </div>
                                        <h5 class="indigo-text">Total: $<?php echo number_format((float)$c['total'], 2); ?></h5>
                                    </div>
                                </div>
                                
                                <div class="divider" style="margin: 15px 0;"></div>
                                
                                <div class="row" style="margin-bottom: 0;">
                                    <div class="col s12">
                                        <h6><strong>Productos:</strong></h6>
                                        <ul class="collection" style="border: none;">
                                            <?php
                                            // Obtener detalles del pedido
                                            $stmtD = $pdo->prepare("SELECT dp.cantidad, dp.precio_unitario, p.nombre, p.nombre_variante 
                                                                   FROM detalle_pedidos dp 
                                                                   JOIN productos p ON dp.id_producto = p.id_producto 
                                                                   WHERE dp.id_pedido = ?");
                                            $stmtD->execute([$c['id_pedido']]);
                                            while($d = $stmtD->fetch()):
                                                $pName = $d['nombre'] . ($d['nombre_variante'] ? " - " . $d['nombre_variante'] : "");
                                                $isReleasedItem = ((int)$d['cantidad'] <= 0);
                                            ?>
                                                <li class="collection-item <?php echo $isReleasedItem ? 'released-line' : ''; ?>" style="padding: 5px 0; border: none;">
                                                    <?php echo $d['cantidad']; ?>x <?php echo esc($pName); ?> 
                                                    <span class="right grey-text">$<?php echo number_format($d['cantidad'] * $d['precio_unitario'], 2); ?></span>
                                                    <?php if ($isReleasedItem): ?>
                                                        <span class="released-chip">Liberado por tiempo de apartado</span>
                                                    <?php endif; ?>
                                                </li>
                                            <?php endwhile; ?>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <div class="card-action right-align" style="border-top: 1px solid rgba(160,160,160,0.2);">
                                <?php if (esPedidoCancelablePorCliente($c)): ?>
                                    <button type="button" onclick="cancelarPedido(<?php echo (int)$c['id_pedido']; ?>)" class="btn-flat red-text text-darken-2 font-weight-bold waves-effect" style="margin-right: 6px;">
                                        <i class="material-icons left">cancel</i> CANCELAR
                                    </button>
                                <?php endif; ?>
                                <a href="detalle_compra.php?id=<?php echo $c['id_pedido']; ?>" class="indigo-text text-darken-4 font-weight-bold">VER DETALLE COMPLETO <i class="material-icons right">chevron_right</i></a>
                            </div>
                            <?php if ($c['estado'] === 'en_reparto'): ?>
                                <div class="card-action orange lighten-5 orange-text text-darken-4">
                                    <i class="material-icons left">local_shipping</i> Tu pedido está en camino a tu domicilio.
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<style>
    .badge { float: none !important; border-radius: 4px; padding: 4px 8px; font-weight: bold; }
    .border-status-pagado { border-left: 8px solid #2196f3; }
    .border-status-en_reparto { border-left: 8px solid #ff9800; }
    .border-status-entregado { border-left: 8px solid #4caf50; }
    .border-status-cancelado { border-left: 8px solid #f44336; }
    .border-status-apartado { border-left: 8px solid #9c27b0; }
    .border-status-pendiente_pago { border-left: 8px solid #9e9e9e; }
    .border-status-liberado-parcial { box-shadow: inset 0 0 0 2px #ffb300; }
    .released-line { background: #fff8e1; border-radius: 4px; padding: 6px 8px !important; margin-bottom: 4px; }
    .released-chip { display: inline-block; margin-left: 8px; font-size: 0.75rem; color: #bf360c; background: #ffe0b2; border-radius: 10px; padding: 2px 8px; }
</style>

<?php if ($canViewPurchases): ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const MOTIVOS_CANCELACION = <?php echo json_encode($motivosCancelacion, JSON_UNESCAPED_UNICODE); ?>;
const CSRF_TOKEN_CANCELACION = <?php echo json_encode(getCsrfToken()); ?>;

function cancelarPedido(idPedido) {
    const opciones = MOTIVOS_CANCELACION.map(m => `<option value="${m.id_motivo}" data-requiere-detalle="${m.requiere_detalle}">${m.motivo}</option>`).join('');

    Swal.fire({
        title: 'Cancelar pedido',
        width: 'min(94vw, 420px)',
        html: `
            <div style="width:100%; max-width:100%; box-sizing:border-box; text-align:left;">
                <p style="margin:0 0 10px;">Cuéntanos por qué deseas cancelar este pedido:</p>
                <select id="swal-motivo-cancelacion" class="browser-default" style="display:block; box-sizing:border-box; width:100%; max-width:100%; padding:10px; border:1px solid #9e9e9e; border-radius:4px; margin:0 0 10px; font-size:1rem;">
                    <option value="">Selecciona un motivo</option>
                    ${opciones}
                </select>
                <textarea id="swal-detalle-cancelacion" style="display:none; box-sizing:border-box; width:100%; max-width:100%; min-height:80px; padding:8px; border:1px solid #9e9e9e; border-radius:4px; font-size:1rem; resize:vertical; margin:0;" placeholder="Cuéntanos más detalles..."></textarea>
            </div>
        `,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#b71c1c',
        confirmButtonText: 'Sí, cancelar pedido',
        cancelButtonText: 'Ya no',
        didOpen: () => {
            const select = document.getElementById('swal-motivo-cancelacion');
            const textarea = document.getElementById('swal-detalle-cancelacion');
            select.addEventListener('change', () => {
                const opt = select.options[select.selectedIndex];
                const requiereDetalle = opt && opt.dataset.requiereDetalle === '1';
                textarea.style.display = (requiereDetalle || select.value !== '') ? 'block' : 'none';
            });
        },
        preConfirm: () => {
            const select = document.getElementById('swal-motivo-cancelacion');
            const textarea = document.getElementById('swal-detalle-cancelacion');
            const idMotivo = parseInt(select.value, 10);
            const opt = select.options[select.selectedIndex];
            const requiereDetalle = opt && opt.dataset.requiereDetalle === '1';

            if (!idMotivo) {
                Swal.showValidationMessage('Selecciona un motivo de cancelación.');
                return false;
            }
            if (requiereDetalle && textarea.value.trim() === '') {
                Swal.showValidationMessage('Cuéntanos brevemente el motivo de tu cancelación.');
                return false;
            }
            return { id_motivo: idMotivo, motivo_detalle: textarea.value.trim() };
        }
    }).then((result) => {
        if (!result.isConfirmed) return;

        fetch('<?php echo BASE_URL; ?>api/cancel_order.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id_pedido: idPedido,
                id_motivo: result.value.id_motivo,
                motivo_detalle: result.value.motivo_detalle,
                csrf_token: CSRF_TOKEN_CANCELACION
            })
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                Swal.fire({
                    title: 'Pedido cancelado',
                    text: data.message || 'Tu pedido fue cancelado correctamente.',
                    icon: 'success',
                    confirmButtonColor: '#1a237e'
                }).then(() => window.location.reload());
            } else {
                Swal.fire({
                    title: 'No se pudo cancelar',
                    text: data.message || 'Ocurrió un error al cancelar el pedido.',
                    icon: 'error',
                    confirmButtonColor: '#b71c1c'
                });
            }
        })
        .catch(() => {
            Swal.fire({
                title: 'Error de conexión',
                text: 'No fue posible cancelar el pedido. Intenta de nuevo.',
                icon: 'error',
                confirmButtonColor: '#b71c1c'
            });
        });
    });
}
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
