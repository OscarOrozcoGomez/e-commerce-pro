<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Este script solo puede ejecutarse desde CLI.\n";
    exit(1);
}

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

$options = getopt('', ['email:', 'id::', 'token::', 'password::', 'password_confirm::', 'help']);

if (isset($options['help']) || (!isset($options['email']) && !isset($options['id']))) {
    fwrite(STDERR, "Uso: php scripts/bootstrap_superadmin.php --email=admin@dominio.com\n");
    fwrite(STDERR, "   o: php scripts/bootstrap_superadmin.php --id=123\n");
    fwrite(STDERR, "El script pedira token de bootstrap y la nueva contrasena por consola.\n");
    exit(1);
}

$expectedToken = trim((string) getenv('SUPERADMIN_BOOTSTRAP_TOKEN'));
if ($expectedToken === '') {
    fwrite(STDERR, "Falta definir SUPERADMIN_BOOTSTRAP_TOKEN en el entorno.\n");
    exit(1);
}

$providedToken = trim((string) ($options['token'] ?? ''));
if ($providedToken === '') {
    fwrite(STDOUT, "Token de bootstrap: ");
    $stdinToken = fgets(STDIN);
    $providedToken = is_string($stdinToken) ? trim($stdinToken) : '';
}

if (!hash_equals($expectedToken, $providedToken)) {
    fwrite(STDERR, "Token de bootstrap inválido.\n");
    exit(1);
}

$pdo = getPDO();

if (isset($options['email'])) {
    $stmt = $pdo->prepare("SELECT u.id_usuario, u.nombre, u.email, u.estado, r.nombre AS rol
                           FROM usuarios u
                           JOIN roles r ON u.id_rol = r.id_rol
                           WHERE u.email = ?
                           LIMIT 1");
    $stmt->execute([trim((string) $options['email'])]);
} else {
    $stmt = $pdo->prepare("SELECT u.id_usuario, u.nombre, u.email, u.estado, r.nombre AS rol
                           FROM usuarios u
                           JOIN roles r ON u.id_rol = r.id_rol
                           WHERE u.id_usuario = ?
                           LIMIT 1");
    $stmt->execute([(int) $options['id']]);
}

$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    fwrite(STDERR, "No se encontró el usuario indicado.\n");
    exit(1);
}

if (($user['rol'] ?? '') !== 'admin') {
    fwrite(STDERR, "Solo se puede elevar a super admin una cuenta con rol admin.\n");
    exit(1);
}

if (($user['estado'] ?? '') !== 'activo') {
    fwrite(STDERR, "La cuenta debe estar activa antes de elevarla a super admin.\n");
    exit(1);
}

$newPassword = trim((string) ($options['password'] ?? ''));
if ($newPassword === '') {
    fwrite(STDOUT, "Nueva contraseña para {$user['email']}: ");
    $stdinPassword = fgets(STDIN);
    $newPassword = is_string($stdinPassword) ? trim($stdinPassword) : '';
}

if (!isPasswordSecure($newPassword)) {
    fwrite(STDERR, "La nueva contraseña no cumple con los requisitos mínimos de seguridad.\n");
    exit(1);
}

$confirmPassword = trim((string) ($options['password_confirm'] ?? ''));
if ($confirmPassword === '') {
    fwrite(STDOUT, "Confirma la nueva contraseña: ");
    $stdinConfirm = fgets(STDIN);
    $confirmPassword = is_string($stdinConfirm) ? trim($stdinConfirm) : '';
}

if (!hash_equals($newPassword, $confirmPassword)) {
    fwrite(STDERR, "La confirmación de contraseña no coincide.\n");
    exit(1);
}

$passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);

$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE usuarios SET es_superadmin = 1, contrasena = ? WHERE id_usuario = ?')
        ->execute([$passwordHash, (int) $user['id_usuario']]);
    logAudit('USUARIO_ELEVADO_SUPERADMIN', 'usuarios', (int) $user['id_usuario'], 'Elevado y password actualizado desde CLI');
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "No se pudo actualizar el usuario: " . $e->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "Usuario elevado a super admin y contraseña actualizada: {$user['email']} ({$user['id_usuario']})\n");