<?php
declare(strict_types=1);

/**
 * Sincroniza cantidad_actual, stock_minimo y stock_maximo de inventario_almacen
 * contra un export CSV de Odoo (vista de Producto / Variante del producto).
 *
 * A diferencia de scripts/import_products.php, este script NUNCA crea ni
 * modifica columnas de `productos` (nombre, precio, categoria, etc.): solo
 * toca `inventario_almacen`, emparejando por `productos.codigo_barras`.
 *
 * Filas del CSV sin código de barras, o cuyo código no exista en `productos`,
 * se listan en un CSV de pendientes para revisar/migrar después — nunca se
 * insertan productos nuevos automáticamente.
 *
 * Columnas esperadas en el CSV (detección flexible por nombre de encabezado,
 * tolerante a acentos y al mojibake típico de exports de Odoo en UTF-8
 * leídos como Latin-1, ej. "CÃ³digo de barras"):
 *   - Código de barras                     (requerida, clave de emparejamiento)
 *   - Cantidad a la mano                   -> cantidad_actual
 *   - Cantidad mínima de reordenamiento    -> stock_minimo
 *   - Cantidad máxima de reordenamiento    -> stock_maximo
 *   - Nombre en pantalla / Nombre          (opcional, solo para el reporte de pendientes)
 *
 * Odoo solo refleja el inventario del almacén central. Las demás sucursales
 * (almacen_id 2, 3, ...) llevan su stock por fuera de Odoo, así que este
 * script SOLO toca el Almacén Central (id_almacen = 1) — no acepta apuntar
 * a otro almacén, para no pisar por error inventario de sucursales que no
 * están sincronizadas con este export.
 *
 * Uso:
 *   C:\xampp\php\php.exe scripts\import_inventory_from_odoo.php --input=export.csv --dry-run
 *   C:\xampp\php\php.exe scripts\import_inventory_from_odoo.php --input=export.csv --apply
 */

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    http_response_code(403);
    echo "Este script solo se puede ejecutar por CLI o phpdbg.\n";
    exit(1);
}

require_once __DIR__ . '/../core/config.php';

// Único almacén que este script puede tocar (Almacén Central). Ver nota en
// el docblock: las demás sucursales no están sincronizadas con Odoo.
const ALMACEN_CENTRAL_ID = 1;

$options = getopt('', ['input:', 'dry-run', 'apply', 'delimiter::', 'help']);

if (isset($options['help']) || !isset($options['input'])) {
    echo "Uso:\n";
    echo "  C:\\xampp\\php\\php.exe scripts\\import_inventory_from_odoo.php --input=archivo.csv [--dry-run|--apply] [--delimiter=,]\n\n";
    echo "Opciones:\n";
    echo "  --input=...      CSV exportado de Odoo (requerido). Debe incluir 'Código de barras'.\n";
    echo "  --dry-run        Simula sin escribir en BD (default).\n";
    echo "  --apply          Ejecuta las actualizaciones reales.\n";
    echo "  --delimiter=...  Forzar delimitador (default: coma).\n";
    echo "  --help           Muestra esta ayuda.\n";
    echo "\nNota: este script solo actualiza el Almacén Central (id_almacen = " . ALMACEN_CENTRAL_ID . "). No toca otras sucursales.\n";
    exit(isset($options['help']) ? 0 : 1);
}

$inputPath = (string)$options['input'];
$almacenId = ALMACEN_CENTRAL_ID;
$dryRun = !array_key_exists('apply', $options);
$delimiter = isset($options['delimiter']) ? (string)$options['delimiter'] : ',';

if (!is_file($inputPath) || !is_readable($inputPath)) {
    fwrite(STDERR, "ERROR: No se puede leer el archivo: {$inputPath}\n");
    exit(1);
}

/**
 * Corrige mojibake típico de UTF-8 leído como Latin-1/Windows-1252
 * (ej. "CÃ³digo" -> "Código"), igual que reconcile_client_phones_from_odoo.php.
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

function normalizeHeader(string $header): string
{
    $h = strtolower(trim(fixMojibake($header)));
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $h);
    if (is_string($ascii) && $ascii !== '') {
        $h = $ascii;
    }
    return preg_replace('/[^a-z0-9]/', '', $h) ?? $h;
}

function parseQuantity(string $raw): ?int
{
    $raw = trim(str_replace(',', '.', $raw));
    if ($raw === '' || !is_numeric($raw)) {
        return null;
    }
    return (int) round((float) $raw);
}

// ─── Localizar columnas por nombre normalizado ──────────────────────────
$fp = fopen($inputPath, 'rb');
if ($fp === false) {
    fwrite(STDERR, "ERROR: No se pudo abrir el CSV.\n");
    exit(1);
}

$header = fgetcsv($fp, 0, $delimiter);
if ($header === false || $header === [null]) {
    fclose($fp);
    fwrite(STDERR, "ERROR: El CSV está vacío.\n");
    exit(1);
}

$idxCodigo = null;
$idxCantidad = null;
$idxMinimo = null;
$idxMaximo = null;
$idxNombre = null;

foreach ($header as $i => $h) {
    $norm = normalizeHeader((string)$h);
    if ($idxCodigo === null && strpos($norm, 'codigodebarras') !== false) {
        $idxCodigo = $i;
    }
    if ($idxCantidad === null && strpos($norm, 'cantidadalamano') !== false) {
        $idxCantidad = $i;
    }
    if ($idxMinimo === null && strpos($norm, 'cantidadminima') !== false) {
        $idxMinimo = $i;
    }
    if ($idxMaximo === null && strpos($norm, 'cantidadmaxima') !== false) {
        $idxMaximo = $i;
    }
    if ($idxNombre === null && (strpos($norm, 'nombreenpantalla') !== false || $norm === 'nombre')) {
        $idxNombre = $i;
    }
}

if ($idxCodigo === null) {
    fclose($fp);
    fwrite(STDERR, "ERROR: No se encontró la columna 'Código de barras' en el CSV.\n");
    exit(1);
}

if ($idxCantidad === null && $idxMinimo === null && $idxMaximo === null) {
    fclose($fp);
    fwrite(STDERR, "ERROR: El CSV no trae ninguna columna de cantidad reconocida (Cantidad a la mano / mínima / máxima de reordenamiento).\n");
    exit(1);
}

// ─── Precargar productos e inventario actual ────────────────────────────
$pdo = getPDO();

$productosPorCodigo = [];
foreach ($pdo->query('SELECT id_producto, codigo_barras FROM productos WHERE codigo_barras IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $codigo = trim((string)$row['codigo_barras']);
    if ($codigo !== '') {
        $productosPorCodigo[$codigo] = (int)$row['id_producto'];
    }
}

$inventarioActual = [];
$stmtInv = $pdo->prepare('SELECT id_producto, cantidad_actual, stock_minimo, stock_maximo FROM inventario_almacen WHERE id_almacen = :id_almacen');
$stmtInv->execute([':id_almacen' => $almacenId]);
foreach ($stmtInv->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $inventarioActual[(int)$row['id_producto']] = [
        'cantidad_actual' => (int)$row['cantidad_actual'],
        'stock_minimo' => (int)$row['stock_minimo'],
        'stock_maximo' => (int)$row['stock_maximo'],
    ];
}

// ─── Leer filas del CSV ──────────────────────────────────────────────────
$aActualizar = [];
$sinCambios = 0;
$pendientes = [];

while (($row = fgetcsv($fp, 0, $delimiter)) !== false) {
    if ($row === [null] || $row === []) {
        continue;
    }

    $codigo = fixMojibake(trim((string)($row[$idxCodigo] ?? '')));
    $nombre = $idxNombre !== null ? fixMojibake(trim((string)($row[$idxNombre] ?? ''))) : '';

    if ($codigo === '') {
        $pendientes[] = ['codigo' => '', 'nombre' => $nombre, 'row' => $row, 'motivo' => 'sin código de barras'];
        continue;
    }

    if (!isset($productosPorCodigo[$codigo])) {
        $pendientes[] = ['codigo' => $codigo, 'nombre' => $nombre, 'row' => $row, 'motivo' => 'código no encontrado en el sistema'];
        continue;
    }

    $idProducto = $productosPorCodigo[$codigo];
    $cantidad = $idxCantidad !== null ? parseQuantity((string)($row[$idxCantidad] ?? '')) : null;
    $minimo = $idxMinimo !== null ? parseQuantity((string)($row[$idxMinimo] ?? '')) : null;
    $maximo = $idxMaximo !== null ? parseQuantity((string)($row[$idxMaximo] ?? '')) : null;

    if ($cantidad === null && $minimo === null && $maximo === null) {
        $sinCambios++;
        continue;
    }

    $actual = $inventarioActual[$idProducto] ?? null;
    $huboCambio = $actual === null
        || ($cantidad !== null && $cantidad !== $actual['cantidad_actual'])
        || ($minimo !== null && $minimo !== $actual['stock_minimo'])
        || ($maximo !== null && $maximo !== $actual['stock_maximo']);

    if (!$huboCambio) {
        $sinCambios++;
        continue;
    }

    $aActualizar[] = [
        'id_producto' => $idProducto,
        'codigo_barras' => $codigo,
        'nombre' => $nombre,
        'cantidad_actual' => $cantidad,
        'stock_minimo' => $minimo,
        'stock_maximo' => $maximo,
        'antes' => $actual,
    ];
}

fclose($fp);

// ─── Aplicar (si corresponde) ─────────────────────────────────────────────
$errores = [];
if (!$dryRun && !empty($aActualizar)) {
    $pdo->beginTransaction();
    try {
        foreach ($aActualizar as $r) {
            $insertCols = ['id_producto', 'id_almacen'];
            $insertVals = [':id_producto', ':id_almacen'];
            $updateSets = [];
            $params = [':id_producto' => $r['id_producto'], ':id_almacen' => $almacenId];

            foreach (['cantidad_actual', 'stock_minimo', 'stock_maximo'] as $campo) {
                if ($r[$campo] === null) {
                    continue;
                }
                $insertCols[] = $campo;
                $insertVals[] = ':' . $campo;
                $updateSets[] = "$campo = VALUES($campo)";
                $params[':' . $campo] = $r[$campo];
            }

            $sql = 'INSERT INTO inventario_almacen (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', $insertVals) . ')'
                . ' ON DUPLICATE KEY UPDATE ' . implode(', ', $updateSets);
            $pdo->prepare($sql)->execute($params);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $errores[] = $e->getMessage();
        $aActualizar = [];
    }
}

// ─── Reporte de pendientes ─────────────────────────────────────────────────
$pendientesFile = null;
if (!empty($pendientes)) {
    $outputDir = __DIR__ . '/output';
    if (!is_dir($outputDir)) {
        mkdir($outputDir, 0755, true);
    }
    $pendientesFile = $outputDir . '/productos_pendientes_odoo_' . date('Ymd_His') . '.csv';
    $out = fopen($pendientesFile, 'wb');
    fputcsv($out, array_merge($header, ['motivo']));
    foreach ($pendientes as $p) {
        fputcsv($out, array_merge($p['row'], [$p['motivo']]));
    }
    fclose($out);
}

// ─── Resumen ────────────────────────────────────────────────────────────
$sinCodigo = count(array_filter($pendientes, static fn($p) => $p['motivo'] === 'sin código de barras'));
$noEncontrados = count($pendientes) - $sinCodigo;

echo "═══════════════════════════════════════════════\n";
echo "  IMPORTACIÓN DE INVENTARIO DESDE ODOO\n";
echo "═══════════════════════════════════════════════\n";
echo 'Modo: ' . ($dryRun ? 'DRY-RUN (sin cambios reales)' : 'APPLY (cambios en BD)') . "\n";
echo "Almacén destino: {$almacenId}\n";
echo "═══════════════════════════════════════════════\n\n";

echo '--- A ACTUALIZAR (' . count($aActualizar) . ") ---\n";
foreach ($aActualizar as $r) {
    $antes = $r['antes'];
    $antesTxt = $antes === null ? 'sin registro previo' : sprintf('actual=%d min=%d max=%d', $antes['cantidad_actual'], $antes['stock_minimo'], $antes['stock_maximo']);
    $despuesTxt = sprintf(
        'actual=%s min=%s max=%s',
        $r['cantidad_actual'] ?? '=',
        $r['stock_minimo'] ?? '=',
        $r['stock_maximo'] ?? '='
    );
    echo sprintf("  [%s] producto #%d \"%s\" (%s) -- antes: %s | nuevo: %s\n", $dryRun ? 'DRY' : 'OK', $r['id_producto'], $r['nombre'], $r['codigo_barras'], $antesTxt, $despuesTxt);
}

echo "\n═══════════════════════════════════════════════\n";
echo "  RESUMEN\n";
echo "═══════════════════════════════════════════════\n";
echo 'A actualizar' . ($dryRun ? ' (simulado)' : '') . ':              ' . count($aActualizar) . "\n";
echo 'Sin cambios (ya coincidía):        ' . $sinCambios . "\n";
echo "Pendientes, código no encontrado:  {$noEncontrados}\n";
echo "Pendientes, sin código de barras:  {$sinCodigo}\n";
echo 'Errores:                           ' . count($errores) . "\n";
foreach ($errores as $e) {
    echo "  - {$e}\n";
}
if ($pendientesFile !== null) {
    echo "\nReporte de pendientes guardado en: {$pendientesFile}\n";
}
echo "═══════════════════════════════════════════════\n";
