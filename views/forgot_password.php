<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $email = trim($_POST['email'] ?? '');

    if (!validateCsrfToken($token)) {
        $error = 'Token CSRF inválido. Por favor recarga la página e inténtalo de nuevo.';
    } elseif ($email === '') {
        $error = 'Ingresa un correo electrónico válido.';
    } else {
        generatePasswordResetToken($email);
        $success = 'Si existe una cuenta con ese correo, recibirás un enlace para restablecer tu contraseña.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recuperar contraseña - POS Sistema</title>
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
                    <div class="card-panel green lighten-4 green-text">
                        <?php echo esc($success); ?>
                    </div>
                <?php endif; ?>
                <form method="post">
                    <?php echo csrfInput(); ?>
                    <div class="input-field">
                        <i class="material-icons prefix">email</i>
                        <input type="email" id="email" name="email" required>
                        <label for="email">Correo electrónico</label>
                    </div>
                    <div class="center-align">
                        <button type="submit" class="btn-large blue waves-effect waves-light">
                            Enviar enlace de recuperación
                            <i class="material-icons right">send</i>
                        </button>
                    </div>
                </form>
                <div class="center-align" style="margin-top: 20px;">
                    <a href="<?php echo BASE_URL; ?>views/login.php" class="white-text">Volver al inicio de sesión</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
</body>
</html>
