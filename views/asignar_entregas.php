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
$successActionUrl = '';
$successActionLabel = '';

// Si el descifrado falla (llave distinta, dato corrupto, etc.) NUNCA mostramos el texto
// cifrado crudo (ENCv1:...); se usa un fallback seguro.
$safeDecryptValue = static function (?string $value, string $fallback = ''): string {
    $raw = trim((string)$value);
    if ($raw === '') {
        return $fallback;
    }
    if (!function_exists('piiIsEncryptedValue') || !function_exists('piiDecryptValue') || !piiIsEncryptedValue($raw)) {
        return $raw;
    }
    $decrypted = trim((string)piiDecryptValue($raw));
    if ($decrypted === $raw || piiIsEncryptedValue($decrypted)) {
        return $fallback;
    }
    return $decrypted;
};

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
                $fechaValidation = deliveryValidateFechaEntregaAsignacion($fechaRaw);
                $fecha = $fechaValidation['fecha'];
                if (!$fechaValidation['valid']) {
                    $error = (string)$fechaValidation['error'];
                }
                // El repartidor cobra al momento de entregar, no se requiere pago previo
                if ($error === '') {
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
            } elseif ($accion === 'convertir_sucursal') {
                $pdo->beginTransaction();
                try {
                    $stmtPedidoConv = $pdo->prepare(
                        "SELECT p.estado, p.id_almacen, p.id_cliente, p.numero_pedido, p.observaciones,
                                COALESCE(NULLIF(TRIM(p.telefono_entrega), ''), c.telefono) AS telefono_resuelto,
                                c.nombre AS cliente_nombre
                         FROM pedidos p
                         LEFT JOIN clientes c ON c.id_cliente = p.id_cliente
                         WHERE p.id_pedido = :id_pedido
                         FOR UPDATE"
                    );
                    $stmtPedidoConv->execute([':id_pedido' => $id_pedido]);
                    $pedidoConv = $stmtPedidoConv->fetch(PDO::FETCH_ASSOC) ?: null;

                    if (!$pedidoConv) {
                        throw new RuntimeException('No se encontro el pedido.');
                    }
                    if (!in_array((string)($pedidoConv['estado'] ?? ''), ['pendiente_pago', 'pagado'], true)) {
                        throw new RuntimeException('Este pedido ya no se puede convertir (estado actual: ' . strtoupper((string)($pedidoConv['estado'] ?? '')) . ').');
                    }

                    $stmtRepCheck = $pdo->prepare('SELECT id_repartidor FROM pedidos WHERE id_pedido = :id_pedido');
                    $stmtRepCheck->execute([':id_pedido' => $id_pedido]);
                    if ($stmtRepCheck->fetchColumn() !== null) {
                        throw new RuntimeException('Este pedido ya tiene un repartidor asignado, no se puede convertir a sucursal.');
                    }

                    $idAlmacenConv = (int)($pedidoConv['id_almacen'] ?? 0);
                    if ($idAlmacenConv <= 0) {
                        throw new RuntimeException('El pedido no tiene una sucursal valida para recoger.');
                    }

                    $columnExistsConv = static function (PDO $pdo, string $table, string $column): bool {
                        $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
                        $stmt->execute([$table, $column]);
                        return ((int)$stmt->fetchColumn()) > 0;
                    };
                    $hasTipoEntregaConv = $columnExistsConv($pdo, 'pedidos', 'tipo_entrega');

                    // Reescribe el marcador ENTREGA embebido en observaciones (respaldo para vistas
                    // que no dependen de la columna tipo_entrega).
                    $obsActualConv = (string)($pedidoConv['observaciones'] ?? '');
                    $obsNuevaConv = preg_replace('/ENTREGA:\s*Domicilio/i', 'ENTREGA: Sucursal', $obsActualConv, 1) ?? $obsActualConv;
                    $obsNuevaConv .= ' | CONVERTIDO_A_SUCURSAL: ' . date('Y-m-d H:i') . ' por ' . (string)($usuario['nombre'] ?? 'usuario');

                    $setPartsConv = ['observaciones = :observaciones'];
                    $paramsConv = [':observaciones' => $obsNuevaConv, ':id_pedido' => $id_pedido];
                    if ($hasTipoEntregaConv) {
                        $setPartsConv[] = 'tipo_entrega = :tipo_entrega';
                        $paramsConv[':tipo_entrega'] = 'Sucursal';
                    }

                    $stmtUpdateConv = $pdo->prepare('UPDATE pedidos SET ' . implode(', ', $setPartsConv) . ' WHERE id_pedido = :id_pedido');
                    $stmtUpdateConv->execute($paramsConv);

                    // Reutiliza el mismo helper que usa el checkout web para crear la notificacion pickup.
                    dbCreatePickupNotification($pdo, [
                        'id_pedido' => $id_pedido,
                        'id_almacen' => $idAlmacenConv,
                        'id_cliente' => $pedidoConv['id_cliente'] ?? null,
                        'numero_pedido' => (string)($pedidoConv['numero_pedido'] ?? ''),
                        'cliente_nombre' => $safeDecryptValue($pedidoConv['cliente_nombre'] ?? '', 'Cliente'),
                        'cliente_telefono' => $safeDecryptValue($pedidoConv['telefono_resuelto'] ?? '', ''),
                        'observaciones' => 'CONVERTIDO_DESDE_DOMICILIO: cliente solicito recoger en sucursal.',
                    ]);

                    $pdo->commit();
                    $success = 'Pedido convertido a recoger en sucursal. Ya esta disponible en Notificaciones Pickup.';
                    $successActionUrl = BASE_URL . 'views/pickup_notifications.php';
                    $successActionLabel = 'Ir a Notificaciones Pickup';
                    logAudit('PEDIDO_CONVERTIDO_A_SUCURSAL', 'pedidos', $id_pedido, 'Convertido de entrega a domicilio a recoger en sucursal');
                } catch (Throwable $txe) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = $txe->getMessage();
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
            <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-top:20px;">
                <h4 style="margin:0;">Asignar Entregas a Domicilio</h4>
                <a href="<?php echo BASE_URL; ?>views/dashboard.php" class="btn blue darken-4 waves-effect waves-light"><i class="material-icons left">dashboard</i> Volver al Dashboard</a>
            </div>
            <p class="grey-text">Selecciona un pedido agendado a domicilio y asígnalo a un repartidor disponible.</p>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="card green lighten-4 green-text text-darken-4" style="padding: 10px; display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
            <span><i class="material-icons left">check_circle</i> <?php echo esc($success); ?></span>
            <?php if ($successActionUrl !== ''): ?>
                <a href="<?php echo esc($successActionUrl); ?>" class="btn green darken-2 waves-effect waves-light">
                    <?php echo esc($successActionLabel); ?> <i class="material-icons right">arrow_forward</i>
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div id="modal-error-asignacion" class="modal">
            <div class="modal-content">
                <h5><i class="material-icons left red-text text-darken-2">error</i>No se pudo asignar el pedido</h5>
                <p><?php echo esc($error); ?></p>
            </div>
            <div class="modal-footer">
                <a href="#!" class="modal-close waves-effect waves-light btn red darken-2">Entendido</a>
            </div>
        </div>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                var elem = document.getElementById('modal-error-asignacion');
                if (elem && typeof M !== 'undefined' && M.Modal) {
                    M.Modal.init(elem, { dismissible: true }).open();
                }
            });
        </script>
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
                                                <input type="date" id="fecha-<?php echo (int)$p['id_pedido']; ?>" name="fecha_entrega" class="assign-delivery-date" required>

                                                <button type="submit" class="btn indigo waves-effect waves-light assign-delivery-submit">
                                                    Asignar <i class="material-icons right">local_shipping</i>
                                                </button>
                                            </form>

                                            <form method="POST" style="margin-top:8px;" onsubmit="return confirm('¿Cambiar este pedido a recoger en sucursal? El cliente dejara de aparecer para asignar a un repartidor.');">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="id_pedido" value="<?php echo $p['id_pedido']; ?>">
                                                <input type="hidden" name="accion" value="convertir_sucursal">
                                                <button type="submit" class="btn-flat waves-effect assign-delivery-to-pickup-btn w-100">
                                                    <i class="material-icons left">store</i>Cambiar a recoger en sucursal
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
    .assign-delivery-to-pickup-btn {
        border: 1px solid #607d8b !important;
        color: #455a64 !important;
        background: #eceff1 !important;
        border-radius: 4px;
        font-size: 0.82rem;
        font-weight: 600;
    }
    .assign-delivery-to-pickup-btn:hover {
        background: #cfd8dc !important;
    }
    .w-100 { width: 100%; }
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
