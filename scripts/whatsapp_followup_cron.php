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

// El interruptor general del asistente (el mismo toggle del dashboard) tambien detiene
// el cron por completo: si Alex esta apagado, no tiene sentido seguir mandando
// seguimientos proactivos ni cerrando/etiquetando conversaciones por su cuenta.
if (!aiIsAssistantGloballyActive($pdo)) {
    fwrite(STDOUT, sprintf('RUN %s | asistente desactivado globalmente, no se ejecuta nada.%s', date('Y-m-d H:i:s'), PHP_EOL));
    exit(0);
}

$resueltas = 0;
$cerradasPorInactividad = 0;
$seguimientosEnviados = 0;
$seguimientosFallidos = 0;
$reactivadasPorInactividad = 0;

// 0) Conversaciones pausadas por intervencion humana (o transferir_a_humano) donde nadie
//    -- ni cliente ni asesor -- volvio a escribir en 24h: Alex retoma solo para no dejar
//    al cliente sin atencion de forma indefinida si el asesor se olvido de reactivarla.
foreach (aiFindConversationsToAutoReactivate($pdo) as $conversacion) {
    if (!$isDryRun) {
        aiAutoReactivateConversation($pdo, (int) $conversacion['id_conversacion']);
    }
    $reactivadasPorInactividad++;
}

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

// 2+3) Mensajes PROACTIVOS de Alex -- el cliente NO escribio primero. Dos origenes
//      posibles: seguimiento de 24h (reenganchar a alguien que se quedo callado, ver
//      aiFindConversationsNeedingFollowup()) o catch-up de horario (contestar a alguien
//      que sigue esperando una respuesta desde fuera del horario de atencion, ver
//      aiFindConversationsPendingRespuesta()).
//
// Decision del negocio (2026-09-14, tras el incidente 2026-09-13): jamas mas de UN
// mensaje proactivo combinado por hora -- nunca "N por corrida" como en la version
// anterior de este mismo mecanismo (esa fue, sin tope, la causa real del bloqueo de
// WhatsApp). El catch-up tiene prioridad sobre el seguimiento (alguien esperando una
// respuesta real pesa mas que un recordatorio); si no hay ninguno de los dos pendiente,
// no se manda nada. Si el backlog no se alcanza a vaciar en el dia, sigue al dia
// siguiente sin problema -- ver aiPuedeEnviarProactivoAhora()/aiRegistrarEnvioProactivo().
$retomadas = 0;
$retomadasFallidas = 0;

if ($isDryRun ? aiEstaEnHorarioAtencion() : aiPuedeEnviarProactivoAhora($pdo)) {
    $pendienteCatchup = aiFindConversationsPendingRespuesta($pdo)[0] ?? null;
    $pendienteSeguimiento = $pendienteCatchup === null ? (aiFindConversationsNeedingFollowup($pdo)[0] ?? null) : null;

    if ($isDryRun) {
        if ($pendienteCatchup !== null) {
            $retomadas++;
        } elseif ($pendienteSeguimiento !== null) {
            $seguimientosEnviados++;
        }
    } elseif ($pendienteCatchup !== null) {
        $replyParts = aiRetomarConversacionPendiente($pdo, $pendienteCatchup);
        if (!empty($replyParts)) {
            $resultado = waSendOutboundMessage((string) $pendienteCatchup['wa_id'], $replyParts);
            aiRegistrarEnvioProactivo($pdo);
            if (!empty($resultado['ok'])) {
                $retomadas++;
            } else {
                $retomadasFallidas++;
            }
        }
    } elseif ($pendienteSeguimiento !== null) {
        $ok = aiSendFollowupMessage($pdo, $pendienteSeguimiento);
        aiRegistrarEnvioProactivo($pdo);
        if ($ok) {
            $seguimientosEnviados++;
        } else {
            $seguimientosFallidos++;
        }
    }
}

fwrite(
    STDOUT,
    sprintf(
        "RUN %s | dry-run=%s | reactivadas_por_inactividad=%d | seguimientos_enviados=%d | seguimientos_fallidos=%d | resueltas=%d | cerradas_por_inactividad=%d | retomadas=%d | retomadas_fallidas=%d%s",
        date('Y-m-d H:i:s'),
        $isDryRun ? 'yes' : 'no',
        $reactivadasPorInactividad,
        $seguimientosEnviados,
        $seguimientosFallidos,
        $resueltas,
        $cerradasPorInactividad,
        $retomadas,
        $retomadasFallidas,
        PHP_EOL
    )
);

exit(0);
