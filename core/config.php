<?php
declare(strict_types=1);

// Configuración general del sistema POS
date_default_timezone_set('America/Mexico_City');
if (!session_id()) {
    session_start();
}
sendSecurityHeaders();

// Parámetros de conexión a la base de datos
const DB_HOST = '127.0.0.1';
const DB_NAME = 'beautyandwell_prod';
const DB_USER = 'root';
const DB_PASS = '';
const DB_CHARSET = 'utf8mb4';

// Rutas y constantes del proyecto
const BASE_URL = '/e-commerce-pro/';
const CSV_IMPORT_PATH = __DIR__ . '/../Exportaciones/Variante del producto (product.product).csv';
const UPLOAD_DIR = __DIR__ . '/uploads';

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
    header("Content-Security-Policy: default-src 'self' https:; script-src 'self' https://cdnjs.cloudflare.com https://fonts.googleapis.com 'unsafe-inline'; style-src 'self' https://cdnjs.cloudflare.com https://fonts.googleapis.com 'unsafe-inline'; font-src https://fonts.gstatic.com; img-src 'self' data: https://via.placeholder.com; connect-src 'self'; frame-ancestors 'self';");
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
