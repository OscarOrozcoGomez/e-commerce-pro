<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/migrations.php';

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

$payload = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($payload)) {
    $payload = [];
}

$targetVersion = null;
if (isset($payload['target_version']) && is_string($payload['target_version']) && trim($payload['target_version']) !== '') {
    $targetVersion = trim($payload['target_version']);
}

$dryRun = !empty($payload['dry_run']);

try {
    $result = runDatabaseMigrations($targetVersion, $dryRun);
    http_response_code(200);
    echo json_encode($result);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ]);
}
