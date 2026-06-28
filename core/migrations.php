<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

const MIGRATIONS_DIR = __DIR__ . '/../database/migrations';
const MIGRATIONS_LOCK_NAME = 'ecommerce_pro_schema_migrations';

function ensureMigrationsTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(191) NOT NULL,
        filename VARCHAR(255) NOT NULL,
        checksum CHAR(64) NOT NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (version)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function parseMigrationVersion(string $filePath): string
{
    $base = basename($filePath);
    if (!preg_match('/^([0-9]{8}_[0-9]{6})_[a-z0-9_\-]+\.sql$/i', $base, $matches)) {
        throw new RuntimeException('Nombre de migracion invalido: ' . $base);
    }
    return $matches[1];
}

function getMigrationFiles(string $directory = MIGRATIONS_DIR): array
{
    if (!is_dir($directory)) {
        return [];
    }

    $files = glob(rtrim($directory, '/\\') . '/*.sql');
    if ($files === false) {
        return [];
    }

    sort($files, SORT_STRING);
    return array_values($files);
}

function acquireMigrationsLock(PDO $pdo, int $timeoutSeconds = 20): void
{
    $stmt = $pdo->prepare('SELECT GET_LOCK(:lock_name, :timeout_seconds)');
    $stmt->execute([
        ':lock_name' => MIGRATIONS_LOCK_NAME,
        ':timeout_seconds' => $timeoutSeconds,
    ]);

    $lock = (int) $stmt->fetchColumn();
    if ($lock !== 1) {
        throw new RuntimeException('No se pudo adquirir el lock de migraciones.');
    }
}

function releaseMigrationsLock(PDO $pdo): void
{
    $stmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
    $stmt->execute([':lock_name' => MIGRATIONS_LOCK_NAME]);
}

function executeSqlMigrationWithMysqli(mysqli $mysqli, string $sql, string $fileName): void
{
    if (!$mysqli->multi_query($sql)) {
        throw new RuntimeException('Error SQL en ' . $fileName . ': ' . $mysqli->error);
    }

    do {
        $result = $mysqli->store_result();
        if ($result instanceof mysqli_result) {
            $result->free();
        }
    } while ($mysqli->more_results() && $mysqli->next_result());

    if ($mysqli->errno) {
        throw new RuntimeException('Error SQL en ' . $fileName . ': ' . $mysqli->error);
    }
}

function getAppliedMigrations(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT version, checksum FROM schema_migrations');
    $rows = $stmt->fetchAll();
    $applied = [];

    foreach ($rows as $row) {
        $applied[(string) $row['version']] = (string) $row['checksum'];
    }

    return $applied;
}

function runDatabaseMigrations(?string $targetVersion = null, bool $dryRun = false): array
{
    $pdo = getPDO();
    $mysqli = getMySqli();

    ensureMigrationsTable($pdo);
    acquireMigrationsLock($pdo);

    try {
        $files = getMigrationFiles();
        $appliedMap = getAppliedMigrations($pdo);

        $appliedNow = [];
        $skipped = [];

        foreach ($files as $file) {
            $version = parseMigrationVersion($file);
            $fileName = basename($file);
            $sql = trim((string) file_get_contents($file));
            $checksum = hash('sha256', $sql);

            if ($sql === '') {
                throw new RuntimeException('La migracion esta vacia: ' . $fileName);
            }

            if ($targetVersion !== null && strcmp($version, $targetVersion) > 0) {
                break;
            }

            if (isset($appliedMap[$version])) {
                if ($appliedMap[$version] !== $checksum) {
                    throw new RuntimeException(
                        'Checksum distinto para migracion ya aplicada: ' . $fileName .
                        '. Crea una nueva migracion en lugar de editar una existente.'
                    );
                }
                $skipped[] = $fileName;
                continue;
            }

            if ($dryRun) {
                $appliedNow[] = [
                    'version' => $version,
                    'filename' => $fileName,
                    'status' => 'pending',
                ];
                continue;
            }

            executeSqlMigrationWithMysqli($mysqli, $sql, $fileName);

            $insert = $pdo->prepare('INSERT INTO schema_migrations (version, filename, checksum) VALUES (?, ?, ?)');
            $insert->execute([$version, $fileName, $checksum]);

            $appliedNow[] = [
                'version' => $version,
                'filename' => $fileName,
                'status' => 'applied',
            ];
        }

        return [
            'success' => true,
            'dry_run' => $dryRun,
            'target_version' => $targetVersion,
            'applied_count' => count($appliedNow),
            'skipped_count' => count($skipped),
            'applied' => $appliedNow,
            'skipped' => $skipped,
        ];
    } finally {
        releaseMigrationsLock($pdo);
        $mysqli->close();
    }
}
