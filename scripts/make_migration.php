<?php

declare(strict_types=1);

/**
 * Generador de plantillas de migración SQL.
 *
 * Uso:
 *   php scripts/make_migration.php "borrar tabla prueba"
 *   php scripts/make_migration.php --mode=timestamp "borrar tabla prueba"
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Error: este script debe ejecutarse desde consola (CLI)." . PHP_EOL);
    exit(1);
}

function printUsage(string $script): void
{
    fwrite(STDOUT, "Uso:" . PHP_EOL);
    fwrite(STDOUT, "  php scripts/{$script} [--mode=sequence|timestamp] \"nombre descriptivo\"" . PHP_EOL);
    fwrite(STDOUT, "  php scripts/{$script} --timestamp \"nombre descriptivo\"" . PHP_EOL);
    fwrite(STDOUT, PHP_EOL);
    fwrite(STDOUT, "Opciones:" . PHP_EOL);
    fwrite(STDOUT, "  --mode=sequence    Usa consecutivo diario YYYYMMDD_000001 (default)." . PHP_EOL);
    fwrite(STDOUT, "  --mode=timestamp   Usa hora exacta YYYYMMDD_HHMMSS." . PHP_EOL);
    fwrite(STDOUT, "  --sequence         Alias de --mode=sequence." . PHP_EOL);
    fwrite(STDOUT, "  --timestamp        Alias de --mode=timestamp." . PHP_EOL);
    fwrite(STDOUT, "  -h, --help         Muestra esta ayuda." . PHP_EOL);
}

$script = basename((string) ($argv[0] ?? 'make_migration.php'));
$args = $argv;
array_shift($args);

$mode = 'sequence';
$nameParts = [];

foreach ($args as $arg) {
    $arg = trim((string) $arg);

    if ($arg === '-h' || $arg === '--help') {
        printUsage($script);
        exit(0);
    }

    if ($arg === '--timestamp') {
        $mode = 'timestamp';
        continue;
    }

    if ($arg === '--sequence') {
        $mode = 'sequence';
        continue;
    }

    if (strpos($arg, '--mode=') === 0) {
        $parsedMode = strtolower(substr($arg, 7));
        if ($parsedMode !== 'sequence' && $parsedMode !== 'timestamp') {
            fwrite(STDERR, "Error: modo invalido '{$parsedMode}'. Usa sequence o timestamp." . PHP_EOL);
            printUsage($script);
            exit(1);
        }

        $mode = $parsedMode;
        continue;
    }

    if (strpos($arg, '-') === 0) {
        fwrite(STDERR, "Error: opcion no reconocida '{$arg}'." . PHP_EOL);
        printUsage($script);
        exit(1);
    }

    $nameParts[] = $arg;
}

$rawName = trim(implode(' ', $nameParts));

if ($rawName === '') {
    fwrite(STDERR, "Error: debes indicar un nombre descriptivo para la migracion." . PHP_EOL);
    printUsage($script);
    exit(1);
}

/**
 * Convierte texto libre a snake_case ASCII.
 */
function toSnakeCase(string $value): string
{
    $value = trim($value);

    // Intenta transliterar acentos y caracteres unicode a ASCII.
    $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($transliterated !== false) {
        $value = $transliterated;
    }

    // Reemplaza cualquier bloque no alfanumerico por guion bajo.
    $value = preg_replace('/[^a-zA-Z0-9]+/', '_', $value) ?? '';
    $value = strtolower($value);
    $value = trim($value, '_');

    // Evita nombres vacios tras limpiar.
    return $value !== '' ? $value : 'migration';
}

/**
 * Obtiene el siguiente consecutivo para la fecha actual: YYYYMMDD_000001
 */
function buildNextPrefix(string $migrationsDir): string
{
    $today = date('Ymd');
    $pattern = '/^' . preg_quote($today, '/') . '_(\d{6})_.*\.sql$/i';

    $maxSequence = 0;

    foreach (scandir($migrationsDir) ?: [] as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }

        if (preg_match($pattern, $file, $matches) === 1) {
            $sequence = (int) $matches[1];
            if ($sequence > $maxSequence) {
                $maxSequence = $sequence;
            }
        }
    }

    $nextSequence = $maxSequence + 1;
    return $today . '_' . str_pad((string) $nextSequence, 6, '0', STR_PAD_LEFT);
}

/**
 * Prefijo por fecha y hora exacta: YYYYMMDD_HHMMSS
 */
function buildTimestampPrefix(): string
{
    return date('Ymd_His');
}

$rootDir = dirname(__DIR__);
$migrationsDir = $rootDir . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'migrations';

if (!is_dir($migrationsDir)) {
    fwrite(STDERR, "Error: no existe la carpeta de migraciones: {$migrationsDir}" . PHP_EOL);
    exit(1);
}

$prefix = $mode === 'timestamp'
    ? buildTimestampPrefix()
    : buildNextPrefix($migrationsDir);
$snakeName = toSnakeCase($rawName);
$fileName = $prefix . '_' . $snakeName . '.sql';
$filePath = $migrationsDir . DIRECTORY_SEPARATOR . $fileName;

if (file_exists($filePath)) {
    fwrite(STDERR, "Error: ya existe una migracion con ese nombre: {$filePath}" . PHP_EOL);
    exit(1);
}

$template = "-- Migracion: {$rawName}" . PHP_EOL;
$template .= "-- Escribe tu SQL abajo:" . PHP_EOL;

$result = @file_put_contents($filePath, $template);
if ($result === false) {
    fwrite(STDERR, "Error: no se pudo crear el archivo de migracion en: {$filePath}" . PHP_EOL);
    exit(1);
}

$realPath = realpath($filePath) ?: $filePath;

fwrite(STDOUT, PHP_EOL);
fwrite(STDOUT, "==============================================" . PHP_EOL);
fwrite(STDOUT, " Migracion creada correctamente" . PHP_EOL);
fwrite(STDOUT, "----------------------------------------------" . PHP_EOL);
fwrite(STDOUT, " Archivo: {$realPath}" . PHP_EOL);
fwrite(STDOUT, " Modo: {$mode}" . PHP_EOL);
fwrite(STDOUT, "==============================================" . PHP_EOL);
fwrite(STDOUT, PHP_EOL);
