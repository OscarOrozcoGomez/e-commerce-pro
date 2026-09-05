<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/lote_caducidad_utils.php';
require_once __DIR__ . '/../core/caducidad_notificaciones_utils.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo se puede ejecutar por CLI (cron). Uso: C:\\xampp\\php\\php.exe scripts/caducidades_notificacion_cron.php [--dry-run]" . PHP_EOL);
    exit(1);
}

$options = getopt('', ['dry-run']);
$isDryRun = array_key_exists('dry-run', $options);

$pdo = getPDO();

if (!loteTablaExiste($pdo, 'lotes_inventario')) {
    fwrite(STDERR, "La tabla lotes_inventario no existe. Corre: php scripts/migrate.php" . PHP_EOL);
    exit(1);
}

$resultado = loteEnviarNotificacionesDeCambios($pdo, null, $isDryRun);

fwrite(STDOUT, sprintf(
    'RUN %s | dry-run=%s | lotes con cambio de severidad: %d | correos enviados: %d%s',
    date('Y-m-d H:i:s'),
    $isDryRun ? 'si' : 'no',
    $resultado['cambios'],
    $resultado['correos_enviados'],
    PHP_EOL
));

exit(0);
