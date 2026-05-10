<?php
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

if (isAuthenticated()) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $token = $_POST['csrf_token'] ?? '';

    if (!validateCsrfToken($token)) {
        $error = 'Token CSRF inválido. Por favor recarga la página e inténtalo de nuevo.';
    } elseif (authenticate($email, $password)) {
        session_regenerate_id(true);
        // Redirigir según el rol
        if (isCliente()) {
            header('Location: ' . BASE_URL . 'index.php');
        } else {
            header('Location: ' . BASE_URL . 'views/dashboard.php');
        }
        exit;
    } else {
        $error = 'Credenciales incorrectas.';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - POS Sistema</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        .login-container { max-width: 400px; margin: 50px auto; }
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .card { border-radius: 10px; }
    </style>
</head>
<body>
    <div class="container login-container">
        <div class="card">
            <div class="card-content">
                <span class="card-title center-align">Iniciar Sesión</span>
                <?php if ($error): ?>
                    <div class="card-panel red lighten-4 red-text">
                        <?php echo esc($error); ?>
                    </div>
                <?php endif; ?>
                <form method="post">
                    <?php echo csrfInput(); ?>
                    <div class="input-field">
                        <i class="material-icons prefix">email</i>
                        <input type="email" id="email" name="email" required>
                        <label for="email">Correo electrónico</label>
                    </div>
                    <div class="input-field">
                        <i class="material-icons prefix">lock</i>
                        <input type="password" id="password" name="password" required>
                        <label for="password">Contraseña</label>
                    </div>
                    <div class="right-align" style="margin-bottom: 20px;">
                        <a href="<?php echo BASE_URL; ?>views/forgot_password.php">¿Olvidaste tu contraseña?</a>
                    </div>
                    <div class="center-align">
                        <button type="submit" class="btn-large blue waves-effect waves-light">
                            Iniciar Sesión
                            <i class="material-icons right">send</i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
</body>
</html>