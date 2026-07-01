<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';

$token = getEnvVar('TELEGRAM_BOT_TOKEN');
$chatId = getEnvVar('TELEGRAM_CHAT_ID');

if ($token === null || $chatId === null) {
    fwrite(STDOUT, "MISSING_TELEGRAM_ENV\n");
    exit(1);
}

$chatId = trim($chatId);
if ($chatId === '' || !preg_match('/^-?\d+$/', $chatId)) {
    fwrite(STDOUT, "INVALID_CHAT_ID_FORMAT\n");
    exit(1);
}

$message = 'Smoke test QA bLife ' . date('Y-m-d H:i:s');

if (!function_exists('curl_init')) {
    fwrite(STDOUT, "CURL_UNAVAILABLE\n");
    exit(2);
}

$checkUrl = 'https://api.telegram.org/bot' . $token . '/getMe';
$checkHandle = curl_init();
curl_setopt($checkHandle, CURLOPT_URL, $checkUrl);
curl_setopt($checkHandle, CURLOPT_RETURNTRANSFER, true);
curl_setopt($checkHandle, CURLOPT_CONNECTTIMEOUT, 8);
curl_setopt($checkHandle, CURLOPT_TIMEOUT, 12);

$checkResponse = curl_exec($checkHandle);
$checkError = curl_error($checkHandle);
$checkCode = (int) curl_getinfo($checkHandle, CURLINFO_HTTP_CODE);
curl_close($checkHandle);

if ($checkResponse === false) {
    fwrite(STDOUT, 'CURL_ERROR_TOKEN_CHECK: ' . ($checkError !== '' ? $checkError : 'unknown') . "\n");
    exit(3);
}

$checkJson = json_decode($checkResponse, true);
$tokenOk = is_array($checkJson) && !empty($checkJson['ok']);
if (!$tokenOk) {
    $checkDescription = is_array($checkJson) && isset($checkJson['description']) && is_string($checkJson['description'])
        ? $checkJson['description']
        : 'unknown';
    fwrite(STDOUT, 'INVALID_TOKEN HTTP_' . $checkCode . ' DESC_' . $checkDescription . "\n");
    exit(4);
}

$url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
$payload = http_build_query([
    'chat_id' => $chatId,
    'text' => $message,
]);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
curl_setopt($ch, CURLOPT_TIMEOUT, 12);

$response = curl_exec($ch);
$error = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false) {
    fwrite(STDOUT, 'CURL_ERROR: ' . ($error !== '' ? $error : 'unknown') . "\n");
    exit(3);
}

$json = json_decode($response, true);
$ok = is_array($json) && !empty($json['ok']);
if ($ok) {
    fwrite(STDOUT, 'TELEGRAM_OK HTTP_' . $httpCode . "\n");
    exit(0);
}

$description = is_array($json) && isset($json['description']) && is_string($json['description'])
    ? $json['description']
    : 'unknown';

fwrite(STDOUT, 'TELEGRAM_FAIL HTTP_' . $httpCode . ' DESC_' . $description . "\n");
exit(4);
