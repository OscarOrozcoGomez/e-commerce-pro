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

// 2) Conversaciones activas sin seguimiento, con mas de 24h desde la ultima respuesta del bot.
//
// Nunca mas de AI_FOLLOWUP_MAX_ENVIOS_POR_CORRIDA envios reales por corrida, con una pausa
// aleatoria entre cada uno -- ver el comentario junto a esas constantes en ai_assistant.php
// (incidente real: una rafaga sin pausa aqui puso la cuenta de WhatsApp en revision). El
// resto del backlog, si lo hay, se manda en la siguiente corrida (20 min despues).
$enviosRealesEnEstaCorrida = 0;
foreach (aiFindConversationsNeedingFollowup($pdo) as $conversacion) {
    if ($isDryRun) {
        $seguimientosEnviados++;
        continue;
    }

    if ($enviosRealesEnEstaCorrida >= AI_FOLLOWUP_MAX_ENVIOS_POR_CORRIDA) {
        break;
    }

    if ($enviosRealesEnEstaCorrida > 0) {
        sleep(random_int(AI_FOLLOWUP_PAUSA_MIN_SEGUNDOS, AI_FOLLOWUP_PAUSA_MAX_SEGUNDOS));
    }

    $ok = aiSendFollowupMessage($pdo, $conversacion);
    $enviosRealesEnEstaCorrida++;
    if ($ok) {
        $seguimientosEnviados++;
    } else {
        $seguimientosFallidos++;
    }
}

// 3) Conversaciones "atoradas": el ultimo mensaje es del cliente y nadie -- ni Alex ni un
//    humano -- las contesto (tipico: llegaron fuera del horario de atencion de Alex, ver
//    AI_HORARIO_ATENCION_* / aiEstaEnHorarioAtencion()). Si ahorita SI es horario de
//    atencion, se retoman con una respuesta real (DeepSeek, ya con todo lo que escribieron
//    en el contexto), nunca mas de AI_HORARIO_CATCHUP_MAX_POR_CORRIDA por corrida y con
//    pausa aleatoria entre cada una -- mismo principio anti-rafaga que el paso 2.
$retomadas = 0;
$retomadasFallidas = 0;
if (aiEstaEnHorarioAtencion()) {
    $catchupEnEstaCorrida = 0;
    foreach (aiFindConversationsPendingRespuesta($pdo) as $conversacion) {
        if ($isDryRun) {
            $retomadas++;
            continue;
        }

        if ($catchupEnEstaCorrida >= AI_HORARIO_CATCHUP_MAX_POR_CORRIDA) {
            break;
        }

        if ($catchupEnEstaCorrida > 0) {
            sleep(random_int(AI_HORARIO_CATCHUP_PAUSA_MIN_SEGUNDOS, AI_HORARIO_CATCHUP_PAUSA_MAX_SEGUNDOS));
        }

        $replyParts = aiRetomarConversacionPendiente($pdo, $conversacion);
        $catchupEnEstaCorrida++;

        if (empty($replyParts)) {
            continue; // la conversacion ya no calificaba (humano la atendio, bot apagado, etc.)
        }

        $resultado = waSendOutboundMessage((string) $conversacion['wa_id'], $replyParts);
        if (!empty($resultado['ok'])) {
            $retomadas++;
        } else {
            $retomadasFallidas++;
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
