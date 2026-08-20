<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/ai_assistant.php';

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    http_response_code(403);
    echo "Este script solo se puede ejecutar por CLI o phpdbg.\n";
    exit(1);
}

function cliArgValue(array $argv, string $prefix): ?string
{
    foreach ($argv as $arg) {
        if (strpos($arg, $prefix) === 0) {
            return substr($arg, strlen($prefix));
        }
    }
    return null;
}

$showHelp = in_array('--help', $argv, true) || in_array('-h', $argv, true);
if ($showHelp) {
    echo "Uso:\n";
    echo "  php scripts/populate_product_benefits.php [--dry-run] [--limit=N]\n\n";
    echo "Opciones:\n";
    echo "  --dry-run   Genera los beneficios con DeepSeek pero no actualiza la base de datos.\n";
    echo "  --limit=N   Procesa como maximo N productos en esta corrida.\n";
    exit(0);
}

$dryRun = in_array('--dry-run', $argv, true);
$limitArg = cliArgValue($argv, '--limit=');
$limit = $limitArg !== null ? max(1, (int)$limitArg) : null;

/**
 * Pide a DeepSeek 3-5 beneficios/necesidades que atiende un producto, a partir de los
 * datos que ya existen en el catalogo (nombre, descripcion, ingredientes). BLife no
 * documenta esto en su sitio, asi que cuando falten descripcion/ingredientes se le
 * pide al modelo usar su conocimiento general de la categoria del producto de forma
 * conservadora, sin inventar ingredientes o certificaciones especificas.
 */
function generarBeneficios(string $nombre, ?string $descripcion, ?string $ingredientes, string $apiKeyVariable): string
{
    $datos = "Producto: {$nombre}\n";
    $datos .= 'Descripcion: ' . (trim((string)$descripcion) !== '' ? trim((string)$descripcion) : '(no disponible)') . "\n";
    $datos .= 'Ingredientes: ' . (trim((string)$ingredientes) !== '' ? trim((string)$ingredientes) : '(no disponible)') . "\n";

    $systemPrompt = 'Eres un asistente que resume, para un catalogo de tienda de suplementos y cuidado personal, '
        . 'los beneficios o necesidades/afecciones que un producto ayuda a atender. '
        . 'Responde SOLO con una lista de 3 a 5 conceptos breves separados por comas, en espanol, minusculas '
        . '(ejemplo: "salud articular, firmeza en piel, fortalecimiento de unas y cabello"). '
        . 'No agregues introduccion, numeracion ni texto fuera de la lista. '
        . 'Usa lenguaje orientativo (ayuda a, asociado con, favorece) -- nunca prometas curas, '
        . 'nunca diagnostiques condiciones medicas ni garantices resultados de salud. '
        . 'Si el producto no trae descripcion ni ingredientes, basate unicamente en lo que el nombre '
        . 'sugiere sobre su categoria, manteniendote conservador y sin inventar ingredientes o certificaciones.';

    $messages = [
        ['role' => 'system', 'content' => $systemPrompt],
        ['role' => 'user', 'content' => $datos],
    ];

    $respuesta = aiCallDeepSeek($messages, [], 'deepseek-chat', 0.3, $apiKeyVariable);
    $texto = trim((string)($respuesta['message']['content'] ?? ''));
    $texto = preg_replace('/\s+/', ' ', $texto) ?? $texto;

    return trim($texto);
}

$pdo = getPDO();
$apiKeyVariable = 'DEEPSEEK_AI_ASSISTANT';
try {
    $stmtConfig = $pdo->query('SELECT api_key_variable FROM ai_asistente_config WHERE id_config = 1');
    $configRow = $stmtConfig ? $stmtConfig->fetch(PDO::FETCH_ASSOC) : null;
    if (is_array($configRow) && trim((string)($configRow['api_key_variable'] ?? '')) !== '') {
        $apiKeyVariable = trim((string)$configRow['api_key_variable']);
    }
} catch (Throwable $e) {
    // Sin ai_asistente_config disponible, se usa el default DEEPSEEK_AI_ASSISTANT.
}

$sql = "SELECT id_producto, nombre, descripcion, ingredientes FROM productos
        WHERE beneficios IS NULL OR beneficios = ''
        ORDER BY id_producto ASC";
if ($limit !== null) {
    $sql .= ' LIMIT ' . $limit;
}

$productos = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

echo "========================================\n";
echo "POBLADO DE BENEFICIOS (DeepSeek)\n";
echo "========================================\n";
echo 'Dry run: ' . ($dryRun ? 'YES' : 'NO') . "\n";
echo 'Productos a procesar: ' . count($productos) . "\n\n";

$updateStmt = $pdo->prepare('UPDATE productos SET beneficios = :beneficios WHERE id_producto = :id');

$actualizados = 0;
$errores = 0;

foreach ($productos as $producto) {
    $id = (int)$producto['id_producto'];
    $nombre = (string)$producto['nombre'];

    try {
        $beneficios = generarBeneficios($nombre, $producto['descripcion'] ?? null, $producto['ingredientes'] ?? null, $apiKeyVariable);

        if ($beneficios === '') {
            echo "[SKIP] #{$id} {$nombre}: DeepSeek regreso una respuesta vacia.\n";
            continue;
        }

        echo "[OK] #{$id} {$nombre}: {$beneficios}\n";

        if (!$dryRun) {
            $updateStmt->execute([':beneficios' => $beneficios, ':id' => $id]);
        }
        $actualizados++;
    } catch (Throwable $e) {
        $errores++;
        error_log("WARNING: populate_product_benefits fallo para producto #{$id}: " . $e->getMessage());
        echo "[ERROR] #{$id} {$nombre}: " . $e->getMessage() . "\n";
    }
}

echo "\n========================================\n";
echo 'Actualizados: ' . $actualizados . "\n";
echo 'Errores: ' . $errores . "\n";
echo 'Modo: ' . ($dryRun ? 'dry-run (sin cambios en BD)' : 'aplicado a la base de datos') . "\n";
echo "Done.\n";
