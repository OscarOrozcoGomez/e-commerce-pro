<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
requireAuth();
if (!isAdmin()) { header('Location: dashboard.php'); exit; }

$pdo = getPDO();
$error = ''; $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF inválido.';
    } else {
        $id_u = (int)$_POST['id_usuario'];
        if ($_POST['accion'] === 'activar') {
            $pdo->prepare("UPDATE usuarios SET estado = 'activo' WHERE id_usuario = ?")->execute([$id_u]);
            $success = 'Cliente activado.';
        } elseif ($_POST['accion'] === 'desactivar') {
            $pdo->prepare("UPDATE usuarios SET estado = 'inactivo' WHERE id_usuario = ?")->execute([$id_u]);
            $success = 'Cliente bloqueado.';
        }
    }
}

$clientes = $pdo->query("SELECT c.*, u.estado, u.id_usuario FROM clientes c JOIN usuarios u ON c.id_usuario = u.id_usuario ORDER BY c.nombre ASC")->fetchAll();

$pageTitle = 'Administrar Clientes';
include __DIR__ . '/includes/header.php';
?>
<div class="container">
    <div class="row">
        <div class="col s12">
            <h4><i class="material-icons left blue-text">people</i> Gestión de Clientes</h4>
            <p class="grey-text">Listado maestro de clientes registrados en el sistema.</p>
        </div>
    </div>

    <?php if ($success): ?><div class="card-panel green lighten-4 green-text"><?php echo esc($success); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="card-panel red lighten-4 red-text"><?php echo esc($error); ?></div><?php endif; ?>

    <div class="card">
        <div class="card-content">
            <table class="striped responsive-table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Email</th>
                        <th>Estado</th>
                        <th class="center-align">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clientes as $c): ?>
                    <tr>
                        <td><strong><?php echo esc($c['nombre']); ?></strong></td>
                        <td><?php echo esc($c['email'] ?? 'N/A'); ?></td>
                        <td>
                            <span class="badge <?php echo $c['estado'] === 'activo' ? 'green' : 'red'; ?> white-text" style="float:none;">
                                <?php echo strtoupper($c['estado']); ?>
                            </span>
                        </td>
                        <td class="center-align">
                            <a href="#modal-dir-<?php echo $c['id_cliente']; ?>" class="btn-small blue waves-effect waves-light modal-trigger" title="Ver Direcciones">
                                <i class="material-icons">place</i>
                            </a>
                            <form method="POST" style="display:inline;">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="id_usuario" value="<?php echo $c['id_usuario']; ?>">
                                <input type="hidden" name="accion" value="<?php echo $c['estado'] === 'activo' ? 'desactivar' : 'activar'; ?>">
                                <button type="submit" class="btn-small <?php echo $c['estado'] === 'activo' ? 'orange' : 'green'; ?> waves-effect waves-light" 
                                        title="<?php echo $c['estado'] === 'activo' ? 'Bloquear' : 'Activar'; ?>">
                                    <i class="material-icons"><?php echo $c['estado'] === 'activo' ? 'block' : 'check'; ?></i>
                                </button>
                            </form>
                        </td>
                    </tr>

                    <!-- Modal Direcciones -->
                    <div id="modal-dir-<?php echo $c['id_cliente']; ?>" class="modal modal-fixed-footer" style="max-width: 500px;">
                        <div class="modal-content">
                            <h5>Direcciones de <?php echo esc($c['nombre']); ?></h5>
                            <div class="divider"></div>
                            <ul class="collection" style="margin-top: 20px;">
                                <?php
                                $stmtD = $pdo->prepare("SELECT * FROM cliente_direcciones WHERE id_cliente = ?");
                                $stmtD->execute([$c['id_cliente']]);
                                $dirs = $stmtD->fetchAll();
                                if (empty($dirs)):
                                ?>
                                    <li class="collection-item grey-text center">Sin direcciones registradas.</li>
                                <?php else: foreach ($dirs as $d): ?>
                                    <li class="collection-item">
                                        <strong><?php echo esc($d['alias']); ?></strong> 
                                        <?php if ($d['es_default']): ?><span class="new badge blue" data-badge-caption="Default"></span><?php endif; ?><br>
                                        <span class="grey-text text-darken-1"><?php echo esc($d['direccion']); ?></span>
                                    </li>
                                <?php endforeach; endif; ?>
                            </ul>
                        </div>
                        <div class="modal-footer">
                            <a href="#!" class="modal-close waves-effect waves-green btn-flat">Cerrar</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>document.addEventListener('DOMContentLoaded', () => M.Modal.init(document.querySelectorAll('.modal')));</script>

<?php include __DIR__ . '/includes/footer.php'; ?>