<?php
declare(strict_types=1);

// gsmLoadSecretsCached() guarda los secretos de Google Secret Manager en sesion, APCu y disco
// hasta por GSM_CACHE_TTL_SECONDS (default 7 dias, ver core/config.php). Eso significa que
// actualizar un secreto (ej. FB_PAGE_ACCESS_TOKEN) en la consola de Secret Manager NO se refleja
// de inmediato en produccion: el servidor puede seguir sirviendo el valor viejo desde cache por
// dias. Este endpoint fuerza la limpieza sin necesitar SSH, reusando el mismo token de
// autenticacion que api/run_migrations.php (MIGRATIONS_DEPLOY_TOKEN) para no duplicar secretos.

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

$cleared = [
    'session' => false,
    'apcu_keys_borradas' => 0,
    'archivos_borrados' => 0,
];

if (function_exists('clear_secrets_cache')) {
    clear_secrets_cache();
    $cleared['session'] = true;
}

// APCu es por proceso/pool de PHP-FPM: correr esto como peticion web (no CLI) es lo que permite
// limpiar el mismo espacio de memoria que usan las peticiones normales del sitio.
if (function_exists('apcu_cache_info') && function_exists('apcu_delete')) {
    try {
        $info = apcu_cache_info();
        $entries = is_array($info) && isset($info['cache_list']) && is_array($info['cache_list'])
            ? $info['cache_list']
            : [];
        foreach ($entries as $entry) {
            $key = is_array($entry) ? (string)($entry['info'] ?? $entry['key'] ?? '') : '';
            if ($key !== '' && str_starts_with($key, 'gsm_cache_')) {
                if (apcu_delete($key)) {
                    $cleared['apcu_keys_borradas']++;
                }
            }
        }
    } catch (Throwable $e) {
        // APCu no disponible o sin permiso de introspeccion; no es fatal, seguimos con el resto.
    }
}

// Cache de archivo: mismo prefijo que gsmGetFileCachePath() en core/google_secret_manager.php.
$tmpDir = rtrim((string)sys_get_temp_dir(), '/\\');
$pattern = $tmpDir . DIRECTORY_SEPARATOR . 'gsm_cache_*.json';
foreach (glob($pattern) ?: [] as $file) {
    if (@unlink($file)) {
        $cleared['archivos_borrados']++;
    }
}

echo json_encode(['success' => true, 'limpiado' => $cleared]);
