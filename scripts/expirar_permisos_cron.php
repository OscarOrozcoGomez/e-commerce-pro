<?php
declare(strict_types=1);

/**
 * Retira los permisos individuales (usuario_permisos) cuya fecha de "acceso hasta"
 * (expira_en) ya pasó. getEffectivePermissions() ya los ignora en tiempo real; este
 * cron solo hace la limpieza física y deja constancia en la auditoría.
 *
 * Uso (cron del host):
 *   C:\xampp\php\php.exe scripts/expirar_permisos_cron.php [--dry-run]
 */

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo se puede ejecutar por CLI (cron). Uso: C:\\xampp\\php\\php.exe scripts/expirar_permisos_cron.php [--dry-run]" . PHP_EOL);
    exit(1);
}

$options = getopt('', ['dry-run']);
$isDryRun = array_key_exists('dry-run', $options);

$pdo = getPDO();
$ahora = date('Y-m-d H:i:s');

try {
    $stmt = $pdo->prepare(
        "SELECT up.id_usuario, up.id_permiso, up.efecto, up.expira_en, up.nota,
                p.clave, u.nombre AS usuario_nombre
         FROM usuario_permisos up
         JOIN permisos p ON p.id_permiso = up.id_permiso
         JOIN usuarios u ON u.id_usuario = up.id_usuario
         WHERE up.expira_en IS NOT NULL AND up.expira_en <= NOW()
         ORDER BY up.id_usuario"
    );
    $stmt->execute();
    $vencidos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf('RUN %s | ERROR al leer permisos vencidos: %s%s', $ahora, $e->getMessage(), PHP_EOL));
    exit(1);
}

if (!$vencidos) {
    fwrite(STDOUT, sprintf('RUN %s | sin permisos vencidos que retirar.%s', $ahora, PHP_EOL));
    exit(0);
}

$del = $pdo->prepare("DELETE FROM usuario_permisos WHERE id_usuario = ? AND id_permiso = ?");
$retirados = 0;
$fallidos = 0;

foreach ($vencidos as $fila) {
    $idUsuario = (int) $fila['id_usuario'];
    $idPermiso = (int) $fila['id_permiso'];
    $detalle = sprintf(
        'Permiso "%s" (%s) de %s #%d retirado por caducidad. Vencía %s. Nota original: %s',
        $fila['clave'],
        $fila['efecto'],
        $fila['usuario_nombre'],
        $idUsuario,
        $fila['expira_en'],
        $fila['nota'] !== null && $fila['nota'] !== '' ? $fila['nota'] : '(sin nota)'
    );

    if ($isDryRun) {
        fwrite(STDOUT, '[dry-run] ' . $detalle . PHP_EOL);
        $retirados++;
        continue;
    }

    try {
        $del->execute([$idUsuario, $idPermiso]);
        logAudit('PERMISO_EXPIRADO', 'usuario_permisos', $idUsuario, $detalle);
        $retirados++;
    } catch (Throwable $e) {
        $fallidos++;
        fwrite(STDERR, sprintf('  fallo al retirar permiso %d de usuario %d: %s%s', $idPermiso, $idUsuario, $e->getMessage(), PHP_EOL));
    }
}

fwrite(STDOUT, sprintf(
    'RUN %s | %s: %d permiso(s) retirado(s)%s.%s',
    $ahora,
    $isDryRun ? 'DRY-RUN' : 'OK',
    $retirados,
    $fallidos > 0 ? sprintf(', %d con error', $fallidos) : '',
    PHP_EOL
));

exit($fallidos > 0 ? 1 : 0);
