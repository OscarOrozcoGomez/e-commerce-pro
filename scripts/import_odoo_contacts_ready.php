<?php
declare(strict_types=1);

/**
 * Importa el CSV preparado de contactos Odoo a tablas usuarios/clientes/cliente_direcciones.
 *
 * Requiere archivo generado por scripts/prepare_odoo_contacts_import.php (*_ready.csv).
 *
 * Uso:
 *   C:\xampp\php\php.exe scripts\import_odoo_contacts_ready.php --input=scripts/output/odoo_ready.csv --dry-run
 *   C:\xampp\php\php.exe scripts\import_odoo_contacts_ready.php --input=scripts/output/odoo_ready.csv --apply
 */

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    http_response_code(403);
    echo "Este script solo se puede ejecutar por CLI o phpdbg.\n";
    exit(1);
}

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/pii_crypto.php';

$options = getopt('', [
    'input:',
    'delimiter::',
    'dry-run',
    'apply',
    'no-encrypt',
    'create-cliente-role',
    'help',
]);

if (isset($options['help']) || !isset($options['input'])) {
    echo "Uso:\n";
    echo "  C:\\xampp\\php\\php.exe scripts\\import_odoo_contacts_ready.php --input=archivo_ready.csv [--dry-run|--apply] [--create-cliente-role] [--no-encrypt]\n\n";
    echo "Opciones:\n";
    echo "  --input=...             CSV ready generado previamente (requerido).\n";
    echo "  --delimiter=...         Forzar delimitador: , ; tab\n";
    echo "  --dry-run               Simula sin escribir en base de datos (default).\n";
    echo "  --apply                 Ejecuta inserciones/actualizaciones reales.\n";
    echo "  --no-encrypt            Desactiva cifrado de PII (telefono/direcciones).\n";
    echo "  --create-cliente-role   Crea rol cliente si no existe.\n";
    echo "  --help                  Muestra esta ayuda.\n";
    exit(isset($options['help']) ? 0 : 1);
}

$inputPath = (string) $options['input'];
$forcedDelimiter = isset($options['delimiter']) ? (string) $options['delimiter'] : null;
$dryRun = !array_key_exists('apply', $options);
$encryptPii = !array_key_exists('no-encrypt', $options);
$createClienteRole = array_key_exists('create-cliente-role', $options);

if ($encryptPii) {
    try {
        piiGetEncryptionKey();
    } catch (Throwable $e) {
        fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
        fwrite(STDERR, "Define PII_ENCRYPTION_KEY (o CUSTOMER_PII_KEY en GCP Secret Manager) antes de importar.\n");
        exit(1);
    }
}

if (!is_file($inputPath) || !is_readable($inputPath)) {
    fwrite(STDERR, "ERROR: No se puede leer el archivo: {$inputPath}\n");
    exit(1);
}

$delimiter = resolveDelimiter($inputPath, $forcedDelimiter);
$fp = fopen($inputPath, 'rb');
if ($fp === false) {
    fwrite(STDERR, "ERROR: No se pudo abrir el CSV.\n");
    exit(1);
}

$header = fgetcsv($fp, 0, $delimiter);
if ($header === false) {
    fclose($fp);
    fwrite(STDERR, "ERROR: El CSV esta vacio.\n");
    exit(1);
}

$headers = normalizeHeaders($header);
$idx = [];
foreach ($headers as $i => $h) {
    $idx[$h] = $i;
}

$required = ['external_id', 'nombre', 'telefono', 'estado', 'maps_link'];
$missing = [];
foreach ($required as $r) {
    if (!array_key_exists($r, $idx)) {
        $missing[] = $r;
    }
}
if ($missing !== []) {
    fclose($fp);
    fwrite(STDERR, "ERROR: Faltan columnas requeridas en ready CSV: " . implode(', ', $missing) . "\n");
    exit(1);
}

$pdo = getPDO();

$schema = inspectSchema($pdo);
if (!$schema['has_usuarios'] || !$schema['has_clientes']) {
    fclose($fp);
    fwrite(STDERR, "ERROR: Esquema incompleto. Se requieren tablas usuarios y clientes.\n");
    exit(1);
}

$clienteRoleId = findClienteRoleId($pdo);
if ($clienteRoleId === null) {
    if ($createClienteRole && !$dryRun) {
        $clienteRoleId = createClienteRole($pdo);
        echo "Se creo rol 'cliente' con id {$clienteRoleId}.\n";
    } else {
        fclose($fp);
        fwrite(STDERR, "ERROR: No existe rol 'cliente'. Ejecuta con --create-cliente-role y --apply o crea el rol manualmente.\n");
        exit(1);
    }
}

$stats = [
    'total' => 0,
    'skipped_invalid' => 0,
    'users_inserted' => 0,
    'users_updated' => 0,
    'clientes_inserted' => 0,
    'clientes_updated' => 0,
    'direcciones_inserted' => 0,
    'direcciones_updated' => 0,
    'errors' => 0,
];

$line = 1;
while (($row = fgetcsv($fp, 0, $delimiter)) !== false) {
    $line++;
    if ($row === [null] || $row === []) {
        continue;
    }

    $stats['total']++;

    $nombre = trim((string)($row[$idx['nombre']] ?? ''));
    $email = strtolower(trim((string)($row[$idx['email']] ?? '')));
    $telefono = preg_replace('/\D+/', '', (string)($row[$idx['telefono']] ?? '')) ?? '';
    $estado = strtolower(trim((string)($row[$idx['estado']] ?? 'activo')));
    $mapsLink = trim((string)($row[$idx['maps_link']] ?? ''));
    $aliasDireccion = trim((string)($row[$idx['alias_direccion']] ?? 'Principal'));
    $direccion = trim((string)($row[$idx['direccion']] ?? 'Por confirmar'));
    $aliasPerfil = preg_match('/^Cliente\s+\d{4}$/', $nombre) === 1 ? $nombre : null;

    $email = normalizeEmailOrEmpty($email);
    if ($email === '' && $telefono === '') {
        $stats['skipped_invalid']++;
        continue;
    }

    if ($nombre === '') {
        $last4 = strlen($telefono) >= 4 ? substr($telefono, -4) : 'SNOM';
        $nombre = 'Cliente ' . $last4;
    }

    if (!in_array($estado, ['activo', 'inactivo'], true)) {
        $estado = 'activo';
    }

    try {
        if (!$dryRun) {
            $pdo->beginTransaction();
        }

        $idUsuario = null;
        if ($email !== '') {
            $idUsuario = upsertUsuario($pdo, $email, $nombre, $estado, (int)$clienteRoleId, $dryRun, $stats);
        }

        $idCliente = upsertCliente(
            $pdo,
            $idUsuario,
            $nombre,
            $email !== '' ? $email : null,
            $telefono !== '' ? $telefono : null,
            $estado,
            $aliasPerfil,
            $schema,
            $encryptPii,
            $dryRun,
            $stats
        );

        if ($idCliente !== null && $mapsLink !== '' && $schema['has_cliente_direcciones']) {
            upsertDireccion(
                $pdo,
                (int)$idCliente,
                $aliasDireccion !== '' ? $aliasDireccion : 'Principal',
                $direccion !== '' ? $direccion : 'Por confirmar',
                $mapsLink,
                $encryptPii,
                $dryRun,
                $stats
            );
        }

        if (!$dryRun) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        $stats['errors']++;
        if (!$dryRun && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, "[ERROR] Linea {$line}: {$e->getMessage()}\n");
    }
}

fclose($fp);

echo "====== RESUMEN IMPORT READY ======\n";
echo "Modo: " . ($dryRun ? 'DRY-RUN' : 'APPLY') . "\n";
echo "Total filas: {$stats['total']}\n";
echo "Filas omitidas por invalidez: {$stats['skipped_invalid']}\n";
echo "Usuarios insertados: {$stats['users_inserted']}\n";
echo "Usuarios actualizados: {$stats['users_updated']}\n";
echo "Clientes insertados: {$stats['clientes_inserted']}\n";
echo "Clientes actualizados: {$stats['clientes_updated']}\n";
echo "Direcciones insertadas: {$stats['direcciones_inserted']}\n";
echo "Direcciones actualizadas: {$stats['direcciones_updated']}\n";
echo "Errores: {$stats['errors']}\n";

echo "\nRecomendacion: correr primero con --dry-run y despues con --apply en QA.\n";

exit($stats['errors'] > 0 ? 2 : 0);

function inspectSchema(PDO $pdo): array
{
    return [
        'has_usuarios' => tableExists($pdo, 'usuarios'),
        'has_clientes' => tableExists($pdo, 'clientes'),
        'has_cliente_direcciones' => tableExists($pdo, 'cliente_direcciones'),
        'clientes_has_id_usuario' => columnExists($pdo, 'clientes', 'id_usuario'),
        'clientes_has_alias_perfil' => columnExists($pdo, 'clientes', 'alias_perfil'),
    ];
}

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

function findClienteRoleId(PDO $pdo): ?int
{
    $stmt = $pdo->prepare("SELECT id_rol FROM roles WHERE LOWER(nombre) = 'cliente' LIMIT 1");
    $stmt->execute();
    $id = $stmt->fetchColumn();
    if ($id === false) {
        return null;
    }
    return (int)$id;
}

function createClienteRole(PDO $pdo): int
{
    $stmt = $pdo->prepare("INSERT INTO roles (nombre, descripcion, estado) VALUES ('cliente', 'Cliente ecommerce', 'activo')");
    $stmt->execute();
    return (int)$pdo->lastInsertId();
}

function upsertUsuario(PDO $pdo, string $email, string $nombre, string $estado, int $idRolCliente, bool $dryRun, array &$stats): int
{
    $select = $pdo->prepare("SELECT id_usuario, nombre, estado, id_rol FROM usuarios WHERE email = :email LIMIT 1");
    $select->execute([':email' => $email]);
    $existing = $select->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $idUsuario = (int)$existing['id_usuario'];
        $needsUpdate = ((string)$existing['nombre'] !== $nombre)
            || ((string)$existing['estado'] !== $estado)
            || ((int)$existing['id_rol'] !== $idRolCliente);

        if ($needsUpdate) {
            if (!$dryRun) {
                $update = $pdo->prepare("UPDATE usuarios SET nombre = :nombre, estado = :estado, id_rol = :id_rol WHERE id_usuario = :id");
                $update->execute([
                    ':nombre' => $nombre,
                    ':estado' => $estado,
                    ':id_rol' => $idRolCliente,
                    ':id' => $idUsuario,
                ]);
            }
            $stats['users_updated']++;
        }

        return $idUsuario;
    }

    if (!$dryRun) {
        $tempPassword = bin2hex(random_bytes(10));
        $passwordHash = password_hash($tempPassword, PASSWORD_BCRYPT);

        $insert = $pdo->prepare(
            "INSERT INTO usuarios (nombre, email, contrasena, id_rol, id_almacen, estado) VALUES (:nombre, :email, :contrasena, :id_rol, NULL, :estado)"
        );
        $insert->execute([
            ':nombre' => $nombre,
            ':email' => $email,
            ':contrasena' => $passwordHash,
            ':id_rol' => $idRolCliente,
            ':estado' => $estado,
        ]);
        $idUsuario = (int)$pdo->lastInsertId();
    } else {
        $idUsuario = 0;
    }

    $stats['users_inserted']++;
    return $idUsuario;
}

function upsertCliente(
    PDO $pdo,
    ?int $idUsuario,
    string $nombre,
    ?string $email,
    ?string $telefono,
    string $estado,
    ?string $aliasPerfil,
    array $schema,
    bool $encryptPii,
    bool $dryRun,
    array &$stats
): ?int {
    $nombreStore = function_exists('piiEncryptValue') && $encryptPii ? piiEncryptValue($nombre) : $nombre;

    if ($schema['clientes_has_id_usuario'] && $idUsuario !== null) {
        $sel = $pdo->prepare("SELECT id_cliente, nombre, email, telefono, estado FROM clientes WHERE id_usuario = :id_usuario LIMIT 1");
        $sel->execute([':id_usuario' => $idUsuario]);
        $existing = $sel->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $idCliente = (int)$existing['id_cliente'];
            $existingNombre = (string)($existing['nombre'] ?? '');
            if (function_exists('piiIsEncryptedValue') && function_exists('piiDecryptValue') && piiIsEncryptedValue($existingNombre)) {
                $existingNombre = (string)piiDecryptValue($existingNombre);
            }
            $existingPhoneNormalized = normalizePhoneDigits((string)piiDecryptValue((string)($existing['telefono'] ?? '')));
            $incomingPhoneNormalized = normalizePhoneDigits((string)($telefono ?? ''));
            $needs = ($existingNombre !== $nombre)
                || ((string)$existing['email'] !== $email)
                || ($existingPhoneNormalized !== $incomingPhoneNormalized)
                || ((string)$existing['estado'] !== $estado);

            if ($needs) {
                if (!$dryRun) {
                    if ($schema['clientes_has_alias_perfil']) {
                        $upd = $pdo->prepare("UPDATE clientes SET nombre = :nombre, alias_perfil = :alias_perfil, email = :email, telefono = :telefono, estado = :estado WHERE id_cliente = :id");
                        $upd->execute([
                            ':nombre' => $nombreStore,
                            ':alias_perfil' => $aliasPerfil,
                            ':email' => $email,
                            ':telefono' => $telefono !== null ? ($encryptPii ? piiEncryptValue($telefono) : $telefono) : null,
                            ':estado' => $estado,
                            ':id' => $idCliente,
                        ]);
                    } else {
                        $upd = $pdo->prepare("UPDATE clientes SET nombre = :nombre, email = :email, telefono = :telefono, estado = :estado WHERE id_cliente = :id");
                        $upd->execute([
                            ':nombre' => $nombreStore,
                            ':email' => $email,
                            ':telefono' => $telefono !== null ? ($encryptPii ? piiEncryptValue($telefono) : $telefono) : null,
                            ':estado' => $estado,
                            ':id' => $idCliente,
                        ]);
                    }
                }
                $stats['clientes_updated']++;
            }

            return $idCliente;
        }

        if (!$dryRun) {
            $ins = $pdo->prepare("INSERT INTO clientes (nombre, email, telefono, id_usuario, estado) VALUES (:nombre, :email, :telefono, :id_usuario, :estado)");
            $ins->execute([
                ':nombre' => $nombreStore,
                ':email' => $email,
                ':telefono' => $telefono !== null ? ($encryptPii ? piiEncryptValue($telefono) : $telefono) : null,
                ':id_usuario' => $idUsuario,
                ':estado' => $estado,
            ]);
            $idCliente = (int)$pdo->lastInsertId();
        } else {
            $idCliente = null;
        }

        $stats['clientes_inserted']++;
        return $idCliente;
    }

    $existing = null;
    if ($email !== null && $email !== '') {
        $sel = $pdo->prepare("SELECT id_cliente, nombre, email, telefono, estado FROM clientes WHERE email = :email LIMIT 1");
        $sel->execute([':email' => $email]);
        $existing = $sel->fetch(PDO::FETCH_ASSOC);
    }

    if (!$existing && $telefono !== null && $telefono !== '') {
        $selPhone = $pdo->prepare("SELECT id_cliente, nombre, email, telefono, estado FROM clientes WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(telefono,''), '(', ''), ')', ''), '-', ''), ' ', ''), '+', '') LIKE :tel LIMIT 1");
        $selPhone->execute([':tel' => '%' . $telefono]);
        $existing = $selPhone->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            $existing = findClienteByEncryptedPhone($pdo, $telefono);
        }
    }

    if ($existing) {
        $idCliente = (int)$existing['id_cliente'];
        $existingNombre = (string)($existing['nombre'] ?? '');
        if (function_exists('piiIsEncryptedValue') && function_exists('piiDecryptValue') && piiIsEncryptedValue($existingNombre)) {
            $existingNombre = (string)piiDecryptValue($existingNombre);
        }
        $existingPhoneNormalized = normalizePhoneDigits((string)piiDecryptValue((string)($existing['telefono'] ?? '')));
        $incomingPhoneNormalized = normalizePhoneDigits((string)($telefono ?? ''));
        $needs = ($existingNombre !== $nombre)
            || ((string)($existing['email'] ?? '') !== (string)($email ?? ''))
            || ($existingPhoneNormalized !== $incomingPhoneNormalized)
            || ((string)$existing['estado'] !== $estado);

        if ($needs) {
            if (!$dryRun) {
                if ($schema['clientes_has_alias_perfil']) {
                    $upd = $pdo->prepare("UPDATE clientes SET nombre = :nombre, alias_perfil = :alias_perfil, email = :email, telefono = :telefono, estado = :estado WHERE id_cliente = :id");
                    $upd->execute([
                        ':nombre' => $nombreStore,
                        ':alias_perfil' => $aliasPerfil,
                        ':email' => $email,
                        ':telefono' => $telefono !== null ? ($encryptPii ? piiEncryptValue($telefono) : $telefono) : null,
                        ':estado' => $estado,
                        ':id' => $idCliente,
                    ]);
                } else {
                    $upd = $pdo->prepare("UPDATE clientes SET nombre = :nombre, email = :email, telefono = :telefono, estado = :estado WHERE id_cliente = :id");
                    $upd->execute([
                        ':nombre' => $nombreStore,
                        ':email' => $email,
                        ':telefono' => $telefono !== null ? ($encryptPii ? piiEncryptValue($telefono) : $telefono) : null,
                        ':estado' => $estado,
                        ':id' => $idCliente,
                    ]);
                }
            }
            $stats['clientes_updated']++;
        }

        return $idCliente;
    }

    if (!$dryRun) {
        if ($schema['clientes_has_id_usuario'] && $idUsuario !== null) {
            if ($schema['clientes_has_alias_perfil']) {
                $ins = $pdo->prepare("INSERT INTO clientes (nombre, alias_perfil, email, telefono, estado, id_usuario) VALUES (:nombre, :alias_perfil, :email, :telefono, :estado, :id_usuario)");
                $ins->execute([
                    ':nombre' => $nombreStore,
                    ':alias_perfil' => $aliasPerfil,
                    ':email' => $email,
                    ':telefono' => $telefono !== null ? ($encryptPii ? piiEncryptValue($telefono) : $telefono) : null,
                    ':estado' => $estado,
                    ':id_usuario' => $idUsuario,
                ]);
            } else {
                $ins = $pdo->prepare("INSERT INTO clientes (nombre, email, telefono, estado, id_usuario) VALUES (:nombre, :email, :telefono, :estado, :id_usuario)");
                $ins->execute([
                    ':nombre' => $nombreStore,
                    ':email' => $email,
                    ':telefono' => $telefono !== null ? ($encryptPii ? piiEncryptValue($telefono) : $telefono) : null,
                    ':estado' => $estado,
                    ':id_usuario' => $idUsuario,
                ]);
            }
        } else {
            if ($schema['clientes_has_alias_perfil']) {
                $ins = $pdo->prepare("INSERT INTO clientes (nombre, alias_perfil, email, telefono, estado) VALUES (:nombre, :alias_perfil, :email, :telefono, :estado)");
                $ins->execute([
                    ':nombre' => $nombreStore,
                    ':alias_perfil' => $aliasPerfil,
                    ':email' => $email,
                    ':telefono' => $telefono !== null ? ($encryptPii ? piiEncryptValue($telefono) : $telefono) : null,
                    ':estado' => $estado,
                ]);
            } else {
                $ins = $pdo->prepare("INSERT INTO clientes (nombre, email, telefono, estado) VALUES (:nombre, :email, :telefono, :estado)");
                $ins->execute([
                    ':nombre' => $nombreStore,
                    ':email' => $email,
                    ':telefono' => $telefono !== null ? ($encryptPii ? piiEncryptValue($telefono) : $telefono) : null,
                    ':estado' => $estado,
                ]);
            }
        }
        $idCliente = (int)$pdo->lastInsertId();
    } else {
        $idCliente = null;
    }

    $stats['clientes_inserted']++;
    return $idCliente;
}

function upsertDireccion(PDO $pdo, int $idCliente, string $alias, string $direccion, string $mapsLink, bool $encryptPii, bool $dryRun, array &$stats): void
{
    $sel = $pdo->prepare("SELECT id_direccion, direccion, maps_link, alias FROM cliente_direcciones WHERE id_cliente = :id_cliente AND es_default = 1 LIMIT 1");
    $sel->execute([':id_cliente' => $idCliente]);
    $existing = $sel->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        $idDireccion = (int)$existing['id_direccion'];
        $existingDireccion = (string)piiDecryptValue((string)$existing['direccion']);
        $existingMaps = (string)piiDecryptValue((string)($existing['maps_link'] ?? ''));
        $existingAlias = (string)piiDecryptValue((string)$existing['alias']);
        $needs = ($existingDireccion !== $direccion)
            || ($existingMaps !== $mapsLink)
            || ($existingAlias !== $alias);

        if ($needs) {
            if (!$dryRun) {
                $upd = $pdo->prepare("UPDATE cliente_direcciones SET alias = :alias, direccion = :direccion, maps_link = :maps_link WHERE id_direccion = :id");
                $upd->execute([
                    ':alias' => $encryptPii ? piiEncryptValue($alias) : $alias,
                    ':direccion' => $encryptPii ? piiEncryptValue($direccion) : $direccion,
                    ':maps_link' => $encryptPii ? piiEncryptValue($mapsLink) : $mapsLink,
                    ':id' => $idDireccion,
                ]);
            }
            $stats['direcciones_updated']++;
        }
        return;
    }

    if (!$dryRun) {
        $ins = $pdo->prepare("INSERT INTO cliente_direcciones (id_cliente, alias, direccion, maps_link, es_default) VALUES (:id_cliente, :alias, :direccion, :maps_link, 1)");
        $ins->execute([
            ':id_cliente' => $idCliente,
            ':alias' => $encryptPii ? piiEncryptValue($alias) : $alias,
            ':direccion' => $encryptPii ? piiEncryptValue($direccion) : $direccion,
            ':maps_link' => $encryptPii ? piiEncryptValue($mapsLink) : $mapsLink,
        ]);
    }

    $stats['direcciones_inserted']++;
}

function resolveDelimiter(string $path, ?string $forced): string
{
    if ($forced !== null && $forced !== '') {
        $forced = strtolower(trim($forced));
        if ($forced === 'tab') {
            return "\t";
        }
        if (in_array($forced, [',', ';'], true)) {
            return $forced;
        }
        throw new RuntimeException('Delimitador no soportado. Usa , ; o tab');
    }

    $sample = file_get_contents($path, false, null, 0, 2048);
    if ($sample === false) {
        return ',';
    }

    if (strncmp($sample, "\xEF\xBB\xBF", 3) === 0) {
        $sample = substr($sample, 3);
    }

    $firstLine = strtok($sample, "\r\n");
    if ($firstLine === false) {
        return ',';
    }

    $counts = [
        ',' => substr_count($firstLine, ','),
        ';' => substr_count($firstLine, ';'),
        "\t" => substr_count($firstLine, "\t"),
    ];

    arsort($counts);
    $delimiter = (string) array_key_first($counts);
    return $counts[$delimiter] > 0 ? $delimiter : ',';
}

function normalizeHeaders(array $header): array
{
    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]) ?? (string)$header[0];
    }

    $result = [];
    foreach ($header as $h) {
        $v = strtolower(trim((string)$h));
        $result[] = $v;
    }
    return $result;
}

function normalizeEmailOrEmpty(string $email): string
{
    $email = strtolower(trim($email));
    if ($email === '') {
        return '';
    }

    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function normalizePhoneDigits(string $telefono): string
{
    $digits = preg_replace('/\D+/', '', $telefono) ?? '';
    if ($digits === '') {
        return '';
    }

    if (strpos($digits, '52') === 0 && strlen($digits) > 10) {
        $digits = substr($digits, 2);
    }

    if (strlen($digits) > 10) {
        $digits = substr($digits, -10);
    }

    return $digits;
}

function findClienteByEncryptedPhone(PDO $pdo, string $phone): ?array
{
    $target = normalizePhoneDigits($phone);
    if ($target === '') {
        return null;
    }

    $stmt = $pdo->query("SELECT id_cliente, nombre, email, telefono, estado FROM clientes WHERE telefono IS NOT NULL AND telefono <> ''");
    if ($stmt === false) {
        return null;
    }

    while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
        $candidate = normalizePhoneDigits((string)piiDecryptValue((string)($row['telefono'] ?? '')));
        if ($candidate !== '' && $candidate === $target) {
            return $row;
        }
    }

    return null;
}
