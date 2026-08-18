<?php
declare(strict_types=1);

/**
 * Rellena coordenadas (latitud, longitud) para domicilios guardados de clientes
 * (cliente_direcciones) que aun no las tienen, usando el maps_link guardado.
 *
 * Muchos domicilios se migraron de Odoo solo con el link de Google Maps y sin
 * direccion en texto (queda como placeholder: "pendiente", "por confirmar", etc.).
 * Cuando el link no trae coordenadas crudas sino un identificador de lugar/CID
 * de Google, este script tambien extrae la direccion legible que Google incluye
 * en la URL (.../maps/place/<direccion>/...) y, si el campo direccion actual es
 * un placeholder o esta vacio, lo reemplaza con esa direccion real.
 *
 * Soporta campos cifrados con ENCv1 (PII) y los desencripta/cifra automaticamente.
 *
 * Uso:
 *   C:\xampp\php\php.exe scripts\backfill_cliente_direcciones_coordinates.php --dry-run
 *   C:\xampp\php\php.exe scripts\backfill_cliente_direcciones_coordinates.php --apply
 *   C:\xampp\php\php.exe scripts\backfill_cliente_direcciones_coordinates.php --apply --batch-size=25 --delay=200
 *
 * Opciones:
 *   --dry-run       Simula cambios sin escribir en BD (default).
 *   --apply         Ejecuta las actualizaciones en BD.
 *   --batch-size=N  Domicilios a procesar por lote (default: 25).
 *   --delay=N       Milisegundos de pausa entre cada geocodificacion (default: 200).
 *   --help          Muestra esta ayuda.
 */

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    http_response_code(403);
    echo "Este script solo se puede ejecutar por CLI o phpdbg.\n";
    exit(1);
}

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/delivery_route_utils.php';
require_once __DIR__ . '/../core/pii_crypto.php';

$options = getopt('', ['dry-run', 'apply', 'batch-size:', 'delay:', 'help']);

if (isset($options['help'])) {
    echo "Uso:\n";
    echo "  C:\\xampp\\php\\php.exe scripts\\backfill_cliente_direcciones_coordinates.php [--dry-run|--apply] [--batch-size=N] [--delay=N]\n\n";
    echo "Opciones:\n";
    echo "  --dry-run       Simula cambios sin escribir en BD (default).\n";
    echo "  --apply         Ejecuta las actualizaciones en BD.\n";
    echo "  --batch-size=N  Domicilios a procesar por lote (default: 25).\n";
    echo "  --delay=N       Milisegundos de pausa entre cada geocodificacion (default: 200).\n";
    echo "  --help          Muestra esta ayuda.\n";
    exit(0);
}

$dryRun = !array_key_exists('apply', $options);
$batchSize = isset($options['batch-size']) ? max(1, (int)$options['batch-size']) : 25;
$delayMs = isset($options['delay']) ? max(0, (int)$options['delay']) : 200;

$ADDRESS_PLACEHOLDERS = [
    'por confirmar', 'pendiente', 'pendiente de confirmar', 'sin direccion',
    'sin especificar', 'direccion pendiente', 'n/a', 'na', 'tbd', 'por definir',
];

function normalizeForPlaceholderCheck(string $value): string
{
    $value = trim(mb_strtolower($value));
    $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    return $transliterated !== false ? trim($transliterated) : $value;
}

function isPlaceholderAddress(string $value, array $placeholders): bool
{
    $normalized = normalizeForPlaceholderCheck($value);
    return $normalized !== '' && in_array($normalized, $placeholders, true);
}

// ─── Validar API key de Google Maps (opcional: sin ella cae a Nominatim) ──
$apiKey = getMapsApiKey(false);

// ─── Validar PII crypto ──────────────────────────────────────────
$piiAvailable = false;
try {
    piiGetEncryptionKey();
    $piiAvailable = true;
} catch (Throwable $e) {
    echo "ADVERTENCIA: PII_ENCRYPTION_KEY no disponible — se procesaran campos en texto plano solamente.\n";
}

$decrypt = static function (?string $value) use ($piiAvailable): string {
    $raw = trim((string)$value);
    if ($raw === '') {
        return '';
    }
    if ($piiAvailable && piiIsEncryptedValue($raw)) {
        $dec = trim((string)piiDecryptValue($raw));
        if ($dec === $raw || piiIsEncryptedValue($dec)) {
            return '';
        }
        return $dec;
    }
    return piiIsEncryptedValue($raw) ? '' : $raw;
};

$encrypt = static function (string $value) use ($piiAvailable): string {
    if ($value === '' || !$piiAvailable || !function_exists('piiEncryptValue')) {
        return $value;
    }
    return (string)piiEncryptValue($value);
};

// ─── Conexion a BD ────────────────────────────────────────────────
$pdo = getPDO();

$whereSql = '(latitud IS NULL OR longitud IS NULL) AND maps_link IS NOT NULL AND maps_link != \'\'';
$totalCandidates = (int)$pdo->query('SELECT COUNT(*) FROM cliente_direcciones WHERE ' . $whereSql)->fetchColumn();

echo "═══════════════════════════════════════════════\n";
echo "  BACKFILL COORDENADAS DE DOMICILIOS DE CLIENTES\n";
echo "═══════════════════════════════════════════════\n";
echo 'Modo:           ' . ($dryRun ? 'DRY-RUN (sin cambios reales)' : 'APPLY (cambios en BD)') . "\n";
echo 'Batch size:     ' . $batchSize . "\n";
echo 'Delay:          ' . $delayMs . " ms\n";
echo 'API Key Maps:   ' . ($apiKey !== '' ? substr($apiKey, 0, 8) . '...' : '[vacia, usara Nominatim]') . "\n";
echo 'PII disponible: ' . ($piiAvailable ? 'SI' : 'NO') . "\n";
echo 'Candidatos:     ' . $totalCandidates . " domicilios sin coordenadas\n";
echo "═══════════════════════════════════════════════\n\n";

if ($totalCandidates === 0) {
    echo "No hay domicilios pendientes por procesar.\n";
    exit(0);
}

$stats = [
    'procesados'              => 0,
    'resueltos_por_regex'     => 0,
    'resueltos_por_direccion' => 0,
    'direccion_actualizada'   => 0,
    'fallo_resolucion'        => 0,
    'actualizados'            => 0,
];

$fallidos = [];

// Se cargan todos los candidatos de una sola vez (la tabla es pequena) y se
// procesan en lotes solo para pausar entre geocodificaciones. Usar LIMIT/OFFSET
// aqui seria incorrecto en modo --apply: cada UPDATE saca filas del WHERE
// (latitud/longitud dejan de ser NULL), lo que recorre el resultado bajo los pies
// del OFFSET y salta filas.
$selectSql = 'SELECT id_direccion, id_cliente, direccion, maps_link, latitud, longitud'
    . ' FROM cliente_direcciones'
    . ' WHERE ' . $whereSql
    . ' ORDER BY id_direccion ASC';
$allCandidates = $pdo->query($selectSql)->fetchAll(PDO::FETCH_ASSOC);

foreach (array_chunk($allCandidates, $batchSize) as $batch) {
    $updateCoordsStmt = null;
    $updateCoordsAndDireccionStmt = null;
    if (!$dryRun) {
        $updateCoordsStmt = $pdo->prepare(
            'UPDATE cliente_direcciones SET latitud = :lat, longitud = :lng WHERE id_direccion = :id'
        );
        $updateCoordsAndDireccionStmt = $pdo->prepare(
            'UPDATE cliente_direcciones SET latitud = :lat, longitud = :lng, direccion = :direccion WHERE id_direccion = :id'
        );
    }

    foreach ($batch as $row) {
        $idDireccion = (int)$row['id_direccion'];
        $idCliente = (int)$row['id_cliente'];
        $mapsLink = $decrypt($row['maps_link'] ?? '');
        $direccionActual = $decrypt($row['direccion'] ?? '');

        $stats['procesados']++;

        if ($mapsLink === '') {
            continue;
        }

        $urlExpandida = deliveryExpandUrlWithCurl($mapsLink);
        $coords = $urlExpandida !== null ? deliveryExtractCoordinatesFromMapsUrl($urlExpandida) : null;
        $direccionExtraida = null;
        $fuente = 'regex';

        if ($coords !== null) {
            $stats['resueltos_por_regex']++;
        } else {
            $direccionExtraida = $urlExpandida !== null ? deliveryExtractAddressFromMapsUrl($urlExpandida) : null;
            if ($direccionExtraida !== null) {
                $coords = deliveryGeocodeAddress($direccionExtraida, $apiKey);
                $fuente = 'direccion+geocode';
                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }
            }
        }

        if ($coords === null) {
            $stats['fallo_resolucion']++;
            $fallidos[] = ['id_direccion' => $idDireccion, 'id_cliente' => $idCliente, 'maps_link' => $mapsLink];
            echo sprintf("  [FAIL] Domicilio #%d (cliente #%d) — no se pudieron resolver coordenadas\n", $idDireccion, $idCliente);
            continue;
        }

        if ($fuente === 'direccion+geocode') {
            $stats['resueltos_por_direccion']++;
        }

        $shouldUpdateDireccion = $direccionExtraida !== null
            && ($direccionActual === '' || isPlaceholderAddress($direccionActual, $ADDRESS_PLACEHOLDERS));

        if ($shouldUpdateDireccion) {
            $stats['direccion_actualizada']++;
        }

        if (!$dryRun) {
            if ($shouldUpdateDireccion && $updateCoordsAndDireccionStmt !== null) {
                $updateCoordsAndDireccionStmt->execute([
                    ':lat' => $coords['lat'],
                    ':lng' => $coords['lng'],
                    ':direccion' => $encrypt($direccionExtraida),
                    ':id' => $idDireccion,
                ]);
            } elseif ($updateCoordsStmt !== null) {
                $updateCoordsStmt->execute([
                    ':lat' => $coords['lat'],
                    ':lng' => $coords['lng'],
                    ':id' => $idDireccion,
                ]);
            }
        }

        $stats['actualizados']++;

        echo sprintf(
            "  [%s] Domicilio #%d (cliente #%d) -> (%.6f, %.6f) | fuente: %s%s\n",
            $dryRun ? 'DRY' : 'OK',
            $idDireccion,
            $idCliente,
            $coords['lat'],
            $coords['lng'],
            $fuente,
            $shouldUpdateDireccion ? " | direccion -> \"{$direccionExtraida}\"" : ''
        );
    }

    echo sprintf("\n--- Lote completado: %d/%d procesados ---\n\n", $stats['procesados'], $totalCandidates);
}

echo "═══════════════════════════════════════════════\n";
echo "  RESUMEN BACKFILL DOMICILIOS\n";
echo "═══════════════════════════════════════════════\n";
echo 'Modo:                       ' . ($dryRun ? 'DRY-RUN' : 'APPLY') . "\n";
echo 'Candidatos totales:         ' . $totalCandidates . "\n";
echo 'Procesados:                 ' . $stats['procesados'] . "\n";
echo '  Resueltos por regex:      ' . $stats['resueltos_por_regex'] . "\n";
echo '  Resueltos por direccion:  ' . $stats['resueltos_por_direccion'] . "\n";
echo '  Direccion actualizada:    ' . $stats['direccion_actualizada'] . "\n";
echo '  Fallo resolucion:         ' . $stats['fallo_resolucion'] . "\n";
echo 'Actualizados' . ($dryRun ? ' (simulado)' : '') . ':           ' . $stats['actualizados'] . "\n";
echo "═══════════════════════════════════════════════\n";

if (!empty($fallidos)) {
    echo "\nDomicilios sin resolver (revisar manualmente):\n";
    foreach ($fallidos as $f) {
        echo "  - id_direccion={$f['id_direccion']} id_cliente={$f['id_cliente']} link={$f['maps_link']}\n";
    }
}
