<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

if (isAuthenticated()) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF inválido.';
    } else {
        $nombre = trim($_POST['nombre'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';

        if (empty($nombre) || empty($email) || empty($password)) {
            $error = 'Todos los campos son obligatorios.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'El formato del correo electrónico no es válido.';
        } elseif ($password !== $confirm_password) {
            $error = 'Las contraseñas no coinciden.';
        } elseif (!isPasswordSecure($password)) {
            $error = 'Seguridad insuficiente: la contraseña debe tener al menos 10 caracteres, incluir mayúsculas, minúsculas, números y un símbolo.';
        } else {
            try {
                $pdo = getPDO();
                // Verificar si el email ya existe
                $stmt = $pdo->prepare("SELECT id_usuario FROM usuarios WHERE email = ?");
                $stmt->execute([$email]);
                if ($stmt->fetch()) {
                    $error = 'El correo electrónico ya está registrado.';
                } else {
                    $hash = password_hash($password, PASSWORD_BCRYPT);
                    // id_rol = 4 es 'cliente' según vimos anteriormente
                    $sql = "INSERT INTO usuarios (nombre, email, contrasena, id_rol, id_almacen, estado) VALUES (?, ?, ?, 4, NULL, 'activo')";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$nombre, $email, $hash]);
                    
                    $newUserId = $pdo->lastInsertId();
                    
                    // Crear entrada en tabla clientes para este usuario
                    $stmtCli = $pdo->prepare("INSERT INTO clientes (nombre, email, id_usuario) VALUES (?, ?, ?)");
                    $stmtCli->execute([$nombre, $email, $newUserId]);
                    
                    $success = 'Cuenta creada con éxito. Ya puedes iniciar sesión.';
                }
            } catch (PDOException $e) {
                $error = 'Error al registrar usuario: ' . $e->getMessage();
            }
        }
    }
}

$pageTitle = 'Registro de Cliente';
include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row" style="margin-top: 50px;">
        <div class="col s12 m6 offset-m3">
            <div class="card">
                <div class="card-content">
                    <span class="card-title center-align">Crear Cuenta Nueva</span>
                    <p class="center-align grey-text">Únete a Belleza y Bienestar para gestionar tus compras.</p>
                    
                    <?php if ($error): ?>
                        <div class="card-panel red lighten-4 red-text text-darken-4">
                            <?php echo esc($error); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="card-panel green lighten-4 green-text text-darken-4">
                            <?php echo esc($success); ?>
                            <div style="margin-top: 10px;">
                                <a href="<?php echo BASE_URL; ?>views/login.php" class="btn green waves-effect waves-light">Ir a Login</a>
                            </div>
                        </div>
                    <?php else: ?>
                        <form method="POST">
                            <?php echo csrfInput(); ?>
                            <div class="input-field">
                                <i class="material-icons prefix">person</i>
                                <input id="nombre" name="nombre" type="text" required value="<?php echo esc($nombre ?? ''); ?>">
                                <label for="nombre">Nombre Completo</label>
                            </div>
                            <div class="input-field">
                                <i class="material-icons prefix">email</i>
                                <input id="email" name="email" type="email" required value="<?php echo esc($email ?? ''); ?>">
                                <label for="email">Correo Electrónico</label>
                            </div>
                            <div class="input-field">
                                <i class="material-icons prefix">lock</i>
                                <input id="password" name="password" type="password" required>
                                <label for="password">Contraseña</label>
                                <i class="material-icons" style="position: absolute; right: 10px; top: 15px; cursor: pointer; color: #9e9e9e;" onclick="togglePass('password', this)">visibility</i>
                            </div>
                            <div class="input-field">
                                <i class="material-icons prefix">lock_outline</i>
                                <input id="confirm_password" name="confirm_password" type="password" required>
                                <label for="confirm_password">Confirmar Contraseña</label>
                                <i class="material-icons" style="position: absolute; right: 10px; top: 15px; cursor: pointer; color: #9e9e9e;" onclick="togglePass('confirm_password', this)">visibility</i>
                            </div>
                            
                            <div style="margin-top: 30px;">
                                <button type="submit" class="btn-large blue darken-4 waves-effect waves-light w-100">
                                    REGISTRARME
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="card-action center-align">
                    <p>¿Ya tienes cuenta? <a href="<?php echo BASE_URL; ?>views/login.php" class="blue-text text-darken-4">Inicia Sesión</a></p>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .w-100 { width: 100%; }
</style>

<script>
    function togglePass(inputId, iconElement) {
        const input = document.getElementById(inputId);
        if (input.type === 'password') {
            input.type = 'text';
            iconElement.innerText = 'visibility_off';
        } else {
            input.type = 'password';
            iconElement.innerText = 'visibility';
        }
    }
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
