<?php
declare(strict_types=1);

/**
 * Rellena coordenadas (latitud, longitud) para pedidos históricos
 * que aún no tienen coordenadas cacheadas.
 *
 * Resuelve coordenadas desde:
 *  - maps_link_entrega (extrae coordenadas de URL de Google Maps)
 *  - direccion_entrega (geocodifica con Google Geocoding API)
 *
 * Soporta campos cifrados con ENCv1 (PII) y los desencripta automáticamente.
 *
 * Uso:
 *   C:\xampp\php\php.exe scripts\backfill_coordinates.php --dry-run
 *   C:\xampp\php\php.exe scripts\backfill_coordinates.php --apply
 *   C:\xampp\php\php.exe scripts\backfill_coordinates.php --apply --batch-size=50 --delay=200
 *
 * Opciones:
 *   --dry-run       Simula cambios sin escribir en BD (default).
 *   --apply         Ejecuta las actualizaciones en BD.
 *   --batch-size=N  Pedidos a procesar por lote (default: 25).
 *   --delay=N       Milisegundos de pausa entre cada geocodificación (default: 200).
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
    echo "  C:\\xampp\\php\\php.exe scripts\\backfill_coordinates.php [--dry-run|--apply] [--batch-size=N] [--delay=N]\n\n";
    echo "Opciones:\n";
    echo "  --dry-run       Simula cambios sin escribir en BD (default).\n";
    echo "  --apply         Ejecuta las actualizaciones en BD.\n";
    echo "  --batch-size=N  Pedidos a procesar por lote (default: 25).\n";
    echo "  --delay=N       Milisegundos de pausa entre cada geocodificación (default: 200).\n";
    echo "  --help          Muestra esta ayuda.\n";
    exit(0);
}

$dryRun = !array_key_exists('apply', $options);
$batchSize = isset($options['batch-size']) ? max(1, (int)$options['batch-size']) : 25;
$delayMs = isset($options['delay']) ? max(0, (int)$options['delay']) : 200;

// ─── Validar API key de Google Maps ──────────────────────────────
$apiKey = '';
try {
    $apiKey = getMapsApiKey(true);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    fwrite(STDERR, "Define MAPS_KEY, Maps_KEY o GOOGLE_MAPS_API_KEY en el entorno o Secret Manager.\n");
    exit(1);
}

// ─── Validar PII crypto (no bloqueante: algunos pedidos pueden estar en texto plano) ──
$piiAvailable = false;
try {
    piiGetEncryptionKey();
    $piiAvailable = true;
} catch (Throwable $e) {
    echo "ADVERTENCIA: PII_ENCRYPTION_KEY no disponible — se procesarán campos en texto plano solamente.\n";
}

// ─── Conexión a BD y validación de columnas ─────────────────────
$pdo = getPDO();

$hasLatitud = columnExists($pdo, 'pedidos', 'latitud');
$hasLongitud = columnExists($pdo, 'pedidos', 'longitud');
$hasMapsLink = columnExists($pdo, 'pedidos', 'maps_link_entrega');
$hasDireccion = columnExists($pdo, 'pedidos', 'direccion_entrega');

if (!$hasLatitud || !$hasLongitud) {
    fwrite(STDERR, "ERROR: Las columnas 'latitud' y/o 'longitud' no existen en la tabla 'pedidos'.\n");
    fwrite(STDERR, "Ejecuta primero la migración: database/migrations/20260725_000001_add_route_coordinates_to_pedidos.sql\n");
    exit(2);
}

if (!$hasMapsLink && !$hasDireccion) {
    fwrite(STDERR, "ERROR: No se encontraron columnas de origen (maps_link_entrega / direccion_entrega).\n");
    exit(2);
}

// ─── Contar candidatos ───────────────────────────────────────────
$whereParts = ['(latitud IS NULL OR longitud IS NULL)'];
$whereParts[] = '((maps_link_entrega IS NOT NULL AND maps_link_entrega != \'\') OR (direccion_entrega IS NOT NULL AND direccion_entrega != \'\'))';

$countSql = 'SELECT COUNT(*) FROM pedidos WHERE ' . implode(' AND ', $whereParts);
$totalCandidates = (int)$pdo->query($countSql)->fetchColumn();

echo "═══════════════════════════════════════════════\n";
echo "  BACKFILL COORDENADAS DE PEDIDOS\n";
echo "═══════════════════════════════════════════════\n";
echo 'Modo:           ' . ($dryRun ? 'DRY-RUN (sin cambios reales)' : 'APPLY (cambios en BD)') . "\n";
echo 'Batch size:     ' . $batchSize . "\n";
echo 'Delay:          ' . $delayMs . " ms\n";
echo 'API Key Maps:   ' . (strlen($apiKey) > 8 ? substr($apiKey, 0, 8) . '...' : '[vacía]') . "\n";
echo 'PII disponible: ' . ($piiAvailable ? 'SÍ' : 'NO') . "\n";
echo 'Candidatos:     ' . $totalCandidates . " pedidos sin coordenadas\n";
echo "═══════════════════════════════════════════════\n\n";

if ($totalCandidates === 0) {
    echo "No hay pedidos pendientes por procesar.\n";
    exit(0);
}

// ─── Estadísticas ────────────────────────────────────────────────
$stats = [
    'procesados'            => 0,
    'resueltos_por_link'    => 0,
    'resueltos_por_geo'     => 0,
    'sin_datos_suficientes' => 0,
    'fallo_resolucion'      => 0,
    'actualizados'          => 0,
    'errores'               => 0,
];

// ─── Procesar en lotes ───────────────────────────────────────────
$offset = 0;
$selectSql = 'SELECT id_pedido, maps_link_entrega, direccion_entrega, latitud, longitud'
    . ' FROM pedidos'
    . ' WHERE ' . implode(' AND ', $whereParts)
    . ' ORDER BY id_pedido ASC'
    . ' LIMIT :limit OFFSET :offset';

while ($offset < $totalCandidates) {
    try {
        if (!$dryRun) {
            $pdo->beginTransaction();
        }

        $stmt = $pdo->prepare($selectSql);
        $stmt->bindValue(':limit', $batchSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $batch = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($batch)) {
            break;
        }

        $updateStmt = null;
        if (!$dryRun) {
            $updateStmt = $pdo->prepare(
                'UPDATE pedidos SET latitud = :lat, longitud = :lng WHERE id_pedido = :id'
            );
        }

        foreach ($batch as $row) {
            $idPedido = (int)$row['id_pedido'];
            $rawMapsLink = (string)($row['maps_link_entrega'] ?? '');
            $rawDireccion = (string)($row['direccion_entrega'] ?? '');
            $currentLat = $row['latitud'] !== null ? (float)$row['latitud'] : null;
            $currentLng = $row['longitud'] !== null ? (float)$row['longitud'] : null;

            // Saltar si ya tiene coordenadas (doble verificación)
            if ($currentLat !== null && $currentLng !== null) {
                $stats['procesados']++;
                continue;
            }

            // ── Desencriptar PII si es necesario ─────────────────
            $mapsLink = '';
            $direccion = '';

            if ($rawMapsLink !== '' && $hasMapsLink) {
                if ($piiAvailable && piiIsEncryptedValue($rawMapsLink)) {
                    $decrypted = piiDecryptValue($rawMapsLink);
                    $mapsLink = is_string($decrypted) ? trim($decrypted) : '';
                } else {
                    $mapsLink = trim($rawMapsLink);
                }
            }

            if ($rawDireccion !== '' && $hasDireccion) {
                if ($piiAvailable && piiIsEncryptedValue($rawDireccion)) {
                    $decrypted = piiDecryptValue($rawDireccion);
                    $direccion = is_string($decrypted) ? trim($decrypted) : '';
                } else {
                    $direccion = trim($rawDireccion);
                }
            }

            // ── Verificar datos suficientes ──────────────────────
            if ($mapsLink === '' && $direccion === '') {
                $stats['sin_datos_suficientes']++;
                $stats['procesados']++;
                echo sprintf("  [SKIP] Pedido #%d — sin maps_link ni dirección\n", $idPedido);
                continue;
            }

            // ── Resolver coordenadas ─────────────────────────────
            $coords = deliveryResolveCoordinates($mapsLink, $direccion, $apiKey);

            if ($coords === null) {
                $stats['fallo_resolucion']++;
                $stats['procesados']++;
                echo sprintf(
                    "  [FAIL] Pedido #%d — no se pudieron resolver coordenadas (link=%s, dir=%s)\n",
                    $idPedido,
                    $mapsLink !== '' ? 'SÍ' : 'NO',
                    $direccion !== '' ? 'SÍ' : 'NO'
                );
                // Pausa incluso en fallos para no saturar APIs
                if ($delayMs > 0 && $direccion !== '' && $mapsLink === '') {
                    usleep($delayMs * 1000);
                }
                continue;
            }

            // ── Determinar fuente de resolución ──────────────────
            $source = ($mapsLink !== '') ? 'LINK' : 'GEO';
            if ($source === 'LINK') {
                $stats['resueltos_por_link']++;
            } else {
                $stats['resueltos_por_geo']++;
            }

            // ── Actualizar BD ────────────────────────────────────
            if (!$dryRun && $updateStmt !== null) {
                $updateStmt->execute([
                    ':lat' => $coords['lat'],
                    ':lng' => $coords['lng'],
                    ':id'  => $idPedido,
                ]);
            }
            $stats['actualizados']++;
            $stats['procesados']++;

            echo sprintf(
                "  [%s] Pedido #%d → (%.6f, %.6f) | fuente: %s\n",
                $dryRun ? 'DRY' : 'OK',
                $idPedido,
                $coords['lat'],
                $coords['lng'],
                $source
            );

            // Pausa entre geocodificaciones (solo cuando se usó geocoding API)
            if ($delayMs > 0 && $source === 'GEO') {
                usleep($delayMs * 1000);
            }
        }

        if (!$dryRun) {
            $pdo->commit();
        }

        // Progreso del lote
        $progreso = min($offset + $batchSize, $totalCandidates);
        $pct = $totalCandidates > 0 ? round(($stats['procesados'] / $totalCandidates) * 100, 1) : 100.0;
        echo sprintf(
            "\n--- Lote completado: %d/%d procesados (%.1f%%) ---\n\n",
            $stats['procesados'],
            $totalCandidates,
            $pct
        );

    } catch (Throwable $e) {
        $stats['errores']++;
        if (!$dryRun && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, 'ERROR en lote (offset=' . $offset . '): ' . $e->getMessage() . "\n");
        // Continuar con el siguiente lote
    }

    $offset += $batchSize;
}

// ─── Resumen final ───────────────────────────────────────────────
echo "═══════════════════════════════════════════════\n";
echo "  RESUMEN BACKFILL COORDENADAS\n";
echo "═══════════════════════════════════════════════\n";
echo 'Modo:                  ' . ($dryRun ? 'DRY-RUN' : 'APPLY') . "\n";
echo 'Candidatos totales:    ' . $totalCandidates . "\n";
echo 'Procesados:            ' . $stats['procesados'] . "\n";
echo '  Resueltos por link:  ' . $stats['resueltos_por_link'] . "\n";
echo '  Resueltos por geo:   ' . $stats['resueltos_por_geo'] . "\n";
echo '  Sin datos:           ' . $stats['sin_datos_suficientes'] . "\n";
echo '  Fallo resolución:    ' . $stats['fallo_resolucion'] . "\n";
echo 'Actualizados' . ($dryRun ? ' (simulado)' : '') . ':      ' . $stats['actualizados'] . "\n";
echo 'Errores de lote:       ' . $stats['errores'] . "\n";
echo "═══════════════════════════════════════════════\n";

exit($stats['errores'] > 0 ? 3 : 0);

// ─── Helpers (copiados del patrón de encrypt_customer_pii.php) ───

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table"
    );
    $stmt->execute([':table' => $table]);
    return (int)$stmt->fetchColumn() > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column"
    );
    $stmt->execute([':table' => $table, ':column' => $column]);
    return (int)$stmt->fetchColumn() > 0;
}