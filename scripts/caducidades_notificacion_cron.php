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
 * --host=DOMINIO  fija $_SERVER['HTTP_HOST'] (vacío en CLI). SIN esto el remitente
 *                 del correo queda como "no-reply@" sin dominio y el relay lo
 *                 rechaza (501). Alternativa: definir APP_EMAIL_FROM_DOMAIN en el
 *                 entorno. Necesario en el VPS.
 * --sellar-inicial  marca la severidad ACTUAL de todos los lotes SIN enviar nada.
 *                 Correr una sola vez al activar el feature para no recibir un
 *                 correo con decenas de lotes en la primera corrida real.
 * --dry-run       detecta y reporta los cambios, sin enviar ni marcar nada.
 *
 *   # crontab del host (VPS), usuario del sitio, PHP 8.2 -- 1x al día:
 *   15 6 * * *  cd /home/bellezaybienestar/htdocs/bellezaybienestar.com.mx && APP_ENV=production /usr/bin/php8.2 scripts/caducidades_notificacion_cron.php --host=bellezaybienestar.com.mx >> logs/caducidades_notificacion_cron.log 2>&1
 *
 *   # una sola vez, ANTES de agendar el cron, para no recibir el muro inicial:
 *   APP_ENV=production /usr/bin/php8.2 scripts/caducidades_notificacion_cron.php --sellar-inicial
 *
 *   # local (XAMPP), para revisar qué haría sin efectos:
 *   C:\xampp\php\php.exe scripts/caducidades_notificacion_cron.php --dry-run
 *
 * Nunca lanza excepción hacia afuera (loteEnviarNotificacionesDeCambios la atrapa)
 * para que un fallo de correo no rompa el cron.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, 'Este script solo se puede ejecutar por CLI (cron).' . PHP_EOL);
    exit(1);
}

$options = getopt('', ['dry-run', 'sellar-inicial', 'host:']);
$isDryRun = array_key_exists('dry-run', $options);
$sellarInicial = array_key_exists('sellar-inicial', $options);
$host = isset($options['host']) ? trim((string) $options['host']) : '';

// Debe fijarse ANTES de cargar config.php: de ahí sale el dominio del remitente
// y las URLs absolutas del correo.
if ($host !== '') {
    $_SERVER['HTTP_HOST'] = $host;
}

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/lote_caducidad_utils.php';
require_once __DIR__ . '/../core/caducidad_notificaciones_utils.php';

$pdo = getPDO();

if (!loteTablaExiste($pdo, 'lotes_inventario')) {
    fwrite(STDERR, 'La tabla lotes_inventario no existe. Corre: php scripts/migrate.php' . PHP_EOL);
    exit(1);
}

if ($sellarInicial) {
    $sellados = loteSellarSeveridadesActuales($pdo);
    fwrite(STDOUT, sprintf(
        'RUN %s | sellar-inicial | lotes sellados sin enviar: %d%s',
        date('Y-m-d H:i:s'),
        $sellados,
        PHP_EOL
    ));
    exit(0);
}

// Mantenimiento ANTES de notificar: cierra solos los lotes que ya se vendieron
// (activo -> agotado), marca los ya vencidos (activo -> caducado) y purga del
// historico los agotados/retirados viejos -- si no, la tabla se llena de lotes
// muertos. Nunca lanza excepcion (ver loteMantenimientoAutomatico).
$mantenimiento = loteMantenimientoAutomatico($pdo, 90, $isDryRun);
fwrite(STDOUT, sprintf(
    'RUN %s | dry-run=%s | mantenimiento: %d agotados, %d caducados, %d purgados%s',
    date('Y-m-d H:i:s'),
    $isDryRun ? 'si' : 'no',
    $mantenimiento['agotados'],
    $mantenimiento['caducados'],
    $mantenimiento['purgados'],
    PHP_EOL
));

$resultado = loteEnviarNotificacionesDeCambios($pdo, null, $isDryRun);

fwrite(STDOUT, sprintf(
    'RUN %s | dry-run=%s | lotes con cambio de severidad: %d | para sacar ya (extra): %d | correos enviados: %d%s',
    date('Y-m-d H:i:s'),
    $isDryRun ? 'si' : 'no',
    $resultado['cambios'],
    $resultado['sacar_ya'] ?? 0,
    $resultado['correos_enviados'],
    PHP_EOL
));

exit(0);
