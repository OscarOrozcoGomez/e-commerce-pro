<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/MaxMindDb/Reader.php';

if (!defined('GEOLITE2_COUNTRY_DB_PATH')) {
    define('GEOLITE2_COUNTRY_DB_PATH', __DIR__ . '/data/GeoLite2-Country.mmdb');
}

/**
 * Resuelve pais (ISO alpha-2) a partir de una IP usando GeoLite2-Country.
 * El archivo .mmdb no se versiona (ver .gitignore); si no esta presente en
 * este entorno, o si el lookup falla por cualquier motivo, devuelve valores
 * nulos en vez de lanzar -- geolocalizar nunca debe romper el registro de la visita.
 *
 * @param string|null $dbPath Ruta al .mmdb a usar; por defecto GEOLITE2_COUNTRY_DB_PATH
 *                             (parametrizable solo para pruebas -- el codigo de
 *                             produccion siempre llama lookupGeo($ip) sin este argumento).
 * @return array{pais: ?string, region: ?string}
 */
function lookupGeo(string $ip, ?string $dbPath = null): array
{
    // Sin cache estatico a proposito (ver ventasFeaturesGetAll() para el mismo
    // razonamiento): cada request de PHP-FPM/Apache es un proceso nuevo, asi que un
    // cache "por proceso" no aporta nada en produccion (esta funcion se llama una sola
    // vez por visita) y si puede filtrar estado entre pruebas o entre una ruta de
    // archivo y otra dentro del mismo proceso de larga duracion (CLI/tests).
    $dbPath = $dbPath ?? GEOLITE2_COUNTRY_DB_PATH;
    $empty = ['pais' => null, 'region' => null];

    if ($ip === '' || $ip === '0.0.0.0' || $ip === '::1' || $ip === '127.0.0.1') {
        return $empty;
    }

    if (!is_readable($dbPath)) {
        return $empty;
    }

    try {
        $reader = new \MaxMindDb\Reader($dbPath);
    } catch (\Throwable $e) {
        error_log('lookupGeo: no se pudo inicializar GeoLite2-Country: ' . $e->getMessage());
        return $empty;
    }

    try {
        $record = $reader->lookup($ip);
    } catch (\Throwable $e) {
        error_log('lookupGeo: error al resolver IP ' . $ip . ': ' . $e->getMessage());
        return $empty;
    }

    if ($record === null) {
        return $empty;
    }

    $countryCode = $record['country']['iso_code']
        ?? $record['registered_country']['iso_code']
        ?? null;

    return [
        'pais' => is_string($countryCode) && $countryCode !== '' ? strtoupper($countryCode) : null,
        'region' => null,
    ];
}
