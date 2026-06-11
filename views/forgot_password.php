<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

$error = '';
$success = '';
$step = 1; // 1: Pedir email, 2: Pedir código y nueva clave
$email_input = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCsrfToken($token)) {
        $error = 'Token CSRF inválido. Por favor recarga la página e inténtalo de nuevo.';
    } else {
        $accion = $_POST['accion'] ?? '';
        
        if ($accion === 'solicitar') {
            $email_input = trim($_POST['email'] ?? '');
            if ($email_input === '') {
                $error = 'Ingresa un correo electrónico válido.';
            } else {
                generatePasswordResetToken($email_input);
                $success = 'Se ha generado un código de seguridad. Revisa tu correo (o el archivo mail_log.txt en XAMPP).';
                $step = 2;
            }
        } elseif ($accion === 'restablecer') {
            $code = trim($_POST['code'] ?? '');
            $new_pass = $_POST['new_password'] ?? '';
            $email_input = $_POST['email_hidden'] ?? '';

            if (resetPasswordWithToken($code, $new_pass)) {
                $success = 'Tu contraseña ha sido actualizada con éxito.';
                $step = 3; // Éxito total
            } else {
                $error = 'El código es inválido o ha expirado.';
                $step = 2;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar contraseña - Sistema de Punto de Venta</title>
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
                <span class="card-title center-align">Recuperar contraseña</span>
                <?php if ($error): ?>
                    <div class="card-panel red lighten-4 red-text">
                        <?php echo esc($error); ?>
                    </div>
                <?php endif; ?>
                <?php if ($success): ?>
                    <div class="card-panel green lighten-4 green-text text-darken-4">
                        <?php echo esc($success); ?>
                    </div>
                <?php endif; ?>

                <?php if ($step === 1): ?>
                <form method="post">
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="accion" value="solicitar">
                    <div class="input-field">
                        <i class="material-icons prefix">email</i>
                        <input type="email" id="email" name="email" required>
                        <label for="email">Correo electrónico</label>
                    </div>
                    <div class="center-align">
                        <button type="submit" class="btn-large blue waves-effect waves-light">
                            Obtener código
                            <i class="material-icons right">send</i>
                        </button>
                    </div>
                </form>
                <?php elseif ($step === 2): ?>
                <form method="post">
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="accion" value="restablecer">
                    <input type="hidden" name="email_hidden" value="<?php echo esc($email_input); ?>">
                    
                    <p class="orange-text"><strong>Paso 2:</strong> Ingresa el código de 6 dígitos.</p>
                    
                    <div class="input-field">
                        <i class="material-icons prefix">lock_open</i>
                        <input type="text" id="code" name="code" required maxlength="6" class="center-align" style="font-size: 2rem; letter-spacing: 10px;">
                        <label for="code">Código de Seguridad</label>
                    </div>
                    
                    <div class="input-field">
                        <i class="material-icons prefix">vpn_key</i>
                        <input type="password" id="new_password" name="new_password" required autocomplete="new-password">
                        <label for="new_password">Nueva Contraseña</label>
                        <i class="material-icons" style="position: absolute; right: 10px; top: 15px; cursor: pointer; color: #9e9e9e;" onclick="togglePass('new_password', this)">visibility</i>
                    </div>

                    <div class="center-align">
                        <button type="submit" class="btn-large green waves-effect waves-light">
                            Cambiar Contraseña
                            <i class="material-icons right">check</i>
                        </button>
                    </div>
                </form>
                <?php elseif ($step === 3): ?>
                <div class="center-align">
                    <i class="material-icons large green-text">check_circle</i>
                    <p>Ya puedes entrar con tu nueva contraseña.</p>
                    <a href="login.php" class="btn blue darken-4">Ir al Inicio de Sesión</a>
                </div>
                <?php endif; ?>

                <div class="center-align" style="margin-top: 20px;">
                    <a href="<?php echo BASE_URL; ?>views/login.php" class="<?php echo ($step === 3) ? 'hide' : 'blue-text'; ?>">Volver al inicio de sesión</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
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
</body>
</html>
