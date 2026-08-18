<?php
declare(strict_types=1);

/**
 * Reconcilia telefonos de clientes existentes contra un export de contactos de Odoo.
 *
 * A diferencia de import_odoo_contacts_ready.php (que da de alta clientes nuevos),
 * este script NUNCA crea clientes: solo actualiza el telefono de clientes que YA
 * existen en la tabla `clientes`, emparejando por nombre normalizado (sin acentos
 * ni mayusculas). Es el emparejamiento correcto para clientes de sucursal sin
 * email ni telefono descifrable (el telefono actual esta vacio o corrupto, asi
 * que no se puede usar como criterio de match).
 *
 * Reglas de seguridad:
 * - Nombre coincide con UN solo cliente y su telefono actual esta vacio -> se
 *   propone actualizar.
 * - Nombre coincide pero el cliente ya tiene un telefono valido DISTINTO al de
 *   Odoo -> se reporta como conflicto, NUNCA se sobreescribe.
 * - Nombre coincide con MAS de un cliente -> se reporta como ambiguo, se omite.
 *
 * Uso:
 *   C:\xampp\php\php.exe scripts\reconcile_client_phones_from_odoo.php --input=contactos.csv --dry-run
 *   C:\xampp\php\php.exe scripts\reconcile_client_phones_from_odoo.php --input=contactos.csv --apply
 */

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    http_response_code(403);
    echo "Este script solo se puede ejecutar por CLI o phpdbg.\n";
    exit(1);
}

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/pii_crypto.php';

$options = getopt('', ['input:', 'dry-run', 'apply', 'delimiter::', 'help']);

if (isset($options['help']) || !isset($options['input'])) {
    echo "Uso:\n";
    echo "  C:\\xampp\\php\\php.exe scripts\\reconcile_client_phones_from_odoo.php --input=archivo.csv [--dry-run|--apply] [--delimiter=,]\n\n";
    echo "Opciones:\n";
    echo "  --input=...      CSV exportado de Odoo (requerido). Columnas esperadas: ID, Nombre completo, Telefono, Correo electronico, Activo.\n";
    echo "  --dry-run        Simula sin escribir en BD (default).\n";
    echo "  --apply          Ejecuta las actualizaciones reales.\n";
    echo "  --delimiter=...  Forzar delimitador (default: coma).\n";
    echo "  --help           Muestra esta ayuda.\n";
    exit(isset($options['help']) ? 0 : 1);
}

$inputPath = (string)$options['input'];
$dryRun = !array_key_exists('apply', $options);
$delimiter = isset($options['delimiter']) ? (string)$options['delimiter'] : ',';

if (!is_file($inputPath) || !is_readable($inputPath)) {
    fwrite(STDERR, "ERROR: No se puede leer el archivo: {$inputPath}\n");
    exit(1);
}

/**
 * Corrige mojibake tipico de UTF-8 leido como Latin-1/Windows-1252
 * (ej. "TelÃ©fono" -> "Teléfono").
 */
function fixMojibake(string $value): string
{
    if ($value === '' || strpos($value, 'Ã') === false) {
        return $value;
    }
    $fixed = @mb_convert_encoding($value, 'ISO-8859-1', 'UTF-8');
    if (!is_string($fixed) || $fixed === '') {
        return $value;
    }
    return mb_check_encoding($fixed, 'UTF-8') ? $fixed : $value;
}

function normalizeNameForMatch(string $nombre): string
{
    $nombre = trim($nombre);
    if ($nombre === '') {
        return '';
    }
    $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nombre);
    if ($transliterated !== false) {
        $nombre = $transliterated;
    }
    $nombre = strtolower($nombre);
    $nombre = preg_replace('/\s+/', ' ', $nombre) ?? $nombre;
    return trim($nombre);
}

function normalizePhoneDigitsMx10(string $phone): ?string
{
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if ($digits === '') {
        return null;
    }
    if (strpos($digits, '52') === 0 && strlen($digits) === 12) {
        $digits = substr($digits, 2);
    }
    if (strpos($digits, '521') === 0 && strlen($digits) === 13) {
        $digits = substr($digits, 3);
    }
    return strlen($digits) === 10 ? $digits : null;
}

function formatPhoneMx(string $digits10): string
{
    return sprintf('(%s) - %s - %s', substr($digits10, 0, 3), substr($digits10, 3, 3), substr($digits10, 6, 4));
}

$decrypt = static function (?string $value): string {
    $raw = trim((string)$value);
    if ($raw === '') {
        return '';
    }
    if (piiIsEncryptedValue($raw)) {
        $dec = trim((string)piiDecryptValue($raw));
        return ($dec === $raw || piiIsEncryptedValue($dec)) ? '' : $dec;
    }
    return $raw;
};

$encrypt = static function (string $value): string {
    return function_exists('piiEncryptValue') ? (string)piiEncryptValue($value) : $value;
};

// ─── Cargar clientes existentes y armar indice por nombre normalizado ──────
$pdo = getPDO();
$clientesRaw = $pdo->query('SELECT id_cliente, nombre, telefono, estado FROM clientes')->fetchAll(PDO::FETCH_ASSOC);

$clientesPorNombre = [];
foreach ($clientesRaw as $c) {
    $nombre = $decrypt($c['nombre'] ?? '');
    if ($nombre === '') {
        continue;
    }
    $telefono = $decrypt($c['telefono'] ?? '');
    $key = normalizeNameForMatch($nombre);
    $clientesPorNombre[$key][] = [
        'id_cliente' => (int)$c['id_cliente'],
        'nombre' => $nombre,
        'telefono' => $telefono,
        'estado' => (string)$c['estado'],
    ];
}

// ─── Leer CSV de Odoo ────────────────────────────────────────────
$fp = fopen($inputPath, 'rb');
if ($fp === false) {
    fwrite(STDERR, "ERROR: No se pudo abrir el CSV.\n");
    exit(1);
}

$header = fgetcsv($fp, 0, $delimiter);
if ($header === false) {
    fclose($fp);
    fwrite(STDERR, "ERROR: El CSV esta vacio.\n");
    exit(1);
}

$idxNombre = null;
$idxTelefono = null;
$idxEmail = null;
foreach ($header as $i => $h) {
    $hFixed = strtolower(trim(fixMojibake((string)$h)));
    $hAscii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $hFixed);
    if (is_string($hAscii) && $hAscii !== '') {
        $hFixed = $hAscii;
    }
    // iconv TRANSLIT a veces inserta apostrofes en vocales acentuadas (p.ej.
    // "telefono" -> "tel'efono"); se descarta todo lo que no sea a-z0-9.
    $hFixed = preg_replace('/[^a-z0-9]/', '', $hFixed) ?? $hFixed;
    if ($idxNombre === null && strpos($hFixed, 'nombre') !== false) {
        $idxNombre = $i;
    }
    if ($idxTelefono === null && strpos($hFixed, 'telefono') !== false) {
        $idxTelefono = $i;
    }
    if ($idxEmail === null && strpos($hFixed, 'correo') !== false) {
        $idxEmail = $i;
    }
}

if ($idxNombre === null || $idxTelefono === null) {
    fclose($fp);
    fwrite(STDERR, "ERROR: No se encontraron columnas de Nombre y/o Telefono en el CSV.\n");
    exit(1);
}

$resultados = [
    'actualizar' => [],
    'conflicto' => [],
    'ambiguo' => [],
    'ya_coincide' => 0,
    'sin_coincidencia' => 0,
    'sin_telefono_odoo' => 0,
];

$line = 1;
while (($row = fgetcsv($fp, 0, $delimiter)) !== false) {
    $line++;
    if ($row === [null] || $row === []) {
        continue;
    }

    $nombreOdoo = fixMojibake(trim((string)($row[$idxNombre] ?? '')));
    $telefonoOdooRaw = trim((string)($row[$idxTelefono] ?? ''));
    $emailOdooRaw = $idxEmail !== null ? trim((string)($row[$idxEmail] ?? '')) : '';

    if ($nombreOdoo === '') {
        continue;
    }

    // Algunos registros traen el telefono en la columna de correo por error
    // de captura en Odoo; se usa como respaldo si la columna telefono viene vacia.
    $digitsOdoo = normalizePhoneDigitsMx10($telefonoOdooRaw);
    if ($digitsOdoo === null && $emailOdooRaw !== '') {
        $digitsOdoo = normalizePhoneDigitsMx10($emailOdooRaw);
    }

    if ($digitsOdoo === null) {
        $resultados['sin_telefono_odoo']++;
        continue;
    }

    $key = normalizeNameForMatch($nombreOdoo);
    $candidatos = $clientesPorNombre[$key] ?? [];

    if (count($candidatos) === 0) {
        $resultados['sin_coincidencia']++;
        continue;
    }

    if (count($candidatos) > 1) {
        $resultados['ambiguo'][] = [
            'nombre_odoo' => $nombreOdoo,
            'candidatos' => array_map(static fn($c) => $c['id_cliente'], $candidatos),
        ];
        continue;
    }

    $cliente = $candidatos[0];
    $digitsActual = normalizePhoneDigitsMx10($cliente['telefono']);

    if ($digitsActual === $digitsOdoo) {
        $resultados['ya_coincide']++;
        continue;
    }

    if ($cliente['telefono'] !== '' && $digitsActual !== null) {
        $resultados['conflicto'][] = [
            'id_cliente' => $cliente['id_cliente'],
            'nombre' => $cliente['nombre'],
            'telefono_actual' => $cliente['telefono'],
            'telefono_odoo' => formatPhoneMx($digitsOdoo),
        ];
        continue;
    }

    $resultados['actualizar'][] = [
        'id_cliente' => $cliente['id_cliente'],
        'nombre' => $cliente['nombre'],
        'telefono_nuevo' => formatPhoneMx($digitsOdoo),
    ];
}

fclose($fp);

// ─── Aplicar (si corresponde) ────────────────────────────────────
if (!$dryRun && !empty($resultados['actualizar'])) {
    $update = $pdo->prepare('UPDATE clientes SET telefono = ? WHERE id_cliente = ?');
    foreach ($resultados['actualizar'] as $r) {
        $update->execute([$encrypt($r['telefono_nuevo']), $r['id_cliente']]);
    }
}

// ─── Reporte ──────────────────────────────────────────────────────
echo "═══════════════════════════════════════════════\n";
echo "  RECONCILIACION DE TELEFONOS CONTRA ODOO\n";
echo "═══════════════════════════════════════════════\n";
echo 'Modo: ' . ($dryRun ? 'DRY-RUN (sin cambios reales)' : 'APPLY (cambios en BD)') . "\n";
echo "═══════════════════════════════════════════════\n\n";

echo "--- A ACTUALIZAR (" . count($resultados['actualizar']) . ") ---\n";
foreach ($resultados['actualizar'] as $r) {
    echo sprintf("  [%s] cliente #%d \"%s\" -> %s\n", $dryRun ? 'DRY' : 'OK', $r['id_cliente'], $r['nombre'], $r['telefono_nuevo']);
}

echo "\n--- CONFLICTOS, revisar manualmente (" . count($resultados['conflicto']) . ") ---\n";
foreach ($resultados['conflicto'] as $r) {
    echo sprintf("  cliente #%d \"%s\" — actual: %s | odoo: %s\n", $r['id_cliente'], $r['nombre'], $r['telefono_actual'], $r['telefono_odoo']);
}

echo "\n--- AMBIGUOS, mismo nombre en varios clientes (" . count($resultados['ambiguo']) . ") ---\n";
foreach ($resultados['ambiguo'] as $r) {
    echo sprintf("  \"%s\" -> candidatos: #%s\n", $r['nombre_odoo'], implode(', #', $r['candidatos']));
}

echo "\n═══════════════════════════════════════════════\n";
echo "  RESUMEN\n";
echo "═══════════════════════════════════════════════\n";
echo 'A actualizar' . ($dryRun ? ' (simulado)' : '') . ':      ' . count($resultados['actualizar']) . "\n";
echo 'Conflictos (sin tocar):     ' . count($resultados['conflicto']) . "\n";
echo 'Ambiguos (sin tocar):       ' . count($resultados['ambiguo']) . "\n";
echo 'Ya coincidian:              ' . $resultados['ya_coincide'] . "\n";
echo 'Sin coincidencia de nombre: ' . $resultados['sin_coincidencia'] . "\n";
echo 'Filas de Odoo sin telefono: ' . $resultados['sin_telefono_odoo'] . "\n";
echo "═══════════════════════════════════════════════\n";
