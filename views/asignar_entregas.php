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
                $fecha = $_POST['fecha_entrega'] ?? null;
                // El repartidor cobra al momento de entregar, no se requiere pago previo
                $hasPedidosTipoEntrega = false;
                $stmtMeta = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'tipo_entrega'");
                $stmtMeta->execute();
                $hasPedidosTipoEntrega = ((int)$stmtMeta->fetchColumn()) > 0;

                if ($hasPedidosTipoEntrega) {
                    $sqlUpdate = "UPDATE pedidos
                                  SET id_repartidor = :rep, fecha_entrega_programada = :fecha
                                  WHERE id_pedido = :pedido
                                    AND estado IN ('pendiente_pago','pagado')
                                    AND tipo_entrega = 'Domicilio'";
                } else {
                    $sqlUpdate = "UPDATE pedidos
                                  SET id_repartidor = :rep, fecha_entrega_programada = :fecha
                                  WHERE id_pedido = :pedido
                                    AND estado IN ('pendiente_pago','pagado')
                                    AND observaciones LIKE '%ENTREGA: Domicilio%'";
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
        $deliveryFilter = "p.tipo_entrega = 'Domicilio'";
    } else {
        // Compatibilidad con esquemas antiguos: tipo de entrega embebido en observaciones.
        $deliveryFilter = "p.observaciones LIKE '%ENTREGA: Domicilio%'";
    }

    $sql = "SELECT p.*, c.nombre as cliente, {$direccionExpr}, c.telefono
            FROM pedidos p
            LEFT JOIN clientes c ON p.id_cliente = c.id_cliente
            WHERE p.estado IN ('pendiente_pago','pagado')
              AND p.id_repartidor IS NULL
              AND {$deliveryFilter}
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
                        <table class="striped responsive-table">
                            <thead>
                                <tr>
                                    <th>Pedido</th>
                                    <th>Cliente / Dirección</th>
                                    <th>Total</th>
                                    <th>Asignar Repartidor</th>
                                    <th>Fecha Entrega</th>
                                    <th>Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pedidos as $p): ?>
                                    <tr>
                                        <form method="POST">
                                            <?php echo csrfInput(); ?>
                                            <input type="hidden" name="id_pedido" value="<?php echo $p['id_pedido']; ?>">
                                            <input type="hidden" name="accion" value="asignar">
                                            
                                            <td>
                                                <strong><?php echo esc($p['numero_pedido']); ?></strong><br>
                                                <span style="font-size:0.75rem; padding:2px 6px; border-radius:4px; color:#fff; background:<?php echo $p['estado'] === 'pendiente_pago' ? '#f57c00' : '#388e3c'; ?>;">
                                                    <?php echo $p['estado'] === 'pendiente_pago' ? 'Cobro al entregar' : 'Ya pagado'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php echo esc($p['cliente'] ?? 'N/A'); ?><br>
                                                <small class="grey-text"><?php echo esc($p['direccion'] ?? 'S/D'); ?></small>
                                            </td>
                                            <td>$<?php echo number_format((float)$p['total'], 2); ?></td>
                                            <td>
                                                <select name="id_repartidor" required class="browser-default">
                                                    <option value="">-- Seleccionar --</option>
                                                    <?php foreach ($repartidores as $r): ?>
                                                        <option value="<?php echo $r['id_usuario']; ?>"><?php echo esc($r['nombre']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="datetime-local" name="fecha_entrega" style="font-size: 0.8rem;">
                                            </td>
                                            <td>
                                                <button type="submit" class="btn-small indigo waves-effect waves-light">
                                                    Asignar
                                                </button>
                                            </td>
                                        </form>
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
