<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/whatsapp_link_utils.php';

requireAuth();
requirePermission('ver_entregas', BASE_URL . 'views/dashboard.php');

$pageTitle = 'Entregas Asignadas';
$pdo = getPDO();
$usuario = $_SESSION['usuario'];
$isAdminView = isAdmin();
$isRepartidorView = isRepartidor();
$isRouteOptimizationAllowed = $isAdminView || $isRepartidorView;
$selectedRepartidorId = $isAdminView ? max(0, (int)($_GET['repartidor_id'] ?? 0)) : (int)($usuario['id_usuario'] ?? 0);
$selectedFechaEntrega = trim((string)($_GET['fecha_entrega'] ?? ''));
// Admin usa el filtro de fecha para planear rutas por dia, por eso su default es "hoy".
// El repartidor necesita ver TODAS sus entregas pendientes por default (incluyendo las
// que se asignaron sin fecha programada), o de lo contrario pedidos con fecha_entrega_programada
// NULL desaparecen del listado aunque el dashboard los siga contando en "Mis Entregas Hoy".
if ($selectedFechaEntrega === '' && $isAdminView) {
    $selectedFechaEntrega = date('Y-m-d');
}
if ($selectedFechaEntrega !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedFechaEntrega)) {
    $selectedFechaEntrega = $isAdminView ? date('Y-m-d') : '';
}
$error = '';
$success = '';
$repartidores = [];
$justDeliveredPedidoId = null;

// Motivos preestablecidos para cuando el repartidor no pudo completar una entrega.
$deliveryCancelReasonOptions = [
    'cliente_no_disponible' => 'Cliente no se encontraba disponible',
    'direccion_incorrecta' => 'Direccion incorrecta o no localizada',
    'cliente_rechazo_pedido' => 'Cliente rechazo el pedido',
    'cliente_no_pudo_pagar' => 'Cliente no pudo pagar / sin efectivo',
    'producto_danado' => 'Producto danado en el trayecto',
    'zona_insegura' => 'Zona insegura / acceso restringido',
    'clima_transito' => 'Clima o transito impidieron la entrega',
    'otro' => 'Otro',
];

// Procesar cambio de estado de reparto
if ($isRepartidorView && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'], $_POST['id_pedido'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF invalido.';
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
                // Confirma entrega y cobro simultaneamente (pago contra entrega)
                $stmt = $pdo->prepare("UPDATE pedidos SET estado = 'entregado', fecha_entrega = NOW(), fecha_pago = NOW() WHERE id_pedido = ? AND id_repartidor = ? AND estado IN ('pendiente_pago','pagado','en_reparto')");
                $stmt->execute([$id_pedido, $usuario['id_usuario']]);
                if ($stmt->rowCount() > 0) {
                    logAudit('PEDIDO_ENTREGADO', 'pedidos', $id_pedido, 'Pedido marcado como entregado y pagado por repartidor');
                    $success = 'Pedido entregado y cobrado correctamente.';
                    $justDeliveredPedidoId = $id_pedido;
                }
            } catch (PDOException $e) {
                $error = 'Error al actualizar el pedido.';
            }
        }

        if ($_POST['accion'] === 'cancelar_entrega') {
            $motivoKey = trim((string)($_POST['motivo_cancelacion'] ?? ''));
            $motivoOtro = trim((string)($_POST['motivo_cancelacion_otro'] ?? ''));

            if (!isset($deliveryCancelReasonOptions[$motivoKey])) {
                $error = 'Selecciona un motivo valido para cancelar la entrega.';
            } elseif ($motivoKey === 'otro' && $motivoOtro === '') {
                $error = 'Escribe el motivo cuando selecciones "Otro".';
            } else {
                $motivoEtiqueta = $motivoKey === 'otro' ? mb_substr($motivoOtro, 0, 180) : $deliveryCancelReasonOptions[$motivoKey];

                $pdo->beginTransaction();
                try {
                    $stmtPedido = $pdo->prepare("SELECT estado, id_almacen FROM pedidos WHERE id_pedido = :id_pedido AND id_repartidor = :id_repartidor FOR UPDATE");
                    $stmtPedido->execute([':id_pedido' => $id_pedido, ':id_repartidor' => $usuario['id_usuario']]);
                    $pedido = $stmtPedido->fetch(PDO::FETCH_ASSOC) ?: null;

                    if (!$pedido) {
                        throw new RuntimeException('No se encontro el pedido o no esta asignado a ti.');
                    }

                    $estadoPedido = (string)($pedido['estado'] ?? '');
                    if (!in_array($estadoPedido, ['pendiente_pago', 'pagado', 'en_reparto'], true)) {
                        throw new RuntimeException('Este pedido ya no se puede cancelar (estado actual: ' . strtoupper($estadoPedido) . ').');
                    }

                    $idAlmacenPedido = (int)($pedido['id_almacen'] ?? 0);

                    $stmtDetalle = $pdo->prepare("SELECT id_producto, cantidad FROM detalle_pedidos WHERE id_pedido = :id_pedido AND cantidad > 0 FOR UPDATE");
                    $stmtDetalle->execute([':id_pedido' => $id_pedido]);
                    $items = $stmtDetalle->fetchAll(PDO::FETCH_ASSOC);

                    $stmtResurtir = $pdo->prepare("INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual, stock_minimo, stock_maximo)
                                                   VALUES (:id_producto, :id_almacen, :cantidad, 2, 5)
                                                   ON DUPLICATE KEY UPDATE cantidad_actual = cantidad_actual + VALUES(cantidad_actual)");
                    // Deja rastro de auditoria del movimiento, igual que 'salida' se registra al vender (api/ventas.php).
                    $stmtMovEntrada = $pdo->prepare(
                        "INSERT INTO movimientos_inventario (id_producto, tipo_movimiento, id_almacen_destino, cantidad, id_usuario, observacion)
                         VALUES (:producto, 'entrada', :almacen, :cantidad, :usuario, :observacion)"
                    );
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
                        $stmtMovEntrada->execute([
                            ':producto' => $idProducto,
                            ':almacen' => $idAlmacenPedido,
                            ':cantidad' => $cantidad,
                            ':usuario' => $usuario['id_usuario'],
                            ':observacion' => 'Entrega no realizada, pedido #' . $id_pedido . ' cancelado por repartidor',
                        ]);
                    }

                    $stmtCancelar = $pdo->prepare("UPDATE pedidos
                                                    SET estado = 'cancelado',
                                                        observaciones = CONCAT(COALESCE(observaciones, ''),
                                                            ' | ENTREGA_NO_REALIZADA: ', :motivo)
                                                    WHERE id_pedido = :id_pedido AND id_repartidor = :id_repartidor");
                    $stmtCancelar->execute([
                        ':motivo' => $motivoEtiqueta,
                        ':id_pedido' => $id_pedido,
                        ':id_repartidor' => $usuario['id_usuario'],
                    ]);

                    $pdo->commit();
                    $success = 'Entrega cancelada. El stock fue devuelto al inventario de la sucursal.';
                    logAudit('PEDIDO_ENTREGA_CANCELADA_REPARTIDOR', 'pedidos', $id_pedido, 'Repartidor no pudo entregar. Motivo: ' . $motivoEtiqueta);
                } catch (Throwable $txe) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $error = $txe->getMessage();
                }
            }
        }
    }
}

// Obtener entregas pendientes para vista de reparto
try {
    $hasClientesDireccion = false;
    $hasClientesUbicacionMapa = false;
    $hasClienteDireccionesTable = false;
    $hasPedidosDireccionEntrega = false;
    $hasPedidosTelefonoEntrega = false;
    $hasPedidosMapsLinkEntrega = false;
    $hasFechaLimiteEntrega = false;
    $hasPrioridadEntrega = false;

    $stmtMeta = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'direccion'");
    $stmtMeta->execute();
    $hasClientesDireccion = ((int)$stmtMeta->fetchColumn()) > 0;

    $stmtMeta = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'ubicacion_mapa'");
    $stmtMeta->execute();
    $hasClientesUbicacionMapa = ((int)$stmtMeta->fetchColumn()) > 0;

    $stmtMeta = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cliente_direcciones'");
    $stmtMeta->execute();
    $hasClienteDireccionesTable = ((int)$stmtMeta->fetchColumn()) > 0;

    $hasClienteDireccionesLatitud = false;
    $hasClienteDireccionesLongitud = false;
    if ($hasClienteDireccionesTable) {
        $stmtMeta = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cliente_direcciones' AND COLUMN_NAME = 'latitud'");
        $stmtMeta->execute();
        $hasClienteDireccionesLatitud = ((int)$stmtMeta->fetchColumn()) > 0;

        $stmtMeta = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cliente_direcciones' AND COLUMN_NAME = 'longitud'");
        $stmtMeta->execute();
        $hasClienteDireccionesLongitud = ((int)$stmtMeta->fetchColumn()) > 0;
    }

    $stmtMeta = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'direccion_entrega'");
    $stmtMeta->execute();
    $hasPedidosDireccionEntrega = ((int)$stmtMeta->fetchColumn()) > 0;

    $stmtMeta = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'telefono_entrega'");
    $stmtMeta->execute();
    $hasPedidosTelefonoEntrega = ((int)$stmtMeta->fetchColumn()) > 0;

    $stmtMeta = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'maps_link_entrega'");
    $stmtMeta->execute();
    $hasPedidosMapsLinkEntrega = ((int)$stmtMeta->fetchColumn()) > 0;

    $stmtMeta = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'fecha_limite_entrega'");
    $stmtMeta->execute();
    $hasFechaLimiteEntrega = ((int)$stmtMeta->fetchColumn()) > 0;

    $stmtMeta = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'prioridad_entrega'");
    $stmtMeta->execute();
    $hasPrioridadEntrega = ((int)$stmtMeta->fetchColumn()) > 0;

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

    if ($hasPedidosMapsLinkEntrega) {
        $fallbackMap = $hasClientesUbicacionMapa && $hasClienteDireccionesTable
            ? "COALESCE(c.ubicacion_mapa, (SELECT cd.maps_link FROM cliente_direcciones cd WHERE cd.id_cliente = c.id_cliente ORDER BY cd.es_default DESC, cd.id_direccion ASC LIMIT 1))"
            : ($hasClientesUbicacionMapa
                ? 'c.ubicacion_mapa'
                : ($hasClienteDireccionesTable
                    ? "(SELECT cd.maps_link FROM cliente_direcciones cd WHERE cd.id_cliente = c.id_cliente ORDER BY cd.es_default DESC, cd.id_direccion ASC LIMIT 1)"
                    : 'NULL'));
        $mapExpr = "COALESCE(NULLIF(TRIM(p.maps_link_entrega), ''), {$fallbackMap}) AS ubicacion_mapa";
    } elseif ($hasClientesUbicacionMapa && $hasClienteDireccionesTable) {
        $mapExpr = "COALESCE(c.ubicacion_mapa, (SELECT cd.maps_link FROM cliente_direcciones cd WHERE cd.id_cliente = c.id_cliente ORDER BY cd.es_default DESC, cd.id_direccion ASC LIMIT 1)) AS ubicacion_mapa";
    } elseif ($hasClientesUbicacionMapa) {
        $mapExpr = "c.ubicacion_mapa AS ubicacion_mapa";
    } elseif ($hasClienteDireccionesTable) {
        $mapExpr = "(SELECT cd.maps_link FROM cliente_direcciones cd WHERE cd.id_cliente = c.id_cliente ORDER BY cd.es_default DESC, cd.id_direccion ASC LIMIT 1) AS ubicacion_mapa";
    } else {
        $mapExpr = "NULL AS ubicacion_mapa";
    }

    $telefonoExpr = $hasPedidosTelefonoEntrega
        ? "COALESCE(NULLIF(TRIM(p.telefono_entrega), ''), c.telefono) AS telefono"
        : 'c.telefono AS telefono';

    $fechaLimiteExpr = $hasFechaLimiteEntrega ? 'p.fecha_limite_entrega AS fecha_limite_entrega' : 'NULL AS fecha_limite_entrega';
    $prioridadExpr = $hasPrioridadEntrega ? 'p.prioridad_entrega AS prioridad_entrega' : '0 AS prioridad_entrega';

    // Igual que direccion/mapa: si el pedido no trae coordenadas propias (ventas agendadas
    // antes de que api/ventas.php empezara a copiarlas), se heredan de la direccion guardada
    // del cliente en vez de bloquear la casilla "Agregar a ruta" por siempre.
    $latitudExpr = $hasClienteDireccionesLatitud
        ? "COALESCE(p.latitud, (SELECT cd.latitud FROM cliente_direcciones cd WHERE cd.id_cliente = c.id_cliente ORDER BY cd.es_default DESC, cd.id_direccion ASC LIMIT 1)) AS latitud"
        : 'p.latitud AS latitud';
    $longitudExpr = $hasClienteDireccionesLongitud
        ? "COALESCE(p.longitud, (SELECT cd.longitud FROM cliente_direcciones cd WHERE cd.id_cliente = c.id_cliente ORDER BY cd.es_default DESC, cd.id_direccion ASC LIMIT 1)) AS longitud"
        : 'p.longitud AS longitud';

    $stmtMetaPickup = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pickup_notificaciones'");
    $stmtMetaPickup->execute();
    $hasPickupNotificacionesTable = ((int)$stmtMetaPickup->fetchColumn()) > 0;
    // Defensa adicional: un pedido con notificacion pickup nunca deberia mostrarse como
    // entrega a domicilio, sin importar si quedo mal asignado por datos historicos.
    $notPickupFilter = $hasPickupNotificacionesTable
        ? " AND NOT EXISTS (SELECT 1 FROM pickup_notificaciones pn WHERE pn.id_pedido = p.id_pedido)"
        : '';

        $sql = "SELECT p.*, p.observaciones,
                   c.nombre as cliente, {$direccionExpr}, {$telefonoExpr}, {$mapExpr},
                   {$fechaLimiteExpr}, {$prioridadExpr},
                   ur.nombre AS repartidor_nombre,
                   {$latitudExpr}, {$longitudExpr}
            FROM pedidos p
            LEFT JOIN clientes c ON p.id_cliente = c.id_cliente
            LEFT JOIN usuarios ur ON p.id_repartidor = ur.id_usuario
            WHERE p.estado IN ('pendiente_pago','pagado','en_reparto')
              AND p.id_repartidor IS NOT NULL{$notPickupFilter}";

    $params = [];
    if ($isAdminView) {
        if ($selectedRepartidorId > 0) {
            $sql .= ' AND p.id_repartidor = :repartidor';
            $params[':repartidor'] = $selectedRepartidorId;
        }
        if ($selectedFechaEntrega !== '') {
            $sql .= ' AND DATE(p.fecha_entrega_programada) = :fecha_entrega';
            $params[':fecha_entrega'] = $selectedFechaEntrega;
        }
        $sql .= ' ORDER BY p.id_repartidor ASC, p.fecha_entrega_programada ASC, p.fecha_creacion DESC';
    } else {
        $sql .= ' AND p.id_repartidor = :repartidor';
        $params[':repartidor'] = (int)($usuario['id_usuario'] ?? 0);
        if ($selectedFechaEntrega !== '') {
            $sql .= ' AND DATE(p.fecha_entrega_programada) = :fecha_entrega';
            $params[':fecha_entrega'] = $selectedFechaEntrega;
        }
        $sql .= ' ORDER BY p.fecha_entrega_programada ASC, p.fecha_creacion DESC';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $entregas = $stmt->fetchAll();

    if ($isAdminView) {
        $sqlRep = "SELECT u.id_usuario, u.nombre
                   FROM usuarios u
                   INNER JOIN roles r ON u.id_rol = r.id_rol
                   WHERE r.nombre = 'repartidor' AND u.estado = 'activo'
                   ORDER BY u.nombre ASC";
        $repartidores = $pdo->query($sqlRep)->fetchAll();
    }

    $detallesPorPedido = [];
    if (!empty($entregas)) {
        $idsPedidos = array_values(array_unique(array_map(static fn($row): int => (int)$row['id_pedido'], $entregas)));
        $placeholders = implode(',', array_fill(0, count($idsPedidos), '?'));
        $sqlDetalles = "SELECT dp.id_pedido, dp.cantidad, p.nombre, p.nombre_variante
                        FROM detalle_pedidos dp
                        JOIN productos p ON dp.id_producto = p.id_producto
                        WHERE dp.id_pedido IN ($placeholders)
                        ORDER BY dp.id_pedido ASC, dp.id_detalle ASC";
        $stmtDetalles = $pdo->prepare($sqlDetalles);
        $stmtDetalles->execute($idsPedidos);
        foreach ($stmtDetalles->fetchAll() as $detalle) {
            $pedidoId = (int)$detalle['id_pedido'];
            if (!isset($detallesPorPedido[$pedidoId])) {
                $detallesPorPedido[$pedidoId] = [];
            }
            $detallesPorPedido[$pedidoId][] = $detalle;
        }
    }
} catch (PDOException $e) {
    $error = 'Error al obtener entregas: ' . $e->getMessage();
    $entregas = [];
    $detallesPorPedido = [];
}

include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="col s12">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 20px; flex-wrap: wrap; gap: 10px;">
                <h4 style="margin: 0;"><?php echo $isAdminView ? 'Entregas Asignadas por Repartidor' : 'Mis Entregas Asignadas'; ?></h4>
                <a href="dashboard.php" class="btn blue darken-4 waves-effect waves-light"><i class="material-icons left">dashboard</i> Volver al Dashboard</a>
            </div>
            <?php if ($isAdminView): ?>
                <p class="grey-text">Desde aqui puedes visualizar y optimizar rutas de repartidores asignados.</p>
            <?php else: ?>
                <p class="grey-text">Aqui aparecen los pedidos asignados para el dia seleccionado.</p>
            <?php endif; ?>

            <?php if ($isAdminView): ?>
                <div class="card" style="margin-top: 12px;">
                    <div class="card-content" style="padding-bottom: 10px;">
                        <span class="card-title" style="font-size: 1.1rem;">Filtrar pedidos asignados</span>
                        <form method="GET" style="display:flex; gap:8px; flex-wrap:wrap; align-items:end;">
                            <div style="min-width:240px; flex:1;">
                                <label for="repartidor_id" class="active">Repartidor</label>
                                <select id="repartidor_id" name="repartidor_id" class="browser-default">
                                    <option value="0" <?php echo $selectedRepartidorId === 0 ? 'selected' : ''; ?>>Todos los repartidores</option>
                                    <?php foreach ($repartidores as $rep): ?>
                                        <?php $repId = (int)$rep['id_usuario']; ?>
                                        <option value="<?php echo $repId; ?>" <?php echo $repId === $selectedRepartidorId ? 'selected' : ''; ?>>
                                            <?php echo esc((string)$rep['nombre']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div style="min-width:220px; flex:1;">
                                <label for="fecha_entrega" class="active">Dia de entrega</label>
                                <input id="fecha_entrega" name="fecha_entrega" type="date" value="<?php echo esc($selectedFechaEntrega); ?>">
                            </div>
                            <button type="submit" class="btn indigo waves-effect waves-light">Aplicar filtro</button>
                            <a href="?" class="btn-flat waves-effect">Limpiar</a>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div class="card" style="margin-top: 12px;">
                    <div class="card-content" style="padding-bottom: 10px;">
                        <span class="card-title" style="font-size: 1.1rem;">Filtrar por dia</span>
                        <p class="grey-text" style="margin:4px 0 12px; font-size:0.85rem;">
                            Sin fecha seleccionada se muestran <strong>todas</strong> tus entregas pendientes, incluyendo las que se asignaron sin dia programado.
                        </p>
                        <form method="GET" style="display:flex; gap:8px; flex-wrap:wrap; align-items:end;">
                            <div style="min-width:220px; flex:1;">
                                <label for="fecha_entrega" class="active">Dia de entrega (opcional)</label>
                                <input id="fecha_entrega" name="fecha_entrega" type="date" value="<?php echo esc($selectedFechaEntrega); ?>">
                            </div>
                            <button type="submit" class="btn indigo waves-effect waves-light">Aplicar filtro</button>
                            <a href="?fecha_entrega=<?php echo esc(urlencode(date('Y-m-d'))); ?>" class="btn-flat waves-effect">Hoy</a>
                            <a href="?fecha_entrega=" class="btn-flat waves-effect">Ver todas</a>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="card green lighten-4 green-text text-darken-4" style="padding: 10px;">
            <i class="material-icons left">check_circle</i> <?php echo esc($success); ?>
            <?php if ($justDeliveredPedidoId): ?>
                <a href="#modal-entrega-publicacion" id="ep-btn-abrir-modal" class="btn green darken-3 waves-effect waves-light modal-trigger" style="margin-left: 10px;">
                    <i class="material-icons left">campaign</i> Publicar esta entrega
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="card red lighten-4 red-text text-darken-4" style="padding: 10px;">
            <i class="material-icons left">error</i> <?php echo esc($error); ?>
        </div>
    <?php endif; ?>

    <?php if ($isRouteOptimizationAllowed && !empty($entregas)): ?>
        <div class="row">
            <div class="col s12">
                <div class="card" id="route-planner-card">
                    <div class="card-content">
                        <span class="card-title">Optimizacion de Ruta de Entrega</span>
                        <p class="grey-text" style="margin-top:0;">Selecciona pedidos, define origen y genera una ruta optimizada para Google Maps.</p>
                        <p id="route-location-status" class="blue-text text-darken-2" style="margin:0 0 10px 0; font-size:0.9rem;"></p>

                        <div class="row" style="margin-bottom:6px;">
                            <div class="input-field col s12 m3">
                                <input id="route-origin-lat" type="number" step="0.00000001" placeholder="20.65969880">
                                <label for="route-origin-lat" class="active">Latitud origen</label>
                            </div>
                            <div class="input-field col s12 m3">
                                <input id="route-origin-lng" type="number" step="0.00000001" placeholder="-103.34960920">
                                <label for="route-origin-lng" class="active">Longitud origen</label>
                            </div>
                            <div class="input-field col s12 m3">
                                <input id="route-start-time" type="text" placeholder="09:30" inputmode="numeric" pattern="^(?:[0-9]|0[0-9]|1[0-2]):[0-5][0-9]$">
                                <label for="route-start-time" class="active">Hora de salida (editable)</label>
                            </div>
                            <div class="input-field col s12 m2">
                                <select id="route-start-meridiem" class="browser-default">
                                    <option value="AM">AM</option>
                                    <option value="PM">PM</option>
                                </select>
                                <label for="route-start-meridiem" class="active">AM/PM</label>
                            </div>
                            <div class="col s12 m4" style="display:flex; align-items:flex-end; gap:8px; flex-wrap:wrap;">
                                <button type="button" class="btn-flat waves-effect route-location-btn" id="route-use-location">
                                    <i class="material-icons left">my_location</i> Usar mi ubicacion
                                </button>
                                <button type="button" class="btn blue-grey darken-1 waves-effect waves-light" id="route-toggle-all">Seleccionar todo</button>
                                <button type="button" class="btn amber darken-3 waves-effect waves-light" id="btn-generate-route">
                                    <i class="material-icons left">alt_route</i>Generar ruta
                                </button>
                            </div>
                        </div>

                        <div id="route-location-guide" class="card-panel amber lighten-5" style="display:none; margin-top:6px;">
                            <strong>Se necesita habilitar ubicacion</strong>
                            <p style="margin:6px 0 0 0;">Si bloqueaste el permiso, habilitalo en el navegador y vuelve a intentar. Tambien puedes capturar latitud/longitud manualmente para continuar.</p>
                            <ul style="margin:8px 0 0 18px;">
                                <li>Android Chrome: candado en la barra -> Permisos -> Ubicacion -> Permitir.</li>
                                <li>iPhone Safari: Configuracion -> Safari -> Ubicacion -> Permitir.</li>
                                <li>Desktop: revisa permisos del navegador y de ubicacion del sistema operativo.</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <div id="modal-route-error" class="modal">
                    <div class="modal-content">
                        <h5><i class="material-icons left red-text text-darken-2">event_busy</i>No se puede generar la ruta</h5>
                        <p id="modal-route-error-text"></p>
                    </div>
                    <div class="modal-footer">
                        <a href="#!" class="modal-close waves-effect waves-light btn red darken-2">Entendido</a>
                    </div>
                </div>

                <div class="card" id="route-result-card" style="display:none;">
                    <div class="card-content" id="route-result-content"></div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row">
        <?php if (empty($entregas)): ?>
            <div class="col s12 center-align" style="padding: 50px;">
                <i class="material-icons grey-text" style="font-size: 5rem;">local_shipping</i>
                <p class="grey-text"><?php echo $isAdminView ? 'No hay entregas asignadas con este filtro.' : 'No tienes entregas pendientes por ahora.'; ?></p>
            </div>
        <?php else: ?>
            <?php foreach ($entregas as $ent): ?>
                <?php
                    $obs = $ent['observaciones'] ?? '';
                    $clienteNombre = $ent['cliente'] ?? null;
                    $clienteTel = $ent['telefono'] ?? null;
                    $clienteDir = $ent['direccion'] ?? null;
                    $clienteMapas = $ent['ubicacion_mapa'] ?? null;
                    $obsClienteNombre = null;
                    $obsClienteTel = null;
                    $obsClienteDir = null;

                    if ($obs) {
                        if (preg_match('/Cliente:\s*([^|]+)/i', $obs, $m)) {
                            $obsClienteNombre = trim($m[1]);
                        }
                        if (preg_match('/Tel:\s*([^|]+)/i', $obs, $m)) {
                            $obsClienteTel = trim($m[1]);
                        }
                        if (preg_match('/Dir:\s*(.+)/i', $obs, $m)) {
                            $obsClienteDir = trim($m[1]);
                        }

                        if (!$clienteNombre && $obsClienteNombre) {
                            $clienteNombre = $obsClienteNombre;
                        }
                        if (!$clienteDir && $obsClienteDir) {
                            $clienteDir = $obsClienteDir;
                        }
                    }

                    if (is_string($clienteNombre) && $clienteNombre !== ''
                        && function_exists('piiIsEncryptedValue')
                        && function_exists('piiDecryptValue')
                        && piiIsEncryptedValue($clienteNombre)) {
                        $clienteNombre = (string)piiDecryptValue($clienteNombre);
                    }

                    if (is_string($clienteTel) && $clienteTel !== ''
                        && function_exists('piiIsEncryptedValue')
                        && function_exists('piiDecryptValue')
                        && piiIsEncryptedValue($clienteTel)) {
                        $clienteTel = (string)piiDecryptValue($clienteTel);
                    }

                    // Si el valor sigue cifrado/no util, usar telefono de observaciones como fallback.
                    if ((is_string($clienteTel) && $clienteTel !== ''
                        && function_exists('piiIsEncryptedValue')
                        && piiIsEncryptedValue($clienteTel)) || !is_string($clienteTel) || trim((string)$clienteTel) === '') {
                        if (is_string($obsClienteTel) && trim($obsClienteTel) !== '') {
                            $clienteTel = trim($obsClienteTel);
                            if (function_exists('piiIsEncryptedValue')
                                && function_exists('piiDecryptValue')
                                && piiIsEncryptedValue($clienteTel)) {
                                $clienteTel = (string)piiDecryptValue($clienteTel);
                            }
                        }
                    }

                    // Nunca mostrar ciphertext ENCv1 en UI.
                    if (is_string($clienteTel)
                        && $clienteTel !== ''
                        && function_exists('piiIsEncryptedValue')
                        && piiIsEncryptedValue($clienteTel)) {
                        $clienteTel = '';
                    }

                    if (is_string($clienteMapas) && $clienteMapas !== ''
                        && function_exists('piiIsEncryptedValue')
                        && function_exists('piiDecryptValue')
                        && piiIsEncryptedValue($clienteMapas)) {
                        $clienteMapas = (string)piiDecryptValue($clienteMapas);
                    }

                    if (is_string($clienteDir) && $clienteDir !== ''
                        && function_exists('piiIsEncryptedValue')
                        && function_exists('piiDecryptValue')
                        && piiIsEncryptedValue($clienteDir)) {
                        $clienteDir = (string)piiDecryptValue($clienteDir);
                    }

                    $clienteMapas = is_string($clienteMapas) ? trim($clienteMapas) : '';
                    $clienteDir = is_string($clienteDir) ? trim($clienteDir) : '';
                    $clienteNombre = is_string($clienteNombre) ? trim($clienteNombre) : '';
                    $clienteTel = is_string($clienteTel) ? trim($clienteTel) : '';

                    if ($clienteMapas !== '' && filter_var($clienteMapas, FILTER_VALIDATE_URL)) {
                        $mapsUrl = $clienteMapas;
                    } elseif ($clienteDir !== '') {
                        $mapsUrl = 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($clienteDir);
                    } else {
                        $mapsUrl = null;
                    }

                    $telLimpio = preg_replace('/\D/', '', (string)$clienteTel);
                    $prioridadPedido = (int)($ent['prioridad_entrega'] ?? 0);
                    $prioridadTextos = [0 => 'Normal', 1 => 'Importante', 2 => 'Urgente', 3 => 'Critica'];
                    $prioridadClases = [0 => 'grey', 1 => 'blue', 2 => 'orange', 3 => 'red'];
                    $prioridadLabel = $prioridadTextos[$prioridadPedido] ?? 'Normal';
                    $prioridadClass = $prioridadClases[$prioridadPedido] ?? 'grey';
                    $tieneCoordenadas = ($ent['latitud'] !== null && $ent['longitud'] !== null);
                ?>
                <div class="col s12 m6">
                    <div class="card hoverable border-delivery">
                        <div class="card-content">
                            <?php if ($isRouteOptimizationAllowed): ?>
                                                            <label class="route-select-wrap">
                                                                <input
                                                                    type="checkbox"
                                                                    class="filled-in route-check"
                                                                    value="<?php echo (int)$ent['id_pedido']; ?>"
                                                                    data-repartidor-id="<?php echo (int)$ent['id_repartidor']; ?>"
                                                                    data-fecha-entrega="<?php echo esc($ent['fecha_entrega_programada'] ? date('Y-m-d', strtotime((string)$ent['fecha_entrega_programada'])) : ''); ?>"
                                                                    <?php if (!$tieneCoordenadas): ?>
                                                                    disabled
                                                                    title="Este pedido no tiene coordenadas de ubicacion"
                                                                    <?php endif; ?>
                                                                />
                                                                <span>Agregar a ruta</span>
                                                                <?php if (!$tieneCoordenadas): ?>
                                                                    <span class="red-text" style="font-size:0.75rem; margin-left:4px;">(sin coordenadas)</span>
                                                                <?php endif; ?>
                                                            </label>
                                                        <?php endif; ?>

                            <span class="card-title indigo-text"><strong><?php echo esc($ent['numero_pedido']); ?></strong></span>
                            <?php if ($isAdminView): ?>
                                <p class="grey-text" style="margin:0 0 6px 0;">
                                    <i class="material-icons tiny">person_pin</i>
                                    Repartidor: <strong><?php echo esc((string)($ent['repartidor_nombre'] ?? 'Sin nombre')); ?></strong>
                                </p>
                            <?php endif; ?>
                            <div class="divider"></div>
                            
                        <div class="section-info">
                            <p>
                                <i class="material-icons tiny indigo-text">person</i>
                                <strong>Cliente:</strong>
                                <span class="delivery-value"><?php echo esc($clienteNombre !== '' ? $clienteNombre : 'N/A'); ?></span>
                            </p>
                            <p>
                                <i class="material-icons tiny indigo-text">phone</i>
                                <strong>Telefono:</strong>
                                <span class="delivery-value"></span>
                                <?php if ($clienteTel && $clienteTel !== 'N/A'): ?>
                                    <a href="tel:<?php echo esc($telLimpio); ?>" class="indigo-text"><?php echo esc($clienteTel); ?></a>
                                    &nbsp;
                                    <?php $waPhoneEntrega = waBuildBusinessLinkPhone($clienteTel); ?>
                                    <a href="https://wa.me/<?php echo esc($waPhoneEntrega); ?>" target="_blank" class="green-text whatsapp-business-link" data-wa-phone="<?php echo esc($waPhoneEntrega); ?>" style="font-size:0.85rem;">
                                        <i class="material-icons tiny">chat</i> WhatsApp
                                    </a>
                                <?php else: ?>
                                    <span class="grey-text">No disponible</span>
                                <?php endif; ?>
                            </p>
                            <p>
                                <i class="material-icons tiny indigo-text">place</i>
                                <strong>Direccion:</strong>
                                <span class="delivery-value"><?php echo esc($clienteDir !== '' ? $clienteDir : 'No especificada'); ?></span>
                            </p>

                            <p>
                                <i class="material-icons tiny indigo-text">flag</i>
                                <strong>Prioridad:</strong>
                                <span class="new badge <?php echo esc($prioridadClass); ?>" data-badge-caption=""><?php echo esc($prioridadLabel); ?></span>
                            </p>
                            <?php if (!empty($ent['fecha_limite_entrega'])): ?>
                                <p class="red-text text-darken-2">
                                    <i class="material-icons tiny">schedule</i>
                                    <strong>Llegar antes de:</strong> <?php echo date('d/m/Y H:i', strtotime((string)$ent['fecha_limite_entrega'])); ?>
                                </p>
                            <?php endif; ?>

                            <?php if ($mapsUrl): ?>
                                <div style="margin-top: 12px;">
                                    <a href="<?php echo esc($mapsUrl); ?>" target="_blank"
                                       class="btn waves-effect waves-light blue darken-2"
                                       style="width:100%; text-align:center;">
                                        <i class="material-icons left">navigation</i> Abrir Navegacion
                                    </a>
                                </div>
                            <?php else: ?>
                                <p class="grey-text" style="font-size:0.85rem; margin-top:8px;">
                                    <i class="material-icons tiny">info</i> Sin coordenadas de mapa registradas.
                                </p>
                            <?php endif; ?>
                        </div>

                            <div class="section-products grey lighten-4" style="padding: 10px; margin-top: 15px; border-radius: 4px;">
                                <h6><strong>Productos a llevar:</strong></h6>
                                <ul style="margin: 0; padding-left: 20px;">
                                    <?php foreach (($detallesPorPedido[(int)$ent['id_pedido']] ?? []) as $d): ?>
                                        <?php $pName = $d['nombre'] . ($d['nombre_variante'] ? " - " . $d['nombre_variante'] : ""); ?>
                                        <li><?php echo $d['cantidad']; ?>x <?php echo esc($pName); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            
                            <?php if ($ent['fecha_entrega_programada']): ?>
                                <p class="orange-text" style="margin-top: 10px;">
                                    <i class="material-icons tiny">event</i> Programado para: <?php echo date('d/m/Y H:i', strtotime($ent['fecha_entrega_programada'])); ?>
                                </p>
                            <?php else: ?>
                                <p class="grey-text" style="margin-top: 10px;">
                                    <i class="material-icons tiny">event_busy</i> Sin dia programado
                                </p>
                            <?php endif; ?>
                        </div>
                        <div class="card-action center-align">
                            <?php if ($isRepartidorView): ?>
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
                                        <button type="submit" class="btn orange darken-3 waves-effect waves-light w-100" onclick="return confirm('Salir a entregar este pedido?')">
                                            SALIR A ENTREGAR <i class="material-icons right">local_shipping</i>
                                        </button>
                                    <?php else: ?>
                                        <input type="hidden" name="accion" value="entregar">
                                        <p class="orange-text" style="font-size:0.85rem; margin-bottom:8px;">
                                            <i class="material-icons tiny">attach_money</i> Cobrar al entregar: <strong>$<?php echo number_format((float)$ent['total'], 2); ?></strong>
                                        </p>
                                        <button type="submit" class="btn green waves-effect waves-light w-100" onclick="return confirm('Confirmar entrega y cobro del pedido?')">
                                            ENTREGADO Y COBRADO <i class="material-icons right">done_all</i>
                                        </button>
                                    <?php endif; ?>
                                </form>

                                <button type="button" class="btn-flat red-text waves-effect toggle-cancel-entrega w-100" data-target="cancel-entrega-<?php echo (int)$ent['id_pedido']; ?>" style="margin-top:6px;">
                                    <i class="material-icons left">cancel</i> No pude entregar
                                </button>
                                <form method="POST" id="cancel-entrega-<?php echo (int)$ent['id_pedido']; ?>" class="cancel-entrega-form" data-cancel-form="1" style="display:none; margin-top:10px; text-align:left;">
                                    <?php echo csrfInput(); ?>
                                    <input type="hidden" name="id_pedido" value="<?php echo $ent['id_pedido']; ?>">
                                    <input type="hidden" name="accion" value="cancelar_entrega">
                                    <label class="active" style="font-size:0.78rem; color:#546e7a;">Motivo por el que no se pudo entregar</label>
                                    <select name="motivo_cancelacion" data-cancel-reason="1" class="browser-default" required style="margin-bottom:8px; height:40px;">
                                        <option value="" selected disabled>-- Selecciona un motivo --</option>
                                        <?php foreach ($deliveryCancelReasonOptions as $reasonKey => $reasonLabel): ?>
                                            <option value="<?php echo esc($reasonKey); ?>"><?php echo esc($reasonLabel); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <input type="text" name="motivo_cancelacion_otro" data-cancel-other="1" maxlength="180" placeholder="Especifica el motivo" style="display:none; width:100%; height:40px; margin-bottom:8px; padding:0 10px; border:1px solid #cfd8dc; border-radius:4px; box-sizing:border-box;">
                                    <button type="submit" class="btn red darken-2 waves-effect waves-light w-100" onclick="return confirm('Confirmar que no se pudo entregar este pedido? Se cancelara la venta y se devolvera el stock al inventario.')">
                                        CONFIRMAR CANCELACION <i class="material-icons right">cancel</i>
                                    </button>
                                </form>
                            <?php else: ?>
                                <p class="grey-text" style="margin: 0; font-size: 0.9rem;">Vista administrativa: solo planeacion de ruta.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($isRepartidorView): ?>
<div id="modal-entrega-publicacion" class="modal">
    <div class="modal-content">
        <h5><i class="material-icons left">campaign</i> Publicar esta entrega</h5>
        <p class="grey-text" id="ep-colonia-info" style="min-height: 1.2em;"></p>
        <div class="row" style="margin-bottom: 0;">
            <div class="col s12">
                <textarea id="ep-texto" class="materialize-textarea" maxlength="500" style="min-height: 90px;"></textarea>
                <label for="ep-texto" class="active">Texto de la publicacion (editable)</label>
            </div>
        </div>
        <div class="file-field input-field">
            <div class="btn blue darken-2">
                <span>Foto</span>
                <input type="file" id="ep-foto-input" accept="image/*" capture="environment">
            </div>
            <div class="file-path-wrapper">
                <input class="file-path validate" type="text" placeholder="Selecciona o toma una foto de la entrega">
            </div>
        </div>
        <div class="center-align" style="margin: 10px 0;">
            <img id="ep-foto-preview" src="" alt="Vista previa" style="display:none; max-width: 100%; max-height: 260px; border-radius: 6px;">
        </div>
        <p id="ep-status" class="center-align" style="min-height: 1.2em;"></p>
    </div>
    <div class="modal-footer">
        <a href="#!" id="ep-btn-omitir" class="modal-close waves-effect btn-flat">Omitir</a>
        <a href="#!" id="ep-btn-compartir" class="waves-effect waves-light btn green">
            <i class="material-icons left">share</i> Compartir
        </a>
        <a href="#!" id="ep-btn-facebook" class="waves-effect waves-light btn blue darken-3">
            <i class="material-icons left">facebook</i> Publicar en Facebook
        </a>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.toggle-cancel-entrega').forEach((btn) => {
        btn.addEventListener('click', () => {
            const targetId = btn.getAttribute('data-target');
            const target = targetId ? document.getElementById(targetId) : null;
            if (!target) return;
            const isHidden = target.style.display === 'none' || target.style.display === '';
            target.style.display = isHidden ? 'block' : 'none';
            btn.innerHTML = isHidden
                ? '<i class="material-icons left">expand_less</i> Cancelar'
                : '<i class="material-icons left">cancel</i> No pude entregar';
        });
    });

    document.querySelectorAll('form.cancel-entrega-form[data-cancel-form="1"]').forEach((formEl) => {
        const reasonSelect = formEl.querySelector('select[data-cancel-reason="1"]');
        const otherInput = formEl.querySelector('input[data-cancel-other="1"]');
        if (!reasonSelect || !otherInput) return;

        const syncOtherFieldVisibility = () => {
            const mustShow = reasonSelect.value === 'otro';
            otherInput.style.display = mustShow ? 'block' : 'none';
            otherInput.required = mustShow;
            if (!mustShow) {
                otherInput.value = '';
            }
        };

        reasonSelect.addEventListener('change', syncOtherFieldVisibility);
        syncOtherFieldVisibility();
    });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const epJustDeliveredId = <?php echo json_encode($justDeliveredPedidoId, JSON_UNESCAPED_UNICODE); ?>;
    const epCsrfToken = <?php echo json_encode(getCsrfToken(), JSON_UNESCAPED_UNICODE); ?>;
    const epEndpoint = <?php echo json_encode(BASE_URL . 'api/entrega_publicacion.php', JSON_UNESCAPED_UNICODE); ?>;

    if (!epJustDeliveredId) {
        return;
    }

    const modalEl = document.getElementById('modal-entrega-publicacion');
    const textoEl = document.getElementById('ep-texto');
    const coloniaInfoEl = document.getElementById('ep-colonia-info');
    const fotoInputEl = document.getElementById('ep-foto-input');
    const previewEl = document.getElementById('ep-foto-preview');
    const statusEl = document.getElementById('ep-status');
    const btnFacebook = document.getElementById('ep-btn-facebook');
    const btnCompartir = document.getElementById('ep-btn-compartir');
    const btnOmitir = document.getElementById('ep-btn-omitir');
    if (!modalEl || typeof M === 'undefined' || !M.Modal) {
        return;
    }

    // No cachear la instancia: footer.php corre M.AutoInit() en su propio DOMContentLoaded y
    // puede re-inicializar este mismo modal despues, dejando una instancia vieja/inerte si la
    // guardamos una sola vez aqui. Pedimos la instancia vigente cada vez que se necesita.
    function epGetModalInstance() {
        return M.Modal.getInstance(modalEl) || M.Modal.init(modalEl, { dismissible: true });
    }

    let epUploadedId = null;
    let epUploadedForFile = null;

    function epSetStatus(msg, isError) {
        statusEl.textContent = msg || '';
        statusEl.className = 'center-align ' + (isError ? 'red-text' : 'green-text');
    }

    function epSetButtonsDisabled(disabled) {
        [btnFacebook, btnCompartir].forEach((btn) => {
            btn.classList.toggle('disabled', disabled);
        });
    }

    fetch(epEndpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'preparar', id_pedido: epJustDeliveredId, csrf_token: epCsrfToken }),
    })
        .then((r) => r.json())
        .then((data) => {
            if (data && data.success) {
                textoEl.value = data.texto || '';
                M.textareaAutoResize(textoEl);
                M.updateTextFields();
                coloniaInfoEl.textContent = data.colonia_detectada
                    ? 'Colonia detectada: ' + data.colonia_detectada + ' (puedes editar el texto arriba)'
                    : 'No se pudo detectar la colonia automaticamente, ajusta el texto si quieres.';
            }
        })
        .catch(() => {});

    // Un pequeno retraso deja que M.AutoInit() del footer termine de correr primero,
    // asi epGetModalInstance() ya opera sobre la instancia final (no una que luego se pisa).
    setTimeout(function () {
        epGetModalInstance().open();
    }, 0);

    fotoInputEl.addEventListener('change', function () {
        epUploadedId = null;
        epUploadedForFile = null;
        epSetStatus('', false);
        const file = fotoInputEl.files && fotoInputEl.files[0];
        if (!file) {
            previewEl.style.display = 'none';
            previewEl.src = '';
            return;
        }
        previewEl.src = URL.createObjectURL(file);
        previewEl.style.display = 'block';
    });

    function epEnsureUploaded() {
        const file = fotoInputEl.files && fotoInputEl.files[0];
        if (!file) {
            return Promise.reject(new Error('Selecciona una foto de la entrega primero.'));
        }
        if (epUploadedId && epUploadedForFile === file) {
            return Promise.resolve(epUploadedId);
        }
        const formData = new FormData();
        formData.append('foto', file);
        formData.append('id_pedido', epJustDeliveredId);
        formData.append('texto', textoEl.value || '');
        formData.append('csrf_token', epCsrfToken);

        return fetch(epEndpoint, { method: 'POST', body: formData })
            .then((r) => r.json())
            .then((data) => {
                if (!data || !data.success) {
                    throw new Error((data && data.error) || 'No se pudo subir la foto.');
                }
                epUploadedId = data.id_publicacion;
                epUploadedForFile = file;
                return epUploadedId;
            });
    }

    btnFacebook.addEventListener('click', function (e) {
        e.preventDefault();
        epSetButtonsDisabled(true);
        epSetStatus('Publicando en Facebook...', false);
        epEnsureUploaded()
            .then((idPublicacion) => fetch(epEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'publicar_facebook',
                    id_pedido: epJustDeliveredId,
                    id_publicacion: idPublicacion,
                    texto: textoEl.value,
                    csrf_token: epCsrfToken,
                }),
            }))
            .then((r) => r.json())
            .then((data) => {
                if (!data || !data.success) {
                    throw new Error((data && data.error) || 'Facebook rechazo la publicacion.');
                }
                epSetStatus('Publicado en Facebook correctamente.', false);
            })
            .catch((err) => epSetStatus(err.message, true))
            .finally(() => epSetButtonsDisabled(false));
    });

    btnCompartir.addEventListener('click', function (e) {
        e.preventDefault();
        const file = fotoInputEl.files && fotoInputEl.files[0];
        if (!file) {
            epSetStatus('Selecciona una foto de la entrega primero.', true);
            return;
        }
        epSetButtonsDisabled(true);
        epSetStatus('Preparando para compartir...', false);
        epEnsureUploaded()
            .then((idPublicacion) => {
                const canNativeShare = navigator.canShare && navigator.canShare({ files: [file] });
                if (navigator.share && canNativeShare) {
                    return navigator.share({ text: textoEl.value, files: [file] })
                        .then(() => fetch(epEndpoint, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ action: 'marcar_compartido', id_pedido: epJustDeliveredId, id_publicacion: idPublicacion, csrf_token: epCsrfToken }),
                        }))
                        .then(() => epSetStatus('Listo, elige WhatsApp (Estado) o Facebook en el menu para publicar.', false))
                        .catch((err) => {
                            if (err && err.name === 'AbortError') {
                                epSetStatus('', false);
                                return;
                            }
                            throw err;
                        });
                }

                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(textoEl.value).catch(() => {});
                }
                epSetStatus('Tu navegador no soporta compartir directo. Se copio el texto; descarga la foto desde la vista previa y compartela manualmente.', true);
            })
            .catch((err) => epSetStatus(err.message, true))
            .finally(() => epSetButtonsDisabled(false));
    });

    btnOmitir.addEventListener('click', function () {
        epGetModalInstance().close();
    });
});
</script>
<?php endif; ?>

<?php if ($isRouteOptimizationAllowed): ?>
<script>
const routeCsrfToken = <?php echo json_encode(getCsrfToken(), JSON_UNESCAPED_UNICODE); ?>;
const routeEndpoint = <?php echo json_encode(BASE_URL . 'api/optimize_delivery_route.php', JSON_UNESCAPED_UNICODE); ?>;
const isAdminRouteView = <?php echo $isAdminView ? 'true' : 'false'; ?>;
const routeDefaultOrigin = { lat: 20.6596988, lng: -103.3496092 };
const routeSelectedDate = <?php echo json_encode($selectedFechaEntrega, JSON_UNESCAPED_UNICODE); ?>;
const routeTodayDate = <?php echo json_encode(date('Y-m-d'), JSON_UNESCAPED_UNICODE); ?>;
let routeOriginSource = 'none';
let routeGeoPermissionState = 'unknown';

function routeEscapeHtml(value) {
    return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function routeGetSelectedCheckboxes() {
    return Array.from(document.querySelectorAll('.route-check:checked'));
}

function routeToggleAll(button) {
    const checks = Array.from(document.querySelectorAll('.route-check:not(:disabled)'));
    if (!checks.length) {
        return;
    }

    if (routeSelectedDate) {
        const dateChecks = checks.filter((check) => String(check.dataset.fechaEntrega || '') === routeSelectedDate);
        if (!dateChecks.length) {
            M.toast({html: 'No hay pedidos del dia seleccionado para agregar.', classes: 'orange darken-2'});
            return;
        }
        const allDateChecked = dateChecks.every((check) => check.checked);
        dateChecks.forEach((check) => {
            check.checked = !allDateChecked;
        });
        button.textContent = allDateChecked ? 'Seleccionar todo' : 'Quitar seleccion';
        return;
    }

    const allChecked = checks.every((check) => check.checked);
    checks.forEach((check) => {
        check.checked = !allChecked;
    });
    button.textContent = allChecked ? 'Seleccionar todo' : 'Quitar seleccion';
}

function routeSafeText(value) {
    const raw = String(value ?? '');
    if (raw.startsWith('ENCv1:')) {
        return '';
    }
    return raw;
}

function routeBuildWaPhone(telefono) {
    const digits = routeSafeText(telefono).replace(/\D/g, '');
    return digits ? ('52' + digits) : '';
}

function routeFormatEtaHora(eta) {
    const match = /^\d{4}-\d{2}-\d{2}[ T](\d{2}):(\d{2})/.exec(String(eta || ''));
    return match ? `${match[1]}:${match[2]} hrs` : '';
}

function routeBuildWaMessage(stop) {
    const numero = routeSafeText(stop.numero_pedido) || String(stop.id_pedido || '');
    const hora = routeFormatEtaHora(stop.eta_estimada);
    let msg = `Hola! Tu pedido ${numero} va en camino.`;
    if (hora) {
        msg += ` Hora estimada de entrega: ${hora}.`;
    }
    msg += ' Este horario es aproximado: podria cambiar segun el trafico o el clima. Te mantendremos informado por este mismo medio (WhatsApp). Gracias por tu paciencia.';
    return msg;
}

function routeParseNumber(raw) {
    if (raw === null || raw === undefined) {
        return null;
    }
    const text = String(raw).trim();
    if (text === '') {
        return null;
    }
    const val = Number(text);
    return Number.isFinite(val) ? val : null;
}

function routeSetOriginInputs(lat, lng, source = 'manual') {
    const latInput = document.getElementById('route-origin-lat');
    const lngInput = document.getElementById('route-origin-lng');
    if (latInput) {
        latInput.value = Number(lat).toFixed(8);
    }
    if (lngInput) {
        lngInput.value = Number(lng).toFixed(8);
    }
    routeOriginSource = source;
}

function routeShowLocationGuide(visible) {
    const guide = document.getElementById('route-location-guide');
    if (guide) {
        guide.style.display = visible ? '' : 'none';
    }
}

function routeUpdateLocationStatus(message, level = 'info') {
    const status = document.getElementById('route-location-status');
    if (!status) return;
    status.textContent = message;
    status.className = '';
    if (level === 'ok') {
        status.classList.add('green-text', 'text-darken-2');
    } else if (level === 'warn') {
        status.classList.add('orange-text', 'text-darken-3');
    } else if (level === 'error') {
        status.classList.add('red-text', 'text-darken-2');
    } else {
        status.classList.add('blue-text', 'text-darken-2');
    }
}

function routeMarkManualOriginIfValid() {
    const lat = routeParseNumber(document.getElementById('route-origin-lat')?.value);
    const lng = routeParseNumber(document.getElementById('route-origin-lng')?.value);
    if (lat === null || lng === null) {
        return false;
    }
    if (lat < -90 || lat > 90 || lng < -180 || lng > 180) {
        return false;
    }

    routeOriginSource = 'manual';
    routeUpdateLocationStatus('Usando origen manual confirmado.', 'warn');
    return true;
}

async function routeCheckGeolocationPermission() {
    if (!navigator.geolocation) {
        routeGeoPermissionState = 'unsupported';
        routeUpdateLocationStatus('Este navegador no soporta geolocalizacion. Captura coordenadas manualmente.', 'warn');
        routeShowLocationGuide(true);
        return;
    }

    if (!navigator.permissions || typeof navigator.permissions.query !== 'function') {
        routeUpdateLocationStatus('Ubicacion disponible. Pulsa "Usar mi ubicacion" para autorizar.', 'info');
        return;
    }

    try {
        const result = await navigator.permissions.query({ name: 'geolocation' });
        routeGeoPermissionState = String(result.state || 'unknown');
        if (routeGeoPermissionState === 'granted') {
            routeUpdateLocationStatus('Permiso de ubicacion concedido. Puedes usar tu ubicacion actual.', 'ok');
            routeShowLocationGuide(false);
        } else if (routeGeoPermissionState === 'denied') {
            routeUpdateLocationStatus('Permiso de ubicacion denegado. Habilitalo o captura origen manual.', 'error');
            routeShowLocationGuide(true);
        } else {
            routeUpdateLocationStatus('Permiso de ubicacion pendiente. Pulsa "Usar mi ubicacion" para autorizar.', 'info');
            routeShowLocationGuide(false);
        }
    } catch (error) {
        routeUpdateLocationStatus('No se pudo leer estado del permiso. Intenta usar tu ubicacion.', 'warn');
    }
}

function routeResolveOrigin() {
    const lat = routeParseNumber(document.getElementById('route-origin-lat')?.value);
    const lng = routeParseNumber(document.getElementById('route-origin-lng')?.value);

    if (lat === null || lng === null) {
        M.toast({html: 'Captura un origen manual o usa tu ubicacion actual.', classes: 'orange darken-2'});
        routeUpdateLocationStatus('Falta origen valido para calcular ruta.', 'error');
        return null;
    }

    if (Math.abs(lat) < 0.0000001 && Math.abs(lng) < 0.0000001) {
        M.toast({html: 'Origen invalido: no uses 0,0.', classes: 'red darken-2'});
        routeUpdateLocationStatus('Origen invalido (0,0). Usa ubicacion real o manual.', 'error');
        return null;
    }

    if (lat < -90 || lat > 90 || lng < -180 || lng > 180) {
        M.toast({html: 'Origen invalido. Revisa latitud y longitud.', classes: 'red darken-2'});
        routeUpdateLocationStatus('Origen fuera de rango valido.', 'error');
        return null;
    }

    if (routeOriginSource !== 'geo') {
        routeOriginSource = 'manual';
    }

    return { lat, lng };
}

function routeFormatTimeInputValue(value) {
    if (!value) return '';
    const digits = String(value).replace(/[^0-9]/g, '').slice(0, 4);
    if (digits.length <= 2) {
        return digits;
    }
    return `${digits.slice(0, 2)}:${digits.slice(2)}`;
}

function routeNormalizeTimeInput(input) {
    const formatted = routeFormatTimeInputValue(input.value || '');
    input.value = formatted;
    return /^(?:[0-9]|0[0-9]|1[0-2]):[0-5][0-9]$/.test(formatted);
}

function routeGetNow12hParts() {
    const now = new Date();
    const hours24 = now.getHours();
    const minutes = now.getMinutes();
    const meridiem = hours24 >= 12 ? 'PM' : 'AM';
    const hours12 = (hours24 % 12) || 12;
    return {
        time: `${String(hours12).padStart(2, '0')}:${String(minutes).padStart(2, '0')}`,
        meridiem,
    };
}

function routeComposeDepartureDateTime() {
    const timeInput = document.getElementById('route-start-time');
    const meridiemInput = document.getElementById('route-start-meridiem');

    if (!timeInput || !meridiemInput) {
        return '';
    }

    let timeRaw = String(timeInput.value || '').trim();
    let meridiem = String(meridiemInput.value || 'AM').toUpperCase() === 'PM' ? 'PM' : 'AM';

    if (timeRaw === '') {
        const nowParts = routeGetNow12hParts();
        timeRaw = nowParts.time;
        meridiem = nowParts.meridiem;
        timeInput.value = timeRaw;
        meridiemInput.value = meridiem;
    } else if (!routeNormalizeTimeInput(timeInput)) {
        M.toast({html: 'Hora invalida. Usa formato como 09:30.', classes: 'orange darken-2'});
        timeInput.focus();
        return '';
    } else {
        timeRaw = timeInput.value;
    }

    const [hhRaw, mmRaw] = timeRaw.split(':');
    let hh = parseInt(hhRaw || '0', 10);
    const mm = parseInt(mmRaw || '0', 10);

    if (!Number.isFinite(hh) || !Number.isFinite(mm) || hh < 0 || hh > 12 || mm < 0 || mm > 59) {
        M.toast({html: 'Hora invalida. Revisa el valor de salida.', classes: 'orange darken-2'});
        timeInput.focus();
        return '';
    }

    if (hh === 12) {
        hh = (meridiem === 'AM') ? 0 : 12;
    } else if (meridiem === 'PM') {
        hh += 12;
    }

    const fechaBase = (routeSelectedDate && /^\d{4}-\d{2}-\d{2}$/.test(routeSelectedDate)) ? routeSelectedDate : routeTodayDate;
    return `${fechaBase}T${String(hh).padStart(2, '0')}:${String(mm).padStart(2, '0')}`;
}

function routeUseCurrentLocation(button) {
    if (!navigator.geolocation) {
        M.toast({html: 'Tu navegador no soporta geolocalizacion.', classes: 'red darken-2'});
        routeUpdateLocationStatus('Geolocalizacion no disponible en este dispositivo.', 'error');
        routeShowLocationGuide(true);
        return;
    }

    const originalHtml = button ? button.innerHTML : '';
    if (button) {
        button.disabled = true;
        button.textContent = 'Obteniendo...';
    }

    navigator.geolocation.getCurrentPosition(
        (position) => {
            routeSetOriginInputs(position.coords.latitude, position.coords.longitude, 'geo');
            routeGeoPermissionState = 'granted';
            routeUpdateLocationStatus('Ubicacion actual capturada correctamente.', 'ok');
            routeShowLocationGuide(false);
            M.toast({html: 'Ubicacion actual cargada.', classes: 'green darken-2'});
            if (button) {
                button.disabled = false;
                button.innerHTML = originalHtml;
            }
        },
        (error) => {
            let message = 'No se pudo obtener tu ubicacion.';
            if (error.code === error.PERMISSION_DENIED) {
                message = 'Permiso de ubicacion denegado.';
                routeGeoPermissionState = 'denied';
                routeUpdateLocationStatus('Permiso denegado. Habilitalo o usa origen manual.', 'error');
                routeShowLocationGuide(true);
            } else if (error.code === error.POSITION_UNAVAILABLE) {
                message = 'Ubicacion no disponible en este momento.';
                routeUpdateLocationStatus('No se pudo resolver la ubicacion del dispositivo.', 'warn');
            } else if (error.code === error.TIMEOUT) {
                message = 'Tiempo agotado al obtener ubicacion.';
                routeUpdateLocationStatus('Tiempo agotado al pedir ubicacion. Reintenta o usa origen manual.', 'warn');
            }

            M.toast({html: message, classes: 'red darken-2'});
            if (button) {
                button.disabled = false;
                button.innerHTML = originalHtml;
            }
        },
        {
            enableHighAccuracy: true,
            timeout: 15000,
            maximumAge: 0,
        }
    );
}

function routeRenderResult(data) {
    const card = document.getElementById('route-result-card');
    const content = document.getElementById('route-result-content');
    if (!card || !content) {
        return;
    }

    const summary = data.summary || {};
    const stops = Array.isArray(data.orderedStops) ? data.orderedStops : [];
    const warnings = Array.isArray(data.warnings) ? data.warnings : [];
    const risk = Array.isArray(data.incumplibles_probables) ? data.incumplibles_probables : [];
    const fallbackNotice = typeof data.fallback_notice === 'string' ? data.fallback_notice.trim() : '';
    const provider = String(data.routing_provider || 'google_routes');

    const stopsHtml = stops.map((stop, index) => {
        const warningClass = stop.en_riesgo ? 'route-stop-risk' : '';
        const limitText = stop.fecha_limite_entrega ? `<div><strong>Limite:</strong> ${routeEscapeHtml(stop.fecha_limite_entrega)}</div>` : '';
        const etaText = stop.eta_estimada ? `<div><strong>ETA:</strong> ${routeEscapeHtml(stop.eta_estimada)}</div>` : '';
        const waPhone = routeBuildWaPhone(stop.telefono);
        const waMessage = routeBuildWaMessage(stop);
        const waLink = waPhone
            ? `<div style="margin-top:4px;"><a href="https://wa.me/${waPhone}?text=${encodeURIComponent(waMessage)}" target="_blank" class="green-text whatsapp-business-link" data-wa-phone="${waPhone}" data-wa-text="${routeEscapeHtml(waMessage)}"><i class="material-icons tiny">chat</i> Avisar hora estimada por WhatsApp</a></div>`
            : '';

        return `
            <li class="route-stop-item ${warningClass}">
                <div><strong>${index + 1}. Pedido ${routeEscapeHtml(stop.numero_pedido || stop.id_pedido)}</strong></div>
                <div>${routeEscapeHtml(routeSafeText(stop.cliente) || 'Cliente')}</div>
                <div>${routeEscapeHtml(routeSafeText(stop.telefono) || 'Sin telefono')}</div>
                <div>${routeEscapeHtml(routeSafeText(stop.direccion) || 'Sin direccion')}</div>
                ${limitText}
                ${etaText}
                ${waLink}
            </li>
        `;
    }).join('');

    const warningsHtml = warnings.length
        ? `<div class="card-panel orange lighten-5"><strong>Pedidos omitidos:</strong><ul>${warnings.map((w) => `<li>Pedido ${routeEscapeHtml(w.numero_pedido || w.id_pedido)}: ${routeEscapeHtml(w.reason || 'Sin detalle')}</li>`).join('')}</ul></div>`
        : '';

    const riskHtml = risk.length
        ? `<div class="card-panel red lighten-5"><strong>Riesgo de incumplimiento:</strong><ul>${risk.map((r) => `<li>Pedido ${routeEscapeHtml(r.numero_pedido || r.id_pedido)} (limite ${routeEscapeHtml(r.fecha_limite_entrega || 'N/A')}, ETA ${routeEscapeHtml(r.eta_estimada || 'N/A')})</li>`).join('')}</ul></div>`
        : '';

    const fallbackHtml = (provider === 'local_fallback')
        ? `<div class="card-panel amber lighten-5"><strong>Modo respaldo:</strong> Se calculo el orden por cercania local porque Google Routes API no devolvio una ruta.${fallbackNotice ? `<br><small>${routeEscapeHtml(fallbackNotice)}</small>` : ''}</div>`
        : '';

    content.innerHTML = `
        <span class="card-title">Ruta optimizada generada</span>
        <p class="grey-text" style="margin-top:0;">
            Paradas: <strong>${routeEscapeHtml(summary.paradas_total || 0)}</strong> |
            Distancia: <strong>${routeEscapeHtml(((summary.distancia_total_m || 0) / 1000).toFixed(2))} km</strong> |
            Duracion: <strong>${routeEscapeHtml(summary.duracion_total_hhmm || '00:00')}</strong>
        </p>
        ${fallbackHtml}
        ${warningsHtml}
        ${riskHtml}
        <ol class="route-stops-list">${stopsHtml}</ol>
        <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
            <a href="${routeEscapeHtml(data.googleMapsUrl || '#')}" target="_blank" class="btn blue darken-2 waves-effect waves-light">
                <i class="material-icons left">navigation</i>Abrir ruta en Google Maps
            </a>
        </div>
    `;

    if (typeof window.waApplyBusinessLinks === 'function') {
        window.waApplyBusinessLinks(content);
    }

    card.style.display = 'block';
}






async function routeGenerateOptimized() {
    const selected = routeGetSelectedCheckboxes();
    if (selected.length < 1) {
        M.toast({html: 'Selecciona al menos 1 pedido para generar la ruta.', classes: 'orange darken-2'});
        return;
    }

    // Verificar que los pedidos tengan coordenadas (data attribute opcional)
    const sinCoords = Array.from(selected).filter((el) => el.disabled);
    if (sinCoords.length > 0) {
        M.toast({html: `Los pedidos con "sin coordenadas" no pueden incluirse en la ruta.`, classes: 'red darken-2'});
        return;
    }

    const repartidores = new Set(selected.map((el) => String(el.dataset.repartidorId || '0')));
    repartidores.delete('0');
    if (isAdminRouteView && repartidores.size > 1) {
        M.toast({html: 'Selecciona pedidos del mismo repartidor.', classes: 'orange darken-2'});
        return;
    }

    const origin = routeResolveOrigin();
    if (!origin) {
        return;
    }

    if (routeOriginSource !== 'geo' && routeOriginSource !== 'manual') {
        M.toast({html: 'Primero define origen valido (ubicacion o manual).', classes: 'orange darken-2'});
        routeUpdateLocationStatus('No se puede generar ruta sin origen confirmado.', 'error');
        return;
    }















    const horaSalida = routeComposeDepartureDateTime();
    if (!horaSalida) {
        return;
    }

    const sinFechaEntrega = selected.filter((el) => String(el.dataset.fechaEntrega || '').trim() === '');
    if (sinFechaEntrega.length > 0) {
        routeShowErrorModal('No se puede generar la ruta: hay ' + sinFechaEntrega.length + ' pedido(s) sin fecha de entrega programada. Todo pedido debe tener una fecha de entrega asignada (ve a "Asignar Entregas") antes de poder agregarlo a una ruta.');
        return;
    }

    const pedidoIds = selected.map((el) => parseInt(el.value, 10)).filter((v) => Number.isFinite(v) && v > 0);
    const fechasSeleccionadas = Array.from(new Set(selected.map((el) => String(el.dataset.fechaEntrega || '').trim()).filter((v) => v !== '')));
    if (!routeSelectedDate && fechasSeleccionadas.length > 1) {
        M.toast({html: 'Selecciona pedidos del mismo dia para generar la ruta.', classes: 'orange darken-2'});
        return;
    }

    if (routeSelectedDate) {
        const mismatchedDate = selected.some((el) => String(el.dataset.fechaEntrega || '') !== routeSelectedDate);
        if (mismatchedDate) {
            M.toast({html: 'Hay pedidos fuera del dia filtrado. Ajusta la seleccion.', classes: 'orange darken-2'});
            return;
        }
    }

    const payload = {
        csrf_token: routeCsrfToken,
        pedidosIds: pedidoIds,
        origen: { lat: origin.lat, lng: origin.lng },
        hora_salida: horaSalida,
        fecha_entrega_filtro: routeSelectedDate || (fechasSeleccionadas.length === 1 ? fechasSeleccionadas[0] : '')
    };

    const btn = document.getElementById('btn-generate-route');
    if (btn) {
        btn.disabled = true;
        btn.textContent = 'Generando...';
    }

    try {
        const response = await fetch(routeEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });

        const data = await response.json();
        if (!data.success) {
            let errorMsg = 'Error: ' + routeEscapeHtml(data.error || 'No se pudo optimizar la ruta.');
            if (Array.isArray(data.warnings) && data.warnings.length) {
                const warningPreview = data.warnings
                    .slice(0, 2)
                    .map((w) => `Pedido ${routeEscapeHtml(w.numero_pedido || w.id_pedido || '?')}`)
                    .join(', ');
                errorMsg += `<br>Omitidos: ${warningPreview}`;
            }
            if (data.hint) {
                errorMsg += `<br>${routeEscapeHtml(data.hint)}`;
            }
            M.toast({html: errorMsg, classes: 'red darken-2'});
            return;
        }

        routeRenderResult(data);
        if (String(data.routing_provider || 'google_routes') === 'local_fallback') {
            M.toast({html: 'Ruta generada en modo respaldo local.', classes: 'orange darken-2'});
        } else {
            M.toast({html: 'Ruta optimizada correctamente.', classes: 'green darken-2'});
        }
    } catch (error) {
        M.toast({html: 'Error de red al generar la ruta.', classes: 'red darken-2'});
    } finally {
        if (btn) {
            btn.disabled = false;
            btn.innerHTML = '<i class="material-icons left">alt_route</i>Generar ruta';
        }
    }
}

function routeShowErrorModal(mensaje) {
    const elem = document.getElementById('modal-route-error');
    const textElem = document.getElementById('modal-route-error-text');
    if (!elem || typeof M === 'undefined' || !M.Modal) {
        M.toast({html: mensaje, classes: 'red darken-2'});
        return;
    }
    if (textElem) {
        textElem.textContent = mensaje;
    }
    const instance = M.Modal.getInstance(elem) || M.Modal.init(elem, { dismissible: true });
    instance.open();
}

document.addEventListener('DOMContentLoaded', () => {
    const toggleBtn = document.getElementById('route-toggle-all');
    const generateBtn = document.getElementById('btn-generate-route');
    const useLocationBtn = document.getElementById('route-use-location');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', () => routeToggleAll(toggleBtn));
    }
    if (generateBtn) {
        generateBtn.addEventListener('click', routeGenerateOptimized);
    }
    if (useLocationBtn) {
        useLocationBtn.addEventListener('click', () => routeUseCurrentLocation(useLocationBtn));
    }

    const latInput = document.getElementById('route-origin-lat');
    const lngInput = document.getElementById('route-origin-lng');
    if (latInput && lngInput) {
        const markManual = () => {
            routeMarkManualOriginIfValid();
        };
        latInput.addEventListener('input', markManual);
        lngInput.addEventListener('input', markManual);
        latInput.addEventListener('blur', markManual);
        lngInput.addEventListener('blur', markManual);
    }

    routeCheckGeolocationPermission();

    const routeTimeInput = document.getElementById('route-start-time');
    const routeMeridiemInput = document.getElementById('route-start-meridiem');
    if (routeTimeInput) {
        routeTimeInput.addEventListener('input', () => {
            routeTimeInput.value = routeFormatTimeInputValue(routeTimeInput.value || '');
        });
        routeTimeInput.addEventListener('blur', () => {
            routeNormalizeTimeInput(routeTimeInput);
        });
    }
    if (routeMeridiemInput && !routeMeridiemInput.value) {
        routeMeridiemInput.value = 'AM';
    }
});
</script>
<?php endif; ?>

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
    .delivery-value {
        margin-left: 4px;
    }
    .route-select-wrap {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        margin-bottom: 10px;
    }
    .route-location-btn {
        border: 2px solid #1565c0 !important;
        color: #1565c0 !important;
        background: #e3f2fd !important;
        border-radius: 4px;
        font-weight: 600;
    }
    .route-location-btn:hover {
        background: #bbdefb !important;
    }
    .route-location-btn:disabled {
        border-color: #b0bec5 !important;
        color: #90a4ae !important;
        background: #eceff1 !important;
    }
    .route-stops-list {
        margin: 0;
        padding-left: 20px;
    }
    .route-stop-item {
        background: #f4f6fb;
        border-radius: 6px;
        padding: 8px 10px;
        margin-bottom: 8px;
    }
    .route-stop-risk {
        border-left: 4px solid #c62828;
        background: #ffebee;
    }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>