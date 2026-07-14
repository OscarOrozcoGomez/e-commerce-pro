<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
if (!isEncargado() && !isAdmin() && !isVendedor()) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$pageTitle = 'Notificaciones Pickup';
$pdo = getPDO();
$usuario = $_SESSION['usuario'];
$idUsuario = (int)($usuario['id_usuario'] ?? 0);
$idAlmacenUsuario = (int)($usuario['id_almacen'] ?? 0);
$error = '';
$success = '';
$cancelSupportReady = false;
$cancelReasonOptions = [
    'cliente_no_llego' => 'Cliente no llego por su pedido',
    'cliente_solicito_cancelar' => 'Cliente solicito cancelar el pedido',
    'pago_no_confirmado' => 'Pago no confirmado en tiempo',
    'sin_contacto_cliente' => 'Sin respuesta del cliente',
    'error_captura_pedido' => 'Error en captura del pedido',
    'producto_danado' => 'Producto danado/no apto para entrega',
    'tiempo_espera_excedido' => 'Tiempo de espera excedido',
    'otro' => 'Otro',
];

try {
    $stmtMeta = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pickup_notificaciones'");
    $stmtMeta->execute();
    $hasPickupTable = ((int)$stmtMeta->fetchColumn()) > 0;
    if (!$hasPickupTable) {
        throw new RuntimeException('El modulo de pickup no esta habilitado aun. Aplica migraciones pendientes.');
    }

    $stmtCancelCol = $pdo->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pickup_notificaciones' AND COLUMN_NAME = 'estado' LIMIT 1");
    $stmtCancelCol->execute();
    $estadoColumnType = (string)($stmtCancelCol->fetchColumn() ?: '');
    $stmtFechaCancel = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pickup_notificaciones' AND COLUMN_NAME = 'fecha_cancelada'");
    $stmtFechaCancel->execute();
    $hasFechaCancelada = ((int)$stmtFechaCancel->fetchColumn()) > 0;
    $cancelSupportReady = (stripos($estadoColumnType, 'cancelada') !== false) && $hasFechaCancelada;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $error = 'Token CSRF invalido.';
        } else {
            $accion = trim((string)($_POST['accion'] ?? ''));
            $idNotificacion = (int)($_POST['id_notificacion'] ?? 0);
            $estado = trim((string)($_POST['estado'] ?? 'nueva'));
            $motivoCancelacionKey = trim((string)($_POST['motivo_cancelacion'] ?? ''));
            $motivoCancelacionOtro = trim((string)($_POST['motivo_cancelacion_otro'] ?? ''));

            if ($idNotificacion <= 0) {
                $error = 'Notificacion invalida.';
            } elseif (!in_array($accion, ['marcar_estado', 'cancelar_pedido'], true)) {
                $error = 'Accion no permitida.';
            } elseif ($accion === 'marcar_estado' && !in_array($estado, ['vista', 'apartada', 'atendida'], true)) {
                $error = 'Estado invalido.';
            } elseif ($accion === 'cancelar_pedido' && !$cancelSupportReady) {
                $error = 'Para cancelar pickup y devolver inventario primero aplica migraciones pendientes.';
            } elseif ($accion === 'cancelar_pedido' && !isset($cancelReasonOptions[$motivoCancelacionKey])) {
                $error = 'Selecciona un motivo de cancelacion valido.';
            } elseif ($accion === 'cancelar_pedido' && $motivoCancelacionKey === 'otro' && $motivoCancelacionOtro === '') {
                $error = 'Escribe el motivo cuando selecciones "Otro".';
            } else {
                if ($error === '') {
                    $motivoCancelacionEtiqueta = '';
                    if ($accion === 'cancelar_pedido') {
                        $motivoCancelacionEtiqueta = (string)($cancelReasonOptions[$motivoCancelacionKey] ?? '');
                        if ($motivoCancelacionKey === 'otro') {
                            $motivoCancelacionEtiqueta = mb_substr($motivoCancelacionOtro, 0, 180);
                        }
                    }

                    $scopeWhere = '';
                    $scopeParams = [];
                    if (isEncargado() || isVendedor()) {
                        $scopeWhere = ' AND id_almacen = :scope_almacen';
                        $scopeParams[':scope_almacen'] = $idAlmacenUsuario;
                    }

                    $stmtCurrent = $pdo->prepare("SELECT id_pedido, estado FROM pickup_notificaciones WHERE id_notificacion = :id_notificacion{$scopeWhere} LIMIT 1");
                    $stmtCurrent->execute([
                        ':id_notificacion' => $idNotificacion,
                    ] + $scopeParams);
                    $current = $stmtCurrent->fetch(PDO::FETCH_ASSOC) ?: null;

                    if (!$current) {
                        $error = 'No se encontro la notificacion o no tienes permisos para modificarla.';
                    } else {
                        $estadoActual = (string)($current['estado'] ?? 'nueva');

                        if ($accion === 'cancelar_pedido') {
                            if (in_array($estadoActual, ['cancelada', 'atendida'], true)) {
                                if ($estadoActual === 'cancelada') {
                                    $success = 'La notificacion ya esta cancelada.';
                                } else {
                                    $error = 'No se puede cancelar una notificacion ya atendida.';
                                }
                            } else {
                                $pdo->beginTransaction();
                                try {
                                    $idPedido = (int)($current['id_pedido'] ?? 0);
                                    if ($idPedido <= 0) {
                                        throw new RuntimeException('Pedido pickup invalido para cancelar.');
                                    }

                                    $stmtPedido = $pdo->prepare("SELECT estado, id_almacen FROM pedidos WHERE id_pedido = :id_pedido FOR UPDATE");
                                    $stmtPedido->execute([':id_pedido' => $idPedido]);
                                    $pedido = $stmtPedido->fetch(PDO::FETCH_ASSOC) ?: null;
                                    if (!$pedido) {
                                        throw new RuntimeException('No se encontro el pedido asociado.');
                                    }

                                    $estadoPedido = (string)($pedido['estado'] ?? '');
                                    $idAlmacenPedido = (int)($pedido['id_almacen'] ?? 0);

                                    if ($estadoPedido !== 'cancelado') {
                                        $stmtDetalle = $pdo->prepare("SELECT id_producto, cantidad FROM detalle_pedidos WHERE id_pedido = :id_pedido AND cantidad > 0 FOR UPDATE");
                                        $stmtDetalle->execute([':id_pedido' => $idPedido]);
                                        $items = $stmtDetalle->fetchAll(PDO::FETCH_ASSOC);

                                        $stmtResurtir = $pdo->prepare("INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual, stock_minimo, stock_maximo)
                                                                       VALUES (:id_producto, :id_almacen, :cantidad, 2, 5)
                                                                       ON DUPLICATE KEY UPDATE cantidad_actual = cantidad_actual + VALUES(cantidad_actual)");

                                        foreach ($items as $it) {
                                            $idProducto = (int)($it['id_producto'] ?? 0);
                                            $cantidad = max(0, (int)($it['cantidad'] ?? 0));
                                            if ($idProducto <= 0 || $cantidad <= 0 || $idAlmacenPedido <= 0) {
                                                continue;
                                            }
                                            $stmtResurtir->execute([
                                                ':id_producto' => $idProducto,
                                                ':id_almacen' => $idAlmacenPedido,
                                                ':cantidad' => $cantidad,
                                            ]);
                                        }

                                        $stmtCancelarPedido = $pdo->prepare("UPDATE pedidos SET estado = 'cancelado' WHERE id_pedido = :id_pedido");
                                        $stmtCancelarPedido->execute([':id_pedido' => $idPedido]);
                                    }

                                    $stmtCancelarNotif = $pdo->prepare("UPDATE pickup_notificaciones
                                                                        SET estado = 'cancelada',
                                                                            id_usuario_seguimiento = :id_usuario,
                                                                            fecha_cancelada = NOW(),
                                                                            notas_seguimiento = CONCAT(COALESCE(notas_seguimiento, ''),
                                                                                CASE WHEN COALESCE(notas_seguimiento, '') = '' THEN '' ELSE ' | ' END,
                                                                                'CANCELADA_AUTO: pedido cancelado y stock devuelto. Motivo: ', :motivo_cancelacion),
                                                                            actualizado_en = NOW()
                                                                      WHERE id_notificacion = :id_notificacion{$scopeWhere}");
                                    $stmtCancelarNotif->execute([
                                        ':id_usuario' => $idUsuario,
                                        ':motivo_cancelacion' => $motivoCancelacionEtiqueta,
                                        ':id_notificacion' => $idNotificacion,
                                    ] + $scopeParams);

                                    $pdo->commit();
                                    $success = 'Pedido pickup cancelado y stock devuelto al inventario de sucursal.';
                                    logAudit('PICKUP_NOTIFICACION_CANCELADA', 'pickup_notificaciones', $idNotificacion, 'Cancelacion pickup con resurtido automatico');
                                } catch (Throwable $txe) {
                                    if ($pdo->inTransaction()) {
                                        $pdo->rollBack();
                                    }
                                    throw $txe;
                                }
                            }
                        }

                        if ($accion === 'marcar_estado') {
                            $transicionesPermitidas = [
                                'nueva' => ['vista'],
                                'vista' => ['apartada'],
                                'apartada' => ['atendida'],
                                'atendida' => [],
                                'cancelada' => [],
                            ];

                            if ($estado === $estadoActual) {
                                $success = 'La notificacion ya estaba en el estado seleccionado.';
                            } elseif (!in_array($estado, $transicionesPermitidas[$estadoActual] ?? [], true)) {
                                $error = 'Transicion de estado no permitida. Estado actual: ' . strtoupper($estadoActual);
                            } else {
                                $pdo->beginTransaction();
                                try {
                                    $sql = "UPDATE pickup_notificaciones
                                            SET estado = :estado,
                                                id_usuario_seguimiento = :id_usuario,
                                                fecha_vista = CASE WHEN :estado_vista IN ('vista','apartada','atendida') AND fecha_vista IS NULL THEN NOW() ELSE fecha_vista END,
                                                fecha_apartada = CASE WHEN :estado_apartada = 'apartada' AND fecha_apartada IS NULL THEN NOW() ELSE fecha_apartada END,
                                                fecha_atendida = CASE WHEN :estado_atendida = 'atendida' THEN NOW() ELSE fecha_atendida END,
                                                actualizado_en = NOW()
                                            WHERE id_notificacion = :id_notificacion{$scopeWhere}";

                                    $stmt = $pdo->prepare($sql);
                                    $params = [
                                        ':estado' => $estado,
                                        ':estado_vista' => $estado,
                                        ':estado_apartada' => $estado,
                                        ':estado_atendida' => $estado,
                                        ':id_usuario' => $idUsuario,
                                        ':id_notificacion' => $idNotificacion,
                                    ] + $scopeParams;

                                    $stmt->execute($params);

                                    if ($estado === 'atendida') {
                                        $idPedido = (int)($current['id_pedido'] ?? 0);
                                        if ($idPedido > 0) {
                                            $stmtPedido = $pdo->prepare("UPDATE pedidos
                                                                       SET estado = 'pagado',
                                                                           fecha_pago = IFNULL(fecha_pago, NOW())
                                                                     WHERE id_pedido = :id_pedido
                                                                       AND estado IN ('pendiente_pago', 'apartado', 'pagado')");
                                            $stmtPedido->execute([':id_pedido' => $idPedido]);
                                        }
                                    }

                                    $pdo->commit();
                                    $success = 'Seguimiento de pickup actualizado.';
                                    logAudit('PICKUP_NOTIFICACION_ACTUALIZADA', 'pickup_notificaciones', $idNotificacion, "Estado actualizado de {$estadoActual} a {$estado}");
                                } catch (Throwable $txe) {
                                    if ($pdo->inTransaction()) {
                                        $pdo->rollBack();
                                    }
                                    throw $txe;
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    $estadoFiltro = trim((string)($_GET['estado'] ?? ''));
    $where = '1=1';
    $params = [];

    if (isEncargado() || isVendedor()) {
        $where .= ' AND pn.id_almacen = :almacen';
        $params[':almacen'] = $idAlmacenUsuario;
    }

    if (in_array($estadoFiltro, ['nueva', 'vista', 'apartada', 'atendida', 'cancelada'], true)) {
        $where .= ' AND pn.estado = :estado';
        $params[':estado'] = $estadoFiltro;
    }

    $sqlList = "SELECT
            pn.*,
            p.numero_pedido,
            p.total,
            p.estado AS estado_pedido,
            p.fecha_creacion AS fecha_pedido,
            c.nombre AS cliente,
            a.nombre AS sucursal,
            TIMESTAMPDIFF(MINUTE, pn.creado_en, NOW()) AS minutos_atraso,
            TIMESTAMPDIFF(HOUR, pn.creado_en, NOW()) AS horas_atraso
        FROM pickup_notificaciones pn
        INNER JOIN pedidos p ON p.id_pedido = pn.id_pedido
        LEFT JOIN clientes c ON c.id_cliente = pn.id_cliente
        INNER JOIN almacenes a ON a.id_almacen = pn.id_almacen
        WHERE {$where}
        ORDER BY FIELD(pn.estado, 'nueva', 'vista', 'apartada', 'atendida', 'cancelada'), pn.creado_en DESC
        LIMIT 300";

    $stmtList = $pdo->prepare($sqlList);
    $stmtList->execute($params);
    $notificaciones = $stmtList->fetchAll(PDO::FETCH_ASSOC);

    $whereCounts = '1=1';
    $paramsCounts = [];
    if (isEncargado() || isVendedor()) {
        $whereCounts .= ' AND id_almacen = :almacen';
        $paramsCounts[':almacen'] = $idAlmacenUsuario;
    }

    $stmtCounts = $pdo->prepare("SELECT estado, COUNT(*) AS total FROM pickup_notificaciones WHERE {$whereCounts} GROUP BY estado");
    $stmtCounts->execute($paramsCounts);
    $rowsCounts = $stmtCounts->fetchAll(PDO::FETCH_ASSOC);
    $counts = ['nueva' => 0, 'vista' => 0, 'apartada' => 0, 'atendida' => 0, 'cancelada' => 0];
    foreach ($rowsCounts as $row) {
        $key = (string)($row['estado'] ?? '');
        if (isset($counts[$key])) {
            $counts[$key] = (int)($row['total'] ?? 0);
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    $notificaciones = [];
    $counts = ['nueva' => 0, 'vista' => 0, 'apartada' => 0, 'atendida' => 0, 'cancelada' => 0];
    $estadoFiltro = '';
}

include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="col s12">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-top:20px;">
                <h4 style="margin:0;"><i class="material-icons left">notifications_active</i> Notificaciones Pickup</h4>
                <a href="<?php echo BASE_URL; ?>views/dashboard.php" class="btn blue darken-4 waves-effect waves-light">
                    <i class="material-icons left">dashboard</i> Volver al Dashboard
                </a>
            </div>
            <p class="grey-text">Seguimiento pickup por sucursal: nueva, vista, apartada y atendida. Las horas se registran automaticamente al cambiar estatus.</p>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="card-panel green lighten-4 green-text text-darken-4"><?php echo esc($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="card-panel red lighten-4 red-text text-darken-4"><?php echo esc($error); ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col s12 m3">
            <div class="card deep-orange lighten-1 white-text">
                <div class="card-content">
                    <span class="card-title">Nuevas</span>
                    <p style="font-size:2.4rem; margin:0;"><?php echo (int)$counts['nueva']; ?></p>
                </div>
            </div>
        </div>
        <div class="col s12 m3">
            <div class="card amber darken-2 white-text">
                <div class="card-content">
                    <span class="card-title">Vistas</span>
                    <p style="font-size:2.4rem; margin:0;"><?php echo (int)$counts['vista']; ?></p>
                </div>
            </div>
        </div>
        <div class="col s12 m3">
            <div class="card blue darken-2 white-text">
                <div class="card-content">
                    <span class="card-title">Apartadas</span>
                    <p style="font-size:2.4rem; margin:0;"><?php echo (int)$counts['apartada']; ?></p>
                </div>
            </div>
        </div>
        <div class="col s12 m3">
            <div class="card green darken-2 white-text">
                <div class="card-content">
                    <span class="card-title">Atendidas</span>
                    <p style="font-size:2.4rem; margin:0;"><?php echo (int)$counts['atendida']; ?></p>
                </div>
            </div>
        </div>
        <div class="col s12 m3">
            <div class="card grey darken-2 white-text">
                <div class="card-content">
                    <span class="card-title">Canceladas</span>
                    <p style="font-size:2.4rem; margin:0;"><?php echo (int)$counts['cancelada']; ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col s12">
            <div class="card">
                <div class="card-content">
                    <form method="GET" class="row" style="margin-bottom:0;">
                        <div class="input-field col s12 m4">
                            <select name="estado" class="browser-default" style="border:1px solid #9e9e9e;">
                                <option value="">Todos los estados</option>
                                <option value="nueva" <?php echo $estadoFiltro === 'nueva' ? 'selected' : ''; ?>>Nueva</option>
                                <option value="vista" <?php echo $estadoFiltro === 'vista' ? 'selected' : ''; ?>>Vista</option>
                                <option value="apartada" <?php echo $estadoFiltro === 'apartada' ? 'selected' : ''; ?>>Apartada</option>
                                <option value="atendida" <?php echo $estadoFiltro === 'atendida' ? 'selected' : ''; ?>>Atendida</option>
                                <option value="cancelada" <?php echo $estadoFiltro === 'cancelada' ? 'selected' : ''; ?>>Cancelada</option>
                            </select>
                        </div>
                        <div class="col s12 m8" style="display:flex; align-items:center; gap:8px; margin-top:8px;">
                            <button type="submit" class="btn indigo waves-effect waves-light">Filtrar</button>
                            <a href="pickup_notifications.php" class="btn-flat">Limpiar</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col s12">
            <div class="card">
                <div class="card-content" style="overflow-x:auto;">
                    <span class="card-title">Listado de Alertas Pickup</span>
                    <?php if (empty($notificaciones)): ?>
                        <p class="center grey-text">No hay notificaciones pickup registradas.</p>
                    <?php else: ?>
                        <table class="striped highlight responsive-table">
                            <thead>
                                <tr>
                                    <th>Pedido</th>
                                    <th>Sucursal</th>
                                    <th>Cliente</th>
                                    <th>Estado</th>
                                    <th>Atraso</th>
                                    <th>Fecha Estimada Reabasto</th>
                                    <th>Seguimiento</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($notificaciones as $n): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc((string)$n['numero_pedido']); ?></strong><br>
                                            <small class="grey-text">$<?php echo number_format((float)$n['total'], 2); ?> | <?php echo esc((string)$n['fecha_pedido']); ?></small>
                                        </td>
                                        <td>
                                            <?php echo esc((string)$n['sucursal']); ?><br>
                                            <small class="grey-text">ID almacén: <?php echo (int)($n['id_almacen'] ?? 0); ?></small>
                                        </td>
                                        <td><?php echo esc((string)($n['cliente'] ?? 'N/A')); ?></td>
                                        <td>
                                            <span class="badge <?php echo $n['estado'] === 'nueva' ? 'deep-orange' : ($n['estado'] === 'vista' ? 'amber darken-2' : ($n['estado'] === 'apartada' ? 'blue darken-2' : ($n['estado'] === 'cancelada' ? 'grey darken-2' : 'green'))); ?> white-text" style="float:none;">
                                                <?php echo strtoupper((string)$n['estado']); ?>
                                            </span>
                                            <?php if (stripos((string)($n['notas_seguimiento'] ?? ''), 'TRASLADO_INTERNO_2_3H') !== false): ?>
                                                <div class="amber lighten-5 amber-text text-darken-4" style="margin-top:6px; padding:6px 8px; border-radius:6px; border-left:4px solid #ff8f00; font-size:.82rem;">
                                                    Requiere traslado interno estimado de 2 a 3 horas.
                                                </div>
                                            <?php endif; ?>
                                            <div style="margin-top:6px; font-size:.82rem;" class="grey-text">
                                                Vista: <?php echo !empty($n['fecha_vista']) ? esc((string)$n['fecha_vista']) : '---'; ?>
                                                <br>
                                                Apartada: <?php echo !empty($n['fecha_apartada']) ? esc((string)$n['fecha_apartada']) : '---'; ?>
                                                <br>
                                                Atendida: <?php echo !empty($n['fecha_atendida']) ? esc((string)$n['fecha_atendida']) : '---'; ?>
                                                <br>
                                                Cancelada: <?php echo !empty($n['fecha_cancelada']) ? esc((string)$n['fecha_cancelada']) : '---'; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php
                                                $minAtraso = max(0, (int)($n['minutos_atraso'] ?? 0));
                                                $hrsAtraso = intdiv($minAtraso, 60);
                                                $minsRest = $minAtraso % 60;
                                                $atrasoTxt = $hrsAtraso . 'h ' . $minsRest . 'm';
                                                $atrasoClass = $minAtraso >= 1440 ? 'red lighten-4 red-text text-darken-4' : ($minAtraso >= 360 ? 'amber lighten-4 amber-text text-darken-4' : 'green lighten-5 green-text text-darken-4');
                                            ?>
                                            <span class="<?php echo $atrasoClass; ?>" style="display:inline-block; padding:4px 8px; border-radius:6px; font-weight:600;">
                                                <?php echo esc($atrasoTxt); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="grey-text">Hora automatica por estatus</span>
                                        </td>
                                        <td>
                                            <div style="display:flex; gap:6px; flex-wrap:wrap; min-width:260px;">
                                                <?php if ((string)$n['estado'] === 'nueva'): ?>
                                                    <form method="POST" style="margin:0;">
                                                        <?php echo csrfInput(); ?>
                                                        <input type="hidden" name="accion" value="marcar_estado">
                                                        <input type="hidden" name="id_notificacion" value="<?php echo (int)$n['id_notificacion']; ?>">
                                                        <input type="hidden" name="estado" value="vista">
                                                        <button type="submit" class="btn-small amber darken-2 waves-effect waves-light">Marcar vista</button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ((string)$n['estado'] === 'vista'): ?>
                                                    <form method="POST" style="margin:0;">
                                                        <?php echo csrfInput(); ?>
                                                        <input type="hidden" name="accion" value="marcar_estado">
                                                        <input type="hidden" name="id_notificacion" value="<?php echo (int)$n['id_notificacion']; ?>">
                                                        <input type="hidden" name="estado" value="apartada">
                                                        <button type="submit" class="btn-small blue darken-2 waves-effect waves-light">Marcar apartada</button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ((string)$n['estado'] === 'apartada'): ?>
                                                    <form method="POST" style="margin:0;">
                                                        <?php echo csrfInput(); ?>
                                                        <input type="hidden" name="accion" value="marcar_estado">
                                                        <input type="hidden" name="id_notificacion" value="<?php echo (int)$n['id_notificacion']; ?>">
                                                        <input type="hidden" name="estado" value="atendida">
                                                        <button type="submit" class="btn-small green darken-2 waves-effect waves-light">Marcar atendida y pagada</button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ($cancelSupportReady && in_array((string)$n['estado'], ['nueva', 'vista', 'apartada'], true)): ?>
                                                    <form method="POST" style="margin:0; display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                                                        <?php echo csrfInput(); ?>
                                                        <input type="hidden" name="accion" value="cancelar_pedido">
                                                        <input type="hidden" name="id_notificacion" value="<?php echo (int)$n['id_notificacion']; ?>">
                                                        <select name="motivo_cancelacion" class="browser-default" style="min-width:220px; max-width:280px; border:1px solid #9e9e9e; height:30px;">
                                                            <option value="" selected disabled>Motivo de cancelacion</option>
                                                            <?php foreach ($cancelReasonOptions as $reasonKey => $reasonLabel): ?>
                                                                <option value="<?php echo esc($reasonKey); ?>"><?php echo esc($reasonLabel); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <input type="text" name="motivo_cancelacion_otro" maxlength="180" placeholder="Si seleccionas Otro, especifica aqui" style="min-width:230px; max-width:320px;" />
                                                        <button type="submit" class="btn-small red darken-2 waves-effect waves-light">Cancelar y resurtir stock</button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ((string)$n['estado'] === 'atendida'): ?>
                                                    <span class="grey-text text-darken-1">Flujo completado.</span>
                                                <?php endif; ?>

                                                <?php if ((string)$n['estado'] === 'cancelada'): ?>
                                                    <span class="grey-text text-darken-1">Pedido cancelado y stock devuelto.</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
