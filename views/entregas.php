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
                    logAudit('PEDIDO_ENTREGADO', 'pedidos', $id_pedido, 'Pedido marcado como entregado y pagado por repartidor');
                    $success = 'Pedido entregado y cobrado correctamente.';
                }
            } catch (PDOException $e) {
                $error = 'Error al actualizar el pedido.';
            }
        }
    }
}

// Obtener entregas pendientes asignadas a este repartidor
try {
    $hasClientesDireccion = false;
    $hasClientesUbicacionMapa = false;
    $hasClienteDireccionesTable = false;
    $hasPedidosDireccionEntrega = false;
    $hasPedidosTelefonoEntrega = false;
    $hasPedidosMapsLinkEntrega = false;

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

    $sql = "SELECT p.*, p.observaciones,
                   c.nombre as cliente, {$direccionExpr}, {$telefonoExpr}, {$mapExpr}
            FROM pedidos p
            LEFT JOIN clientes c ON p.id_cliente = c.id_cliente
            WHERE p.id_repartidor = :repartidor AND p.estado IN ('pendiente_pago','pagado','en_reparto')
            ORDER BY p.fecha_entrega_programada ASC, p.fecha_creacion DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':repartidor' => $usuario['id_usuario']]);
    $entregas = $stmt->fetchAll();

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
                            <?php
                                // Extraer datos desde observaciones cuando la tabla clientes no los tiene
                                // Formato: "ENTREGA: domicilio | Cliente: Nombre | Tel: 555... | Dir: Calle..."
                                $obs = $ent['observaciones'] ?? '';
                                $clienteNombre = $ent['cliente'] ?? null;
                                $clienteTel    = $ent['telefono'] ?? null;
                                $clienteDir    = $ent['direccion'] ?? null;
                                $clienteMapas  = $ent['ubicacion_mapa'] ?? null;

                                if ($obs) {
                                    if (!$clienteNombre && preg_match('/Cliente:\s*([^|]+)/i', $obs, $m)) {
                                        $clienteNombre = trim($m[1]);
                                    }
                                    if (!$clienteTel && preg_match('/Tel:\s*([^|]+)/i', $obs, $m)) {
                                        $clienteTel = trim($m[1]);
                                    }
                                    if (!$clienteDir && preg_match('/Dir:\s*(.+)/i', $obs, $m)) {
                                        $clienteDir = trim($m[1]);
                                    }
                                }

                                // URL de navegación: primero coordenadas guardadas, luego búsqueda por texto
                                if ($clienteMapas) {
                                    $mapsUrl = $clienteMapas;
                                } elseif ($clienteDir) {
                                    $mapsUrl = 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($clienteDir);
                                } else {
                                    $mapsUrl = null;
                                }

                                // Teléfono limpio para WhatsApp
                                $telLimpio = preg_replace('/\D/', '', (string)$clienteTel);
                            ?>
                            <p><i class="material-icons tiny indigo-text">person</i> <strong>Cliente:</strong> <?php echo esc($clienteNombre ?? 'N/A'); ?></p>
                            <p>
                                <i class="material-icons tiny indigo-text">phone</i>
                                <strong>Teléfono:</strong>
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
                            <p><i class="material-icons tiny indigo-text">place</i> <strong>Dirección:</strong> <?php echo esc($clienteDir ?? 'No especificada'); ?></p>

                            <?php if ($mapsUrl): ?>
                                <div style="margin-top: 12px;">
                                    <a href="<?php echo esc($mapsUrl); ?>" target="_blank"
                                       class="btn waves-effect waves-light blue darken-2"
                                       style="width:100%; text-align:center;">
                                        <i class="material-icons left">navigation</i> Abrir Navegación
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
