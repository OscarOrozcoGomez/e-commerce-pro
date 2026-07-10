<?php
declare(strict_types=1);

/**
 * Cifra PII existente de clientes en BD.
 *
 * Campos cifrados:
 * - clientes.nombre
 * - clientes.telefono
 * - clientes.alias_perfil (si existe)
 * - cliente_direcciones.alias
 * - cliente_direcciones.direccion
 * - cliente_direcciones.maps_link
 *
 * Uso:
 *   C:\xampp\php\php.exe scripts\encrypt_customer_pii.php --dry-run
 *   C:\xampp\php\php.exe scripts\encrypt_customer_pii.php --apply
 */

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    http_response_code(403);
    echo "Este script solo se puede ejecutar por CLI o phpdbg.\n";
    exit(1);
}

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/pii_crypto.php';

$options = getopt('', ['dry-run', 'apply', 'help']);

if (isset($options['help'])) {
    echo "Uso:\n";
    echo "  C:\\xampp\\php\\php.exe scripts\\encrypt_customer_pii.php [--dry-run|--apply]\n\n";
    echo "Opciones:\n";
    echo "  --dry-run  Simula cambios sin escribir en BD (default).\n";
    echo "  --apply    Ejecuta cifrado en BD.\n";
    echo "  --help     Muestra ayuda.\n";
    exit(0);
}

$dryRun = !array_key_exists('apply', $options);

try {
    piiGetEncryptionKey();
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    fwrite(STDERR, "Define PII_ENCRYPTION_KEY (o CUSTOMER_PII_KEY en GCP Secret Manager).\n");
    exit(1);
}

$pdo = getPDO();
$stats = [
    'clientes_nombre_encrypted' => 0,
    'clientes_telefono_encrypted' => 0,
    'clientes_alias_perfil_encrypted' => 0,
    'direcciones_alias_encrypted' => 0,
    'direcciones_direccion_encrypted' => 0,
    'direcciones_maps_encrypted' => 0,
    'rows_touched' => 0,
    'errors' => 0,
];

$hasAliasPerfil = columnExists($pdo, 'clientes', 'alias_perfil');

try {
    if (!$dryRun) {
        $pdo->beginTransaction();
    }

    $stmtClientes = $pdo->query('SELECT id_cliente, nombre, telefono' . ($hasAliasPerfil ? ', alias_perfil' : '') . ' FROM clientes');
    if ($stmtClientes !== false) {
        $updSql = 'UPDATE clientes SET nombre = :nombre, telefono = :telefono' . ($hasAliasPerfil ? ', alias_perfil = :alias_perfil' : '') . ' WHERE id_cliente = :id';
        $upd = $pdo->prepare($updSql);

        while (($row = $stmtClientes->fetch(PDO::FETCH_ASSOC)) !== false) {
            $id = (int)$row['id_cliente'];
            $nombre = (string)($row['nombre'] ?? '');
            $telefono = (string)($row['telefono'] ?? '');
            $aliasPerfil = $hasAliasPerfil ? (string)($row['alias_perfil'] ?? '') : '';

            $newNombre = $nombre;
            $newTelefono = $telefono;
            $newAliasPerfil = $aliasPerfil;
            $changed = false;

            if ($nombre !== '' && !piiIsEncryptedValue($nombre)) {
                $newNombre = (string)piiEncryptValue($nombre);
                $changed = true;
                $stats['clientes_nombre_encrypted']++;
            }

            if ($telefono !== '' && !piiIsEncryptedValue($telefono)) {
                $newTelefono = (string)piiEncryptValue($telefono);
                $changed = true;
                $stats['clientes_telefono_encrypted']++;
            }

            if ($hasAliasPerfil && $aliasPerfil !== '' && !piiIsEncryptedValue($aliasPerfil)) {
                $newAliasPerfil = (string)piiEncryptValue($aliasPerfil);
                $changed = true;
                $stats['clientes_alias_perfil_encrypted']++;
            }

            if ($changed) {
                if (!$dryRun) {
                    $params = [':nombre' => $newNombre, ':telefono' => $newTelefono, ':id' => $id];
                    if ($hasAliasPerfil) {
                        $params[':alias_perfil'] = $newAliasPerfil;
                    }
                    $upd->execute($params);
                }
                $stats['rows_touched']++;
            }
        }
    }

    if (tableExists($pdo, 'cliente_direcciones')) {
        $stmtDir = $pdo->query('SELECT id_direccion, alias, direccion, maps_link FROM cliente_direcciones');
        if ($stmtDir !== false) {
            $updDir = $pdo->prepare('UPDATE cliente_direcciones SET alias = :alias, direccion = :direccion, maps_link = :maps_link WHERE id_direccion = :id');

            while (($row = $stmtDir->fetch(PDO::FETCH_ASSOC)) !== false) {
                $id = (int)$row['id_direccion'];
                $alias = (string)($row['alias'] ?? '');
                $direccion = (string)($row['direccion'] ?? '');
                $mapsLink = (string)($row['maps_link'] ?? '');

                $newAlias = $alias;
                $newDireccion = $direccion;
                $newMaps = $mapsLink;
                $changed = false;

                if ($alias !== '' && !piiIsEncryptedValue($alias)) {
                    $newAlias = (string)piiEncryptValue($alias);
                    $changed = true;
                    $stats['direcciones_alias_encrypted']++;
                }

                if ($direccion !== '' && !piiIsEncryptedValue($direccion)) {
                    $newDireccion = (string)piiEncryptValue($direccion);
                    $changed = true;
                    $stats['direcciones_direccion_encrypted']++;
                }

                if ($mapsLink !== '' && !piiIsEncryptedValue($mapsLink)) {
                    $newMaps = (string)piiEncryptValue($mapsLink);
                    $changed = true;
                    $stats['direcciones_maps_encrypted']++;
                }

                if ($changed) {
                    if (!$dryRun) {
                        $updDir->execute([
                            ':alias' => $newAlias,
                            ':direccion' => $newDireccion,
                            ':maps_link' => $newMaps,
                            ':id' => $id,
                        ]);
                    }
                    $stats['rows_touched']++;
                }
            }
        }
    }

    if (!$dryRun) {
        $pdo->commit();
    }
} catch (Throwable $e) {
    $stats['errors']++;
    if (!$dryRun && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'ERROR: ' . $e->getMessage() . "\n");
    exit(2);
}

echo "====== RESUMEN CIFRADO CLIENTES ======\n";
echo 'Modo: ' . ($dryRun ? 'DRY-RUN' : 'APPLY') . "\n";
echo 'Filas afectadas: ' . $stats['rows_touched'] . "\n";
echo 'clientes.nombre cifrados: ' . $stats['clientes_nombre_encrypted'] . "\n";
echo 'clientes.telefono cifrados: ' . $stats['clientes_telefono_encrypted'] . "\n";
echo 'clientes.alias_perfil cifrados: ' . $stats['clientes_alias_perfil_encrypted'] . "\n";
echo 'cliente_direcciones.alias cifrados: ' . $stats['direcciones_alias_encrypted'] . "\n";
echo 'cliente_direcciones.direccion cifrados: ' . $stats['direcciones_direccion_encrypted'] . "\n";
echo 'cliente_direcciones.maps_link cifrados: ' . $stats['direcciones_maps_encrypted'] . "\n";
echo 'Errores: ' . $stats['errors'] . "\n";

exit(0);

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table");
    $stmt->execute([':table' => $table]);
    return (int)$stmt->fetchColumn() > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table AND COLUMN_NAME = :column");
    $stmt->execute([':table' => $table, ':column' => $column]);
    return (int)$stmt->fetchColumn() > 0;
}
