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

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttpsRequest,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

$sessionIdleTimeoutEnv = getenv('SESSION_IDLE_TIMEOUT');
if ($sessionIdleTimeoutEnv === false) {
    $sessionIdleTimeoutEnv = $_SERVER['SESSION_IDLE_TIMEOUT'] ?? $_ENV['SESSION_IDLE_TIMEOUT'] ?? null;
}
$sessionIdleTimeout = is_numeric($sessionIdleTimeoutEnv) ? (int)$sessionIdleTimeoutEnv : 1800;

$sessionRotateIntervalEnv = getenv('SESSION_ROTATE_INTERVAL');
if ($sessionRotateIntervalEnv === false) {
    $sessionRotateIntervalEnv = $_SERVER['SESSION_ROTATE_INTERVAL'] ?? $_ENV['SESSION_ROTATE_INTERVAL'] ?? null;
}
$sessionRotateInterval = is_numeric($sessionRotateIntervalEnv) ? (int)$sessionRotateIntervalEnv : 600;

if (session_status() === PHP_SESSION_ACTIVE) {
    enforceSessionInactivityTimeout($sessionIdleTimeout);
}

if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['usuario'])) {
    rotateSessionIdIfNeeded($sessionRotateInterval);
}

sendSecurityHeaders();

// Rutas y constantes del proyecto (definidas temprano para manejo de errores seguro).
if (!defined('BASE_URL')) {
    // Detección automática: si es localhost usa la subcarpeta, si no, usa la raíz.
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
        define('BASE_URL', '/e-commerce-pro/');
    } else {
        define('BASE_URL', '/');
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
            'MAPS_KEY' => ['MAPS_KEY', 'Maps_KEY', 'GOOGLE_MAPS_API_KEY'],
            'GOOGLE_MAPS_API_KEY' => ['GOOGLE_MAPS_API_KEY', 'MAPS_KEY', 'Maps_KEY'],
            'TELEGRAM_BOT_TOKEN' => ['TELEGRAM_BOT_TOKEN', 'TELEGRAM_TOKEN'],
            'TELEGRAM_CHAT_ID' => ['TELEGRAM_CHAT_ID'],
            'TELEGRAM_NOTIFICATIONS_ENABLED' => ['TELEGRAM_NOTIFICATIONS_ENABLED'],
        ];

        if (function_exists('gsmLoadSecretsCached')) {
            $googleSecrets = gsmLoadSecretsCached($secretMapping, $googleDebug, 300);
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
}

preloadSecretSources();

// Configuración segura: leer secretos desde el entorno del servidor.
// En despliegues como Google Cloud, setear estas variables en Secret Manager
// o en el entorno de ejecución en lugar de dejar valores en el código.
function getEnvVar(string $name, ?string $default = null, bool $required = false): ?string
{
    $value = getenv($name);
    if ($value === false) {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? $_SERVER['REDIRECT_' . $name] ?? null;
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
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    return new PDO($dsn, DB_USER, DB_PASS, $options);
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
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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

        $_SESSION['session_expired'] = 1;
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
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header("Content-Security-Policy: default-src 'self' https:; script-src 'self' https://cdnjs.cloudflare.com https://fonts.googleapis.com https://cdn.jsdelivr.net https://maps.googleapis.com 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com https://cdn.jsdelivr.net https://maps.googleapis.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: blob: https:; connect-src 'self' https:; frame-ancestors 'self';");
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

    // Evitar que páginas autenticadas queden en cache del navegador/proxies compartidos.
    if (session_status() === PHP_SESSION_ACTIVE && isset($_SESSION['usuario'])) {
        header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
}
