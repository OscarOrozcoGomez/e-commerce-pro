<?php
declare(strict_types=1);

// Configuración de seguridad para manejo de errores - MOVER AL PRINCIPIO
ini_set('display_errors', '0'); 
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1'); 
error_reporting(E_ALL);

// Log dedicado de la app para diagnostico en hostings donde error_log global no se actualiza.
$appErrorLogPath = __DIR__ . '/../app_error.log';
ini_set('error_log', $appErrorLogPath);

// Configuración general del sistema POS
date_default_timezone_set('America/Mexico_City');
$isHttpsRequest = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
);

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', $isHttpsRequest ? '1' : '0');

$sessionIdleTimeoutEnv = getenv('SESSION_IDLE_TIMEOUT');
if ($sessionIdleTimeoutEnv === false) {
    $sessionIdleTimeoutEnv = $_SERVER['SESSION_IDLE_TIMEOUT'] ?? $_ENV['SESSION_IDLE_TIMEOUT'] ?? null;
}
$sessionIdleTimeout = is_numeric($sessionIdleTimeoutEnv) ? (int)$sessionIdleTimeoutEnv : 604800;

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', (string) $sessionIdleTimeout);
    ini_set('session.cookie_lifetime', (string) $sessionIdleTimeout);

    session_set_cookie_params([
        'lifetime' => $sessionIdleTimeout,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttpsRequest,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Cookie de visitante anonimo (independiente de la sesion) para poder correlacionar
// varias visitas de un mismo invitado y atribuirlas a la misma campana/origen.
if (!headers_sent() && (!isset($_COOKIE['visitor_id']) || !preg_match('/^[a-f0-9]{32}$/', (string) $_COOKIE['visitor_id']))) {
    $visitorId = bin2hex(random_bytes(16));
    setcookie('visitor_id', $visitorId, [
        'expires' => time() + 63072000,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttpsRequest,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    $_COOKIE['visitor_id'] = $visitorId;
}

$sessionRotateIntervalEnv = getenv('SESSION_ROTATE_INTERVAL');
if ($sessionRotateIntervalEnv === false) {
    $sessionRotateIntervalEnv = $_SERVER['SESSION_ROTATE_INTERVAL'] ?? $_ENV['SESSION_ROTATE_INTERVAL'] ?? null;
}
$sessionRotateInterval = is_numeric($sessionRotateIntervalEnv) ? (int)$sessionRotateIntervalEnv : 600;

if (session_status() === PHP_SESSION_ACTIVE) {
    enforceSessionInactivityTimeout($sessionIdleTimeout);
}

// Los endpoints de sondeo en segundo plano (pings de actividad, chat, dashboard) se disparan
// con mucha frecuencia y en paralelo; si coinciden con el momento de rotar el ID de sesion,
// pueden pisar/perder la sesion de otra peticion en vuelo (condicion de carrera). Se excluyen
// de la rotacion -- esta igual ocurre en la siguiente peticion normal a una vista.
$scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
$isBackgroundPollingEndpoint = (bool) preg_match(
    '#/api/(log_activity|chat_handler|dashboard_data)\.php$#',
    $scriptName
);

if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['usuario']) && !$isBackgroundPollingEndpoint) {
    rotateSessionIdIfNeeded($sessionRotateInterval);
}

$gsmCacheTtlEnv = getenv('GSM_CACHE_TTL_SECONDS');
if ($gsmCacheTtlEnv === false) {
    $gsmCacheTtlEnv = $_SERVER['GSM_CACHE_TTL_SECONDS'] ?? $_ENV['GSM_CACHE_TTL_SECONDS'] ?? null;
}
$gsmCacheTtlSeconds = is_numeric($gsmCacheTtlEnv) ? (int)$gsmCacheTtlEnv : 604800;

sendSecurityHeaders();

// Rutas y constantes del proyecto (definidas temprano para manejo de errores seguro).
if (!defined('BASE_URL')) {
    // APP_BASE_URL: override explicito para entornos donde ni "localhost" ni "127.0.0.1"
    // implican la subcarpeta /e-commerce-pro/ -- p.ej. CI, donde `php -S 127.0.0.1:8000 -t .`
    // sirve el repo directo en la raiz (sin esa subcarpeta), asi que la deteccion automatica
    // de abajo pondria un BASE_URL equivocado y cada link/redirect de la app 404earia.
    $envBaseUrl = getenv('APP_BASE_URL');
    if ($envBaseUrl !== false && trim($envBaseUrl) !== '') {
        define('BASE_URL', $envBaseUrl);
    } else {
        // Detección automática: si es localhost (o una IP de LAN, para probar desde el
        // celular en la misma red -- p.ej. 192.168.x.x) usa la subcarpeta; si no, la raíz.
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $hostSinPuerto = (string) preg_replace('/:\d+$/', '', $host);
        $esRedLocal = strpos($host, 'localhost') !== false
            || strpos($host, '127.0.0.1') !== false
            || preg_match('/^(10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.)/', $hostSinPuerto) === 1;
        if ($esRedLocal) {
            // Deriva la subcarpeta del propio script para que un git worktree servido en
            // htdocs (p.ej. /e-commerce-pro-roles-permisos/) también funcione en el navegador
            // local; cae a /e-commerce-pro/ si no se puede determinar.
            $scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '')));
            if (preg_match('#^/([^/]+)#', $scriptDir, $m) && $m[1] !== 'views' && $m[1] !== 'api') {
                define('BASE_URL', '/' . $m[1] . '/');
            } else {
                define('BASE_URL', '/e-commerce-pro/');
            }
        } else {
            define('BASE_URL', '/');
        }
    }
}

set_exception_handler(function ($exception) {
    $requestId = bin2hex(random_bytes(6));
    $logMsg = sprintf(
        " [EXCEPCIÓN CRÍTICA] ReqID: %s | Mensaje: %s | Archivo: %s | Línea: %d",
        $requestId,
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    );
    error_log($logMsg);
    error_log("STACK TRACE (ReqID {$requestId}): " . $exception->getTraceAsString());

    // Escribir tambien de forma directa por si el handler de PHP ignora error_log.
    $manualLog = sprintf(
        "[%s] %s\n%s\n\n",
        date('Y-m-d H:i:s'),
        $logMsg,
        $exception->getTraceAsString()
    );
    @file_put_contents(__DIR__ . '/../app_error.log', $manualLog, FILE_APPEND);
    if (!headers_sent()) {
        $safeBaseUrl = defined('BASE_URL') ? BASE_URL : '/';
        header('Location: ' . $safeBaseUrl . 'views/error.php?rid=' . urlencode($requestId));
    }
    exit;
});

$gsmHelperPath = __DIR__ . '/google_secret_manager.php';
if (is_readable($gsmHelperPath)) {
    require_once $gsmHelperPath;
}

$piiCryptoPath = __DIR__ . '/pii_crypto.php';
if (is_readable($piiCryptoPath)) {
    require_once $piiCryptoPath;
}

function applySecretValue(string $key, string $value): void
{
    $trimmedKey = trim($key);
    if ($trimmedKey === '') {
        return;
    }

    $trimmedValue = trim($value);
    putenv($trimmedKey . '=' . $trimmedValue);
    $_ENV[$trimmedKey] = $trimmedValue;
    $_SERVER[$trimmedKey] = $trimmedValue;
}

function loadSecretsFromFile(string $filePath): bool
{
    if (!is_readable($filePath)) {
        return false;
    }

    if (substr($filePath, -4) === '.php') {
        $data = require $filePath;
        if (!is_array($data)) {
            return false;
        }
        foreach ($data as $key => $value) {
            if (!is_string($key) || (!is_string($value) && !is_numeric($value))) {
                continue;
            }
            applySecretValue($key, (string) $value);
        }
        return true;
    }

    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return false;
    }

    foreach ($lines as $line) {
        $trimmedLine = trim($line);
        if ($trimmedLine === '' || strpos($trimmedLine, '#') === 0) {
            continue;
        }
        if (strpos($trimmedLine, '=') === false) {
            continue;
        }
        [$key, $value] = explode('=', $trimmedLine, 2);
        $normalizedValue = trim($value, " \t\n\r\0\x0B\"'");
        applySecretValue($key, $normalizedValue);
    }

    return true;
}

function preloadSecretSources(): void
{
    $rawAppEnv = getenv('APP_ENV');
    if ($rawAppEnv === false) {
        $rawAppEnv = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? '';
    }
    $normalizedEnv = strtolower(trim((string) $rawAppEnv));
    $hostForSecrets = $_SERVER['HTTP_HOST'] ?? '';
    $isLocalHostForSecrets = strpos($hostForSecrets, 'localhost') !== false || strpos($hostForSecrets, '127.0.0.1') !== false;
    $isLocalContext = (PHP_SAPI === 'cli')
        || $isLocalHostForSecrets
        || in_array($normalizedEnv, ['qa', 'local', 'dev', 'development', 'test'], true);

    $parseBool = static function ($value): ?bool {
        if ($value === null || $value === false) {
            return null;
        }
        $value = strtolower(trim((string)$value));
        if ($value === '') {
            return null;
        }
        if (in_array($value, ['1', 'true', 'yes', 'on'], true)) {
            return true;
        }
        if (in_array($value, ['0', 'false', 'no', 'off'], true)) {
            return false;
        }
        return null;
    };

    $disableGsmEnv = $parseBool(getenv('DISABLE_GSM') !== false ? getenv('DISABLE_GSM') : ($_SERVER['DISABLE_GSM'] ?? $_ENV['DISABLE_GSM'] ?? null));
    $allowGsmLocalEnv = $parseBool(getenv('ALLOW_GSM_LOCAL') !== false ? getenv('ALLOW_GSM_LOCAL') : ($_SERVER['ALLOW_GSM_LOCAL'] ?? $_ENV['ALLOW_GSM_LOCAL'] ?? null));
    $disableGsm = $disableGsmEnv ?? $isLocalContext;
    $allowGsmLocal = $allowGsmLocalEnv ?? false;
    $gsmCacheTtlSeconds = $GLOBALS['gsmCacheTtlSeconds'] ?? null;
    if (!is_int($gsmCacheTtlSeconds) || $gsmCacheTtlSeconds <= 0) {
        $gsmCacheTtlEnv = getenv('GSM_CACHE_TTL_SECONDS');
        if ($gsmCacheTtlEnv === false) {
            $gsmCacheTtlEnv = $_SERVER['GSM_CACHE_TTL_SECONDS'] ?? $_ENV['GSM_CACHE_TTL_SECONDS'] ?? null;
        }
        $gsmCacheTtlSeconds = is_numeric($gsmCacheTtlEnv) ? (int) $gsmCacheTtlEnv : 604800;
    }

    $googleLoadedCount = 0;
    $googleDebug = [];
    $shouldUseGsm = function_exists('gsmLoadSecrets') && !$disableGsm && (!$isLocalContext || $allowGsmLocal);
    if ($shouldUseGsm) {
        $secretMapping = [
            'DB_HOST' => ['DB_HOST'],
            'DB_NAME' => ['DB_NAME'],
            'DB_USER' => ['DB_USER'],
            'DB_PASSWORD' => ['DB_PASSWORD'],
            'DB_CHARSET' => ['DB_CHARSET'],
            'MIGRATIONS_DEPLOY_TOKEN' => ['MIGRATIONS_DEPLOY_TOKEN'],
            'PII_ENCRYPTION_KEY' => ['PII_ENCRYPTION_KEY', 'CUSTOMER_PII_KEY'],
            'MAPS_KEY' => ['MAPS_KEY', 'Maps_KEY', 'GOOGLE_MAPS_API_KEY'],
            'GOOGLE_MAPS_API_KEY' => ['GOOGLE_MAPS_API_KEY', 'MAPS_KEY', 'Maps_KEY'],
            'TELEGRAM_BOT_TOKEN' => ['TELEGRAM_BOT_TOKEN', 'TELEGRAM_TOKEN'],
            'TELEGRAM_CHAT_ID' => ['TELEGRAM_CHAT_ID'],
            'TELEGRAM_NOTIFICATIONS_ENABLED' => ['TELEGRAM_NOTIFICATIONS_ENABLED'],
            // DEEPSEEK_AI_ASSISTANT es el nombre por defecto (configurable por fila desde
            // ai_asistente_config.api_key_variable); DEEPSEEK_API_KEY se deja como alias legacy.
            'DEEPSEEK_AI_ASSISTANT' => ['DEEPSEEK_AI_ASSISTANT'],
            'DEEPSEEK_API_KEY' => ['DEEPSEEK_API_KEY'],
            'WA_WEBHOOK_TOKEN' => ['WA_WEBHOOK_TOKEN'],
            // Nombre oficial del secreto en Google Secret Manager; se mapea a WA_WEBHOOK_TOKEN
            // al final de esta funcion para que el resto del codigo no cambie.
            'WA_WEBHOOK_SECRET' => ['WA_WEBHOOK_SECRET'],
            // Endpoint que expone el puente Node.js para mensajes proactivos (cron de seguimiento).
            'WA_BRIDGE_SEND_URL' => ['WA_BRIDGE_SEND_URL'],
            // Endpoint que expone el puente Node.js para aplicar/quitar etiquetas nativas de WhatsApp.
            'WA_BRIDGE_LABEL_URL' => ['WA_BRIDGE_LABEL_URL'],
            // Endpoint que expone el puente Node.js para relevar llamadas a DeepSeek -- el hosting
            // de PHP bloquea la salida directa a api.deepseek.com, el droplet si tiene salida libre.
            'WA_BRIDGE_DEEPSEEK_URL' => ['WA_BRIDGE_DEEPSEEK_URL'],
            'GTM_CONTAINER_ID' => ['GTM_CONTAINER_ID'],
            'GA4_MEASUREMENT_ID' => ['GA4_MEASUREMENT_ID'],
            'GA4_DIRECT_ENABLED' => ['GA4_DIRECT_ENABLED'],
            'GOOGLE_ADS_SEND_TO' => ['GOOGLE_ADS_SEND_TO'],
            'FB_PAGE_ID' => ['FB_PAGE_ID', 'FACEBOOK_PAGE_ID'],
            'FB_PAGE_ACCESS_TOKEN' => ['FB_PAGE_ACCESS_TOKEN', 'TOKEN_FACEBOOK_PAGE_APP'],
        ];

        if (function_exists('gsmLoadSecretsCached')) {
            $googleSecrets = gsmLoadSecretsCached($secretMapping, $googleDebug, $gsmCacheTtlSeconds);
        } else {
            $googleSecrets = gsmLoadSecrets($secretMapping, $googleDebug);
        }

        foreach ($googleSecrets as $key => $value) {
            applySecretValue($key, $value);
            $googleLoadedCount++;
        }

        if ($googleLoadedCount > 0 && empty($googleDebug['from_cache'])) {
            $source = isset($googleDebug['token_source']) && is_string($googleDebug['token_source'])
                ? $googleDebug['token_source']
                : 'unknown';
            error_log('INFO: Secretos cargados desde Google Secret Manager (' . $googleLoadedCount . ') con token ' . $source);
        } elseif ($googleLoadedCount > 0 && !empty($googleDebug['from_cache'])) {
            // Cache hit: evitamos ruido en logs de producción para cada request.
        } elseif (!empty($googleDebug['errors']) && is_array($googleDebug['errors'])) {
            $source = isset($googleDebug['token_source']) && is_string($googleDebug['token_source'])
                ? $googleDebug['token_source']
                : 'unknown';
            $projectId = isset($googleDebug['project_id']) && is_string($googleDebug['project_id'])
                ? $googleDebug['project_id']
                : 'unknown';
            $saEmail = isset($googleDebug['service_account_email']) && is_string($googleDebug['service_account_email'])
                ? $googleDebug['service_account_email']
                : 'unknown';
            error_log('WARNING: GSM contexto -> project=' . $projectId . ' | token_source=' . $source . ' | sa=' . $saEmail);
            foreach ($googleDebug['errors'] as $googleError) {
                if (is_string($googleError) && trim($googleError) !== '') {
                    error_log('WARNING: Google Secret Manager: ' . $googleError);
                }
            }
        }
    }

    $envSuffixCandidates = [];
    if ($normalizedEnv !== '' && preg_match('/^[a-z0-9_\-]+$/', $normalizedEnv)) {
        $envSuffixCandidates[] = $normalizedEnv;

        // QA y local son equivalentes para pruebas en XAMPP.
        if ($normalizedEnv === 'qa' && !in_array('local', $envSuffixCandidates, true)) {
            $envSuffixCandidates[] = 'local';
        }
        if ($normalizedEnv === 'local' && !in_array('qa', $envSuffixCandidates, true)) {
            $envSuffixCandidates[] = 'qa';
        }
    }

    if (empty($envSuffixCandidates) && $isLocalContext) {
        $envSuffixCandidates = ['qa', 'local'];
    }

    $baseCandidates = [
        __DIR__ . '/app_secrets.php',
        __DIR__ . '/app_secrets.env',
        __DIR__ . '/../.app_secrets.env',
        __DIR__ . '/../.app_secrets.php',
        __DIR__ . '/../app_secrets.env',
        __DIR__ . '/../app_secrets.php',
        __DIR__ . '/../.app_secrests.php',
        __DIR__ . '/../app_secrests.php',
    ];

    $candidates = [];
    foreach ($envSuffixCandidates as $envSuffix) {
        $candidates[] = __DIR__ . '/app_secrets.' . $envSuffix . '.php';
        $candidates[] = __DIR__ . '/app_secrets.' . $envSuffix . '.env';
        $candidates[] = __DIR__ . '/../.app_secrets.' . $envSuffix . '.php';
        $candidates[] = __DIR__ . '/../.app_secrets.' . $envSuffix . '.env';
        $candidates[] = __DIR__ . '/../app_secrets.' . $envSuffix . '.php';
        $candidates[] = __DIR__ . '/../app_secrets.' . $envSuffix . '.env';
    }

    $candidates = array_merge($candidates, $baseCandidates);

    $homePath = getenv('HOME');
    if ($homePath === false || trim($homePath) === '') {
        $homePath = $_SERVER['HOME'] ?? '';
    }
    if (is_string($homePath) && trim($homePath) !== '') {
        $homePath = rtrim($homePath, '/\\');
        foreach ($envSuffixCandidates as $envSuffix) {
            $candidates[] = $homePath . '/.app_secrets.' . $envSuffix . '.env';
            $candidates[] = $homePath . '/.app_secrets.' . $envSuffix . '.php';
            $candidates[] = $homePath . '/app_secrets.' . $envSuffix . '.env';
            $candidates[] = $homePath . '/app_secrets.' . $envSuffix . '.php';
        }

        $candidates[] = $homePath . '/.app_secrets.env';
        $candidates[] = $homePath . '/.app_secrets.php';
        $candidates[] = $homePath . '/app_secrets.env';
        $candidates[] = $homePath . '/app_secrets.php';
        $candidates[] = $homePath . '/.app_secrests.php';
        $candidates[] = $homePath . '/app_secrests.php';
    }

    $loadedSecretSource = null;
    foreach ($candidates as $secretFile) {
        if (loadSecretsFromFile($secretFile)) {
            $loadedSecretSource = $secretFile;
            break;
        }
    }

    if ($loadedSecretSource !== null) {
        error_log('INFO: Secretos cargados desde: ' . $loadedSecretSource);
    } elseif ($googleLoadedCount === 0) {
        if ($isLocalContext || $disableGsm) {
            error_log('INFO: Entorno local/QA sin secretos cargados desde GCP (DISABLE_GSM activo por defecto).');
        } else {
            error_log('WARNING: No se encontró archivo de secretos local.');
        }
    }

    // WA_WEBHOOK_TOKEN se resuelve desde WA_WEBHOOK_SECRET (via Secret Manager en produccion,
    // o desde core/app_secrets.qa.php en local/QA -- ambos casos ya dejaron el valor
    // disponible arriba). Se hace al final, independientemente de la fuente, para que
    // api/whatsapp_webhook.php y el resto del codigo sigan leyendo WA_WEBHOOK_TOKEN sin cambios.
    $waWebhookSecretValue = getenv('WA_WEBHOOK_SECRET');
    if ($waWebhookSecretValue === false) {
        $waWebhookSecretValue = $_SERVER['WA_WEBHOOK_SECRET'] ?? $_ENV['WA_WEBHOOK_SECRET'] ?? false;
    }
    if ($waWebhookSecretValue !== false && trim((string) $waWebhookSecretValue) !== '') {
        applySecretValue('WA_WEBHOOK_TOKEN', (string) $waWebhookSecretValue);
    }
}

preloadSecretSources();

// Configuración segura: leer secretos desde el entorno del servidor.
// En despliegues como Google Cloud, setear estas variables en Secret Manager
// o en el entorno de ejecución en lugar de dejar valores en el código.
function getEnvVar(string $name, ?string $default = null, bool $required = false): ?string
{
    // $_SERVER/$_ENV se leen primero porque son aislados por request; putenv()
    // (usado por applySecretValue() para publicar los secretos que carga esta
    // funcion) escribe una variable de entorno compartida por TODO el proceso de
    // Apache, y bajo el MPM con hilos de Windows (mpm_winnt) eso puede hacer que
    // un request lea a mitad de escritura el putenv() de otro request concurrente
    // -- causaba, por ejemplo, que PII_ENCRYPTION_KEY se leyera corrupta bajo
    // carga concurrente y el descifrado de datos de clientes fallara de forma
    // intermitente. getenv() se deja como respaldo para variables que sí vienen
    // del entorno real del proceso (p.ej. las que setea CI vía `env:`) y que
    // nunca pasan por applySecretValue().
    $value = $_SERVER[$name] ?? $_ENV[$name] ?? null;
    if ($value === null) {
        $value = getenv($name);
        if ($value === false) {
            $value = $_SERVER['REDIRECT_' . $name] ?? null;
        }
    }
    if ($value !== null) {
        $value = trim((string) $value);
        if ($value === '') {
            $value = null;
        }
    }
    if ($value === null) {
        if ($required) {
            error_log(sprintf('ERROR: Falta variable de entorno requerida: %s.', $name));
            throw new RuntimeException(sprintf('Falta variable de entorno requerida: %s.', $name));
        }
        return $default;
    }
    return $value;
}

function getMapsApiKey(bool $required = false): string
{
    $mapsKey = getEnvVar('MAPS_KEY');
    if ($mapsKey !== null) {
        return $mapsKey;
    }

    // Compatibilidad con nombres legacy o con diferente capitalización.
    $mapsKeyLegacyCase = getEnvVar('Maps_KEY');
    if ($mapsKeyLegacyCase !== null) {
        return $mapsKeyLegacyCase;
    }

    $legacyKey = getEnvVar('GOOGLE_MAPS_API_KEY');
    if ($legacyKey !== null) {
        return $legacyKey;
    }

    if ($required) {
        error_log('ERROR: Falta secreto requerido de Google Maps: MAPS_KEY, Maps_KEY o GOOGLE_MAPS_API_KEY.');
        throw new RuntimeException('Falta secreto requerido de Google Maps: MAPS_KEY, Maps_KEY o GOOGLE_MAPS_API_KEY.');
    }

    return '';
}

// Modo de ejecución: QA por defecto en localhost/CLI, producción fuera de ahí.
$hostForEnv = $_SERVER['HTTP_HOST'] ?? '';
$isLocalHost = strpos($hostForEnv, 'localhost') !== false || strpos($hostForEnv, '127.0.0.1') !== false;
$defaultAppEnv = (PHP_SAPI === 'cli' || $isLocalHost) ? 'qa' : 'production';
define('APP_ENV', strtolower((string) getEnvVar('APP_ENV', $defaultAppEnv)));
define('IS_PRODUCTION', APP_ENV === 'production');

// Parámetros de conexión a la base de datos.
define('DB_HOST', getEnvVar('DB_HOST', '127.0.0.1'));
define('DB_NAME', getEnvVar('DB_NAME', 'beautyandwell_prod'));
define('DB_USER', getEnvVar('DB_USER', IS_PRODUCTION ? null : 'root', IS_PRODUCTION));
define('DB_PASS', getEnvVar('DB_PASSWORD', IS_PRODUCTION ? null : '', IS_PRODUCTION));
define('DB_CHARSET', getEnvVar('DB_CHARSET', 'utf8mb4'));

// Llaves de API para Google Maps. Se puede usar MAPS_KEY o GOOGLE_MAPS_API_KEY.
if (!defined('GOOGLE_MAPS_API_KEY')) {
    // No bloquear toda la app si falta la llave; solo afectará vistas que usan Maps.
    define('GOOGLE_MAPS_API_KEY', getMapsApiKey(false));
}

// Palabra clave que, si aparece en las notas de un pedido capturado por un admin/encargado,
// hace que ese pedido no descuente inventario (ventas de muestra, cortesía, etc.).
// Configurable por entorno para no dejarla fija en el código.
define('SALE_INVENTORY_BYPASS_KEYWORD', getEnvVar('SALE_INVENTORY_BYPASS_KEYWORD', 'SININVENTARIO'));

// Segundo factor por correo (código de 6 dígitos) para el login de staff admin/encargado.
// Apagado por defecto: actívalo con STAFF_OTP_ENABLED=1 en el entorno cuando el envío de
// correo esté disponible en el host.
if (!defined('STAFF_OTP_ENABLED')) {
    $staffOtpEnv = strtolower((string) (getEnvVar('STAFF_OTP_ENABLED', '0') ?? '0'));
    define('STAFF_OTP_ENABLED', in_array($staffOtpEnv, ['1', 'true', 'on', 'yes'], true));
}

const CSV_IMPORT_PATH = __DIR__ . '/../Exportaciones/Variante del producto (product.product).csv';
const UPLOAD_DIR = __DIR__ . '/uploads';
const PRODUCTS_IMG_DIR = __DIR__ . '/../assets/img/products/';

if (!is_dir(UPLOAD_DIR)) {
    @mkdir(UPLOAD_DIR, 0755, true);
}

/**
 * Devuelve una conexión PDO segura.
 *
 * @return PDO
 * @throws PDOException
 */
function getPDO(): PDO
{
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ];

    $hostCandidates = array_values(array_unique(array_filter([
        DB_HOST,
        DB_HOST === '127.0.0.1' ? 'localhost' : '127.0.0.1',
        DB_HOST === 'localhost' ? '127.0.0.1' : 'localhost',
    ], static function ($host) {
        return is_string($host) && trim($host) !== '';
    })));

    $lastException = null;
    foreach ($hostCandidates as $hostCandidate) {
        $dsn = 'mysql:host=' . $hostCandidate . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            return new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $exception) {
            $lastException = $exception;
        }
    }

    if ($lastException instanceof PDOException) {
        throw $lastException;
    }

    throw new RuntimeException('No se pudo establecer conexión con la base de datos.');
}

/**
 * Devuelve una conexión mysqli.
 *
 * @return mysqli
 */
function getMySqli(): mysqli
{
    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    if ($mysqli->connect_errno) {
        throw new RuntimeException('Error al conectar con la base de datos: ' . $mysqli->connect_error);
    }
    $mysqli->set_charset(DB_CHARSET);
    return $mysqli;
}

/**
 * Escapa un valor para salida HTML.
 *
 * @param string $value
 * @return string
 */
function esc(string $value): string
{
    if (function_exists('piiIsEncryptedValue') && function_exists('piiDecryptValue') && piiIsEncryptedValue($value)) {
        $value = (string)piiDecryptValue($value);
    }
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Devuelve el visitor_id anonimo persistido en cookie, o null si no existe
 * (por ejemplo, si config.php se cargo despues de headers_sent()).
 */
function getVisitorId(): ?string
{
    $visitorId = $_COOKIE['visitor_id'] ?? null;
    if (!is_string($visitorId) || !preg_match('/^[a-f0-9]{32}$/', $visitorId)) {
        return null;
    }
    return $visitorId;
}

function rotateSessionIdIfNeeded(int $intervalSeconds = 600): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }

    $intervalSeconds = max(60, $intervalSeconds);
    $now = time();
    $lastRotation = isset($_SESSION['_session_id_rotated_at']) ? (int)$_SESSION['_session_id_rotated_at'] : 0;

    if ($lastRotation <= 0) {
        $_SESSION['_session_id_rotated_at'] = $now;
        return;
    }

    if (($now - $lastRotation) < $intervalSeconds) {
        return;
    }

    session_regenerate_id(true);
    $_SESSION['_session_id_rotated_at'] = $now;
}

function enforceSessionInactivityTimeout(int $timeoutSeconds = 1800): void
{
    if (session_status() !== PHP_SESSION_ACTIVE || !isset($_SESSION['usuario'])) {
        return;
    }

    $timeoutSeconds = max(300, $timeoutSeconds);
    $now = time();
    $lastActivity = isset($_SESSION['_last_activity_at']) ? (int)$_SESSION['_last_activity_at'] : 0;

    if ($lastActivity > 0 && ($now - $lastActivity) > $timeoutSeconds) {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'] ?? '/',
                $params['domain'] ?? '',
                (bool)($params['secure'] ?? false),
                (bool)($params['httponly'] ?? true)
            );
        }

        session_destroy();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $_SESSION['session_notice'] = 'Tu sesión expiró por inactividad. Por tu seguridad, te invitamos a iniciar sesión de nuevo.';
        return;
    }

    $_SESSION['_last_activity_at'] = $now;
}

function sendSecurityHeaders(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    // geolocation=(self): el propio sitio SI usa geolocalizacion (boton "Usar mi ubicacion"
    // en entregas.php para planear rutas). camera/microphone en () porque nada en el sitio
    // usa getUserMedia (camara en vivo) -- la captura de fotos usa <input capture>, que no
    // depende de este permiso. Un allowlist vacio "()" bloquea incluso al propio origen.
    header('Permissions-Policy: geolocation=(self), microphone=(), camera=()');
    // cdn.tiny.cloud: views/manage_blogs.php carga el editor TinyMCE desde ahi. Sin este
    // dominio en script-src/script-src-elem, el <script src="https://cdn.tiny.cloud/..."> se
    // bloquea por CSP y el tinymce.init() que le sigue en la misma etiqueta <script> lanza
    // "tinymce is not defined", lo que aborta el resto de ese bloque -- incluido el listener de
    // autogeneracion de slug -- para todo admin/encargado real, no solo en pruebas.
    // googleads.g.doubleclick.net / googleadservices.com: tag de Google Ads (ver PR #74).
    header("Content-Security-Policy: default-src 'self' https:; script-src 'self' https://static.cloudflareinsights.com https://cdnjs.cloudflare.com https://fonts.googleapis.com https://cdn.jsdelivr.net https://cdn.tiny.cloud https://maps.googleapis.com https://www.googletagmanager.com https://www.google-analytics.com https://region1.google-analytics.com https://googleads.g.doubleclick.net https://www.googleadservices.com 'unsafe-inline'; script-src-elem 'self' https://static.cloudflareinsights.com https://cdnjs.cloudflare.com https://fonts.googleapis.com https://cdn.jsdelivr.net https://cdn.tiny.cloud https://maps.googleapis.com https://www.googletagmanager.com https://www.google-analytics.com https://region1.google-analytics.com https://googleads.g.doubleclick.net https://www.googleadservices.com 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com https://cdn.jsdelivr.net https://cdn.tiny.cloud https://maps.googleapis.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: blob: https:; connect-src 'self' https:; frame-ancestors 'self';");
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

    // Evitar que páginas autenticadas queden en cache del navegador/proxies compartidos.
    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['usuario'])) {
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
}
