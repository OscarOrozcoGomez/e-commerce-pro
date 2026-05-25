<?php
declare(strict_types=1);

// Configuración de seguridad para manejo de errores - MOVER AL PRINCIPIO
ini_set('display_errors', '1'); // Cambiamos temporalmente a 1 para ver errores en pantalla si fallara algo crítico
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1'); 
error_reporting(E_ALL);

// Configuración general del sistema POS
date_default_timezone_set('America/Mexico_City');
if (!session_id()) {
    // Usamos @ para suprimir advertencias de archivos bloqueados temporales comunes en XAMPP/Windows
    @session_start();
}
sendSecurityHeaders();

set_exception_handler(function ($exception) {
    $logMsg = sprintf(
        " [EXCEPCIÓN CRÍTICA] Mensaje: %s | Archivo: %s | Línea: %d",
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine()
    );
    error_log($logMsg);
    error_log("STACK TRACE: " . $exception->getTraceAsString());
    if (!headers_sent()) {
        header('Location: ' . BASE_URL . 'views/error.php');
    }
    exit;
});

// Cargar variables de entorno locales si existen (para no subirlas a GitHub)
if (file_exists(__DIR__ . '/env.php')) {
    require_once __DIR__ . '/env.php';
}

// Parámetros de conexión a la base de datos
if (!defined('DB_HOST')) {
    define('DB_HOST', '127.0.0.1');
    define('DB_NAME', 'beautyandwell_prod');
    define('DB_USER', 'root');
    define('DB_PASS', '');
    define('DB_CHARSET', 'utf8mb4');
}

// Llaves de API (En producción, lo ideal es usar variables de entorno)
if (!defined('GOOGLE_MAPS_API_KEY')) {
    define('GOOGLE_MAPS_API_KEY', ''); 
}

// Rutas y constantes del proyecto
if (!defined('BASE_URL')) {
    // Detección automática: si es localhost usa la subcarpeta, si no, usa la raíz.
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false) {
        define('BASE_URL', '/e-commerce-pro/');
    } else {
        define('BASE_URL', '/');
    }
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

function sendSecurityHeaders(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header("Content-Security-Policy: default-src 'self' https:; script-src 'self' https://cdnjs.cloudflare.com https://fonts.googleapis.com https://cdn.jsdelivr.net https://maps.googleapis.com 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com https://cdn.jsdelivr.net https://maps.googleapis.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: https://via.placeholder.com https://maps.gstatic.com https://maps.googleapis.com; connect-src 'self' https://maps.googleapis.com; frame-ancestors 'self';");
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
