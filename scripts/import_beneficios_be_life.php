<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    http_response_code(403);
    echo "Este script solo se puede ejecutar por CLI o phpdbg.\n";
    exit(1);
}

/**
 * Importa beneficios ya redactados (catalogo oficial de Be Life) a productos.beneficios,
 * evitando llamadas a DeepSeek para lo que ya viene curado. Match SOLO por nombre exacto
 * normalizado (sin acentos, minusculas, espacios colapsados) contra productos.nombre.
 *
 * Diseño deliberadamente conservador: si el nombre no calza exacto, o calza con mas de
 * una fila, NO se aplica -- se reporta para revision manual. Nunca se actualiza por
 * substring/similitud para evitar poner el beneficio de un producto en otro.
 */

function normalizarNombre(string $s): string
{
    $s = trim($s);
    $s = mb_strtolower($s, 'UTF-8');
    if (function_exists('transliterator_transliterate')) {
        $t = transliterator_transliterate('Any-Latin; Latin-ASCII', $s);
        if ($t !== false) {
            $s = $t;
        }
    }
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    $s = preg_replace('/[^a-z0-9 ]/', '', $s) ?? $s;
    return trim($s);
}

$showHelp = in_array('--help', $argv, true) || in_array('-h', $argv, true);
if ($showHelp) {
    echo "Uso:\n";
    echo "  php scripts/import_beneficios_be_life.php [--apply] [--json=ruta]\n\n";
    echo "Opciones:\n";
    echo "  --apply   Sin esto, solo se muestra el reporte (dry-run). Con esto, se actualiza la BD.\n";
    echo "  --json=   Ruta al JSON fuente (default: scripts/data/catalogo_be_life_completo.json).\n";
    exit(0);
}

$apply = in_array('--apply', $argv, true);
$jsonArg = null;
foreach ($argv as $arg) {
    if (strpos($arg, '--json=') === 0) {
        $jsonArg = substr($arg, strlen('--json='));
    }
}
$jsonPath = $jsonArg ?? (__DIR__ . '/data/catalogo_be_life_completo.json');

$json = json_decode((string)file_get_contents($jsonPath), true);
if (!is_array($json)) {
    fwrite(STDERR, "No se pudo leer/parsear el JSON: {$jsonPath}\n");
    exit(1);
}

$pdo = getPDO();
$rows = $pdo->query('SELECT id_producto, nombre, beneficios FROM productos')->fetchAll(PDO::FETCH_ASSOC);

$prodPorNombre = [];
foreach ($rows as $r) {
    $norm = normalizarNombre((string)$r['nombre']);
    $prodPorNombre[$norm][] = $r;
}

$aplicables = [];
$yaConBeneficios = [];
$sinMatch = [];
$matchMultiple = [];

foreach ($json as $item) {
    $nombreJson = (string)($item['nombre'] ?? '');
    $beneficiosArr = $item['beneficios'] ?? [];
    if (!is_array($beneficiosArr) || $beneficiosArr === []) {
        continue;
    }
    $norm = normalizarNombre($nombreJson);
    $candidatos = $prodPorNombre[$norm] ?? [];

    if (count($candidatos) === 0) {
        $sinMatch[] = $nombreJson;
        continue;
    }
    if (count($candidatos) > 1) {
        $matchMultiple[] = ['json' => $nombreJson, 'prod' => $candidatos];
        continue;
    }

    $prod = $candidatos[0];
    $tieneBeneficios = $prod['beneficios'] !== null && trim((string)$prod['beneficios']) !== '';
    if ($tieneBeneficios) {
        $yaConBeneficios[] = $nombreJson;
        continue;
    }

    $texto = implode(' ', array_map(static function (string $b): string {
        return rtrim(trim($b), '.') . '.';
    }, $beneficiosArr));

    $aplicables[] = [
        'id_producto' => (int)$prod['id_producto'],
        'nombre' => $prod['nombre'],
        'beneficios' => $texto,
    ];
}

echo "========================================\n";
echo "IMPORT BENEFICIOS BE LIFE\n";
echo "========================================\n";
echo 'Modo: ' . ($apply ? 'APLICANDO CAMBIOS' : 'dry-run (sin cambios en BD)') . "\n";
echo 'Aplicables (match unico, sin beneficios): ' . count($aplicables) . "\n";
echo 'Ya tenian beneficios (omitidos): ' . count($yaConBeneficios) . "\n";
echo 'Sin match en catalogo: ' . count($sinMatch) . "\n";
echo 'Match multiple (requieren revision manual, no se tocan): ' . count($matchMultiple) . "\n\n";

foreach ($aplicables as $a) {
    echo "  #{$a['id_producto']} | {$a['nombre']}\n";
    echo "      -> {$a['beneficios']}\n";
}

if (!$apply) {
    echo "\nDry-run: no se aplico ningun cambio. Vuelve a correr con --apply para escribir en la BD.\n";
    exit(0);
}

$updateStmt = $pdo->prepare(
    'UPDATE productos SET beneficios = :beneficios
     WHERE id_producto = :id AND (beneficios IS NULL OR beneficios = "")'
);

$actualizados = 0;
foreach ($aplicables as $a) {
    $updateStmt->execute([':beneficios' => $a['beneficios'], ':id' => $a['id_producto']]);
    $actualizados += $updateStmt->rowCount();
}

echo "\nActualizados: {$actualizados}\n";
