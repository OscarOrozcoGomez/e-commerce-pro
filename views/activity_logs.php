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

$query .= " ORDER BY l.fecha_creacion DESC LIMIT 500";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$logs = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid" style="padding: 20px;">
    <div class="row">
        <div class="col s12">
            <h4><i class="material-icons left" style="font-size: 2.5rem;">history</i> Log de Actividad de Usuarios</h4>
            <p class="grey-text">Seguimiento detallado de clics y visitas de todos los usuarios del sistema.</p>
        </div>
    </div>

    <!-- Filtros -->
    <div class="card">
        <div class="card-content">
            <form method="GET" class="row" style="margin-bottom: 0;">
                <div class="input-field col s12 m4">
                    <select name="usuario" class="browser-default">
                        <option value="0">Todos los usuarios</option>
                        <?php foreach ($usuarios as $u): ?>
                            <option value="<?php echo $u['id_usuario']; ?>" <?php echo $filtro_usuario == $u['id_usuario'] ? 'selected' : ''; ?>>
                                <?php echo esc($u['nombre']); ?> (<?php echo esc($u['email']); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="input-field col s12 m3">
                    <select name="tipo" class="browser-default">
                        <option value="">Todos los tipos</option>
                        <option value="visit" <?php echo $filtro_tipo == 'visit' ? 'selected' : ''; ?>>Visitas</option>
                        <option value="click" <?php echo $filtro_tipo == 'click' ? 'selected' : ''; ?>>Clics</option>
                    </select>
                </div>
                <div class="col s12 m2" style="padding-top: 15px;">
                    <button type="submit" class="btn indigo waves-effect waves-light">Filtrar</button>
                </div>
                <div class="col s12 m3 right-align" style="padding-top: 15px;">
                    <a href="?" class="btn-flat grey-text">Limpiar</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Tabla de Logs -->
    <div class="card">
        <div class="card-content" style="overflow-x: auto;">
            <table class="striped condensed">
                <thead>
                    <tr>
                        <th>Fecha/Hora</th>
                        <th>Usuario</th>
                        <th>Acción</th>
                        <th>Elemento / URL</th>
                        <th>IP / Navegador</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5" class="center-align">No hay registros de actividad.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td style="font-size: 0.85rem;"><?php echo date('d/m/Y H:i:s', strtotime($log['fecha_creacion'])); ?></td>
                                <td>
                                    <span style="font-weight: bold;"><?php echo esc($log['usuario_nombre']); ?></span><br>
                                    <small class="grey-text"><?php echo esc($log['usuario_email']); ?></small>
                                </td>
                                <td>
                                    <?php if ($log['tipo_accion'] === 'visit'): ?>
                                        <span class="badge blue white-text" style="float: none; margin: 0;">VISITA</span>
                                    <?php else: ?>
                                        <span class="badge green white-text" style="float: none; margin: 0;">CLIC</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($log['tipo_accion'] === 'click'): ?>
                                        <strong>"<?php echo esc($log['elemento_texto']); ?>"</strong><br>
                                        <small class="blue-text">ID: <?php echo esc($log['elemento_id'] ?? 'N/A'); ?></small><br>
                                    <?php endif; ?>
                                    <small class="grey-text text-darken-1" style="word-break: break-all;">
                                        URL: <?php echo esc(str_replace(BASE_URL, '/', $log['url'])); ?>
                                    </small>
                                </td>
                                <td>
                                    <small><?php echo esc($log['ip_address']); ?></small><br>
                                    <small class="grey-text" style="font-size: 0.7rem; display: block; max-width: 200px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?php echo esc($log['user_agent']); ?>">
                                        <?php echo esc($log['user_agent']); ?>
                                    </small>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
