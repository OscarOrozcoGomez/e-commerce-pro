<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/entrega_item_utils.php';
require_once __DIR__ . '/../core/pedido_item_admin_utils.php';
require_once __DIR__ . '/../core/cliente_loyalty_utils.php';

requireAuth();
// Fase 4: el permiso 'asignar_entregas' abre esta vista; el rol se mantiene como respaldo.
if (!hasPermission('asignar_entregas') && !canManageDeliveryOrders()) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$pageTitle = 'Asignar Entregas';
$pdo = getPDO();
$usuario = $_SESSION['usuario'];
$clientesFrecuentesIds = clienteFrecuenteGetIds($pdo);
// Esta pantalla es solo para entregas a domicilio (ver $deliveryFilter mas abajo). El id_almacen
// de un pedido a domicilio es de donde se surte el stock (siempre el Almacen Central, ver
// resolveCheckoutWarehouse en core/auth.php), no de que encargado le corresponde: cualquier
// encargado (o el admin) puede asignar repartidor a cualquier entrega a domicilio. El filtro por
// sucursal si aplica para pickup, que se maneja aparte en views/pickup_notifications.php.
$scopeAlmacenId = null;
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
            } elseif ($accion === 'agregar_producto_pedido') {
                $idProductoAgregar = (int)($_POST['id_producto'] ?? 0);
                $cantidadAgregar = (int)($_POST['cantidad'] ?? 0);
                $resultadoAgregar = dbAdminAgregarProductoPedido($pdo, $id_pedido, $idProductoAgregar, $cantidadAgregar, (int)($usuario['id_usuario'] ?? 0));
                if ($resultadoAgregar['success']) {
                    $success = $resultadoAgregar['message'];
                } else {
                    $error = $resultadoAgregar['message'];
                }
            } elseif ($accion === 'quitar_producto_pedido') {
                $idDetalleQuitar = (int)($_POST['id_detalle'] ?? 0);
                $motivoQuitar = trim((string)($_POST['motivo'] ?? ''));
                if ($motivoQuitar === '') {
                    $motivoQuitar = 'Ajuste de pedido por ' . (string)($usuario['nombre'] ?? 'administracion');
                }
                // Admin/encargado puede editar productos aunque el pedido ya se haya entregado
                // (para agregar/quitar como parte de la misma venta), a diferencia del
                // repartidor que solo puede hacerlo mientras aun no se entrega.
                $estadosPermitidosAdmin = ['pendiente_pago', 'pagado', 'en_reparto', 'entregado'];
                $resultadoQuitar = dbMarkProductoNoEntregado($pdo, $id_pedido, $idDetalleQuitar, null, $motivoQuitar, (int)($usuario['id_usuario'] ?? 0), $estadosPermitidosAdmin);
                if ($resultadoQuitar['success']) {
                    $success = $resultadoQuitar['message'];
                } else {
                    $error = $resultadoQuitar['message'];
                }
            } elseif ($accion === 'cancelar_pedido_completo') {
                $motivoCancelar = trim((string)($_POST['motivo'] ?? ''));
                if ($motivoCancelar === '') {
                    $motivoCancelar = 'Pedido cancelado por ' . (string)($usuario['nombre'] ?? 'administracion');
                }
                // A diferencia de quitar_producto_pedido, aqui no se permite cancelar un pedido
                // ya entregado (seria una devolucion, no una cancelacion de entrega).
                $resultadoCancelarPedido = dbCancelarPedidoCompleto($pdo, $id_pedido, null, $motivoCancelar, (int)($usuario['id_usuario'] ?? 0), null, 'PEDIDO_CANCELADO_ADMIN');
                if ($resultadoCancelarPedido['success']) {
                    $success = $resultadoCancelarPedido['message'];
                } else {
                    $error = $resultadoCancelarPedido['message'];
                }
            }
        } catch (PDOException $e) {
            $error = 'Error al asignar: ' . $e->getMessage();
        }

        // Post/Redirect/Get: evita que recargar la pagina (o que el navegador la recargue
        // solo porque estuvo en segundo plano) reenvie el mismo POST y dispare el aviso
        // "Confirm Form Resubmission". El resultado viaja por sesion (flash) y se conserva
        // la pestana activa (Por Asignar / Asignadas) segun que accion se acaba de hacer.
        $tabsDeAsignadas = ['agregar_producto_pedido', 'quitar_producto_pedido', 'cancelar_pedido_completo'];
        $_SESSION['asignar_entregas_flash'] = [
            'error' => $error,
            'success' => $success,
            'successActionUrl' => $successActionUrl,
            'successActionLabel' => $successActionLabel,
        ];
        $redirectTab = in_array($accion, $tabsDeAsignadas, true) ? 'asignadas' : '';
        $redirectUrl = basename($_SERVER['PHP_SELF']) . ($redirectTab !== '' ? ('?tab=' . $redirectTab) : '');
        header('Location: ' . $redirectUrl);
        exit;
    }
}

if (isset($_SESSION['asignar_entregas_flash']) && is_array($_SESSION['asignar_entregas_flash'])) {
    $flash = $_SESSION['asignar_entregas_flash'];
    unset($_SESSION['asignar_entregas_flash']);
    $error = (string)($flash['error'] ?? '');
    $success = (string)($flash['success'] ?? '');
    $successActionUrl = (string)($flash['successActionUrl'] ?? '');
    $successActionLabel = (string)($flash['successActionLabel'] ?? '');
}

$activeTab = trim((string)($_GET['tab'] ?? '')) === 'asignadas' ? 'asignadas' : 'por-asignar';

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

    $almacenFilter = $scopeAlmacenId !== null ? ' AND p.id_almacen = :id_almacen' : '';
    $sql = "SELECT p.*, c.nombre as cliente, {$direccionExpr}, c.telefono
            FROM pedidos p
            LEFT JOIN clientes c ON p.id_cliente = c.id_cliente
            WHERE p.estado IN ('pendiente_pago','pagado')
              AND p.id_repartidor IS NULL
              AND {$deliveryFilter}{$notPickupFilter}{$almacenFilter}
            ORDER BY p.fecha_creacion DESC";
    $stmtPedidos = $pdo->prepare($sql);
    $stmtPedidos->execute($scopeAlmacenId !== null ? [':id_almacen' => $scopeAlmacenId] : []);
    $pedidos = $stmtPedidos->fetchAll();

    // Obtener lista de repartidores
    $sql_rep = "SELECT id_usuario, nombre FROM usuarios WHERE id_rol = (SELECT id_rol FROM roles WHERE nombre = 'repartidor') AND estado = 'activo'";
    $repartidores = $pdo->query($sql_rep)->fetchAll();

    // Pestaña "Asignadas": todo pedido a domicilio que ya tiene repartidor, en cualquier
    // estado (incluye entregados y cancelados), para que admin/encargado vean el estado
    // real y puedan seguir editando productos aunque ya se haya entregado.
    $sqlAsignados = "SELECT p.*, c.nombre as cliente, {$direccionExpr}, c.telefono, r.nombre AS repartidor_nombre
            FROM pedidos p
            LEFT JOIN clientes c ON p.id_cliente = c.id_cliente
            LEFT JOIN usuarios r ON p.id_repartidor = r.id_usuario
            WHERE p.id_repartidor IS NOT NULL{$notPickupFilter}{$almacenFilter}
            ORDER BY (p.estado = 'cancelado') ASC, p.fecha_entrega_programada DESC, p.fecha_creacion DESC";
    $stmtAsignados = $pdo->prepare($sqlAsignados);
    $stmtAsignados->execute($scopeAlmacenId !== null ? [':id_almacen' => $scopeAlmacenId] : []);
    $pedidosAsignados = $stmtAsignados->fetchAll();

    $detallesPorPedidoAsignado = [];
    if (!empty($pedidosAsignados)) {
        $idsPedidosAsignados = array_values(array_unique(array_map(static fn($row): int => (int)$row['id_pedido'], $pedidosAsignados)));
        $placeholdersAsignados = implode(',', array_fill(0, count($idsPedidosAsignados), '?'));
        $sqlDetallesAsignados = "SELECT dp.id_pedido, dp.id_detalle, dp.cantidad, dp.estado_entrega, dp.motivo_rechazo, pr.nombre, pr.nombre_variante
                                  FROM detalle_pedidos dp
                                  JOIN productos pr ON dp.id_producto = pr.id_producto
                                  WHERE dp.id_pedido IN ({$placeholdersAsignados})
                                  ORDER BY dp.id_pedido ASC, dp.id_detalle ASC";
        $stmtDetallesAsignados = $pdo->prepare($sqlDetallesAsignados);
        $stmtDetallesAsignados->execute($idsPedidosAsignados);
        foreach ($stmtDetallesAsignados->fetchAll() as $detalleAsignado) {
            $detallesPorPedidoAsignado[(int)$detalleAsignado['id_pedido']][] = $detalleAsignado;
        }
    }

    // Catalogo simple para el selector de "Agregar producto" en la pestaña Asignadas.
    $productosActivos = $pdo->query("SELECT id_producto, nombre, nombre_variante, precio_venta FROM productos WHERE estado = 'activo' ORDER BY nombre ASC, nombre_variante ASC")->fetchAll();
} catch (PDOException $e) {
    $error = 'Error de base de datos: ' . $e->getMessage();
    $pedidos = [];
    $repartidores = [];
    $pedidosAsignados = [];
    $detallesPorPedidoAsignado = [];
    $productosActivos = [];
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

    <div class="row" style="margin-bottom:0;">
        <div class="col s12">
            <ul class="tabs">
                <li class="tab col s6"><a href="#tab-por-asignar" <?php echo $activeTab === 'por-asignar' ? 'class="active"' : ''; ?>>Por Asignar (<?php echo count($pedidos); ?>)</a></li>
                <li class="tab col s6"><a href="#tab-asignadas" <?php echo $activeTab === 'asignadas' ? 'class="active"' : ''; ?>>Asignadas (<?php echo count($pedidosAsignados); ?>)</a></li>
            </ul>
        </div>
    </div>

    <div id="tab-por-asignar">
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
                                                <?php if (in_array((int)($p['id_cliente'] ?? 0), $clientesFrecuentesIds, true)): ?><?php echo clienteFrecuenteBadgeHtml(); ?><?php endif; ?>
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

    <div id="tab-asignadas">
    <div class="row">
        <div class="col s12">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Entregas Ya Asignadas</span>
                    <p class="grey-text" style="font-size:0.9rem; margin-top:0;">Consulta el estado real de cada pedido asignado. Puedes agregar o quitar productos aunque el pedido ya se haya entregado (queda como parte de la misma venta), o cancelar el pedido completo mientras no se haya entregado (libera el inventario y ya no requiere asignacion).</p>

                    <?php if (empty($pedidosAsignados)): ?>
                        <p class="center-align grey-text">No hay pedidos asignados por ahora.</p>
                    <?php else: ?>
                        <?php
                            $estadoAsignadoLabels = [
                                'pendiente_pago' => ['Pendiente de pago', '#f57c00'],
                                'pagado' => ['Pagado, por salir', '#1976d2'],
                                'en_reparto' => ['En camino', '#5e35b1'],
                                'entregado' => ['Entregado', '#388e3c'],
                                'cancelado' => ['Cancelado', '#757575'],
                            ];
                        ?>
                        <div class="row assign-deliveries-grid">
                            <?php foreach ($pedidosAsignados as $pa): ?>
                                <?php
                                    $estadoPa = (string)($pa['estado'] ?? '');
                                    [$estadoPaLabel, $estadoPaColor] = $estadoAsignadoLabels[$estadoPa] ?? [strtoupper($estadoPa), '#455a64'];
                                    $puedeEditarAsignado = $estadoPa !== 'cancelado';
                                    // Cancelar el pedido completo (a diferencia de editar productos) no aplica a uno
                                    // ya entregado: eso seria una devolucion, no una entrega que no se realizo.
                                    $puedeCancelarAsignado = in_array($estadoPa, ['pendiente_pago', 'pagado', 'en_reparto'], true);
                                    $itemsPa = $detallesPorPedidoAsignado[(int)$pa['id_pedido']] ?? [];
                                    $entregadosRestantesPa = count(array_filter($itemsPa, static fn($it) => (string)($it['estado_entrega'] ?? 'entregado') === 'entregado'));
                                ?>
                                <div class="col s12 m6 l4">
                                    <div class="card assign-delivery-card">
                                        <div class="card-content">
                                            <div class="assign-delivery-header">
                                                <span class="assign-delivery-numero"><?php echo esc((string)$pa['numero_pedido']); ?></span>
                                                <span class="assign-delivery-badge" style="background:<?php echo esc($estadoPaColor); ?>;"><?php echo esc($estadoPaLabel); ?></span>
                                            </div>

                                            <div class="assign-delivery-row">
                                                <i class="material-icons tiny">person</i>
                                                <span><?php echo esc((string)($pa['cliente'] ?? 'N/A')); ?></span>
                                                <?php if (in_array((int)($pa['id_cliente'] ?? 0), $clientesFrecuentesIds, true)): ?><?php echo clienteFrecuenteBadgeHtml(); ?><?php endif; ?>
                                            </div>
                                            <div class="assign-delivery-row">
                                                <i class="material-icons tiny">place</i>
                                                <span class="grey-text text-darken-1"><?php echo esc((string)($pa['direccion'] ?? 'S/D')); ?></span>
                                            </div>
                                            <div class="assign-delivery-row">
                                                <i class="material-icons tiny">person_pin</i>
                                                <span class="grey-text text-darken-1">Repartidor: <?php echo esc((string)($pa['repartidor_nombre'] ?? 'N/A')); ?></span>
                                            </div>
                                            <div class="assign-delivery-total">$<?php echo number_format((float)$pa['total'], 2); ?></div>

                                            <div class="assign-products-list">
                                                <?php if (empty($itemsPa)): ?>
                                                    <p class="grey-text" style="font-size:0.85rem;">Sin productos.</p>
                                                <?php else: ?>
                                                    <ul style="margin:0; padding-left:18px;">
                                                        <?php foreach ($itemsPa as $itemPa): ?>
                                                            <?php
                                                                $itemRechazadoPa = (string)($itemPa['estado_entrega'] ?? 'entregado') === 'rechazado';
                                                                $nombrePa = (string)$itemPa['nombre'] . (!empty($itemPa['nombre_variante']) ? ' - ' . (string)$itemPa['nombre_variante'] : '');
                                                            ?>
                                                            <li style="font-size:0.88rem; margin-bottom:6px;<?php echo $itemRechazadoPa ? ' text-decoration:line-through; color:#9e9e9e;' : ''; ?>">
                                                                <?php echo (int)$itemPa['cantidad']; ?>x <?php echo esc($nombrePa); ?>
                                                                <?php if ($itemRechazadoPa): ?>
                                                                    <span class="new badge red" data-badge-caption="" style="margin-left:4px;">Quitado</span>
                                                                <?php elseif ($puedeEditarAsignado): ?>
                                                                    <form method="POST" style="display:inline;" onsubmit="return confirm('¿Quitar este producto del pedido? Se regresara al inventario.');">
                                                                        <?php echo csrfInput(); ?>
                                                                        <input type="hidden" name="id_pedido" value="<?php echo (int)$pa['id_pedido']; ?>">
                                                                        <input type="hidden" name="id_detalle" value="<?php echo (int)$itemPa['id_detalle']; ?>">
                                                                        <input type="hidden" name="accion" value="quitar_producto_pedido">
                                                                        <button type="submit" class="btn-flat red-text waves-effect" style="padding:0 4px; height:20px; line-height:20px; font-size:0.7rem; text-decoration:none;" <?php echo $entregadosRestantesPa <= 1 ? 'disabled title="No puedes quitar el ultimo producto; usa Cancelar pedido completo abajo si aplica."' : ''; ?>>
                                                                        <i class="material-icons tiny" style="font-size:14px; vertical-align:middle;">close</i>Quitar
                                                                        </button>
                                                                    </form>
                                                                <?php endif; ?>
                                                            </li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php endif; ?>
                                            </div>

                                            <?php if ($puedeCancelarAsignado): ?>
                                                <?php $idCancelModalPa = 'cancel-pedido-modal-' . (int)$pa['id_pedido']; ?>
                                                <form method="POST" id="cancelar-pedido-form-<?php echo (int)$pa['id_pedido']; ?>" style="margin-top:10px;">
                                                    <?php echo csrfInput(); ?>
                                                    <input type="hidden" name="id_pedido" value="<?php echo (int)$pa['id_pedido']; ?>">
                                                    <input type="hidden" name="accion" value="cancelar_pedido_completo">
                                                    <button type="button" class="btn-flat red-text waves-effect w-100 modal-trigger" href="#<?php echo esc($idCancelModalPa); ?>" style="border:1px solid #ef9a9a; border-radius:4px; font-size:0.8rem; font-weight:600; text-decoration:none;">
                                                        <i class="material-icons left" style="font-size:18px; vertical-align:middle;">cancel</i>Cancelar pedido completo
                                                    </button>
                                                </form>
                                                <div id="<?php echo esc($idCancelModalPa); ?>" class="modal">
                                                    <div class="modal-content">
                                                        <h5><i class="material-icons left red-text text-darken-2">cancel</i>Cancelar pedido completo</h5>
                                                        <p>¿Cancelar el pedido <strong><?php echo esc((string)$pa['numero_pedido']); ?></strong> por completo? Todos los productos volveran al inventario y ya no aparecera para asignacion.</p>
                                                    </div>
                                                    <div class="modal-footer">
                                                        <a href="#!" class="modal-close waves-effect btn-flat">Volver</a>
                                                        <a href="#!" class="modal-close waves-effect waves-light btn red darken-2" onclick="document.getElementById('cancelar-pedido-form-<?php echo (int)$pa['id_pedido']; ?>').submit(); return false;">Sí, cancelar pedido</a>
                                                    </div>
                                                </div>
                                            <?php endif; ?>

                                            <?php if ($puedeEditarAsignado): ?>
                                                <form method="POST" class="assign-add-product-form" style="margin-top:10px; border-top:1px solid #eceff1; padding-top:10px;">
                                                    <?php echo csrfInput(); ?>
                                                    <input type="hidden" name="id_pedido" value="<?php echo (int)$pa['id_pedido']; ?>">
                                                    <input type="hidden" name="accion" value="agregar_producto_pedido">
                                                    <label class="assign-delivery-label">Agregar producto</label>
                                                    <select name="id_producto" required class="browser-default assign-delivery-select">
                                                        <option value="">-- Seleccionar producto --</option>
                                                        <?php foreach ($productosActivos as $prodOpt): ?>
                                                            <?php $nombreProdOpt = (string)$prodOpt['nombre'] . (!empty($prodOpt['nombre_variante']) ? ' - ' . (string)$prodOpt['nombre_variante'] : ''); ?>
                                                            <option value="<?php echo (int)$prodOpt['id_producto']; ?>"><?php echo esc($nombreProdOpt); ?> ($<?php echo number_format((float)$prodOpt['precio_venta'], 2); ?>)</option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <div style="display:flex; gap:8px; align-items:flex-end; margin-top:8px;">
                                                        <div style="flex:1;">
                                                            <label class="assign-delivery-label" style="margin-top:0;">Cantidad</label>
                                                            <input type="number" name="cantidad" min="1" value="1" required style="width:100%; height:38px; border:1px solid #cfd8dc; border-radius:4px; padding:0 10px; box-sizing:border-box;">
                                                        </div>
                                                        <button type="submit" class="btn-small green darken-1 waves-effect waves-light" style="height:38px;">
                                                            <i class="material-icons left" style="margin-right:2px;">add</i>Agregar
                                                        </button>
                                                    </div>
                                                </form>
                                            <?php endif; ?>
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
