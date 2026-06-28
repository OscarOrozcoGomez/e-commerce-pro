<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/migrations.php';

function cliArgValue(array $argv, string $prefix): ?string
{
    foreach ($argv as $arg) {
        if (strpos($arg, $prefix) === 0) {
            return substr($arg, strlen($prefix));
        }
    }
    return null;
}

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Este script solo se puede ejecutar por CLI.\n";
    exit(1);
}

$dryRun = in_array('--dry-run', $argv, true);
$targetVersion = cliArgValue($argv, '--to=');

try {
    $result = runDatabaseMigrations($targetVersion, $dryRun);

    echo "Migraciones completadas.\n";
    echo 'Dry run: ' . ($result['dry_run'] ? 'si' : 'no') . "\n";
    echo 'Aplicadas: ' . (string) $result['applied_count'] . "\n";
    echo 'Omitidas (ya aplicadas): ' . (string) $result['skipped_count'] . "\n";

    if (!empty($result['applied'])) {
        echo "Detalle de migraciones procesadas:\n";
        foreach ($result['applied'] as $row) {
            echo '- ' . $row['version'] . ' | ' . $row['filename'] . ' | ' . $row['status'] . "\n";
        }
    }

    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error de migraciones: ' . $e->getMessage() . "\n");
    exit(1);
}
