<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo se puede ejecutar por CLI." . PHP_EOL);
    exit(1);
}

$options = getopt('', ['dry-run', 'limit::']);
$isDryRun = array_key_exists('dry-run', $options);
$limit = isset($options['limit']) && is_numeric($options['limit'])
    ? max(1, (int)$options['limit'])
    : 1000;

$rootDir = dirname(__DIR__);
$fallbackPath = $rootDir . '/audit_fallback.log';
$runId = date('Ymd_His');
$processingPath = $rootDir . '/audit_fallback.processing.' . $runId . '.log';

if (!is_file($fallbackPath) || filesize($fallbackPath) === 0) {
    fwrite(STDOUT, "No hay eventos pendientes en audit_fallback.log" . PHP_EOL);
    exit(0);
}

if (!@rename($fallbackPath, $processingPath)) {
    fwrite(STDERR, "No se pudo rotar audit_fallback.log para procesar." . PHP_EOL);
    exit(1);
}

$handle = @fopen($processingPath, 'rb');
if ($handle === false) {
    fwrite(STDERR, "No se pudo abrir el archivo de procesamiento: " . $processingPath . PHP_EOL);
    // Intentar restaurar para no perder eventos.
    @rename($processingPath, $fallbackPath);
    exit(1);
}

$pdo = null;
$stmt = null;
if (!$isDryRun) {
    try {
        $pdo = getPDO();
        try {
            $pdo->exec('SET SESSION innodb_lock_wait_timeout = 3');
        } catch (Throwable $e) {
            // Continuar aunque no sea posible ajustar timeout.
        }

        $stmt = $pdo->prepare(
            'INSERT INTO logs_auditoria (id_usuario, accion, tabla_afectada, id_registro, detalles, ip_address) VALUES (?, ?, ?, ?, ?, ?)'
        );
    } catch (Throwable $e) {
        fclose($handle);
        fwrite(STDERR, "No se pudo iniciar conexión SQL: " . $e->getMessage() . PHP_EOL);
        // Restaurar archivo para no perder eventos.
        @rename($processingPath, $fallbackPath);
        exit(1);
    }
}

$totalRead = 0;
$processed = 0;
$failed = 0;
$kept = [];

while (!feof($handle) && $totalRead < $limit) {
    $line = fgets($handle);
    if ($line === false) {
        break;
    }

    $raw = trim($line);
    if ($raw === '') {
        continue;
    }

    $totalRead++;

    $entry = json_decode($raw, true);
    if (!is_array($entry)) {
        $failed++;
        $kept[] = $raw;
        continue;
    }

    $accion = trim((string)($entry['accion'] ?? ''));
    $tabla = trim((string)($entry['tabla'] ?? ''));
    $detalles = (string)($entry['detalles'] ?? '');

    if ($accion === '' || $tabla === '') {
        $failed++;
        $kept[] = $raw;
        continue;
    }

    if ($isDryRun) {
        $processed++;
        continue;
    }

    $idUsuario = isset($entry['id_usuario']) && is_numeric($entry['id_usuario'])
        ? (int)$entry['id_usuario']
        : null;
    $idRegistro = isset($entry['id_registro']) && is_numeric($entry['id_registro'])
        ? (int)$entry['id_registro']
        : null;
    $ip = trim((string)($entry['ip'] ?? '0.0.0.0'));

    try {
        $stmt->execute([
            $idUsuario,
            $accion,
            $tabla,
            $idRegistro,
            $detalles,
            $ip === '' ? '0.0.0.0' : $ip,
        ]);
        $processed++;
    } catch (Throwable $e) {
        $failed++;
        $kept[] = $raw;
    }
}

// Reinyectar pendientes no procesados por limite o por error.
while (!feof($handle)) {
    $line = fgets($handle);
    if ($line === false) {
        break;
    }

    $raw = trim($line);
    if ($raw !== '') {
        $kept[] = $raw;
    }
}

fclose($handle);
@unlink($processingPath);

if (!empty($kept)) {
    $out = fopen($fallbackPath, 'ab');
    if ($out === false) {
        fwrite(STDERR, "No se pudo reescribir pendientes en audit_fallback.log" . PHP_EOL);
        exit(1);
    }

    foreach ($kept as $pending) {
        fwrite($out, $pending . PHP_EOL);
    }
    fclose($out);
}

fwrite(
    STDOUT,
    sprintf(
        "RUN %s | dry-run=%s | leidos=%d | procesados=%d | fallidos=%d | pendientes=%d%s",
        $runId,
        $isDryRun ? 'yes' : 'no',
        $totalRead,
        $processed,
        $failed,
        count($kept),
        PHP_EOL
    )
);

exit(0);
