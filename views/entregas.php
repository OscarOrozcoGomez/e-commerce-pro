<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

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
if ($selectedFechaEntrega === '') {
    $selectedFechaEntrega = date('Y-m-d');
}
if ($selectedFechaEntrega !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedFechaEntrega)) {
    $selectedFechaEntrega = date('Y-m-d');
}
$error = '';
$success = '';
$repartidores = [];

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
                }
            } catch (PDOException $e) {
                $error = 'Error al actualizar el pedido.';
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

        $sql = "SELECT p.*, p.observaciones,
                   c.nombre as cliente, {$direccionExpr}, {$telefonoExpr}, {$mapExpr},
                   {$fechaLimiteExpr}, {$prioridadExpr},
                   ur.nombre AS repartidor_nombre,
                   p.latitud, p.longitud
            FROM pedidos p
            LEFT JOIN clientes c ON p.id_cliente = c.id_cliente
            LEFT JOIN usuarios ur ON p.id_repartidor = ur.id_usuario
            WHERE p.estado IN ('pendiente_pago','pagado','en_reparto')
              AND p.id_repartidor IS NOT NULL";

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
                        <form method="GET" style="display:flex; gap:8px; flex-wrap:wrap; align-items:end;">
                            <div style="min-width:220px; flex:1;">
                                <label for="fecha_entrega" class="active">Dia de entrega</label>
                                <input id="fecha_entrega" name="fecha_entrega" type="date" value="<?php echo esc($selectedFechaEntrega); ?>" required>
                            </div>
                            <button type="submit" class="btn indigo waves-effect waves-light">Aplicar filtro</button>
                            <a href="?fecha_entrega=<?php echo esc(urlencode(date('Y-m-d'))); ?>" class="btn-flat waves-effect">Hoy</a>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
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

    <?php if ($isRouteOptimizationAllowed && !empty($entregas)): ?>
        <div class="row">
            <div class="col s12">
                <div class="card" id="route-planner-card">
                    <div class="card-content">
                        <span class="card-title">Optimizacion de Ruta de Entrega</span>
                        <p class="grey-text" style="margin-top:0;">Selecciona pedidos, define origen y genera una ruta optimizada para Google Maps.</p>

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
                                <button type="button" class="btn-flat waves-effect" id="route-use-location">
                                    <i class="material-icons left">my_location</i> Usar mi ubicacion
                                </button>
                                <button type="button" class="btn blue-grey darken-1 waves-effect waves-light" id="route-toggle-all">Seleccionar todo</button>
                                <button type="button" class="btn amber darken-3 waves-effect waves-light" id="btn-generate-route">
                                    <i class="material-icons left">alt_route</i>Generar ruta
                                </button>
                            </div>
                        </div>
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
                                    <a href="https://wa.me/52<?php echo esc($telLimpio); ?>" target="_blank" class="green-text" style="font-size:0.85rem;">
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

<?php if ($isRouteOptimizationAllowed): ?>
<script>
const routeCsrfToken = <?php echo json_encode(getCsrfToken(), JSON_UNESCAPED_UNICODE); ?>;
const routeEndpoint = <?php echo json_encode(BASE_URL . 'api/optimize_delivery_route.php', JSON_UNESCAPED_UNICODE); ?>;
const isAdminRouteView = <?php echo $isAdminView ? 'true' : 'false'; ?>;
const routeDefaultOrigin = { lat: 20.6596988, lng: -103.3496092 };
const routeSelectedDate = <?php echo json_encode($selectedFechaEntrega, JSON_UNESCAPED_UNICODE); ?>;
const routeTodayDate = <?php echo json_encode(date('Y-m-d'), JSON_UNESCAPED_UNICODE); ?>;

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

function routeSetOriginInputs(lat, lng) {
    const latInput = document.getElementById('route-origin-lat');
    const lngInput = document.getElementById('route-origin-lng');
    if (latInput) {
        latInput.value = Number(lat).toFixed(8);
    }
    if (lngInput) {
        lngInput.value = Number(lng).toFixed(8);
    }
}

function routeResolveOrigin() {
    const lat = routeParseNumber(document.getElementById('route-origin-lat')?.value);
    const lng = routeParseNumber(document.getElementById('route-origin-lng')?.value);

    if (lat === null && lng === null) {
        routeSetOriginInputs(routeDefaultOrigin.lat, routeDefaultOrigin.lng);
        return { lat: routeDefaultOrigin.lat, lng: routeDefaultOrigin.lng };
    }

    if (lat === null || lng === null) {
        M.toast({html: 'Completa latitud y longitud del origen.', classes: 'orange darken-2'});
        return null;
    }

    if (Math.abs(lat) < 0.0000001 && Math.abs(lng) < 0.0000001) {
        routeSetOriginInputs(routeDefaultOrigin.lat, routeDefaultOrigin.lng);
        return { lat: routeDefaultOrigin.lat, lng: routeDefaultOrigin.lng };
    }

    if (lat < -90 || lat > 90 || lng < -180 || lng > 180) {
        M.toast({html: 'Origen invalido. Revisa latitud y longitud.', classes: 'red darken-2'});
        return null;
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
        return;
    }

    const originalHtml = button ? button.innerHTML : '';
    if (button) {
        button.disabled = true;
        button.textContent = 'Obteniendo...';
    }

    navigator.geolocation.getCurrentPosition(
        (position) => {
            routeSetOriginInputs(position.coords.latitude, position.coords.longitude);
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
            } else if (error.code === error.POSITION_UNAVAILABLE) {
                message = 'Ubicacion no disponible en este momento.';
            } else if (error.code === error.TIMEOUT) {
                message = 'Tiempo agotado al obtener ubicacion.';
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

        return `
            <li class="route-stop-item ${warningClass}">
                <div><strong>${index + 1}. Pedido ${routeEscapeHtml(stop.numero_pedido || stop.id_pedido)}</strong></div>
                <div>${routeEscapeHtml(routeSafeText(stop.cliente) || 'Cliente')}</div>
                <div>${routeEscapeHtml(routeSafeText(stop.telefono) || 'Sin telefono')}</div>
                <div>${routeEscapeHtml(routeSafeText(stop.direccion) || 'Sin direccion')}</div>
                ${limitText}
                ${etaText}
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















    const horaSalida = routeComposeDepartureDateTime();
    if (!horaSalida) {
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
    if (latInput && lngInput && String(latInput.value).trim() === '' && String(lngInput.value).trim() === '') {
        routeSetOriginInputs(routeDefaultOrigin.lat, routeDefaultOrigin.lng);
    }

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