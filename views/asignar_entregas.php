<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
if (!canManageDeliveryOrders()) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$pageTitle = 'Asignar Entregas';
$pdo = getPDO();
$usuario = $_SESSION['usuario'];
$error = '';
$success = '';

// Procesar asignación
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id_pedido'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF inválido.';
    } else {
        $id_pedido = intval($_POST['id_pedido']);
        $accion = $_POST['accion'] ?? '';

        try {
            if ($accion === 'asignar' && isset($_POST['id_repartidor'])) {
                $id_repartidor = intval($_POST['id_repartidor']);
                $fechaRaw = trim((string)($_POST['fecha_entrega'] ?? ''));
                $fecha = null;
                if ($fechaRaw !== '') {
                    $fechaParsed = DateTimeImmutable::createFromFormat('Y-m-d', $fechaRaw);
                    if ($fechaParsed instanceof DateTimeImmutable) {
                        $fecha = $fechaParsed->format('Y-m-d 00:00:00');
                    } else {
                        throw new Exception('La fecha de entrega no es valida.');
                    }
                }
                // El repartidor cobra al momento de entregar, no se requiere pago previo
                $hasPedidosTipoEntrega = false;
                $stmtMeta = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'tipo_entrega'");
                $stmtMeta->execute();
                $hasPedidosTipoEntrega = ((int)$stmtMeta->fetchColumn()) > 0;

                $stmtMetaPickup = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pickup_notificaciones'");
                $stmtMetaPickup->execute();
                $hasPickupNotificacionesTable = ((int)$stmtMetaPickup->fetchColumn()) > 0;
                // Un pedido con notificacion pickup es SIEMPRE de recoger en sucursal (se crea
                // exclusivamente para ese flujo), sin importar si tipo_entrega/observaciones estan
                // mal etiquetados. Esta verificacion es a prueba de datos historicos inconsistentes.
                $notPickupClause = $hasPickupNotificacionesTable
                    ? " AND NOT EXISTS (SELECT 1 FROM pickup_notificaciones pn WHERE pn.id_pedido = pedidos.id_pedido)"
                    : '';

                if ($hasPedidosTipoEntrega) {
                    $sqlUpdate = "UPDATE pedidos
                                  SET id_repartidor = :rep, fecha_entrega_programada = :fecha
                                  WHERE id_pedido = :pedido
                                    AND estado IN ('pendiente_pago','pagado')
                                                                        AND (
                                                                                tipo_entrega = 'Domicilio'
                                                                                OR ((tipo_entrega IS NULL OR TRIM(tipo_entrega) = '') AND observaciones LIKE '%ENTREGA: Domicilio%')
                                                                        ){$notPickupClause}";
                } else {
                    $sqlUpdate = "UPDATE pedidos
                                  SET id_repartidor = :rep, fecha_entrega_programada = :fecha
                                  WHERE id_pedido = :pedido
                                    AND estado IN ('pendiente_pago','pagado')
                                    AND observaciones LIKE '%ENTREGA: Domicilio%'{$notPickupClause}";
                }

                $stmt = $pdo->prepare($sqlUpdate);
                $stmt->execute([
                    ':rep' => $id_repartidor,
                    ':fecha' => $fecha ?: null,
                    ':pedido' => $id_pedido
                ]);
                if ($stmt->rowCount() > 0) {
                    logAudit('PEDIDO_ASIGNADO', 'pedidos', $id_pedido, "Pedido asignado al repartidor ID: $id_repartidor");
                    $success = 'Pedido asignado correctamente.';
                } else {
                    $error = 'No se pudo asignar. Verifica que el pedido no esté ya en reparto o entregado.';
                }
            }
        } catch (PDOException $e) {
            $error = 'Error al asignar: ' . $e->getMessage();
        }
    }
}

// Obtener pedidos pendientes de asignación (sin repartidor aún, estados pre-entrega)
try {
    $hasClientesDireccion = false;
    $hasClienteDireccionesTable = false;
    $hasPedidosTipoEntrega = false;
    $hasPedidosDireccionEntrega = false;

    $stmtMeta = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'direccion'");
    $stmtMeta->execute();
    $hasClientesDireccion = ((int)$stmtMeta->fetchColumn()) > 0;

    $stmtMeta = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cliente_direcciones'");
    $stmtMeta->execute();
    $hasClienteDireccionesTable = ((int)$stmtMeta->fetchColumn()) > 0;

    $stmtMeta = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'tipo_entrega'");
    $stmtMeta->execute();
    $hasPedidosTipoEntrega = ((int)$stmtMeta->fetchColumn()) > 0;

    $stmtMeta = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'direccion_entrega'");
    $stmtMeta->execute();
    $hasPedidosDireccionEntrega = ((int)$stmtMeta->fetchColumn()) > 0;

    $stmtMeta = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pickup_notificaciones'");
    $stmtMeta->execute();
    $hasPickupNotificacionesTable = ((int)$stmtMeta->fetchColumn()) > 0;

    if ($hasPedidosDireccionEntrega) {
        $fallbackDireccion = $hasClientesDireccion && $hasClienteDireccionesTable
            ? "COALESCE(c.direccion, (SELECT cd.direccion FROM cliente_direcciones cd WHERE cd.id_cliente = c.id_cliente ORDER BY cd.es_default DESC, cd.id_direccion ASC LIMIT 1))"
            : ($hasClientesDireccion
                ? 'c.direccion'
                : ($hasClienteDireccionesTable
                    ? "(SELECT cd.direccion FROM cliente_direcciones cd WHERE cd.id_cliente = c.id_cliente ORDER BY cd.es_default DESC, cd.id_direccion ASC LIMIT 1)"
                    : 'NULL'));
        $direccionExpr = "COALESCE(NULLIF(TRIM(p.direccion_entrega), ''), {$fallbackDireccion}) AS direccion";
    } elseif ($hasClientesDireccion && $hasClienteDireccionesTable) {
        $direccionExpr = "COALESCE(c.direccion, (SELECT cd.direccion FROM cliente_direcciones cd WHERE cd.id_cliente = c.id_cliente ORDER BY cd.es_default DESC, cd.id_direccion ASC LIMIT 1)) AS direccion";
    } elseif ($hasClientesDireccion) {
        $direccionExpr = "c.direccion AS direccion";
    } elseif ($hasClienteDireccionesTable) {
        $direccionExpr = "(SELECT cd.direccion FROM cliente_direcciones cd WHERE cd.id_cliente = c.id_cliente ORDER BY cd.es_default DESC, cd.id_direccion ASC LIMIT 1) AS direccion";
    } else {
        $direccionExpr = "NULL AS direccion";
    }

    if ($hasPedidosTipoEntrega) {
        $deliveryFilter = "(
            p.tipo_entrega = 'Domicilio'
            OR ((p.tipo_entrega IS NULL OR TRIM(p.tipo_entrega) = '') AND p.observaciones LIKE '%ENTREGA: Domicilio%')
        )";
    } else {
        // Compatibilidad con esquemas antiguos: tipo de entrega embebido en observaciones.
        $deliveryFilter = "p.observaciones LIKE '%ENTREGA: Domicilio%'";
    }

    // Un pedido con notificacion pickup es SIEMPRE de recoger en sucursal (se crea exclusivamente
    // para ese flujo en dbCreatePickupNotification), sin importar si tipo_entrega/observaciones
    // quedaron mal etiquetados en pedidos historicos. Evita que un pickup se cuele en la lista
    // de "Asignar Entregas a Domicilio".
    $notPickupFilter = $hasPickupNotificacionesTable
        ? " AND NOT EXISTS (SELECT 1 FROM pickup_notificaciones pn WHERE pn.id_pedido = p.id_pedido)"
        : '';

    $sql = "SELECT p.*, c.nombre as cliente, {$direccionExpr}, c.telefono
            FROM pedidos p
            LEFT JOIN clientes c ON p.id_cliente = c.id_cliente
            WHERE p.estado IN ('pendiente_pago','pagado')
              AND p.id_repartidor IS NULL
              AND {$deliveryFilter}{$notPickupFilter}
            ORDER BY p.fecha_creacion DESC";
    $pedidos = $pdo->query($sql)->fetchAll();

    // Obtener lista de repartidores
    $sql_rep = "SELECT id_usuario, nombre FROM usuarios WHERE id_rol = (SELECT id_rol FROM roles WHERE nombre = 'repartidor') AND estado = 'activo'";
    $repartidores = $pdo->query($sql_rep)->fetchAll();
} catch (PDOException $e) {
    $error = 'Error de base de datos: ' . $e->getMessage();
    $pedidos = [];
    $repartidores = [];
}

include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="col s12">
            <h4>Asignar Entregas a Domicilio</h4>
            <p class="grey-text">Selecciona un pedido agendado a domicilio y asígnalo a un repartidor disponible.</p>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="card green lighten-4 green-text text-darken-4" style="padding: 10px;">
            <i class="material-icons left">check_circle</i> <?php echo esc($success); ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="card red lighten-4 red-text text-darken-4" style="padding: 10px;">
            <i class="material-icons left">error</i> <?php echo esc($error); ?>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col s12">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Pedidos por Asignar a Repartidor</span>
                    <p class="grey-text" style="font-size:0.9rem; margin-top:0;">El repartidor cobrará al momento de entregar. Solo asigna el repartidor y la fecha estimada.</p>

                    <?php if (empty($pedidos)): ?>
                        <p class="center-align grey-text">No hay pedidos pendientes de asignación por ahora.</p>
                    <?php else: ?>
                        <div class="row assign-deliveries-grid">
                            <?php foreach ($pedidos as $p): ?>
                                <div class="col s12 m6 l4">
                                    <div class="card assign-delivery-card">
                                        <div class="card-content">
                                            <div class="assign-delivery-header">
                                                <span class="assign-delivery-numero"><?php echo esc($p['numero_pedido']); ?></span>
                                                <span class="assign-delivery-badge" style="background:<?php echo $p['estado'] === 'pendiente_pago' ? '#f57c00' : '#388e3c'; ?>;">
                                                    <?php echo $p['estado'] === 'pendiente_pago' ? 'Cobro al entregar' : 'Ya pagado'; ?>
                                                </span>
                                            </div>

                                            <div class="assign-delivery-row">
                                                <i class="material-icons tiny">person</i>
                                                <span><?php echo esc($p['cliente'] ?? 'N/A'); ?></span>
                                            </div>
                                            <div class="assign-delivery-row">
                                                <i class="material-icons tiny">place</i>
                                                <span class="grey-text text-darken-1"><?php echo esc($p['direccion'] ?? 'S/D'); ?></span>
                                            </div>
                                            <div class="assign-delivery-total">$<?php echo number_format((float)$p['total'], 2); ?></div>

                                            <form method="POST" class="assign-delivery-form">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="id_pedido" value="<?php echo $p['id_pedido']; ?>">
                                                <input type="hidden" name="accion" value="asignar">

                                                <label class="assign-delivery-label" for="repartidor-<?php echo (int)$p['id_pedido']; ?>">Asignar repartidor</label>
                                                <select id="repartidor-<?php echo (int)$p['id_pedido']; ?>" name="id_repartidor" required class="browser-default assign-delivery-select">
                                                    <option value="">-- Seleccionar --</option>
                                                    <?php foreach ($repartidores as $r): ?>
                                                        <option value="<?php echo $r['id_usuario']; ?>"><?php echo esc($r['nombre']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>

                                                <label class="assign-delivery-label" for="fecha-<?php echo (int)$p['id_pedido']; ?>">Día de entrega</label>
                                                <input type="date" id="fecha-<?php echo (int)$p['id_pedido']; ?>" name="fecha_entrega" class="assign-delivery-date">

                                                <button type="submit" class="btn indigo waves-effect waves-light assign-delivery-submit">
                                                    Asignar <i class="material-icons right">local_shipping</i>
                                                </button>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .assign-deliveries-grid { margin-top: 8px; }
    .assign-delivery-card {
        display: flex;
        flex-direction: column;
        height: 100%;
        margin: 0;
        border: 1px solid #e0e0e0;
        box-shadow: 0 1px 3px rgba(0,0,0,0.08);
    }
    .assign-delivery-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        flex-wrap: wrap;
        margin-bottom: 10px;
    }
    .assign-delivery-numero {
        font-weight: 700;
        font-size: 1.05rem;
        word-break: break-word;
    }
    .assign-delivery-badge {
        font-size: 0.72rem;
        font-weight: 600;
        padding: 3px 9px;
        border-radius: 12px;
        color: #fff;
        white-space: nowrap;
    }
    .assign-delivery-row {
        display: flex;
        align-items: flex-start;
        gap: 8px;
        margin-bottom: 6px;
        font-size: 0.95rem;
        line-height: 1.35;
    }
    .assign-delivery-row i { color: #607d8b; margin-top: 2px; flex-shrink: 0; }
    .assign-delivery-total {
        font-size: 1.2rem;
        font-weight: 700;
        color: #2e7d32;
        margin: 6px 0 14px;
    }
    .assign-delivery-form { margin-top: auto; }
    .assign-delivery-label {
        display: block;
        font-size: 0.8rem;
        font-weight: 600;
        color: #546e7a;
        margin-bottom: 4px;
        margin-top: 10px;
    }
    select.assign-delivery-select {
        width: 100%;
        height: 44px;
        border: 1px solid #cfd8dc;
        border-radius: 4px;
        padding: 0 10px;
    }
    input[type="date"].assign-delivery-date {
        width: 100%;
        height: 44px;
        border: 1px solid #cfd8dc;
        border-radius: 4px;
        padding: 0 10px;
        box-sizing: border-box;
    }
    .assign-delivery-submit {
        width: 100%;
        margin-top: 16px;
        height: 46px;
    }

    @media only screen and (max-width: 600px) {
        .assign-delivery-card .card-content { padding: 18px 16px; }
        .assign-delivery-numero { font-size: 1.1rem; }
    }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
