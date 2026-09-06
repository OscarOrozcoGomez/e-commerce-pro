<?php
declare(strict_types=1);

/**
 * Revisa los lotes de inventario y, si alguno cambió de severidad de caducidad
 * desde la última corrida (columna lotes_inventario.ultima_severidad_notificada),
 * manda UN correo con el detalle a los destinatarios activos de
 * caducidad_notificacion_correos (se administran en views/notificaciones_caducidades.php).
 *
 * La severidad de un lote cambia sola con el paso del tiempo, así que este cron
 * es el disparador: conviene correrlo una vez al día, temprano.
 *
 *   # crontab del host (VPS), usuario del sitio, PHP 8.2:
 *   15 6 * * *  cd /ruta/al/sitio && /usr/bin/php8.2 scripts/caducidades_notificacion_cron.php >> logs/caducidades_notificacion_cron.log 2>&1
 *
 *   # local (XAMPP), para probar sin enviar:
 *   C:\xampp\php\php.exe scripts/caducidades_notificacion_cron.php --dry-run
 *
 * Nunca lanza excepción hacia afuera (loteEnviarNotificacionesDeCambios la atrapa)
 * para que un fallo de correo no rompa el cron. Con --dry-run detecta y reporta
 * los cambios pero no envía ni marca los lotes como notificados.
 */

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
