<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
if (!isEncargado() && !isAdmin()) {
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

try {
    $stmtMeta = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pickup_notificaciones'");
    $stmtMeta->execute();
    $hasPickupTable = ((int)$stmtMeta->fetchColumn()) > 0;
    if (!$hasPickupTable) {
        throw new RuntimeException('El modulo de pickup no esta habilitado aun. Aplica migraciones pendientes.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $error = 'Token CSRF invalido.';
        } else {
            $accion = trim((string)($_POST['accion'] ?? ''));
            $idNotificacion = (int)($_POST['id_notificacion'] ?? 0);
            $estado = trim((string)($_POST['estado'] ?? 'nueva'));
            $fechaEstimacionRaw = trim((string)($_POST['fecha_estimacion_reabasto'] ?? ''));
            $notas = trim((string)($_POST['notas_seguimiento'] ?? ''));

            if ($idNotificacion <= 0) {
                $error = 'Notificacion invalida.';
            } elseif ($accion !== 'actualizar') {
                $error = 'Accion no permitida.';
            } elseif (!in_array($estado, ['nueva', 'vista', 'atendida'], true)) {
                $error = 'Estado invalido.';
            } else {
                $fechaEstimacion = null;
                if ($fechaEstimacionRaw !== '') {
                    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $fechaEstimacionRaw);
                    if ($dt === false) {
                        $error = 'Formato invalido para fecha estimada de reabasto.';
                    } else {
                        $fechaEstimacion = $dt->format('Y-m-d H:i:s');
                    }
                }

                if ($error === '') {
                    $scopeWhere = '';
                    $scopeParams = [];
                    if (isEncargado()) {
                        $scopeWhere = ' AND id_almacen = :scope_almacen';
                        $scopeParams[':scope_almacen'] = $idAlmacenUsuario;
                    }

                    $sql = "UPDATE pickup_notificaciones
                            SET estado = :estado,
                                fecha_estimacion_reabasto = :fecha_estimacion,
                                notas_seguimiento = :notas,
                                id_usuario_seguimiento = :id_usuario,
                                fecha_vista = CASE WHEN :estado = 'vista' AND fecha_vista IS NULL THEN NOW() WHEN :estado = 'nueva' THEN NULL ELSE fecha_vista END,
                                fecha_atendida = CASE WHEN :estado = 'atendida' THEN NOW() WHEN :estado != 'atendida' THEN NULL ELSE fecha_atendida END,
                                actualizado_en = NOW()
                            WHERE id_notificacion = :id_notificacion{$scopeWhere}";

                    $stmt = $pdo->prepare($sql);
                    $params = [
                        ':estado' => $estado,
                        ':fecha_estimacion' => $fechaEstimacion,
                        ':notas' => $notas !== '' ? $notas : null,
                        ':id_usuario' => $idUsuario,
                        ':id_notificacion' => $idNotificacion,
                    ] + $scopeParams;

                    $stmt->execute($params);
                    if ($stmt->rowCount() > 0) {
                        $success = 'Seguimiento de pickup actualizado.';
                        logAudit('PICKUP_NOTIFICACION_ACTUALIZADA', 'pickup_notificaciones', $idNotificacion, "Estado actualizado a {$estado}");
                    } else {
                        $error = 'No se pudo actualizar la notificacion. Verifica permisos o si hubo cambios.';
                    }
                }
            }
        }
    }

    $estadoFiltro = trim((string)($_GET['estado'] ?? ''));
    $where = '1=1';
    $params = [];

    if (isEncargado()) {
        $where .= ' AND pn.id_almacen = :almacen';
        $params[':almacen'] = $idAlmacenUsuario;
    }

    if (in_array($estadoFiltro, ['nueva', 'vista', 'atendida'], true)) {
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
            a.nombre AS sucursal
        FROM pickup_notificaciones pn
        INNER JOIN pedidos p ON p.id_pedido = pn.id_pedido
        LEFT JOIN clientes c ON c.id_cliente = pn.id_cliente
        INNER JOIN almacenes a ON a.id_almacen = pn.id_almacen
        WHERE {$where}
        ORDER BY FIELD(pn.estado, 'nueva', 'vista', 'atendida'), pn.creado_en DESC
        LIMIT 300";

    $stmtList = $pdo->prepare($sqlList);
    $stmtList->execute($params);
    $notificaciones = $stmtList->fetchAll(PDO::FETCH_ASSOC);

    $whereCounts = '1=1';
    $paramsCounts = [];
    if (isEncargado()) {
        $whereCounts .= ' AND id_almacen = :almacen';
        $paramsCounts[':almacen'] = $idAlmacenUsuario;
    }

    $stmtCounts = $pdo->prepare("SELECT estado, COUNT(*) AS total FROM pickup_notificaciones WHERE {$whereCounts} GROUP BY estado");
    $stmtCounts->execute($paramsCounts);
    $rowsCounts = $stmtCounts->fetchAll(PDO::FETCH_ASSOC);
    $counts = ['nueva' => 0, 'vista' => 0, 'atendida' => 0];
    foreach ($rowsCounts as $row) {
        $key = (string)($row['estado'] ?? '');
        if (isset($counts[$key])) {
            $counts[$key] = (int)($row['total'] ?? 0);
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    $notificaciones = [];
    $counts = ['nueva' => 0, 'vista' => 0, 'atendida' => 0];
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
            <p class="grey-text">Seguimiento de pedidos para recoger en sucursal: nueva, vista, atendida y fecha estimada de reabasto.</p>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="card-panel green lighten-4 green-text text-darken-4"><?php echo esc($success); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="card-panel red lighten-4 red-text text-darken-4"><?php echo esc($error); ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col s12 m4">
            <div class="card deep-orange lighten-1 white-text">
                <div class="card-content">
                    <span class="card-title">Nuevas</span>
                    <p style="font-size:2.4rem; margin:0;"><?php echo (int)$counts['nueva']; ?></p>
                </div>
            </div>
        </div>
        <div class="col s12 m4">
            <div class="card amber darken-2 white-text">
                <div class="card-content">
                    <span class="card-title">Vistas</span>
                    <p style="font-size:2.4rem; margin:0;"><?php echo (int)$counts['vista']; ?></p>
                </div>
            </div>
        </div>
        <div class="col s12 m4">
            <div class="card green darken-2 white-text">
                <div class="card-content">
                    <span class="card-title">Atendidas</span>
                    <p style="font-size:2.4rem; margin:0;"><?php echo (int)$counts['atendida']; ?></p>
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
                                <option value="atendida" <?php echo $estadoFiltro === 'atendida' ? 'selected' : ''; ?>>Atendida</option>
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
                                        <td><?php echo esc((string)$n['sucursal']); ?></td>
                                        <td><?php echo esc((string)($n['cliente'] ?? 'N/A')); ?></td>
                                        <td>
                                            <span class="badge <?php echo $n['estado'] === 'nueva' ? 'deep-orange' : ($n['estado'] === 'vista' ? 'amber darken-2' : 'green'); ?> white-text" style="float:none;">
                                                <?php echo strtoupper((string)$n['estado']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($n['fecha_estimacion_reabasto'])): ?>
                                                <?php echo esc((string)$n['fecha_estimacion_reabasto']); ?>
                                            <?php else: ?>
                                                <span class="grey-text">Sin definir</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="POST" style="min-width:260px;">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="accion" value="actualizar">
                                                <input type="hidden" name="id_notificacion" value="<?php echo (int)$n['id_notificacion']; ?>">

                                                <select name="estado" class="browser-default" style="margin-bottom:6px; border:1px solid #9e9e9e;">
                                                    <option value="nueva" <?php echo $n['estado'] === 'nueva' ? 'selected' : ''; ?>>Nueva</option>
                                                    <option value="vista" <?php echo $n['estado'] === 'vista' ? 'selected' : ''; ?>>Vista</option>
                                                    <option value="atendida" <?php echo $n['estado'] === 'atendida' ? 'selected' : ''; ?>>Atendida</option>
                                                </select>

                                                <input type="datetime-local" name="fecha_estimacion_reabasto" value="<?php echo !empty($n['fecha_estimacion_reabasto']) ? date('Y-m-d\TH:i', strtotime((string)$n['fecha_estimacion_reabasto'])) : ''; ?>" style="margin-bottom:6px;">
                                                <input type="text" name="notas_seguimiento" maxlength="255" placeholder="Nota de seguimiento" value="<?php echo esc((string)($n['notas_seguimiento'] ?? '')); ?>" style="margin-bottom:6px;">

                                                <button type="submit" class="btn-small indigo waves-effect waves-light">Guardar</button>
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
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
