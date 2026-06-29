<?php
declare(strict_types=1);

const MIGRATIONS_DIR = __DIR__ . '/../database/migrations';
const MIGRATIONS_LOCK_NAME = 'ecommerce_pro_migration_history_lock';

function migrationConfig(): array
{
    static $resolved = null;
    if (is_array($resolved)) {
        return $resolved;
    }

    $loadedConfig = require __DIR__ . '/config.php';
    if (is_array($loadedConfig)) {
        $resolved = [
            'DB_HOST' => (string) ($loadedConfig['DB_HOST'] ?? '127.0.0.1'),
            'DB_NAME' => (string) ($loadedConfig['DB_NAME'] ?? ''),
            'DB_USER' => (string) ($loadedConfig['DB_USER'] ?? ''),
            'DB_PASSWORD' => (string) ($loadedConfig['DB_PASSWORD'] ?? ''),
            'DB_CHARSET' => (string) ($loadedConfig['DB_CHARSET'] ?? 'utf8mb4'),
            'MIGRATIONS_DEPLOY_TOKEN' => (string) ($loadedConfig['MIGRATIONS_DEPLOY_TOKEN'] ?? ''),
        ];
    } else {
        $resolved = [
            'DB_HOST' => defined('DB_HOST') ? (string) DB_HOST : '127.0.0.1',
            'DB_NAME' => defined('DB_NAME') ? (string) DB_NAME : '',
            'DB_USER' => defined('DB_USER') ? (string) DB_USER : '',
            'DB_PASSWORD' => defined('DB_PASS') ? (string) DB_PASS : '',
            'DB_CHARSET' => defined('DB_CHARSET') ? (string) DB_CHARSET : 'utf8mb4',
            'MIGRATIONS_DEPLOY_TOKEN' => function_exists('getEnvVar')
                ? (string) (getEnvVar('MIGRATIONS_DEPLOY_TOKEN', '') ?? '')
                : (string) (getenv('MIGRATIONS_DEPLOY_TOKEN') ?: ''),
        ];
    }

    if ($resolved['DB_NAME'] === '' || $resolved['DB_USER'] === '') {
        throw new RuntimeException('Configuracion de BD incompleta para migraciones.');
    }

    return $resolved;
}

function migrationDeployToken(): string
{
    $config = migrationConfig();
    return trim((string) ($config['MIGRATIONS_DEPLOY_TOKEN'] ?? ''));
}

function migrationPdo(): PDO
{
    $config = migrationConfig();
    $dsn = 'mysql:host=' . $config['DB_HOST'] . ';dbname=' . $config['DB_NAME'] . ';charset=' . $config['DB_CHARSET'];
    return new PDO($dsn, $config['DB_USER'], $config['DB_PASSWORD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function migrationMysqli(): mysqli
{
    $config = migrationConfig();
    $mysqli = new mysqli($config['DB_HOST'], $config['DB_USER'], $config['DB_PASSWORD'], $config['DB_NAME']);
    if ($mysqli->connect_errno) {
        throw new RuntimeException('Error de conexion MySQL: ' . $mysqli->connect_error);
    }
    $mysqli->set_charset($config['DB_CHARSET']);
    return $mysqli;
}

function ensureMigrationHistoryTable(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS migration_history (
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
    return (string) $matches[1];
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

    if ((int) $stmt->fetchColumn() !== 1) {
        throw new RuntimeException('No se pudo adquirir lock de migraciones.');
    }
}

function releaseMigrationsLock(PDO $pdo): void
{
    $stmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
    $stmt->execute([':lock_name' => MIGRATIONS_LOCK_NAME]);
}

function executeMigrationSql(mysqli $mysqli, string $sql, string $fileName): void
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

    if ($mysqli->errno !== 0) {
        throw new RuntimeException('Error SQL en ' . $fileName . ': ' . $mysqli->error);
    }
}

function getAppliedMigrationsMap(PDO $pdo): array
{
    $stmt = $pdo->query('SELECT version, checksum FROM migration_history');
    $rows = $stmt->fetchAll();
    $map = [];

    foreach ($rows as $row) {
        $map[(string) $row['version']] = (string) $row['checksum'];
    }

    return $map;
}

function runDatabaseMigrations(?string $targetVersion = null, bool $dryRun = false): array
{
    $pdo = migrationPdo();
    $mysqli = migrationMysqli();

    ensureMigrationHistoryTable($pdo);
    acquireMigrationsLock($pdo);

    try {
        $files = getMigrationFiles();
        $appliedMap = getAppliedMigrationsMap($pdo);

        $appliedFiles = [];
        $skippedFiles = [];
        $pendingFiles = [];

        foreach ($files as $filePath) {
            $version = parseMigrationVersion($filePath);
            $fileName = basename($filePath);
            $sql = trim((string) file_get_contents($filePath));
            $checksum = hash('sha256', $sql);

            if ($sql === '') {
                throw new RuntimeException('Migracion vacia: ' . $fileName);
            }

            if ($targetVersion !== null && strcmp($version, $targetVersion) > 0) {
                break;
            }

            if (isset($appliedMap[$version])) {
                if ($appliedMap[$version] !== $checksum) {
                    throw new RuntimeException(
                        'Checksum invalido en migracion ya aplicada: ' . $fileName .
                        '. No edites migraciones antiguas; crea una nueva.'
                    );
                }

                $skippedFiles[] = $fileName;
                continue;
            }

            if ($dryRun) {
                $pendingFiles[] = $fileName;
                continue;
            }

            executeMigrationSql($mysqli, $sql, $fileName);

            $insert = $pdo->prepare('INSERT INTO migration_history (version, filename, checksum) VALUES (?, ?, ?)');
            $insert->execute([$version, $fileName, $checksum]);
            $appliedFiles[] = $fileName;
        }

        return [
            'success' => true,
            'dry_run' => $dryRun,
            'target_version' => $targetVersion,
            'total_files' => count($files),
            'applied_count' => count($appliedFiles),
            'applied_files' => $appliedFiles,
            'skipped_count' => count($skippedFiles),
            'skipped_files' => $skippedFiles,
            'pending_count' => count($pendingFiles),
            'pending_files' => $pendingFiles,
        ];
    } finally {
        releaseMigrationsLock($pdo);
        $mysqli->close();
    }
}
