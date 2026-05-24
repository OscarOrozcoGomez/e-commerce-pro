<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
if (!isAdmin()) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$pageTitle = 'Logs de Actividad';
$pdo = getPDO();

$filtro_usuario = isset($_GET['usuario']) ? intval($_GET['usuario']) : 0;
$filtro_tipo = isset($_GET['tipo']) ? $_GET['tipo'] : '';
$fecha_inicio = $_GET['fecha_inicio'] ?? '';
$fecha_fin = $_GET['fecha_fin'] ?? '';

// Obtener lista de usuarios para el filtro
$usuarios = $pdo->query("SELECT id_usuario, nombre, email FROM usuarios ORDER BY nombre")->fetchAll();

// Construir consulta de logs
$query = "SELECT l.*, u.nombre as usuario_nombre, u.email as usuario_email 
          FROM logs_actividad l 
          JOIN usuarios u ON l.id_usuario = u.id_usuario 
          WHERE 1=1";

$params = [];
if ($filtro_usuario > 0) {
    $query .= " AND l.id_usuario = :id_usuario";
    $params[':id_usuario'] = $filtro_usuario;
}
if ($filtro_tipo !== '') {
    $query .= " AND l.tipo_accion = :tipo";
    $params[':tipo'] = $filtro_tipo;
}
if ($fecha_inicio) {
    $query .= " AND DATE(l.fecha_creacion) >= :inicio";
    $params[':inicio'] = $fecha_inicio;
}
if ($fecha_fin) {
    $query .= " AND DATE(l.fecha_creacion) <= :fin";
    $params[':fin'] = $fecha_fin;
}

$query .= " ORDER BY l.fecha_creacion DESC LIMIT 500";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll();

// Agrupar logs por Mes y Día para la vista colapsable
$groupedLogs = [];
foreach ($logs as $log) {
    $dateObj = new DateTime($log['fecha_creacion']);
    $monthKey = $dateObj->format('F Y'); // E.g., "May 2024"
    $dayKey = $dateObj->format('Y-m-d');
    
    if (!isset($groupedLogs[$monthKey])) $groupedLogs[$monthKey] = [];
    if (!isset($groupedLogs[$monthKey][$dayKey])) $groupedLogs[$monthKey][$dayKey] = [];
    $groupedLogs[$monthKey][$dayKey][] = $log;
}

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid" style="padding: 20px;">
    <div class="row">
        <div class="col s12">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; flex-wrap: wrap; gap: 10px;">
                <h4 style="margin: 0;"><i class="material-icons left" style="font-size: 2.5rem;">history</i> Log de Actividad de Usuarios</h4>
                <a href="dashboard.php" class="btn blue darken-4 waves-effect waves-light"><i class="material-icons left">dashboard</i> Volver al Dashboard</a>
            </div>
            <p class="grey-text">Seguimiento detallado de clics y visitas de todos los usuarios del sistema.</p>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card">
        <div class="card-content">
            <form method="GET" class="row" style="margin-bottom: 0;">
                <div class="input-field col s12 m3">
                    <select name="usuario" class="browser-default">
                        <option value="0">Todos los usuarios</option>
                        <?php foreach ($usuarios as $u): ?>
                            <option value="<?php echo $u['id_usuario']; ?>" <?php echo $filtro_usuario == $u['id_usuario'] ? 'selected' : ''; ?>>
                                <?php echo esc($u['nombre']); ?> (<?php echo esc($u['email']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-field col s12 m2">
                    <select name="tipo" class="browser-default">
                        <option value="">Todos los tipos</option>
                        <option value="visit" <?php echo $filtro_tipo == 'visit' ? 'selected' : ''; ?>>Visitas</option>
                        <option value="click" <?php echo $filtro_tipo == 'click' ? 'selected' : ''; ?>>Clics</option>
                    </select>
                </div>
                <div class="input-field col s6 m2">
                    <input type="date" name="fecha_inicio" id="fecha_inicio" value="<?php echo esc($fecha_inicio); ?>">
                    <label for="fecha_inicio" class="active">Desde</label>
                </div>
                <div class="input-field col s6 m2">
                    <input type="date" name="fecha_fin" id="fecha_fin" value="<?php echo esc($fecha_fin); ?>">
                    <label for="fecha_fin" class="active">Hasta</label>
                </div>
                <div class="col s12 m1" style="padding-top: 15px;">
                    <button type="submit" class="btn indigo waves-effect waves-light">Filtrar</button>
                </div>
                <div class="col s12 m2 right-align" style="padding-top: 15px;">
                    <a href="?" class="btn-flat grey-text">Limpiar</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Visualización de Logs Agrupados -->
    <?php if (empty($groupedLogs)): ?>
        <div class="card-panel center-align grey-text">No se encontraron registros para los filtros seleccionados.</div>
    <?php else: ?>
        <?php 
        $firstDay = true;
        foreach ($groupedLogs as $month => $days): ?>
            <div class="month-group">
                <h5 class="indigo-text text-darken-4" style="margin-top: 30px; font-weight: bold; border-bottom: 2px solid #e8eaf6; padding-bottom: 10px;">
                    <i class="material-icons left">calendar_month</i> <?php echo $month; ?>
                </h5>
                
                <ul class="collapsible z-depth-1">
                    <?php foreach ($days as $day => $dayLogs): ?>
                        <li class="<?php echo $firstDay ? 'active' : ''; ?>">
                            <div class="collapsible-header" style="display: flex; justify-content: space-between; align-items: center;">
                                <span>
                                    <i class="material-icons indigo-text">event</i>
                                    <strong><?php echo date('d \d\e F', strtotime($day)); ?></strong>
                                </span>
                                <span class="new badge blue darken-1" data-badge-caption="acciones"><?php echo count($dayLogs); ?></span>
                            </div>
                            <div class="collapsible-body white" style="padding: 0;">
                                <table class="striped highlight responsive-table">
                                    <thead>
                                        <tr>
                                            <th>Hora</th>
                                            <th>Usuario</th>
                                            <th>Acción</th>
                                            <th>Detalle</th>
                                            <th>IP</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($dayLogs as $log): ?>
                                            <tr>
                                                <td><?php echo date('H:i:s', strtotime($log['fecha_creacion'])); ?></td>
                                                <td>
                                                    <span style="font-weight: 500;"><?php echo esc($log['usuario_nombre']); ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge <?php echo $log['tipo_accion'] === 'visit' ? 'blue' : 'green'; ?> white-text" style="float: none;">
                                                        <?php echo strtoupper($log['tipo_accion']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($log['tipo_accion'] === 'click'): ?>
                                                        <strong>"<?php echo esc($log['elemento_texto']); ?>"</strong><br>
                                                    <?php endif; ?>
                                                    <small class="grey-text"><?php echo esc(str_replace(BASE_URL, '/', $log['url'])); ?></small>
                                                </td>
                                                <td><small><?php echo esc($log['ip_address']); ?></small></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </li>
                    <?php 
                    $firstDay = false;
                    endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<style>
    .collapsible-header:hover { background-color: #f5f5f5; }
    .collapsible-body table { font-size: 0.9rem; }
    .badge { border-radius: 4px; min-width: 60px; font-weight: bold; }
    input[type="date"] { margin-bottom: 0 !important; }
</style>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const elems = document.querySelectorAll('.collapsible');
        M.Collapsible.init(elems, { accordion: false });
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
