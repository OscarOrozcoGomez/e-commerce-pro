<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
requirePermission('venta', BASE_URL . 'views/dashboard.php'); // Encargados tienen 'venta'

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
            if ($accion === 'confirmar_pago') {
                $stmt = $pdo->prepare("UPDATE pedidos SET estado = 'pagado', fecha_pago = NOW() WHERE id_pedido = :pedido AND estado = 'pendiente_pago'");
                $stmt->execute([':pedido' => $id_pedido]);
                if ($stmt->rowCount() > 0) {
                    logAudit('PEDIDO_CONFIRMADO', 'pedidos', $id_pedido, 'Pago confirmado por vendedor/encargado');
                    $success = 'Pedido confirmado como pagado.';
                } else {
                    $error = 'No fue posible confirmar el pago (estado actual distinto a pendiente).';
                }
            } elseif ($accion === 'asignar' && isset($_POST['id_repartidor'])) {
                $id_repartidor = intval($_POST['id_repartidor']);
                $fecha = $_POST['fecha_entrega'] ?? null;
                $stmt = $pdo->prepare("UPDATE pedidos SET id_repartidor = :rep, fecha_entrega_programada = :fecha WHERE id_pedido = :pedido AND estado = 'pagado'");
                $stmt->execute([
                    ':rep' => $id_repartidor,
                    ':fecha' => $fecha ?: null,
                    ':pedido' => $id_pedido
                ]);
                if ($stmt->rowCount() > 0) {
                    logAudit('PEDIDO_ASIGNADO', 'pedidos', $id_pedido, "Pedido asignado al repartidor ID: $id_repartidor");
                    $success = 'Pedido asignado correctamente.';
                } else {
                    $error = 'Solo se pueden asignar pedidos en estado pagado.';
                }
            }
        } catch (PDOException $e) {
            $error = 'Error al asignar: ' . $e->getMessage();
        }
    }
}

// Pedidos pendientes de confirmación de pago
try {
    $sqlPend = "SELECT p.id_pedido, p.numero_pedido, p.total, p.fecha_creacion,
                       c.nombre as cliente, c.telefono
                FROM pedidos p
                LEFT JOIN clientes c ON p.id_cliente = c.id_cliente
                WHERE p.estado = 'pendiente_pago'
                ORDER BY p.fecha_creacion DESC";
    $pedidosPendientes = $pdo->query($sqlPend)->fetchAll();
} catch (PDOException $e) {
    $pedidosPendientes = [];
}

// Obtener pedidos pagados sin repartidor
try {
    $sql = "SELECT p.*, c.nombre as cliente, c.direccion 
            FROM pedidos p 
            LEFT JOIN clientes c ON p.id_cliente = c.id_cliente
            WHERE p.estado = 'pagado' AND p.id_repartidor IS NULL
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
            <p class="grey-text">Selecciona un pedido pagado y asígnalo a un repartidor disponible.</p>
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
                    <span class="card-title">Confirmar Pago (Pendientes)</span>
                    <?php if (empty($pedidosPendientes)): ?>
                        <p class="center-align grey-text">No hay pedidos pendientes de pago.</p>
                    <?php else: ?>
                        <table class="striped responsive-table">
                            <thead>
                                <tr>
                                    <th>Pedido</th>
                                    <th>Cliente</th>
                                    <th>Total</th>
                                    <th>Fecha</th>
                                    <th>Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pedidosPendientes as $pp): ?>
                                    <tr>
                                        <td><?php echo esc($pp['numero_pedido']); ?></td>
                                        <td><?php echo esc($pp['cliente'] ?? 'Cliente General'); ?></td>
                                        <td>$<?php echo number_format((float)$pp['total'], 2); ?></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime((string)$pp['fecha_creacion'])); ?></td>
                                        <td>
                                            <form method="POST" style="display:inline;">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="id_pedido" value="<?php echo (int)$pp['id_pedido']; ?>">
                                                <input type="hidden" name="accion" value="confirmar_pago">
                                                <button type="submit" class="btn-small green darken-2 waves-effect waves-light">Marcar como Pagado</button>
                                            </form>
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

    <div class="row">
        <div class="col s12">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Pedidos Pendientes de Asignación</span>
                    
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
                                            
                                            <td><strong><?php echo esc($p['numero_pedido']); ?></strong></td>
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
