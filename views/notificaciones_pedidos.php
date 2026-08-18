<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
if (!isAdmin()) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$pageTitle = 'Notificaciones de Pedidos';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF inválido.';
    } else {
        $accion = $_POST['accion'];

        if ($accion === 'agregar') {
            $correo = trim((string) ($_POST['correo'] ?? ''));
            $resultado = dbAddOrderNotificationEmail($correo);
            if ($resultado['success']) {
                $success = $resultado['message'];
            } else {
                $error = $resultado['message'];
            }
        } elseif ($accion === 'cambiar_estado') {
            $idCorreo = (int) ($_POST['id_correo'] ?? 0);
            $nuevoActivo = ($_POST['nuevo_estado'] ?? '') === '1';
            if (dbSetOrderNotificationEmailActive($idCorreo, $nuevoActivo)) {
                $success = 'Estado actualizado correctamente.';
            } else {
                $error = 'No fue posible actualizar el estado del correo.';
            }
        } elseif ($accion === 'eliminar') {
            $idCorreo = (int) ($_POST['id_correo'] ?? 0);
            if (dbDeleteOrderNotificationEmail($idCorreo)) {
                $success = 'Correo eliminado de la lista.';
            } else {
                $error = 'No fue posible eliminar el correo.';
            }
        }
    }
}

$correos = dbGetOrderNotificationEmails();

include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="col s12">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 20px; flex-wrap: wrap; gap: 10px;">
                <h4 style="margin: 0;"><i class="material-icons left">mark_email_unread</i> Notificaciones de Pedidos</h4>
                <a href="dashboard.php" class="btn blue darken-4 waves-effect waves-light"><i class="material-icons left">dashboard</i> Volver al Dashboard</a>
            </div>
            <p class="grey-text" style="margin-top: 4px;">
                Cada vez que se registra un pedido nuevo en la tienda en línea, se envía un correo automático a todos los correos activos de esta lista.
            </p>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="card-panel red lighten-4 red-text"><?php echo esc($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="card-panel green lighten-4 green-text"><?php echo esc($success); ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col s12 m5">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Agregar Correo</span>
                    <form method="POST">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="accion" value="agregar">
                        <div class="input-field">
                            <input type="email" name="correo" id="correo" required placeholder="ejemplo@correo.com">
                            <label for="correo" class="active">Correo electrónico</label>
                        </div>
                        <button type="submit" class="btn blue darken-4 w-100">Agregar a la Lista</button>
                    </form>
                    <p class="grey-text text-small" style="margin-top: 14px;">
                        Puedes agregar tantos correos como necesites: dueños, encargados de sucursal, celulares con app de correo, etc.
                    </p>
                </div>
            </div>
        </div>

        <div class="col s12 m7">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Correos Configurados (<?php echo count($correos); ?>)</span>
                    <?php if (empty($correos)): ?>
                        <p class="grey-text">Aún no hay correos configurados. Agrega el primero para empezar a recibir avisos.</p>
                    <?php else: ?>
                        <table class="striped">
                            <thead>
                                <tr>
                                    <th>Correo</th>
                                    <th>Estado</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($correos as $c): ?>
                                    <tr>
                                        <td><?php echo esc((string) $c['correo']); ?></td>
                                        <td>
                                            <span class="badge <?php echo ((int) $c['activo'] === 1) ? 'green' : 'red'; ?> white-text" style="float:none;">
                                                <?php echo ((int) $c['activo'] === 1) ? 'ACTIVO' : 'INACTIVO'; ?>
                                            </span>
                                        </td>
                                        <td style="white-space: nowrap;">
                                            <form method="POST" style="display:inline;">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="accion" value="cambiar_estado">
                                                <input type="hidden" name="id_correo" value="<?php echo (int) $c['id_correo']; ?>">
                                                <input type="hidden" name="nuevo_estado" value="<?php echo ((int) $c['activo'] === 1) ? '0' : '1'; ?>">
                                                <button type="submit" class="btn-small <?php echo ((int) $c['activo'] === 1) ? 'orange darken-3' : 'green darken-2'; ?> waves-effect waves-light" title="Cambiar estado">
                                                    <i class="material-icons"><?php echo ((int) $c['activo'] === 1) ? 'pause' : 'check'; ?></i>
                                                </button>
                                            </form>
                                            <form method="POST" style="display:inline; margin-left:4px;" onsubmit="return confirm('¿Eliminar este correo de la lista?');">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="accion" value="eliminar">
                                                <input type="hidden" name="id_correo" value="<?php echo (int) $c['id_correo']; ?>">
                                                <button type="submit" class="btn-small red darken-2 waves-effect waves-light" title="Eliminar correo">
                                                    <i class="material-icons">delete</i>
                                                </button>
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

<style>.w-100 { width: 100%; }</style>
<?php include __DIR__ . '/includes/footer.php'; ?>
