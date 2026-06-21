<?php
$host = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false;
$baseUrl = defined('BASE_URL') ? BASE_URL : ($isLocal ? '/e-commerce-pro/' : '/');
$reqId = isset($_GET['rid']) ? preg_replace('/[^a-f0-9]/', '', (string) $_GET['rid']) : '';
$safeReqId = htmlspecialchars($reqId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Algo salió mal - POS Sistema</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <style>
        body { display: flex; min-height: 100vh; flex-direction: column; justify-content: center; background-color: #f5f5f5; }
        .error-container { text-align: center; padding: 20px; }
        .error-code { font-size: 8rem; font-weight: bold; color: #1a237e; margin: 0; }
        .error-message { font-size: 1.5rem; color: #757575; margin-bottom: 30px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="row">
            <div class="col s12 m8 offset-m2">
                <div class="card-panel z-depth-2 error-container">
                    <i class="material-icons large red-text text-lighten-2">report_problem</i>
                    <h1 class="error-code">Oops!</h1>
                    <p class="error-message">
                        Lo sentimos, ha ocurrido un error inesperado o la página no está disponible.
                    </p>
                    <p class="grey-text">
                        Nuestro equipo técnico ha sido notificado. Por seguridad, no podemos mostrar más detalles.
                    </p>
                    <?php if ($reqId !== ''): ?>
                        <p class="grey-text text-darken-2" style="margin-top: 10px; font-size: 0.9rem;">
                            Folio técnico: <?php echo $safeReqId; ?>
                        </p>
                    <?php endif; ?>
                    <div style="margin-top: 40px;">
                        <a href="<?php echo $baseUrl; ?>" class="btn-large blue darken-4 waves-effect waves-light">
                            <i class="material-icons left">home</i> Volver al Inicio
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/js/materialize.min.js"></script>
</body>
</html>