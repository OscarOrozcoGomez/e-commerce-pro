<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=UTF-8');

function maskInfo(?string $value): string
{
	if ($value === null) {
		return 'MISSING';
	}
	$trimmed = trim($value);
	if ($trimmed === '') {
		return 'EMPTY';
	}
	return 'SET(len=' . strlen($trimmed) . ')';
}

function loadSecretsArrayFromPhp(string $path): ?array
{
	if (!is_readable($path)) {
		return null;
	}

	$data = require $path;
	if (!is_array($data)) {
		return null;
	}

	$normalized = [];
	foreach ($data as $k => $v) {
		if (!is_string($k)) {
			continue;
		}
		if (is_string($v) || is_numeric($v)) {
			$normalized[trim($k)] = trim((string) $v);
		}
	}

	return $normalized;
}

echo "=== DIAGNOSTICO PRODUCCION ===\n";
echo 'Fecha: ' . date('Y-m-d H:i:s') . "\n";
echo 'Host: ' . ($_SERVER['HTTP_HOST'] ?? 'unknown') . "\n\n";

$candidates = [
	__DIR__ . '/../.app_secrets.php',
	__DIR__ . '/../app_secrets.php',
	__DIR__ . '/../.app_secrests.php',
	__DIR__ . '/../app_secrests.php',
];

$home = getenv('HOME');
if ($home === false || trim($home) === '') {
	$home = $_SERVER['HOME'] ?? '';
}
if (is_string($home) && trim($home) !== '') {
	$home = rtrim($home, '/\\');
	$candidates[] = $home . '/.app_secrets.php';
	$candidates[] = $home . '/app_secrets.php';
	$candidates[] = $home . '/.app_secrests.php';
	$candidates[] = $home . '/app_secrests.php';
}

$loadedPath = null;
$secrets = [];
foreach ($candidates as $path) {
	$data = loadSecretsArrayFromPhp($path);
	if ($data !== null) {
		$loadedPath = $path;
		$secrets = $data;
		break;
	}
}

echo "Secret file loaded: " . ($loadedPath ?? 'NONE') . "\n";
echo "\n";

$appEnv = $secrets['APP_ENV'] ?? null;
$dbHost = $secrets['DB_HOST'] ?? '127.0.0.1';
$dbName = $secrets['DB_NAME'] ?? 'beautyandwell_prod';
$dbUser = $secrets['DB_USER'] ?? null;
$dbPass = $secrets['DB_PASSWORD'] ?? null;
$mapsKey = $secrets['MAPS_KEY'] ?? ($secrets['Maps_KEY'] ?? ($secrets['GOOGLE_MAPS_API_KEY'] ?? null));

echo "APP_ENV: " . ($appEnv ?? 'DEFAULT(production)') . "\n";
echo "DB_HOST: " . $dbHost . "\n";
echo "DB_NAME: " . $dbName . "\n";
echo "DB_USER: " . maskInfo($dbUser) . "\n";
echo "DB_PASSWORD: " . maskInfo($dbPass) . "\n";
echo "MAPS_KEY: " . maskInfo($mapsKey) . "\n\n";

if ($dbUser === null || trim($dbUser) === '' || $dbPass === null || trim($dbPass) === '') {
	echo "DB TEST: SKIPPED (faltan DB_USER o DB_PASSWORD)\n";
	exit;
}

$dsn = 'mysql:host=' . $dbHost . ';dbname=' . $dbName . ';charset=utf8mb4';
try {
	$pdo = new PDO($dsn, trim((string) $dbUser), trim((string) $dbPass), [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
	]);

	$ok = $pdo->query('SELECT 1')->fetchColumn();
	echo 'DB TEST: OK (SELECT ' . (string) $ok . ")\n";
} catch (Throwable $e) {
	echo 'DB TEST: FAIL -> ' . $e->getMessage() . "\n";
}

echo "\nIMPORTANTE: Elimina este archivo despues del diagnostico.\n";
