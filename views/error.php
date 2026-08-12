<?php
$host = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false;
$baseUrl = defined('BASE_URL') ? BASE_URL : ($isLocal ? '/e-commerce-pro/' : '/');
$gtmContainerId = trim((string) (getenv('GTM_CONTAINER_ID') ?: 'GTM-TPZG9NDT'));
$ga4MeasurementId = trim((string) (getenv('GA4_MEASUREMENT_ID') ?: 'G-R2BFK5J1BJ'));
$googleAdsId = trim((string) (getenv('GOOGLE_ADS_ID') ?: 'AW-18369681241'));
$allowMarketingLocal = filter_var((string) (getenv('ALLOW_MARKETING_LOCAL') ?: '0'), FILTER_VALIDATE_BOOLEAN);
$trackingEnabled = !$isLocal || $allowMarketingLocal;
$reqId = isset($_GET['rid']) ? preg_replace('/[^a-f0-9]/', '', (string) $_GET['rid']) : '';
$safeReqId = htmlspecialchars($reqId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Algo salió mal - POS Sistema</title>
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
    <?php if ($trackingEnabled): ?>
        <!-- Google Tag Manager (noscript) -->
        <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?php echo htmlspecialchars($gtmContainerId, ENT_QUOTES, 'UTF-8'); ?>" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
        <!-- End Google Tag Manager (noscript) -->
    <?php endif; ?>

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