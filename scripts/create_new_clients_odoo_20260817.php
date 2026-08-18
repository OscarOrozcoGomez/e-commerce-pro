<?php
declare(strict_types=1);

/**
 * Alta puntual de 8 clientes nuevos identificados en el export de Odoo del
 * 2026-08-17 (Contacto Agosto 17.csv) que no tenian coincidencia por nombre
 * en la tabla clientes. Incluye telefono y, cuando el export trae el link de
 * Google Maps, tambien el domicilio con coordenadas resueltas.
 *
 * Uso:
 *   C:\xampp\php\php.exe scripts\create_new_clients_odoo_20260817.php --dry-run
 *   C:\xampp\php\php.exe scripts\create_new_clients_odoo_20260817.php --apply
 */

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    http_response_code(403);
    echo "Este script solo se puede ejecutar por CLI o phpdbg.\n";
    exit(1);
}

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/delivery_route_utils.php';
require_once __DIR__ . '/../core/pii_crypto.php';

$options = getopt('', ['dry-run', 'apply', 'help']);
if (isset($options['help'])) {
    echo "Uso: php scripts\\create_new_clients_odoo_20260817.php [--dry-run|--apply]\n";
    exit(0);
}
$dryRun = !array_key_exists('apply', $options);

function normalizePhoneDigitsMx10(string $phone): ?string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if ($digits === '') return null;
    if (strpos($digits, '52') === 0 && strlen($digits) === 12) $digits = substr($digits, 2);
    if (strpos($digits, '521') === 0 && strlen($digits) === 13) $digits = substr($digits, 3);
    return strlen($digits) === 10 ? $digits : null;
}

function formatPhoneMx(string $digits10): string
{
    return sprintf('(%s) - %s - %s', substr($digits10, 0, 3), substr($digits10, 3, 3), substr($digits10, 6, 4));
}

$encrypt = static function (?string $value): ?string {
    if ($value === null || $value === '') return $value;
    return function_exists('piiEncryptValue') ? piiEncryptValue($value) : $value;
};

// nombre, telefono crudo, email, maps_link
$nuevos = [
    ['nombre' => 'Erika - 9562', 'telefono' => '+52 33 1950 9562', 'email' => null, 'maps_link' => 'https://maps.app.goo.gl/GeBmvbgXHcp47f9w9'],
    ['nombre' => 'Juan Cortes-3333', 'telefono' => '+52 33 3727 3333', 'email' => null, 'maps_link' => 'https://maps.app.goo.gl/YbwUrD4c9VN45JMP9'],
    ['nombre' => 'Julio-3865', 'telefono' => '+52 33 1216 3865', 'email' => null, 'maps_link' => 'https://maps.app.goo.gl/RajLC3HGXgtJHbec7'],
    ['nombre' => 'Luisa Amiga Perla', 'telefono' => '+52 33 2225 9605', 'email' => null, 'maps_link' => null],
    ['nombre' => 'Lulu -5554', 'telefono' => '+52 33 1748 5554', 'email' => null, 'maps_link' => 'https://maps.app.goo.gl/5rcssfKdUdU6asSN8'],
    ['nombre' => 'Lupita Bautista-2218', 'telefono' => '+52 33 3568 2218', 'email' => null, 'maps_link' => 'https://maps.app.goo.gl/NfoTNDB65cYg3reTA'],
    ['nombre' => 'Mar - 7321', 'telefono' => '+52 33 2802 7321', 'email' => null, 'maps_link' => 'https://maps.app.goo.gl/Vkhghyk828PFfM3r7'],
    ['nombre' => 'Perla Velazco García', 'telefono' => '+52 33 1637 2758', 'email' => 'perlavelazco87@gmail.com', 'maps_link' => null],
];

$pdo = getPDO();
$apiKey = getMapsApiKey(false);

$hasClienteDirecciones = (bool)$pdo->query(
    "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cliente_direcciones'"
)->fetchColumn();

echo "═══════════════════════════════════════════════\n";
echo "  ALTA DE 8 CLIENTES NUEVOS DESDE ODOO\n";
echo "═══════════════════════════════════════════════\n";
echo 'Modo: ' . ($dryRun ? 'DRY-RUN' : 'APPLY') . "\n";
echo "═══════════════════════════════════════════════\n\n";

foreach ($nuevos as $n) {
    $digits = normalizePhoneDigitsMx10($n['telefono']);
    if ($digits === null) {
        echo "  [SKIP] {$n['nombre']} — telefono invalido\n";
        continue;
    }
    $telefonoFormateado = formatPhoneMx($digits);

    // Evita duplicar si ya se corrio antes con --apply.
    $existeStmt = $pdo->query("SELECT id_cliente, nombre FROM clientes")->fetchAll(PDO::FETCH_ASSOC);
    $yaExiste = false;
    foreach ($existeStmt as $row) {
        $nombreDb = trim((string)$row['nombre']);
        if (piiIsEncryptedValue($nombreDb)) {
            $dec = trim((string)piiDecryptValue($nombreDb));
            if ($dec !== $nombreDb && !piiIsEncryptedValue($dec)) $nombreDb = $dec;
        }
        if (strcasecmp($nombreDb, $n['nombre']) === 0) {
            $yaExiste = true;
            break;
        }
    }
    if ($yaExiste) {
        echo "  [SKIP] {$n['nombre']} — ya existe en clientes, no se duplica\n";
        continue;
    }

    $idCliente = null;
    if (!$dryRun) {
        $stmt = $pdo->prepare("INSERT INTO clientes (nombre, email, telefono, estado) VALUES (?, ?, ?, 'activo')");
        $stmt->execute([
            $encrypt($n['nombre']),
            $encrypt($n['email']),
            $encrypt($telefonoFormateado),
        ]);
        $idCliente = (int)$pdo->lastInsertId();
    }

    echo sprintf(
        "  [%s] Cliente creado: \"%s\" tel=%s%s%s\n",
        $dryRun ? 'DRY' : 'OK',
        $n['nombre'],
        $telefonoFormateado,
        $n['email'] ? " email={$n['email']}" : '',
        $idCliente !== null ? " (id={$idCliente})" : ''
    );

    if ($n['maps_link'] === null || !$hasClienteDirecciones) {
        continue;
    }

    $urlExpandida = deliveryExpandUrlWithCurl($n['maps_link']);
    $coords = $urlExpandida !== null ? deliveryExtractCoordinatesFromMapsUrl($urlExpandida) : null;
    $direccionExtraida = null;

    if ($coords === null) {
        $direccionExtraida = $urlExpandida !== null ? deliveryExtractAddressFromMapsUrl($urlExpandida) : null;
        if ($direccionExtraida !== null) {
            $coords = deliveryGeocodeAddress($direccionExtraida, $apiKey);
        }
    }

    $direccionTexto = $direccionExtraida ?? 'Pendiente';

    if ($coords === null) {
        echo "         [FAIL] No se pudo resolver coordenadas del link, se omite domicilio\n";
        continue;
    }

    if (!$dryRun && $idCliente !== null) {
        $stmt = $pdo->prepare(
            'INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default, latitud, longitud) VALUES (?, ?, ?, ?, 1, ?, ?)'
        );
        $stmt->execute([
            $idCliente,
            'Principal',
            $encrypt($direccionTexto),
            $encrypt($n['maps_link']),
            $coords['lat'],
            $coords['lng'],
        ]);
    }

    echo sprintf(
        "         [%s] Domicilio -> (%.6f, %.6f) direccion=\"%s\"\n",
        $dryRun ? 'DRY' : 'OK',
        $coords['lat'],
        $coords['lng'],
        $direccionTexto
    );
}

echo "\n═══════════════════════════════════════════════\n";
echo "Listo.\n";
