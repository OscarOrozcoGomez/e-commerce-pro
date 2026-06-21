<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();

if (!isAdmin() && !isEncargado()) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$pageTitle = 'Liberar Stock Apartado';
$pdo = getPDO();
$defaultExpiryHours = max(1, (int)getEnvVar('RESERVATION_EXPIRY_HOURS', '48'));
$applyThreshold = isset($_GET['apply_threshold']) ? in_array((string)$_GET['apply_threshold'], ['1', 'true', 'yes', 'on'], true) : true;
$expiryHours = (int)($_GET['threshold_hours'] ?? $defaultExpiryHours);
$expiryHours = min(720, max(1, $expiryHours));

$orders = [];
$itemsByOrder = [];

try {
    $where = "p.estado IN ('pendiente_pago', 'apartado')
              AND EXISTS (
                  SELECT 1 FROM detalle_pedidos dpv
                  WHERE dpv.id_pedido = p.id_pedido AND dpv.cantidad > 0
              )";
    if ($applyThreshold) {
        $where .= " AND p.fecha_creacion <= DATE_SUB(NOW(), INTERVAL {$expiryHours} HOUR)";
    }

    $sqlOrders = "SELECT p.id_pedido, p.numero_pedido, p.estado, p.fecha_creacion, p.total,
                         COALESCE(c.nombre, 'Cliente General') AS cliente_nombre,
                         COALESCE(c.telefono, '') AS cliente_telefono,
                         COALESCE(u.nombre, 'Sistema') AS usuario_nombre,
                         TIMESTAMPDIFF(HOUR, p.fecha_creacion, NOW()) AS horas_reservado
                  FROM pedidos p
                  LEFT JOIN clientes c ON p.id_cliente = c.id_cliente
                  LEFT JOIN usuarios u ON p.id_usuario = u.id_usuario
                  WHERE {$where}
                  ORDER BY p.fecha_creacion ASC";

    $orders = $pdo->query($sqlOrders)->fetchAll() ?: [];

    if (!empty($orders)) {
        $orderIds = array_map(static fn(array $o): int => (int)$o['id_pedido'], $orders);
        $placeholders = implode(',', array_fill(0, count($orderIds), '?'));

        $sqlItems = "SELECT dp.id_detalle, dp.id_pedido, dp.id_producto, dp.cantidad, pr.nombre, pr.nombre_variante, pr.sku
                     FROM detalle_pedidos dp
                     JOIN productos pr ON pr.id_producto = dp.id_producto
                     WHERE dp.id_pedido IN ($placeholders)
                   AND dp.cantidad > 0
                     ORDER BY dp.id_pedido ASC";
        $stmtItems = $pdo->prepare($sqlItems);
        $stmtItems->execute($orderIds);

        foreach (($stmtItems->fetchAll() ?: []) as $row) {
            $idPedido = (int)$row['id_pedido'];
            if (!isset($itemsByOrder[$idPedido])) {
                $itemsByOrder[$idPedido] = [];
            }
            $itemsByOrder[$idPedido][] = [
                'id_detalle' => (int)$row['id_detalle'],
                'id_producto' => (int)$row['id_producto'],
                'cantidad' => (int)$row['cantidad'],
                'nombre' => trim((string)$row['nombre'] . (!empty($row['nombre_variante']) ? ' - ' . $row['nombre_variante'] : '')),
                'sku' => (string)($row['sku'] ?? ''),
            ];
        }
    }
} catch (Throwable $e) {
    error_log('Error cargando reservas expiradas: ' . $e->getMessage());
}

include __DIR__ . '/includes/header.php';
?>

<div class="container" style="margin-top: 24px; margin-bottom: 40px;">
    <div class="row">
        <div class="col s12">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                <h4 style="margin:0;">Liberar Stock Apartado</h4>
                <a href="dashboard.php" class="btn-flat waves-effect"><i class="material-icons left">arrow_back</i>Volver</a>
            </div>
            <p class="grey-text">
                <?php if ($applyThreshold): ?>
                    Se muestran pedidos con estado pendiente/apartado y antiguedad mayor o igual a <strong><?php echo (int)$expiryHours; ?> horas</strong>.
                <?php else: ?>
                    Se muestran <strong>todos</strong> los pedidos con estado pendiente/apartado, sin importar el tiempo transcurrido.
                <?php endif; ?>
            </p>
            <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin:8px 0 18px;">
                <a href="?apply_threshold=0" class="btn-small waves-effect <?php echo !$applyThreshold ? 'red darken-3 white-text' : 'grey lighten-3 black-text'; ?>">Sin filtro</a>
                <a href="?apply_threshold=1&amp;threshold_hours=24" class="btn-small waves-effect <?php echo ($applyThreshold && $expiryHours === 24) ? 'indigo darken-3 white-text' : 'grey lighten-3 black-text'; ?>">1 dia</a>
                <a href="?apply_threshold=1&amp;threshold_hours=48" class="btn-small waves-effect <?php echo ($applyThreshold && $expiryHours === 48) ? 'indigo darken-3 white-text' : 'grey lighten-3 black-text'; ?>">2 dias</a>
                <a href="?apply_threshold=1&amp;threshold_hours=72" class="btn-small waves-effect <?php echo ($applyThreshold && $expiryHours === 72) ? 'indigo darken-3 white-text' : 'grey lighten-3 black-text'; ?>">3 dias</a>
                <form method="get" action="" style="display:flex; gap:8px; align-items:center; margin:0;">
                    <input type="hidden" name="apply_threshold" value="1" />
                    <input type="number" min="1" max="720" name="threshold_hours" value="<?php echo (int)$expiryHours; ?>" style="width:120px; height:32px; margin:0;" />
                    <button type="submit" class="btn-small waves-effect blue darken-3">Aplicar</button>
                </form>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col s12">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Pedidos elegibles para liberar</span>

                    <?php if (empty($orders)): ?>
                        <p class="green-text text-darken-2" style="font-weight:600;">No hay pedidos expirados para liberar en este momento.</p>
                    <?php else: ?>
                        <div style="display:flex; gap:10px; flex-wrap:wrap; margin-bottom:16px;">
                            <button type="button" class="btn orange darken-3 waves-effect waves-light" onclick="liberarSeleccionados()">
                                <i class="material-icons left">lock_open</i>Liberar seleccionados
                            </button>
                            <button type="button" class="btn red darken-3 waves-effect waves-light" onclick="liberarTodos()">
                                <i class="material-icons left">warning</i>Liberar todos
                            </button>
                            <button type="button" class="btn-flat waves-effect" onclick="toggleAll(this)">Seleccionar todo</button>
                        </div>

                        <div style="overflow-x:auto;">
                            <table class="striped highlight">
                                <thead>
                                    <tr>
                                        <th></th>
                                        <th>Pedido</th>
                                        <th>Cliente</th>
                                        <th>Usuario que registro</th>
                                        <th>Tiempo apartado</th>
                                        <th>Total</th>
                                        <th>Productos</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($orders as $order):
                                        $idPedido = (int)$order['id_pedido'];
                                        $horas = (int)($order['horas_reservado'] ?? 0);
                                        $dias = intdiv($horas, 24);
                                        $horasRestantes = $horas % 24;
                                        $items = $itemsByOrder[$idPedido] ?? [];
                                    ?>
                                        <tr>
                                            <td>
                                                <label>
                                                    <input type="checkbox" class="filled-in release-check" value="<?php echo $idPedido; ?>" />
                                                    <span></span>
                                                </label>
                                            </td>
                                            <td>
                                                <strong><?php echo esc($order['numero_pedido']); ?></strong><br>
                                                <span class="grey-text" style="font-size:12px;">#<?php echo $idPedido; ?> | <?php echo esc((string)$order['estado']); ?></span>
                                            </td>
                                            <td>
                                                <strong><?php echo esc($order['cliente_nombre']); ?></strong><br>
                                                <span class="grey-text" style="font-size:12px;"><?php echo esc((string)$order['cliente_telefono']); ?></span>
                                            </td>
                                            <td><?php echo esc($order['usuario_nombre']); ?></td>
                                            <td>
                                                <strong><?php echo $dias; ?> d <?php echo $horasRestantes; ?> h</strong><br>
                                                <span class="grey-text" style="font-size:12px;">Desde: <?php echo date('d/m/Y H:i', strtotime((string)$order['fecha_creacion'])); ?></span>
                                            </td>
                                            <td>$<?php echo number_format((float)$order['total'], 2); ?></td>
                                            <td>
                                                <?php if (empty($items)): ?>
                                                    <span class="grey-text">Sin detalle</span>
                                                <?php else: ?>
                                                    <ul style="margin:0; padding-left:18px;">
                                                        <?php foreach ($items as $item): ?>
                                                            <li>
                                                                <?php echo (int)$item['cantidad']; ?> x <?php echo esc($item['nombre']); ?>
                                                                <?php if (!empty($item['sku'])): ?>
                                                                    <span class="grey-text" style="font-size:12px;">(<?php echo esc($item['sku']); ?>)</span>
                                                                <?php endif; ?>
                                                                <button type="button" class="btn-flat red-text text-darken-2" style="padding:0 6px; margin-left:6px; height:auto; line-height:1.2;" onclick="liberarProducto(<?php echo (int)$item['id_detalle']; ?>, '<?php echo esc(addslashes((string)$item['nombre'])); ?>', <?php echo (int)$item['cantidad']; ?>)">Liberar producto</button>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php endif; ?>
                                            </td>
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

<script>
const thresholdHours = <?php echo (int)$expiryHours; ?>;
const applyThreshold = <?php echo $applyThreshold ? 'true' : 'false'; ?>;

function getSelectedOrderIds() {
    return Array.from(document.querySelectorAll('.release-check:checked')).map(el => parseInt(el.value, 10)).filter(Boolean);
}

function toggleAll(btn) {
    const checks = Array.from(document.querySelectorAll('.release-check'));
    const allChecked = checks.length > 0 && checks.every(ch => ch.checked);
    checks.forEach(ch => ch.checked = !allChecked);
    btn.textContent = allChecked ? 'Seleccionar todo' : 'Quitar seleccion';
}

function liberarSeleccionados() {
    const ids = getSelectedOrderIds();
    if (!ids.length) {
        M.toast({html: 'Selecciona al menos un pedido.', classes: 'orange darken-2'});
        return;
    }
    if (!confirm('Se liberaran ' + ids.length + ' pedidos seleccionados. Esta accion no se puede deshacer.')) return;
    ejecutarLiberacion({ action: 'release_orders', release_all: false, order_ids: ids });
}

function liberarTodos() {
    if (!confirm('Se liberaran TODOS los pedidos expirados listados. Esta accion no se puede deshacer.')) return;
    ejecutarLiberacion({ action: 'release_orders', release_all: true, order_ids: [] });
}

function liberarProducto(detailId, productName, maxQty) {
    const entrada = prompt('Cuantas unidades liberar de "' + productName + '"? (1 a ' + maxQty + ')', '1');
    if (entrada === null) return;

    const qty = parseInt(entrada, 10);
    if (!qty || qty < 1 || qty > maxQty) {
        M.toast({html: 'Cantidad invalida. Debe ser entre 1 y ' + maxQty + '.', classes: 'orange darken-2'});
        return;
    }

    if (!confirm('Se liberaran ' + qty + ' unidad(es) de ' + productName + '. Esta accion no se puede deshacer.')) return;
    ejecutarLiberacion({ action: 'release_item', detail_id: detailId, release_qty: qty });
}

function ejecutarLiberacion(payload) {
    payload.threshold_hours = thresholdHours;
    payload.apply_threshold = applyThreshold;
    fetch('<?php echo BASE_URL; ?>api/cleanup_reservations.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            M.toast({html: data.message, classes: 'green darken-2'});
            setTimeout(() => window.location.reload(), 1000);
            return;
        }
        M.toast({html: 'Error: ' + (data.error || 'No se pudo liberar stock'), classes: 'red darken-2'});
    })
    .catch(() => M.toast({html: 'Error de red al liberar stock.', classes: 'red darken-2'}));
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>