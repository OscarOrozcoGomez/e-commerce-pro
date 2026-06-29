<?php
declare(strict_types=1);

function gsmEnsureSessionStarted(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        @session_start();
    }
}

function clear_secrets_cache(): void
{
    gsmEnsureSessionStarted();
    unset($_SESSION['app_secrets'], $_SESSION['app_secrets_cached_at']);
}

function gsmGetSessionSecretsCache(): ?array
{
    gsmEnsureSessionStarted();
    if (!isset($_SESSION['app_secrets']) || !is_array($_SESSION['app_secrets']) || empty($_SESSION['app_secrets'])) {
        return null;
    }

    return $_SESSION['app_secrets'];
}

function gsmSetSessionSecretsCache(array $secrets): void
{
    if (empty($secrets)) {
        return;
    }

    gsmEnsureSessionStarted();
    $_SESSION['app_secrets'] = $secrets;
    $_SESSION['app_secrets_cached_at'] = time();
}

function gsmGetEnvValue(string $name): ?string
{
    $value = getenv($name);
    if ($value === false) {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? $_SERVER['REDIRECT_' . $name] ?? null;
    }
    if ($value === null) {
        return null;
    }

    $value = trim((string) $value);
    return $value === '' ? null : $value;
}

function gsmBase64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function gsmHttpRequest(string $method, string $url, string $body = '', array $headers = [], int $timeout = 8): array
{
    $headerLines = [];
    foreach ($headers as $k => $v) {
        $headerLines[] = $k . ': ' . $v;
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headerLines);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            return ['ok' => false, 'code' => 0, 'body' => '', 'error' => $error !== '' ? $error : 'curl_exec failed'];
        }

        return ['ok' => $httpCode >= 200 && $httpCode < 300, 'code' => $httpCode, 'body' => (string) $responseBody, 'error' => ''];
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'content' => $body,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ],
    ]);

    $responseBody = @file_get_contents($url, false, $context);
    $statusCode = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $match)) {
        $statusCode = (int) $match[1];
    }

    if ($responseBody === false) {
        return ['ok' => false, 'code' => $statusCode, 'body' => '', 'error' => 'file_get_contents failed'];
    }

    return ['ok' => $statusCode >= 200 && $statusCode < 300, 'code' => $statusCode, 'body' => (string) $responseBody, 'error' => ''];
}

function gsmLoadServiceAccount(string $path): ?array
{
    if (!is_readable($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    if (empty($decoded['client_email']) || empty($decoded['private_key'])) {
        return null;
    }

    return $decoded;
}

function gsmGetServiceAccountPath(): ?string
{
    $envPath = gsmGetEnvValue('GCP_SA_KEY_FILE')
        ?? gsmGetEnvValue('GOOGLE_APPLICATION_CREDENTIALS')
        ?? gsmGetEnvValue('GCP_SERVICE_ACCOUNT_FILE');

    $candidates = [];
    if ($envPath !== null) {
        $candidates[] = $envPath;
    }

    $homePath = getenv('HOME');
    if ($homePath === false || trim($homePath) === '') {
        $homePath = $_SERVER['HOME'] ?? '';
    }

    if (is_string($homePath) && trim($homePath) !== '') {
        $homePath = rtrim($homePath, '/\\');
        $candidates[] = $homePath . '/.gcp/sa.json';
        $candidates[] = $homePath . '/.gcp/service-account.json';
        $candidates[] = $homePath . '/public_html/.gcp/sa.json';
        $candidates[] = $homePath . '/public_html/.gcp/service-account.json';
    }

    $checked = [];
    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || trim($candidate) === '') {
            continue;
        }
        if (isset($checked[$candidate])) {
            continue;
        }
        $checked[$candidate] = true;

        if (is_readable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function gsmGetAccessTokenFromServiceAccount(array $sa, ?string &$reason): ?string
{
    $tokenUri = isset($sa['token_uri']) && is_string($sa['token_uri']) && trim($sa['token_uri']) !== ''
        ? trim($sa['token_uri'])
        : 'https://oauth2.googleapis.com/token';

    $now = time();
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    $payload = [
        'iss' => (string) $sa['client_email'],
        // Secret Manager acepta cloud-platform para acceso OAuth server-to-server.
        'scope' => 'https://www.googleapis.com/auth/cloud-platform',
        'aud' => $tokenUri,
        'iat' => $now,
        'exp' => $now + 3600,
    ];

    $headerEncoded = gsmBase64UrlEncode((string) json_encode($header));
    $payloadEncoded = gsmBase64UrlEncode((string) json_encode($payload));
    $unsignedJwt = $headerEncoded . '.' . $payloadEncoded;

    $signature = '';
    $signOk = openssl_sign($unsignedJwt, $signature, (string) $sa['private_key'], OPENSSL_ALGO_SHA256);
    if (!$signOk) {
        $reason = 'No se pudo firmar el JWT con la llave privada.';
        return null;
    }

    $jwt = $unsignedJwt . '.' . gsmBase64UrlEncode($signature);
    $postBody = http_build_query([
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt,
    ]);

    $response = gsmHttpRequest('POST', $tokenUri, $postBody, [
        'Content-Type' => 'application/x-www-form-urlencoded',
    ]);

    if (!$response['ok']) {
        $reason = 'OAuth token request failed (code=' . $response['code'] . ', error=' . $response['error'] . ')';
        return null;
    }

    $parsed = json_decode($response['body'], true);
    if (!is_array($parsed) || !isset($parsed['access_token'])) {
        $reason = 'Respuesta OAuth invalida.';
        return null;
    }

    return (string) $parsed['access_token'];
}

function gsmReadSecret(string $projectId, string $secretName, string $accessToken, ?string &$reason): ?string
{
    $url = 'https://secretmanager.googleapis.com/v1/projects/'
        . rawurlencode($projectId)
        . '/secrets/' . rawurlencode($secretName)
        . '/versions/latest:access';

    $response = gsmHttpRequest('GET', $url, '', [
        'Authorization' => 'Bearer ' . $accessToken,
    ]);

    if (!$response['ok']) {
        $bodySnippet = '';
        if (isset($response['body']) && is_string($response['body']) && trim($response['body']) !== '') {
            $decoded = json_decode($response['body'], true);
            if (is_array($decoded) && isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
                $bodySnippet = $decoded['error']['message'];
            } else {
                $bodySnippet = substr(trim($response['body']), 0, 220);
            }
        }
        $reason = 'Google API failed for ' . $secretName
            . ' (code=' . $response['code']
            . ', error=' . $response['error']
            . ', detail=' . $bodySnippet . ')';
        return null;
    }

    $parsed = json_decode($response['body'], true);
    if (!is_array($parsed) || !isset($parsed['payload']['data'])) {
        $reason = 'Payload invalido para secreto ' . $secretName;
        return null;
    }

    $decoded = base64_decode((string) $parsed['payload']['data'], true);
    if ($decoded === false) {
        $reason = 'Base64 invalido para secreto ' . $secretName;
        return null;
    }

    return trim($decoded);
}

function gsmLoadSecrets(array $mapping, array &$debug): array
{
    $sessionSecrets = gsmGetSessionSecretsCache();
    if (is_array($sessionSecrets) && !empty($sessionSecrets)) {
        $debug = [
            'project_id' => gsmGetEnvValue('GCP_PROJECT_ID') ?? gsmGetEnvValue('GOOGLE_CLOUD_PROJECT') ?? gsmGetEnvValue('GCLOUD_PROJECT') ?? gsmGetEnvValue('PROJECT_ID'),
            'token_source' => 'cache:session',
            'service_account_email' => null,
            'loaded' => array_keys($sessionSecrets),
            'errors' => [],
            'from_cache' => true,
        ];
        return $sessionSecrets;
    }

    $debug = [
        'project_id' => null,
        'token_source' => null,
        'service_account_email' => null,
        'loaded' => [],
        'errors' => [],
    ];

    $projectId = gsmGetEnvValue('GCP_PROJECT_ID')
        ?? gsmGetEnvValue('GOOGLE_CLOUD_PROJECT')
        ?? gsmGetEnvValue('GCLOUD_PROJECT')
        ?? gsmGetEnvValue('PROJECT_ID');

    if ($projectId === null) {
        $debug['errors'][] = 'No hay project id (GCP_PROJECT_ID/GOOGLE_CLOUD_PROJECT).';
        return [];
    }
    $debug['project_id'] = $projectId;

    $accessToken = gsmGetEnvValue('GCP_ACCESS_TOKEN');
    if ($accessToken !== null) {
        $debug['token_source'] = 'env:GCP_ACCESS_TOKEN';
    } else {
        $configuredSaPath = gsmGetEnvValue('GCP_SA_KEY_FILE')
            ?? gsmGetEnvValue('GOOGLE_APPLICATION_CREDENTIALS')
            ?? gsmGetEnvValue('GCP_SERVICE_ACCOUNT_FILE');
        $saPath = gsmGetServiceAccountPath();
        if ($saPath === null) {
            if ($configuredSaPath !== null) {
                $debug['errors'][] = 'Service account file no legible en ruta configurada: ' . $configuredSaPath . ' (revisa existencia y permisos).';
            } else {
                $debug['errors'][] = 'No hay token ni service account file (GCP_SA_KEY_FILE).';
            }
            return [];
        }

        $sa = gsmLoadServiceAccount($saPath);
        if ($sa === null) {
            $debug['errors'][] = 'No se pudo leer service account file: ' . $saPath;
            return [];
        }
        if (isset($sa['client_email']) && is_string($sa['client_email'])) {
            $debug['service_account_email'] = $sa['client_email'];
        }

        $tokenReason = '';
        $accessToken = gsmGetAccessTokenFromServiceAccount($sa, $tokenReason);
        if ($accessToken === null) {
            $debug['errors'][] = 'No se pudo obtener access token: ' . $tokenReason;
            return [];
        }

        $debug['token_source'] = 'service-account:' . $saPath;
    }

    $loaded = [];
    foreach ($mapping as $envName => $secretNames) {
        if (!is_array($secretNames)) {
            continue;
        }

        $value = null;
        foreach ($secretNames as $secretName) {
            if (!is_string($secretName) || trim($secretName) === '') {
                continue;
            }

            $reason = '';
            $candidate = gsmReadSecret($projectId, $secretName, $accessToken, $reason);
            if ($candidate !== null) {
                $value = $candidate;
                $debug['loaded'][] = $envName . '<-' . $secretName;
                break;
            }

            $debug['errors'][] = $reason;
        }

        if ($value !== null) {
            $loaded[$envName] = $value;
        }
    }

    if (!empty($loaded)) {
        gsmSetSessionSecretsCache($loaded);
    }

    return $loaded;
}

function gsmNormalizeMapping(array $mapping): array
{
    $normalized = [];
    foreach ($mapping as $envName => $secretNames) {
        if (!is_string($envName) || $envName === '' || !is_array($secretNames)) {
            continue;
        }

        $filteredSecretNames = [];
        foreach ($secretNames as $secretName) {
            if (!is_string($secretName)) {
                continue;
            }
            $secretName = trim($secretName);
            if ($secretName === '') {
                continue;
            }
            $filteredSecretNames[] = $secretName;
        }

        if (!empty($filteredSecretNames)) {
            $normalized[$envName] = $filteredSecretNames;
        }
    }

    ksort($normalized);
    return $normalized;
}

function gsmBuildCacheKey(array $mapping): string
{
    $projectId = gsmGetEnvValue('GCP_PROJECT_ID')
        ?? gsmGetEnvValue('GOOGLE_CLOUD_PROJECT')
        ?? gsmGetEnvValue('GCLOUD_PROJECT')
        ?? gsmGetEnvValue('PROJECT_ID')
        ?? 'no-project';

    $normalized = gsmNormalizeMapping($mapping);
    $signature = json_encode($normalized);
    if (!is_string($signature)) {
        $signature = '';
    }

    return 'gsm_cache_' . hash('sha256', $projectId . '|' . $signature);
}

function gsmGetFileCachePath(string $cacheKey): string
{
    $tmpDir = rtrim((string)sys_get_temp_dir(), '/\\');
    return $tmpDir . DIRECTORY_SEPARATOR . $cacheKey . '.json';
}

function gsmReadFileCache(string $cachePath): ?array
{
    if (!is_readable($cachePath)) {
        return null;
    }

    $raw = @file_get_contents($cachePath);
    if ($raw === false || $raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return null;
    }

    if (!isset($decoded['expires_at'], $decoded['secrets']) || !is_array($decoded['secrets'])) {
        return null;
    }

    return $decoded;
}

function gsmWriteFileCache(string $cachePath, array $secrets, int $ttlSeconds): void
{
    $payload = [
        'created_at' => time(),
        'expires_at' => time() + max(30, $ttlSeconds),
        'secrets' => $secrets,
    ];

    @file_put_contents($cachePath, (string)json_encode($payload), LOCK_EX);
}

function gsmReadApcuCache(string $cacheKey): ?array
{
    if (!function_exists('apcu_fetch') || !filter_var((string)ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN)) {
        return null;
    }

    $ok = false;
    $cached = apcu_fetch($cacheKey, $ok);
    if (!$ok || !is_array($cached) || !isset($cached['expires_at'], $cached['secrets']) || !is_array($cached['secrets'])) {
        return null;
    }

    return $cached;
}

function gsmWriteApcuCache(string $cacheKey, array $secrets, int $ttlSeconds): void
{
    if (!function_exists('apcu_store') || !filter_var((string)ini_get('apc.enabled'), FILTER_VALIDATE_BOOLEAN)) {
        return;
    }

    $ttl = max(30, $ttlSeconds);
    $payload = [
        'created_at' => time(),
        'expires_at' => time() + $ttl,
        'secrets' => $secrets,
    ];

    @apcu_store($cacheKey, $payload, $ttl);
}

function gsmLoadSecretsCached(array $mapping, array &$debug, int $ttlSeconds = 300): array
{
    $sessionSecrets = gsmGetSessionSecretsCache();
    if (is_array($sessionSecrets) && !empty($sessionSecrets)) {
        $debug = [
            'project_id' => gsmGetEnvValue('GCP_PROJECT_ID') ?? gsmGetEnvValue('GOOGLE_CLOUD_PROJECT') ?? gsmGetEnvValue('GCLOUD_PROJECT') ?? gsmGetEnvValue('PROJECT_ID'),
            'token_source' => 'cache:session',
            'service_account_email' => null,
            'loaded' => array_keys($sessionSecrets),
            'errors' => [],
            'from_cache' => true,
        ];
        return $sessionSecrets;
    }

    $cacheKey = gsmBuildCacheKey($mapping);
    $now = time();

    $apcuCached = gsmReadApcuCache($cacheKey);
    if (is_array($apcuCached) && (int)$apcuCached['expires_at'] >= $now) {
        $debug = [
            'project_id' => gsmGetEnvValue('GCP_PROJECT_ID') ?? gsmGetEnvValue('GOOGLE_CLOUD_PROJECT') ?? gsmGetEnvValue('GCLOUD_PROJECT') ?? gsmGetEnvValue('PROJECT_ID'),
            'token_source' => 'cache:apcu',
            'service_account_email' => null,
            'loaded' => array_keys($apcuCached['secrets']),
            'errors' => [],
            'from_cache' => true,
        ];
        return $apcuCached['secrets'];
    }

    $fileCachePath = gsmGetFileCachePath($cacheKey);
    $fileCached = gsmReadFileCache($fileCachePath);
    if (is_array($fileCached) && (int)$fileCached['expires_at'] >= $now) {
        gsmWriteApcuCache($cacheKey, $fileCached['secrets'], (int)$fileCached['expires_at'] - $now);
        $debug = [
            'project_id' => gsmGetEnvValue('GCP_PROJECT_ID') ?? gsmGetEnvValue('GOOGLE_CLOUD_PROJECT') ?? gsmGetEnvValue('GCLOUD_PROJECT') ?? gsmGetEnvValue('PROJECT_ID'),
            'token_source' => 'cache:file',
            'service_account_email' => null,
            'loaded' => array_keys($fileCached['secrets']),
            'errors' => [],
            'from_cache' => true,
        ];
        return $fileCached['secrets'];
    }

    $secrets = gsmLoadSecrets($mapping, $debug);
    $debug['from_cache'] = false;

    if (!empty($secrets)) {
        gsmSetSessionSecretsCache($secrets);
        gsmWriteApcuCache($cacheKey, $secrets, $ttlSeconds);
        gsmWriteFileCache($fileCachePath, $secrets, $ttlSeconds);
        return $secrets;
    }

    // Fallback de resiliencia: si falla GSM, usar cache expirado reciente (hasta 24h).
    if (is_array($fileCached) && isset($fileCached['expires_at']) && ($now - (int)$fileCached['expires_at']) <= 86400) {
        $debug['errors'][] = 'Se usaron secretos en cache expirado por fallo temporal en Google Secret Manager.';
        $debug['token_source'] = 'cache:file-stale';
        $debug['from_cache'] = true;
        return $fileCached['secrets'];
    }

    return [];
}
