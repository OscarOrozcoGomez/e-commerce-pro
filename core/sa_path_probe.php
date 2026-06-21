<?php
declare(strict_types=1);

header('Content-Type: text/plain; charset=UTF-8');

function envVal(string $name): ?string
{
    $v = getenv($name);
    if ($v === false) {
        $v = $_SERVER[$name] ?? $_ENV[$name] ?? $_SERVER['REDIRECT_' . $name] ?? null;
    }
    if ($v === null) {
        return null;
    }
    $v = trim((string) $v);
    return $v === '' ? null : $v;
}

function statPath(string $path): string
{
    $exists = file_exists($path) ? 'yes' : 'no';
    $readable = is_readable($path) ? 'yes' : 'no';
    return $path . ' | exists=' . $exists . ' | readable=' . $readable;
}

echo "=== SA PATH PROBE ===\n";
echo 'Host: ' . ($_SERVER['HTTP_HOST'] ?? 'unknown') . "\n";

$envPath = envVal('GCP_SA_KEY_FILE') ?? envVal('GOOGLE_APPLICATION_CREDENTIALS') ?? envVal('GCP_SERVICE_ACCOUNT_FILE');
echo 'Configured env path: ' . ($envPath ?? 'MISSING') . "\n\n";

$paths = [];
if ($envPath !== null) {
    $paths[] = $envPath;
}

$home = getenv('HOME');
if ($home === false || trim((string)$home) === '') {
    $home = $_SERVER['HOME'] ?? '';
}
$home = is_string($home) ? rtrim($home, '/\\') : '';

if ($home !== '') {
    $paths[] = $home . '/.gcp/sa.json';
    $paths[] = $home . '/public_html/.gcp/sa.json';
    $paths[] = $home . '/.gcp/service-account.json';
    $paths[] = $home . '/public_html/.gcp/service-account.json';
}

$seen = [];
foreach ($paths as $p) {
    if (isset($seen[$p])) {
        continue;
    }
    $seen[$p] = true;
    echo statPath($p) . "\n";
}

echo "\nElimina este archivo despues de validar.\n";
