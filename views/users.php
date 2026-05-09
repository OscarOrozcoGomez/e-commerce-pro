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
                $password = password_hash($_POST['password'] ?? '', PASSWORD_BCRYPT);
                $id_rol = intval($_POST['id_rol'] ?? 0);
                $id_almacen = intval($_POST['id_almacen'] ?? 0) ?: null;
                
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
            $stmt = $pdo->prepare("UPDATE usuarios SET estado = ? WHERE id_usuario = ?");
            $stmt->execute([$nuevo_estado, $id]);
            logAudit('USUARIO_ESTADO_CAMBIADO', 'usuarios', $id, "Nuevo estado: $nuevo_estado");
            $success = 'Estado de usuario actualizado.';
        }
    }
}

// Obtener usuarios
try {
    $sql = "SELECT u.id_usuario, u.nombre, u.email, r.nombre as rol, a.nombre as almacen, u.estado
            FROM usuarios u
            JOIN roles r ON u.id_rol = r.id_rol
            LEFT JOIN almacenes a ON u.id_almacen = a.id_almacen
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
            <h4>Gestionar Usuarios</h4>
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
                                                <button type="submit" class="btn-small <?php echo $user['estado'] === 'activo' ? 'orange' : 'green'; ?>">
                                                    <?php echo $user['estado'] === 'activo' ? 'Desactivar' : 'Activar'; ?>
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
