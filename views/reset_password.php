<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

setSecurityHeaders();

$token = trim($_GET['token'] ?? $_POST['token'] ?? '');
$error = '';
$success = '';

if (empty($token)) {
    $error = 'Token no válido. Comprueba el enlace del correo.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (!validateCsrfToken($csrfToken)) {
        $error = 'Token CSRF inválido. Por favor recarga la página e inténtalo de nuevo.';
    } elseif ($password === '' || $confirmPassword === '') {
        $error = 'Ambos campos de contraseña son obligatorios.';
    } elseif ($password !== $confirmPassword) {
        $error = 'Las contraseñas no coinciden.';
    } elseif (!isPasswordSecure($password)) {
        $error = 'La nueva contraseña debe tener al menos 10 caracteres, incluir mayúsculas, minúsculas, números y un símbolo.';
    } else {
        $resetError = null;
        if (resetPasswordWithToken($token, $password, $resetError)) {
            $success = 'Contraseña restablecida correctamente. Ahora puedes iniciar sesión.';
        } else {
            $error = $resetError ?: 'El token no es válido o ya expiró.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cambiar contraseña - Sistema de Punto de Venta</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        .login-container { max-width: 500px; margin: 50px auto; }
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .card { border-radius: 10px; }
    </style>
</head>
<body>
    <div class="container login-container">
        <div class="card">
            <div class="card-content">
                <span class="card-title center-align">Restablecer contraseña</span>
                <?php if ($error): ?>
                    <div class="card-panel red lighten-4 red-text">
                        <?php echo esc($error); ?>
                    </div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="card-panel green lighten-4 green-text">
                        <?php echo esc($success); ?>
                    </div>
                <?php endif; ?>

                <?php if (!$success && !empty($token)): ?>
                    <form method="post">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="token" value="<?php echo esc($token); ?>">

                        <div class="input-field">
                            <i class="material-icons prefix">lock</i>
                            <input type="password" id="password" name="password" required>
                            <label for="password">Nueva contraseña</label>
                        </div>

                        <div class="input-field">
                            <i class="material-icons prefix">lock</i>
                            <input type="password" id="confirm_password" name="confirm_password" required>
                            <label for="confirm_password">Confirma la contraseña</label>
                        </div>

                        <div class="center-align">
                            <button type="submit" class="btn-large blue waves-effect waves-light">
                                Guardar nueva contraseña
                                <i class="material-icons right">lock_open</i>
                            </button>
                        </div>
                    </form>
                <?php endif; ?>

                <div class="center-align" style="margin-top: 20px;">
                    <a href="<?php echo BASE_URL; ?>views/login.php" class="white-text">Volver al inicio de sesión</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
</body>
</html>
