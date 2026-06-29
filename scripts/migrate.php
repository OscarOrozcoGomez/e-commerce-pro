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

$showHelp = in_array('--help', $argv, true) || in_array('-h', $argv, true);
if ($showHelp) {
    echo "Uso:\n";
    echo "  C:\\xampp\\php\\php.exe scripts/migrate.php [--dry-run] [--to=YYYYMMDD_HHMMSS]\n\n";
    echo "Opciones:\n";
    echo "  --dry-run   Muestra migraciones pendientes sin ejecutarlas.\n";
    echo "  --to=...    Ejecuta solo hasta esa version (inclusive).\n";
    exit(0);
}

$dryRun = in_array('--dry-run', $argv, true);
$targetVersion = cliArgValue($argv, '--to=');

try {
    $result = runDatabaseMigrations($targetVersion, $dryRun);

    echo "========================================\n";
    echo "MIGRATIONS REPORT\n";
    echo "========================================\n";
    echo 'Dry run: ' . ($result['dry_run'] ? 'YES' : 'NO') . "\n";
    echo 'Target version: ' . ($result['target_version'] ?? 'latest') . "\n";
    echo 'Total SQL files: ' . (string) $result['total_files'] . "\n";
    echo 'Applied: ' . (string) $result['applied_count'] . "\n";
    echo 'Skipped: ' . (string) $result['skipped_count'] . "\n";
    echo 'Pending: ' . (string) $result['pending_count'] . "\n";

    if (!empty($result['applied_files'])) {
        echo "\nApplied files:\n";
        foreach ($result['applied_files'] as $file) {
            echo '  [OK] ' . $file . "\n";
        }
    }

    if (!empty($result['pending_files'])) {
        echo "\nPending files:\n";
        foreach ($result['pending_files'] as $file) {
            echo '  [..] ' . $file . "\n";
        }
    }

    if (!empty($result['skipped_files'])) {
        echo "\nSkipped files (already applied):\n";
        foreach ($result['skipped_files'] as $file) {
            echo '  [SKIP] ' . $file . "\n";
        }
    }

    echo "\nDone.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "\nMigration error:\n");
    fwrite(STDERR, '  ' . $e->getMessage() . "\n");
    exit(1);
}
