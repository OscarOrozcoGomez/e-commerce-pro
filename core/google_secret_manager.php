<?php
declare(strict_types=1);

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

    return $loaded;
}
