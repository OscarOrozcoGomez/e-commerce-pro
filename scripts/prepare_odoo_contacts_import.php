<?php
declare(strict_types=1);

/**
 * Prepara contactos exportados de Odoo para migracion a e-commerce.
 *
 * Entradas esperadas (encabezados Odoo):
 * - ID o ID externo
 * - Nombre
 * - Correo electronico normalizado (preferido) o Correo electronico
 * - Numero de telefono (preferido) o Telefono
 * - Activo
 * - Sitio web / URL del sitio web / Enlace del sitio web (maps link)
 *
 * Salidas:
 * - clean.csv: todos los registros limpiados
 * - ready.csv: solo registros listos para crear cuenta ecommerce (email valido)
 * - review.csv: registros con incidencias (faltantes, posibles duplicados, etc.)
 *
 * Uso:
 *   C:\xampp\php\php.exe scripts\prepare_odoo_contacts_import.php --input=odoo_contactos.csv
 *   C:\xampp\php\php.exe scripts\prepare_odoo_contacts_import.php --input=odoo.csv --out-dir=tmp --dry-run
 */

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    http_response_code(403);
    echo "Este script solo se puede ejecutar por CLI o phpdbg.\n";
    exit(1);
}

$options = getopt('', [
    'input:',
    'out-dir::',
    'delimiter::',
    'dry-run',
    'help',
]);

if (isset($options['help']) || !isset($options['input'])) {
    echo "Uso:\n";
    echo "  C:\\xampp\\php\\php.exe scripts\\prepare_odoo_contacts_import.php --input=archivo.csv [--out-dir=salida] [--delimiter=,|;|tab] [--dry-run]\n\n";
    echo "Opciones:\n";
    echo "  --input=...      Ruta al CSV exportado de Odoo (requerido).\n";
    echo "  --out-dir=...    Directorio de salida. Default: scripts/output\n";
    echo "  --delimiter=...  Forzar delimitador: , ; tab\n";
    echo "  --dry-run        No escribe archivos; solo muestra resumen.\n";
    echo "  --help           Muestra esta ayuda.\n";
    exit(isset($options['help']) ? 0 : 1);
}

$inputPath = (string) $options['input'];
$outDir = isset($options['out-dir']) ? (string) $options['out-dir'] : (__DIR__ . '/output');
$dryRun = array_key_exists('dry-run', $options);
$forcedDelimiter = isset($options['delimiter']) ? (string) $options['delimiter'] : null;

if (!is_file($inputPath) || !is_readable($inputPath)) {
    fwrite(STDERR, "ERROR: No se puede leer el archivo: {$inputPath}\n");
    exit(1);
}

$delimiter = resolveDelimiter($inputPath, $forcedDelimiter);
$handle = fopen($inputPath, 'rb');
if ($handle === false) {
    fwrite(STDERR, "ERROR: No se pudo abrir el archivo de entrada.\n");
    exit(1);
}

$headerRow = fgetcsv($handle, 0, $delimiter);
if ($headerRow === false) {
    fclose($handle);
    fwrite(STDERR, "ERROR: El CSV esta vacio o no se pudo leer el encabezado.\n");
    exit(1);
}

$headers = normalizeHeaders($headerRow);
$idx = buildHeaderIndex($headers);

$requiredAny = [
    ['id externo', 'id'],
    ['nombre'],
    ['numero de telefono', 'telefono'],
    ['activo'],
];

$missing = [];
foreach ($requiredAny as $group) {
    $exists = false;
    foreach ($group as $field) {
        if (array_key_exists($field, $idx)) {
            $exists = true;
            break;
        }
    }
    if (!$exists) {
        $missing[] = implode(' o ', $group);
    }
}

if ($missing !== []) {
    fclose($handle);
    fwrite(STDERR, "ERROR: Faltan columnas requeridas en CSV:\n - " . implode("\n - ", $missing) . "\n");
    exit(1);
}

$rowsClean = [];
$rowsReady = [];
$rowsReview = [];
$stats = [
    'total' => 0,
    'ready' => 0,
    'review' => 0,
    'blocked' => 0,
    'fixed_mojibake' => 0,
    'name_generated' => 0,
    'maps_link_set' => 0,
    'duplicates_email' => 0,
    'duplicates_phone' => 0,
    'missing_email' => 0,
    'missing_phone' => 0,
    'ready_phone_only' => 0,
    'ready_email_phone' => 0,
];

$seenEmail = [];
$seenPhone = [];
$line = 1;

while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
    $line++;
    if ($row === [null] || $row === []) {
        continue;
    }

    $stats['total']++;

    $record = buildRecord($row, $idx);

    if ($record['mojibake_fixed']) {
        $stats['fixed_mojibake']++;
    }

    if ($record['name_generated']) {
        $stats['name_generated']++;
    }

    if ($record['maps_link'] !== '') {
        $stats['maps_link_set']++;
    }

    $issues = [];

    if ($record['email'] === '') {
        $stats['missing_email']++;
        $issues[] = 'missing_email';
    }

    if ($record['telefono'] === '') {
        $stats['missing_phone']++;
        $issues[] = 'missing_phone';
    }

    if ($record['email'] !== '') {
        if (isset($seenEmail[$record['email']])) {
            $stats['duplicates_email']++;
            $issues[] = 'duplicate_email';
        } else {
            $seenEmail[$record['email']] = $line;
        }
    }

    if ($record['telefono'] !== '') {
        if (isset($seenPhone[$record['telefono']])) {
            $stats['duplicates_phone']++;
            $issues[] = 'duplicate_phone';
        } else {
            $seenPhone[$record['telefono']] = $line;
        }
    }

    $quality = 'ready';
    if (in_array('missing_email', $issues, true) && in_array('missing_phone', $issues, true)) {
        $quality = 'blocked';
    } elseif (in_array('missing_phone', $issues, true)) {
        $quality = 'review';
    }

    $canCreateUser = $record['email'] !== '';
    $record['can_create_user'] = $canCreateUser ? '1' : '0';

    $record['quality_status'] = $quality;
    $record['issues'] = implode('|', $issues);
    $record['source_line'] = (string) $line;

    $rowsClean[] = $record;

    if ($quality === 'ready') {
        $rowsReady[] = $record;
        $stats['ready']++;
        if ($record['email'] !== '' && $record['telefono'] !== '') {
            $stats['ready_email_phone']++;
        } elseif ($record['email'] === '' && $record['telefono'] !== '') {
            $stats['ready_phone_only']++;
        }
    } else {
        $rowsReview[] = $record;
        $stats['review']++;
        if ($quality === 'blocked') {
            $stats['blocked']++;
        }
    }
}

fclose($handle);

if (!$dryRun) {
    if (!is_dir($outDir) && !mkdir($outDir, 0775, true) && !is_dir($outDir)) {
        fwrite(STDERR, "ERROR: No se pudo crear directorio de salida: {$outDir}\n");
        exit(1);
    }

    $base = pathinfo($inputPath, PATHINFO_FILENAME);
    $cleanPath = rtrim($outDir, '/\\') . DIRECTORY_SEPARATOR . $base . '_clean.csv';
    $readyPath = rtrim($outDir, '/\\') . DIRECTORY_SEPARATOR . $base . '_ready.csv';
    $reviewPath = rtrim($outDir, '/\\') . DIRECTORY_SEPARATOR . $base . '_review.csv';
    $summaryPath = rtrim($outDir, '/\\') . DIRECTORY_SEPARATOR . $base . '_summary.json';

    writeCsv($cleanPath, $rowsClean);
    writeCsv($readyPath, $rowsReady);
    writeCsv($reviewPath, $rowsReview);

    $summary = [
        'generated_at' => date('c'),
        'input' => realpath($inputPath) ?: $inputPath,
        'delimiter' => delimiterLabel($delimiter),
        'stats' => $stats,
        'outputs' => [
            'clean_csv' => $cleanPath,
            'ready_csv' => $readyPath,
            'review_csv' => $reviewPath,
        ],
    ];

    file_put_contents($summaryPath, json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    echo "Archivos generados:\n";
    echo " - {$cleanPath}\n";
    echo " - {$readyPath}\n";
    echo " - {$reviewPath}\n";
    echo " - {$summaryPath}\n\n";
}

echo "====== RESUMEN PREPARACION ODOO ======\n";
echo "Total filas leidas: {$stats['total']}\n";
echo "Listas para ecommerce: {$stats['ready']}\n";
echo "Para revision: {$stats['review']}\n";
echo "Bloqueadas (sin email y sin telefono): {$stats['blocked']}\n";
echo "Mojibake corregido: {$stats['fixed_mojibake']}\n";
echo "Alias generados (sin nombre): {$stats['name_generated']}\n";
echo "Con maps_link: {$stats['maps_link_set']}\n";
echo "Duplicados email: {$stats['duplicates_email']}\n";
echo "Duplicados telefono: {$stats['duplicates_phone']}\n";
echo "Faltan email: {$stats['missing_email']}\n";
echo "Faltan telefono: {$stats['missing_phone']}\n";
echo "Listos con email+telefono: {$stats['ready_email_phone']}\n";
echo "Listos solo telefono: {$stats['ready_phone_only']}\n";

echo "\nSiguiente paso recomendado: importar primero *_ready.csv en ambiente QA.\n";

exit(0);

function resolveDelimiter(string $path, ?string $forced): string
{
    if ($forced !== null && $forced !== '') {
        $forced = strtolower(trim($forced));
        if ($forced === 'tab') {
            return "\t";
        }
        if (in_array($forced, [',', ';'], true)) {
            return $forced;
        }
        throw new RuntimeException('Delimitador no soportado. Usa , ; o tab');
    }

    $sample = file_get_contents($path, false, null, 0, 2048);
    if ($sample === false) {
        return ',';
    }

    if (strncmp($sample, "\xEF\xBB\xBF", 3) === 0) {
        $sample = substr($sample, 3);
    }

    $firstLine = strtok($sample, "\r\n");
    if ($firstLine === false) {
        return ',';
    }

    $counts = [
        ',' => substr_count($firstLine, ','),
        ';' => substr_count($firstLine, ';'),
        "\t" => substr_count($firstLine, "\t"),
    ];

    arsort($counts);
    $delimiter = (string) array_key_first($counts);
    return $counts[$delimiter] > 0 ? $delimiter : ',';
}

function normalizeHeaders(array $headerRow): array
{
    if ($headerRow !== [] && isset($headerRow[0])) {
        $headerRow[0] = removeUtf8Bom((string) $headerRow[0]);
    }

    $headers = [];
    foreach ($headerRow as $h) {
        $headers[] = canonicalHeader((string) $h);
    }

    return $headers;
}

function buildHeaderIndex(array $headers): array
{
    $idx = [];
    foreach ($headers as $i => $header) {
        if ($header === '') {
            continue;
        }
        if (!array_key_exists($header, $idx)) {
            $idx[$header] = $i;
        }
    }
    return $idx;
}

function removeUtf8Bom(string $value): string
{
    return preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;
}

function canonicalHeader(string $raw): string
{
    $v = normalizeToUtf8($raw);
    $v = strtolower($v);
    $v = strtr($v, [
        'á' => 'a',
        'é' => 'e',
        'í' => 'i',
        'ó' => 'o',
        'ú' => 'u',
        'ü' => 'u',
        'ñ' => 'n',
    ]);
    $v = preg_replace('/\s+/', ' ', trim($v)) ?? $v;
    return $v;
}

function buildRecord(array $row, array $idx): array
{
    $idExterno = firstValue($row, $idx, ['id externo', 'id']);

    $rawName = firstValue($row, $idx, ['nombre', 'nombre completo']);
    $nameResult = normalizeName($rawName, firstValue($row, $idx, ['numero de telefono', 'telefono']));

    $rawEmail = firstValue($row, $idx, ['correo electronico normalizado', 'correo electronico']);
    $email = normalizeEmail($rawEmail);

    $rawPhone = firstValue($row, $idx, ['numero de telefono', 'telefono']);
    $phone = normalizePhoneMx($rawPhone);

    $rawActive = firstValue($row, $idx, ['activo']);
    $estado = toEstado($rawActive);

    $rawMaps = firstValue($row, $idx, ['sitio web', 'url del sitio web', 'enlace del sitio web']);
    $mapsLink = normalizeMapsLink($rawMaps);

    return [
        'external_id' => $idExterno,
        'nombre' => $nameResult['name'],
        'nombre_original' => normalizeToUtf8((string) $rawName),
        'email' => $email,
        'telefono' => $phone,
        'estado' => $estado,
        'maps_link' => $mapsLink,
        'alias_direccion' => $mapsLink !== '' ? 'Principal' : '',
        'direccion' => $mapsLink !== '' ? 'Por confirmar' : '',
        'mojibake_fixed' => $nameResult['mojibake_fixed'],
        'name_generated' => $nameResult['generated'],
    ];
}

function firstValue(array $row, array $idx, array $keys): string
{
    foreach ($keys as $k) {
        if (array_key_exists($k, $idx)) {
            $v = $row[$idx[$k]] ?? '';
            return normalizeToUtf8((string) $v);
        }
    }
    return '';
}

function normalizeToUtf8(string $value): string
{
    $value = trim(removeUtf8Bom($value));
    if ($value === '') {
        return '';
    }

    // Limpia separadores invisibles comunes.
    $value = str_replace(["\xC2\xA0", "\xE2\x80\x8B"], ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;

    // Si se detecta mojibake, intenta reconstruir texto original.
    if (textLooksMojibake($value)) {
        $fixed = @iconv('UTF-8', 'ISO-8859-1//IGNORE', $value);
        if (is_string($fixed) && $fixed !== '' && preg_match('//u', $fixed)) {
            $value = $fixed;
        }
    }

    return trim($value);
}

function textLooksMojibake(string $value): bool
{
    return preg_match('/Ã.|Â.|\x{FFFD}/u', $value) === 1;
}

function normalizeName(string $name, string $rawPhone): array
{
    $cleanName = normalizeToUtf8($name);
    $mojibakeFixed = textLooksMojibake($name) && $cleanName !== $name;

    $phone = normalizePhoneMx($rawPhone);
    $last4 = $phone !== '' ? substr($phone, -4) : '';

    if ($cleanName === '') {
        $generated = $last4 !== '' ? ('Cliente ' . $last4) : 'Cliente sin nombre';
        return [
            'name' => $generated,
            'generated' => true,
            'mojibake_fixed' => $mojibakeFixed,
        ];
    }

    // Respeta formato existente "Nombre - 1234".
    if (preg_match('/\-\s*\d{4}$/', $cleanName) === 1) {
        return [
            'name' => $cleanName,
            'generated' => false,
            'mojibake_fixed' => $mojibakeFixed,
        ];
    }

    return [
        'name' => $cleanName,
        'generated' => false,
        'mojibake_fixed' => $mojibakeFixed,
    ];
}

function normalizeEmail(string $email): string
{
    $email = strtolower(trim(normalizeToUtf8($email)));
    if ($email === '') {
        return '';
    }

    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function normalizePhoneMx(string $phone): string
{
    $phone = normalizeToUtf8($phone);
    if ($phone === '') {
        return '';
    }

    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    if ($digits === '') {
        return '';
    }

    if (strpos($digits, '52') === 0 && strlen($digits) > 10) {
        $digits = substr($digits, 2);
    }

    if (strlen($digits) > 10) {
        $digits = substr($digits, -10);
    }

    if (strlen($digits) !== 10) {
        return '';
    }

    return $digits;
}

function toEstado(string $activo): string
{
    $v = strtolower(trim(normalizeToUtf8($activo)));
    if ($v === '') {
        return 'activo';
    }

    $truthy = ['1', 'true', 't', 'si', 'sí', 'yes', 'y', 'activo'];
    return in_array($v, $truthy, true) ? 'activo' : 'inactivo';
}

function normalizeMapsLink(string $raw): string
{
    $v = trim(normalizeToUtf8($raw));
    if ($v === '') {
        return '';
    }

    if (!preg_match('#^https?://#i', $v)) {
        $v = 'https://' . ltrim($v, '/');
    }

    if (!filter_var($v, FILTER_VALIDATE_URL)) {
        return '';
    }

    return $v;
}

function writeCsv(string $path, array $rows): void
{
    $fp = fopen($path, 'wb');
    if ($fp === false) {
        throw new RuntimeException('No se pudo crear archivo: ' . $path);
    }

    // BOM UTF-8 para compatibilidad con Excel.
    fwrite($fp, "\xEF\xBB\xBF");

    $headers = [
        'external_id',
        'nombre',
        'nombre_original',
        'email',
        'telefono',
        'can_create_user',
        'estado',
        'maps_link',
        'alias_direccion',
        'direccion',
        'quality_status',
        'issues',
        'source_line',
    ];

    fputcsv($fp, $headers);

    foreach ($rows as $row) {
        $line = [];
        foreach ($headers as $h) {
            $line[] = (string) ($row[$h] ?? '');
        }
        fputcsv($fp, $line);
    }

    fclose($fp);
}

function delimiterLabel(string $delimiter): string
{
    if ($delimiter === "\t") {
        return 'tab';
    }
    return $delimiter;
}
