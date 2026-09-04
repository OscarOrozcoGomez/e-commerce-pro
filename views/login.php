<?php
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

$hostForTracking = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$isLocalTrackingContext = strpos($hostForTracking, 'localhost') !== false
    || strpos($hostForTracking, '127.0.0.1') !== false
    || strpos($hostForTracking, '[::1]') !== false
    || strpos($hostForTracking, '::1') !== false
    || (defined('APP_ENV') && in_array(strtolower((string)APP_ENV), ['qa', 'local', 'dev', 'development', 'test'], true));
$allowMarketingLocal = filter_var((string) (getenv('ALLOW_MARKETING_LOCAL') ?: '0'), FILTER_VALIDATE_BOOLEAN);
$trackingEnabled = !$isLocalTrackingContext || $allowMarketingLocal;
$gtmContainerId = trim((string) (getenv('GTM_CONTAINER_ID') ?: 'GTM-TPZG9NDT'));
$ga4MeasurementId = trim((string) (getenv('GA4_MEASUREMENT_ID') ?: 'G-R2BFK5J1BJ'));
$googleAdsId = trim((string) (getenv('GOOGLE_ADS_ID') ?: 'AW-18369681241'));

if (isAuthenticated()) {
    header('Location: ' . BASE_URL . 'index.php');
    exit;
}

$error = '';
if (!empty($_SESSION['session_notice'])) {
    $error = $_SESSION['session_notice'];
    unset($_SESSION['session_notice']);
} elseif (!empty($_SESSION['session_expired'])) {
    $error = 'Tu sesión terminó. Por tu seguridad, te invitamos a iniciar sesión de nuevo.';
    unset($_SESSION['session_expired']);
}

// Segundo paso: pantalla de código cuando el staff tiene 2FO por correo (mejora 9).
$otpStage = false;
$notice = '';

$redirectAfterLogin = static function (): void {
    $redirect = trim($_GET['redirect'] ?? '');
    if ($redirect !== '' && (strpos($redirect, 'http://') === 0 || strpos($redirect, 'https://') === 0 || strpos($redirect, '//') === 0)) {
        $redirect = '';
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close(); // libera el lock de sesión antes de redirigir
    }
    if ($redirect !== '') {
        header('Location: ' . BASE_URL . ltrim($redirect, '/'));
    } elseif (isCliente()) {
        header('Location: ' . BASE_URL . 'index.php');
    } else {
        header('Location: ' . BASE_URL . 'views/dashboard.php');
    }
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';

    if (!validateCsrfToken($token)) {
        $error = 'Token CSRF inválido. Por favor recarga la página e inténtalo de nuevo.';
        $otpStage = !empty($_SESSION['_pending_otp']);
    } elseif (isset($_POST['otp_resend'])) {
        if (resendStaffLoginOtp($msgResend)) {
            $notice = 'Te enviamos un código nuevo. Revisa tu correo.';
        } else {
            $error = $msgResend;
        }
        $otpStage = !empty($_SESSION['_pending_otp']);
    } elseif (isset($_POST['otp_code'])) {
        if (verifyStaffLoginOtp((string) $_POST['otp_code'], $otpErr)) {
            $redirectAfterLogin();
        }
        $error = $otpErr;
        $otpStage = !empty($_SESSION['_pending_otp']);
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        try {
            $challenge = null;
            if (authenticate($email, $password, $challenge)) {
                $redirectAfterLogin();
            } elseif ($challenge === 'otp_required') {
                $otpStage = true;
                $notice = 'Te enviamos un código de 6 dígitos a tu correo. Escríbelo para terminar de entrar.';
            } else {
                $error = 'Credenciales incorrectas.';
            }
        } catch (Exception $e) {
            // Capturamos el mensaje de bloqueo de la cuenta
            $error = $e->getMessage();
        }
    }
} elseif (!empty($_SESSION['_pending_otp'])) {
    // Recargó la página con un login a medias: seguimos pidiendo el código.
    $otpStage = true;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Iniciar Sesión - Sistema de Punto de Venta</title>
        <?php if ($trackingEnabled): ?>
                <!-- Google Tag Manager -->
                <script>
                        (function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start': new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','<?php echo htmlspecialchars($gtmContainerId, ENT_QUOTES, 'UTF-8'); ?>');
                </script>
                <!-- End Google Tag Manager -->
                <!-- Global site tag (gtag.js) - Google Analytics / Google Ads -->
                <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo htmlspecialchars($ga4MeasurementId, ENT_QUOTES, 'UTF-8'); ?>"></script>
                <script>
                    window.dataLayer = window.dataLayer || [];
                    function gtag(){dataLayer.push(arguments);}
                    gtag('js', new Date());
                    gtag('config', '<?php echo htmlspecialchars($ga4MeasurementId, ENT_QUOTES, 'UTF-8'); ?>');
                    gtag('config', '<?php echo htmlspecialchars($googleAdsId, ENT_QUOTES, 'UTF-8'); ?>');
                </script>
        <?php endif; ?>
    <link rel="icon" type="image/png" href="<?php echo BASE_URL; ?>assets/img/logo.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        .login-container { max-width: 400px; margin: 50px auto; }
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; }
        .card { border-radius: 10px; }
    </style>
</head>
<body>
    <?php if ($trackingEnabled): ?>
        <!-- Google Tag Manager (noscript) -->
        <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?php echo htmlspecialchars($gtmContainerId, ENT_QUOTES, 'UTF-8'); ?>" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
        <!-- End Google Tag Manager (noscript) -->
    <?php endif; ?>

    <div class="container login-container">
        <div class="card">
            <div class="card-content">
                <span class="card-title center-align"><?php echo $otpStage ? 'Verificación por correo' : 'Iniciar Sesión'; ?></span>
                <?php if ($error): ?>
                    <div class="card-panel green lighten-4 green-text text-darken-4">
                        <?php echo esc($error); ?>
                    </div>
                <?php endif; ?>
                <?php if ($notice): ?>
                    <div class="card-panel blue lighten-4 blue-text text-darken-4">
                        <?php echo esc($notice); ?>
                    </div>
                <?php endif; ?>

                <?php if ($otpStage): ?>
                    <p class="grey-text text-darken-1" style="margin-top:0;">
                        Introduce el código de 6 dígitos que enviamos a tu correo. Caduca en 10 minutos.
                    </p>
                    <form method="post">
                        <?php echo csrfInput(); ?>
                        <div class="input-field">
                            <i class="material-icons prefix">pin</i>
                            <input type="text" id="otp_code" name="otp_code" inputmode="numeric" pattern="\d{6}" maxlength="6"
                                   autocomplete="one-time-code" required autofocus>
                            <label for="otp_code">Código de verificación</label>
                        </div>
                        <div class="center-align">
                            <button type="submit" class="btn-large blue waves-effect waves-light">
                                Verificar y entrar
                                <i class="material-icons right">check</i>
                            </button>
                        </div>
                    </form>
                    <form method="post" class="center-align" style="margin-top:14px;">
                        <?php echo csrfInput(); ?>
                        <button type="submit" name="otp_resend" value="1" class="btn-flat blue-text">
                            Reenviar código
                        </button>
                    </form>
                <?php else: ?>
                    <form method="post">
                        <?php echo csrfInput(); ?>
                        <div class="input-field">
                            <i class="material-icons prefix">email</i>
                            <input type="text" id="email" name="email" required autocomplete="username">
                            <label for="email">Correo electrónico o teléfono</label>
                        </div>
                        <div class="input-field">
                            <i class="material-icons prefix">lock</i>
                            <input type="password" id="password" name="password" required>
                            <label for="password">Contraseña</label>
                            <i class="material-icons" style="position: absolute; right: 10px; top: 15px; cursor: pointer; color: #9e9e9e;" onclick="togglePass('password', this)">visibility</i>
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
                <?php endif; ?>
            </div>
            <div class="card-action center-align">
                <a href="<?php echo BASE_URL; ?>index.php" class="blue-text text-darken-4" style="display: inline-flex; align-items: center; gap: 5px;">
                    <i class="material-icons">arrow_back</i> Volver al Catálogo
                </a>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
    <script>
        // Si venimos de un cierre de sesión, limpiamos el carrito local
        if (window.location.search.includes('logout=1')) {
            localStorage.removeItem('cart');
        }

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