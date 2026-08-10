<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
requirePermission('gestionar_usuarios', BASE_URL . 'views/dashboard.php');

$pageTitle = 'Gestionar Usuarios';
$pdo = getPDO();
$error = '';
$success = '';

function generateTemporarySecurePassword(int $length = 12): string
{
    $length = max(10, $length);

    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghijkmnpqrstuvwxyz';
    $digits = '23456789';
    $symbols = '!@#$%^&*()_+-=';

    $password = [
        $upper[random_int(0, strlen($upper) - 1)],
        $lower[random_int(0, strlen($lower) - 1)],
        $digits[random_int(0, strlen($digits) - 1)],
        $symbols[random_int(0, strlen($symbols) - 1)],
    ];

    $all = $upper . $lower . $digits . $symbols;
    while (count($password) < $length) {
        $password[] = $all[random_int(0, strlen($all) - 1)];
    }

    shuffle($password);
    return implode('', $password);
}

function getStaffUserById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT u.id_usuario, u.estado, COALESCE(u.es_superadmin, 0) AS es_superadmin, r.nombre AS rol
                           FROM usuarios u
                           JOIN roles r ON u.id_rol = r.id_rol
                           WHERE u.id_usuario = ?
                           LIMIT 1");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user !== false ? $user : null;
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF inválido. Por favor recarga la página e inténtalo de nuevo.';
    } else {
        $accion = htmlspecialchars($_POST['accion']);
        
        if ($accion === 'agregar') {
            try {
                $email = htmlspecialchars($_POST['email'] ?? '');
                $nombre = htmlspecialchars($_POST['nombre'] ?? '');
                $passwordRaw = $_POST['password'] ?? '';

                if (!isPasswordSecure($passwordRaw)) {
                    throw new Exception("La contraseña no cumple con los requisitos mínimos de seguridad (10 caracteres, mayúscula, número y símbolo).");
                }

                $password = password_hash($passwordRaw, PASSWORD_BCRYPT);
                $id_rol = intval($_POST['id_rol'] ?? 0);
                $id_almacen = intval($_POST['id_almacen'] ?? 0) ?: null;
                
                // Validar que Vendedores y Encargados tengan sucursal asignada
                $stmtRol = $pdo->prepare("SELECT nombre FROM roles WHERE id_rol = ?");
                $stmtRol->execute([$id_rol]);
                $rolNombre = $stmtRol->fetchColumn();

                if ($rolNombre === 'admin' && !isSuperAdmin()) {
                    throw new Exception('Solo un super admin puede crear otros administradores.');
                }

                if (in_array($rolNombre, ['vendedor', 'encargado']) && !$id_almacen) {
                    throw new Exception("Los vendedores y encargados deben tener una sucursal asignada obligatoriamente.");
                }

                $sql = "INSERT INTO usuarios (nombre, email, contrasena, id_rol, id_almacen, estado) 
                        VALUES (:nombre, :email, :contrasena, :id_rol, :id_almacen, 'activo')";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':nombre' => $nombre,
                    ':email' => $email,
                    ':contrasena' => $password,
                    ':id_rol' => $id_rol,
                    ':id_almacen' => $id_almacen,
                ]);
                logAudit('USUARIO_CREADO', 'usuarios', (int)$pdo->lastInsertId(), "Email: $email");
                $success = 'Usuario creado correctamente.';
            } catch (PDOException $e) {
                $error = 'Error: ' . $e->getMessage();
            }
        } elseif ($accion === 'cambiar_estado') {
            $id = intval($_POST['id_usuario']);
            $nuevo_estado = $_POST['estado'] === 'activo' ? 'inactivo' : 'activo';

            $targetUser = getStaffUserById($pdo, $id);
            if (!$targetUser) {
                throw new Exception('Usuario no encontrado.');
            }

            if (isAdminAccount($targetUser) && !isSuperAdmin()) {
                throw new Exception('Solo un super admin puede modificar cuentas de administrador.');
            }

            if (($targetUser['es_superadmin'] ?? 0) && $nuevo_estado === 'inactivo') {
                $stmtSuper = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE estado = 'activo' AND COALESCE(es_superadmin, 0) = 1");
                $stmtSuper->execute();
                if ((int)$stmtSuper->fetchColumn() <= 1) {
                    throw new Exception('No puedes desactivar el último super admin activo.');
                }
            }

            $stmt = $pdo->prepare("UPDATE usuarios SET estado = ? WHERE id_usuario = ?");
            $stmt->execute([$nuevo_estado, $id]);
            logAudit('USUARIO_ESTADO_CAMBIADO', 'usuarios', $id, "Nuevo estado: $nuevo_estado");
            $success = 'Estado de usuario actualizado.';
        } elseif ($accion === 'desbloquear') {
            $id = intval($_POST['id_usuario']);

            $targetUser = getStaffUserById($pdo, $id);
            if (!$targetUser) {
                throw new Exception('Usuario no encontrado.');
            }

            if (isAdminAccount($targetUser) && !isSuperAdmin()) {
                throw new Exception('Solo un super admin puede desbloquear cuentas de administrador.');
            }

            $pdo->prepare("UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id_usuario = ?")->execute([$id]);
            logAudit('USUARIO_DESBLOQUEADO', 'usuarios', $id, "Cuenta desbloqueada manualmente por admin");
            $success = 'Usuario desbloqueado correctamente.';
        } elseif ($accion === 'reset_password_staff') {
            try {
                $id = intval($_POST['id_usuario'] ?? 0);
                $emailUsuario = trim((string)($_POST['email_usuario'] ?? ''));

                if ($id <= 0) {
                    throw new Exception('ID de usuario inválido para reset de contraseña.');
                }

                $targetUser = getStaffUserById($pdo, $id);
                if (!$targetUser) {
                    throw new Exception('Usuario no encontrado.');
                }

                if (isAdminAccount($targetUser) && !isSuperAdmin()) {
                    throw new Exception('Solo un super admin puede resetear la contraseña de cuentas de administrador.');
                }

                $tempPassword = generateTemporarySecurePassword(12);
                $tempHash = password_hash($tempPassword, PASSWORD_BCRYPT);

                $pdo->prepare("UPDATE usuarios SET contrasena = ?, intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id_usuario = ?")
                    ->execute([$tempHash, $id]);

                logAudit('USUARIO_PASSWORD_RESETEADA', 'usuarios', $id, 'Reset manual de contraseña para staff');
                $target = $emailUsuario !== '' ? $emailUsuario : ('usuario #' . $id);
                $success = 'Contraseña temporal generada para ' . $target . ': ' . $tempPassword;
            } catch (Throwable $e) {
                $error = 'No se pudo resetear la contraseña: ' . $e->getMessage();
            }
        }
    }
}

// Obtener usuarios
try {
    $sql = "SELECT u.id_usuario, u.nombre, u.email, r.nombre as rol, a.nombre as almacen, u.estado, u.es_superadmin, u.intentos_fallidos, u.bloqueado_hasta
            FROM usuarios u
            JOIN roles r ON u.id_rol = r.id_rol
            LEFT JOIN almacenes a ON u.id_almacen = a.id_almacen
            WHERE r.nombre != 'cliente'
            ORDER BY u.nombre";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $usuarios = $stmt->fetchAll();
} catch (PDOException $e) {
    $usuarios = [];
}

// Obtener roles y almacenes
try {
    $stmt = $pdo->prepare("SELECT * FROM roles WHERE estado = 'activo'");
    $stmt->execute();
    $roles = $stmt->fetchAll();
    
    $stmt = $pdo->prepare("SELECT * FROM almacenes WHERE estado = 'activo'");
    $stmt->execute();
    $almacenes = $stmt->fetchAll();
} catch (PDOException $e) {
    $roles = [];
    $almacenes = [];
}

include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="col s12">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 20px; flex-wrap: wrap;">
                <h4 style="margin: 0;">Gestionar Usuarios</h4>
                <a href="dashboard.php" class="btn blue darken-4 waves-effect waves-light"><i class="material-icons left">dashboard</i> Volver al Dashboard</a>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="row">
            <div class="col s12">
                <div class="card red lighten-2">
                    <div class="card-content white-text">
                        <p><?php echo esc($error); ?></p>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="row">
            <div class="col s12">
                <div class="card green lighten-2">
                    <div class="card-content white-text">
                        <p><?php echo esc($success); ?></p>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col s12 m6">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Crear Nuevo Usuario</span>
                    <form method="POST">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="accion" value="agregar">
                        
                        <div class="input-field">
                            <input type="text" id="nombre" name="nombre" required>
                            <label for="nombre">Nombre Completo</label>
                        </div>
                        
                        <div class="input-field">
                            <input type="email" id="email" name="email" required>
                            <label for="email">Email</label>
                        </div>
                        
                        <div class="input-field">
                            <input type="password" id="password" name="password" required>
                            <label for="password">Contraseña</label>
                        </div>
                        
                        <div class="input-field">
                            <select name="id_rol" required>
                                <option value="">-- Selecciona rol --</option>
                                <?php foreach ($roles as $rol): ?>
                                    <?php if (!isSuperAdmin() && $rol['nombre'] === 'admin') continue; ?>
                                    <option value="<?php echo $rol['id_rol']; ?>"><?php echo esc($rol['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label>Rol</label>
                        </div>
                        
                        <div class="input-field">
                            <select name="id_almacen">
                                <option value="">-- Sin almacén asignado --</option>
                                <?php foreach ($almacenes as $almacen): ?>
                                    <option value="<?php echo $almacen['id_almacen']; ?>"><?php echo esc($almacen['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label>Almacén</label>
                        </div>
                        
                        <button type="submit" class="btn waves-effect waves-light blue">
                            Crear Usuario <i class="material-icons right">person_add</i>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col s12 m6">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Usuarios del Sistema</span>
                    <div style="overflow-x: auto;">
                        <table class="striped">
                            <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th>Email</th>
                                    <th>Rol</th>
                                    <th>Almacén</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($usuarios as $user): ?>
                                    <tr>
                                        <td><?php echo esc($user['nombre']); ?></td>
                                        <td><?php echo esc($user['email']); ?></td>
                                        <td><?php echo esc($user['rol']); ?></td>
                                        <td><?php echo esc($user['almacen'] ?? 'N/A'); ?></td>
                                        <td>
                                            <form method="POST" style="display:inline;">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="accion" value="cambiar_estado">
                                                <input type="hidden" name="id_usuario" value="<?php echo $user['id_usuario']; ?>">
                                                <input type="hidden" name="estado" value="<?php echo $user['estado']; ?>">
                                                <button type="submit" class="btn-small <?php echo $user['estado'] === 'activo' ? 'orange' : 'green'; ?>" title="<?php echo $user['estado'] === 'activo' ? 'Desactivar' : 'Activar'; ?>">
                                                    <i class="material-icons"><?php echo $user['estado'] === 'activo' ? 'block' : 'check'; ?></i>
                                                </button>
                                            </form>

                                            <?php if ((int)$user['intentos_fallidos'] >= 5 || ($user['bloqueado_hasta'] && strtotime($user['bloqueado_hasta']) > time())): ?>
                                            <form method="POST" style="display:inline;">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="accion" value="desbloquear">
                                                <input type="hidden" name="id_usuario" value="<?php echo $user['id_usuario']; ?>">
                                                <button type="submit" class="btn-small blue waves-effect waves-light" title="Desbloquear intentos">
                                                    <i class="material-icons">lock_open</i>
                                                </button>
                                            </form>
                                            <?php endif; ?>

                                            <form method="POST" style="display:inline;" onsubmit="return confirm('¿Resetear contraseña de este usuario staff?');">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="accion" value="reset_password_staff">
                                                <input type="hidden" name="id_usuario" value="<?php echo $user['id_usuario']; ?>">
                                                <input type="hidden" name="email_usuario" value="<?php echo esc($user['email']); ?>">
                                                <button type="submit" class="btn-small red darken-2 waves-effect waves-light" title="Resetear contraseña">
                                                    <i class="material-icons">vpn_key</i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var selects = document.querySelectorAll('select');
        M.FormSelect.init(selects);
        M.updateTextFields();
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
