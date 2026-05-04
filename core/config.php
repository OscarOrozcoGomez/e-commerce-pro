<?php
declare(strict_types=1);

// Configuración general del sistema POS
date_default_timezone_set('America/Mexico_City');
if (!session_id()) {
    session_start();
}

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
