<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/ai_assistant.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo se puede ejecutar por CLI (cron). Uso: C:\\xampp\\php\\php.exe scripts/whatsapp_followup_cron.php [--dry-run]" . PHP_EOL);
    exit(1);
}

$options = getopt('', ['dry-run']);
$isDryRun = array_key_exists('dry-run', $options);

$pdo = getPDO();

$resueltas = 0;
$cerradasPorInactividad = 0;
$seguimientosEnviados = 0;
$seguimientosFallidos = 0;

// 1) Conversaciones que ya tienen un seguimiento enviado: si el cliente contesto, se
//    limpia la marca (vuelve al flujo normal); si no y ya pasaron 48h desde el primer
//    mensaje, se cierra y se etiqueta "Preguntón" para no seguir insistiendo.
foreach (aiFindConversationsAwaitingFollowupReply($pdo) as $conversacion) {
    $idConversacion = (int) $conversacion['id_conversacion'];

    if (aiCustomerRepliedAfterFollowup($pdo, $idConversacion)) {
        if (!$isDryRun) {
            aiClearFollowupFlag($pdo, $idConversacion);
        }
        $resueltas++;
        continue;
    }

    $horasDesdePrimerMensaje = aiHoursSinceFirstMessage($pdo, $idConversacion);
    if ($horasDesdePrimerMensaje !== null && $horasDesdePrimerMensaje >= AI_FOLLOWUP_CLOSE_HOURS) {
        if (!$isDryRun) {
            aiCloseUnresponsiveConversation($pdo, $idConversacion);
        }
        $cerradasPorInactividad++;
    }
}

// 2) Conversaciones activas sin seguimiento, con mas de 24h desde la ultima respuesta del bot.
foreach (aiFindConversationsNeedingFollowup($pdo) as $conversacion) {
    if ($isDryRun) {
        $seguimientosEnviados++;
        continue;
    }

    $ok = aiSendFollowupMessage($pdo, $conversacion);
    if ($ok) {
        $seguimientosEnviados++;
    } else {
        $seguimientosFallidos++;
    }
}

fwrite(
    STDOUT,
    sprintf(
        "RUN %s | dry-run=%s | seguimientos_enviados=%d | seguimientos_fallidos=%d | resueltas=%d | cerradas_por_inactividad=%d%s",
        date('Y-m-d H:i:s'),
        $isDryRun ? 'yes' : 'no',
        $seguimientosEnviados,
        $seguimientosFallidos,
        $resueltas,
        $cerradasPorInactividad,
        PHP_EOL
    )
);

exit(0);
