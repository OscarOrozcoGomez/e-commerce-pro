<?php
declare(strict_types=1);

// Endpoint de un solo uso (temporal): optimiza en el propio servidor las imagenes de
// producto que ya estaban subidas ANTES de que existiera core/image_optimizer.php --
// esas nunca pasan por el pipeline de deploy (assets/img/products/ esta en .gitignore
// a proposito, es contenido subido, no codigo), asi que no hay otra forma de tocarlas
// que no sea corriendo esto directo en el servidor.
//
// Diseñado para llamarse repetidas veces con offset creciente (paginado): procesar
// ~1000 imagenes con GD en una sola request se arriesga a un timeout del hosting.
// Por default corre en modo "dry_run" (no toca nada, solo reporta que haria) -- hay
// que pedir explicitamente dry_run=false para que si modifique archivos.
//
// Borrar este archivo del servidor cuando ya no se necesite (no debe quedarse
// disponible indefinidamente, aunque este protegido con token).

set_time_limit(120);

require_once __DIR__ . '/../core/migrations.php';
require_once __DIR__ . '/../core/image_optimizer.php';

header('Content-Type: application/json');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo no permitido']);
    exit;
}

$expectedToken = migrationDeployToken();
if ($expectedToken === null || $expectedToken === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'MIGRATIONS_DEPLOY_TOKEN no configurado']);
    exit;
}

$providedToken = '';
if (isset($_SERVER['HTTP_X_MIGRATIONS_TOKEN']) && is_string($_SERVER['HTTP_X_MIGRATIONS_TOKEN'])) {
    $providedToken = trim($_SERVER['HTTP_X_MIGRATIONS_TOKEN']);
}
if ($providedToken === '' && isset($_GET['token']) && is_string($_GET['token'])) {
    $providedToken = trim($_GET['token']);
}

if (!is_string($providedToken) || !hash_equals($expectedToken, $providedToken)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token invalido']);
    exit;
}

if (!imageOptimizerAvailable()) {
    http_response_code(200);
    echo json_encode(['success' => false, 'error' => 'GD no esta disponible en este servidor; no se puede optimizar nada.']);
    exit;
}

$offset = max(0, (int) ($_GET['offset'] ?? 0));
$limit = max(1, min(40, (int) ($_GET['limit'] ?? 15)));
$dryRun = !(isset($_GET['dry_run']) && $_GET['dry_run'] === 'false');

$baseDir = realpath(__DIR__ . '/../assets/img/products');
if ($baseDir === false) {
    http_response_code(200);
    echo json_encode(['success' => false, 'error' => 'No se encontro assets/img/products en este servidor.']);
    exit;
}

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS)
);
$allFiles = [];
foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile()) {
        continue;
    }
    $ext = strtolower($fileInfo->getExtension());
    if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
        continue;
    }
    $allFiles[] = $fileInfo->getPathname();
}
sort($allFiles);

$totalFiles = count($allFiles);
$batch = array_slice($allFiles, $offset, $limit);

$processed = [];
$resizedCount = 0;
$skippedCount = 0;
$errorCount = 0;
$bytesBefore = 0;
$bytesAfter = 0;

foreach ($batch as $path) {
    $sizeBefore = (int) (@filesize($path) ?: 0);
    $result = optimizeUploadedProductImage($path, $dryRun);
    $relativePath = ltrim(str_replace($baseDir, '', $path), '\\/');

    $entry = [
        'file' => $relativePath,
        'bytes_before' => $sizeBefore,
    ];

    if ($dryRun) {
        if (!empty($result['would_resize'])) {
            $entry['would_resize'] = true;
            $processed[] = $entry;
        }
        continue;
    }

    if (!empty($result['resized'])) {
        clearstatcache(true, $path);
        $sizeAfter = (int) (@filesize($path) ?: 0);
        $entry['resized'] = true;
        $entry['bytes_after'] = $sizeAfter;
        $bytesBefore += $sizeBefore;
        $bytesAfter += $sizeAfter;
        $resizedCount++;
        $processed[] = $entry;
    } elseif (($result['reason'] ?? '') === 'ya_dentro_del_limite') {
        $skippedCount++;
    } else {
        $entry['error'] = $result['reason'] ?? 'desconocido';
        $processed[] = $entry;
        $errorCount++;
    }
}

$nextOffset = ($offset + $limit) < $totalFiles ? ($offset + $limit) : null;

echo json_encode([
    'success' => true,
    'dry_run' => $dryRun,
    'total_files' => $totalFiles,
    'offset' => $offset,
    'limit' => $limit,
    'next_offset' => $nextOffset,
    'done' => $nextOffset === null,
    'summary' => [
        'resized' => $resizedCount,
        'ya_optimizadas' => $skippedCount,
        'errores' => $errorCount,
        'bytes_before' => $bytesBefore,
        'bytes_after' => $bytesAfter,
    ],
    'files' => $processed,
]);
