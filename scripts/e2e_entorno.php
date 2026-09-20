<?php
declare(strict_types=1);

// Solo lectura. Lo consume tests/e2e (leerEntorno en permisos-datos.ts): dice que integraciones externas tiene
// configuradas ESTE entorno, para que una prueba decida si puede verificar algo que depende de ellas (p.ej. el boton
// "Ver fachada" de views/entregas.php solo existe con GOOGLE_MAPS_API_KEY) o debe verificar que NO aparece.
// Imprime SOLO booleanos: nunca el valor de una clave.

require_once __DIR__ . '/../core/config.php';

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    http_response_code(403);
    echo "Este script solo se puede ejecutar por CLI o phpdbg.\n";
    exit(1);
}

echo json_encode(
    ['google_maps_key' => defined('GOOGLE_MAPS_API_KEY') && GOOGLE_MAPS_API_KEY !== ''],
    JSON_UNESCAPED_UNICODE
), "\n";
