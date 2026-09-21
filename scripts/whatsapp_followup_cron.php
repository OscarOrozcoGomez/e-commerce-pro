<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/ai_assistant.php';
require_once __DIR__ . '/../core/alex_recompra_utils.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script solo se puede ejecutar por CLI (cron). Uso: C:\\xampp\\php\\php.exe scripts/whatsapp_followup_cron.php [--dry-run]" . PHP_EOL);
    exit(1);
}

// --recompra-ofertas: activa el paso 4 (recompra proactiva de productos por caducar, ver
// core/alex_recompra_utils.php). Va APAGADO por defecto porque es contacto no solicitado: hay que
// agregar la bandera al crontab del VPS a proposito.
$options = getopt('', ['dry-run', 'recompra-ofertas']);
$isDryRun = array_key_exists('dry-run', $options);
$recompraActiva = array_key_exists('recompra-ofertas', $options);

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
$recompras = 0;
$recomprasFallidas = 0;

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

// 2) Catch-up de horario: contestar, con retraso, a alguien que YA escribio mientras Alex
//    estaba callado por politica (fuera de horario, ver aiEstaEnHorarioAtencion()). No es
//    contacto no solicitado -- por eso tiene su propio cupo (aiPuedeResponderCatchupAhora()),
//    independiente del seguimiento de 24h (aclaracion del negocio, 2026-09-18: antes un
//    cliente nuevo de madrugada podia esperar hasta 2 horas su primera respuesta si el cupo
//    compartido ya lo habia usado un seguimiento). Cadencia propia de ~5 min entre cada uno
//    (ver AI_CATCHUP_INTERVALO_MIN_MINUTOS) -- rapido para no dejar esperando al backlog de
//    la noche, pero nunca instantaneo para todos a la vez.
//
// "Independiente" es solo la CADENCIA de cada uno entre corridas -- dentro de UNA misma
// corrida se sigue mandando como maximo un mensaje real, igual que antes de separar los
// cupos: si esta corrida ya intento un catch-up (haya tenido pendiente o no antes del envio),
// el seguimiento de 24h se deja para la siguiente corrida en la que el catch-up no tenga
// nada que hacer. Eso evita el caso donde, justo al abrir el horario, ambos cupos esten
// libres a la vez y se manden 2 mensajes reales (a 2 clientes distintos) en la misma
// ejecucion del script.
$retomadas = 0;
$retomadasFallidas = 0;
$huboCatchupEnEstaCorrida = false;
$huboSeguimientoEnEstaCorrida = false;

if ($isDryRun ? aiEstaEnHorarioAtencion() : aiPuedeResponderCatchupAhora($pdo)) {
    $pendienteCatchup = aiFindConversationsPendingRespuesta($pdo)[0] ?? null;

    if ($pendienteCatchup !== null) {
        $huboCatchupEnEstaCorrida = true;
    }

    if ($isDryRun) {
        if ($pendienteCatchup !== null) {
            $retomadas++;
        }
    } elseif ($pendienteCatchup !== null) {
        $replyParts = aiRetomarConversacionPendiente($pdo, $pendienteCatchup);
        if (!empty($replyParts)) {
            $resultado = waSendOutboundMessage((string) $pendienteCatchup['wa_id'], $replyParts);
            aiRegistrarEnvioCatchup($pdo);
            if (!empty($resultado['ok'])) {
                $retomadas++;
            } else {
                $retomadasFallidas++;
            }
        }
    }
}

// 3) Seguimiento de 24h: Alex reenganchando SIN que el cliente haya escrito primero, para
//    intentar rescatar una venta que se quedo a medias (ver aiFindConversationsNeedingFollowup()).
//    Esto SI es contacto no solicitado -- se queda con el tope estricto de 1/hora (decision
//    del negocio 2026-09-14, tras el incidente 2026-09-13) para no parecer una campaña
//    automatizada. Si el backlog no se alcanza a vaciar en el dia, sigue al dia siguiente
//    sin problema -- ver aiPuedeEnviarProactivoAhora()/aiRegistrarEnvioProactivo().
if (!$huboCatchupEnEstaCorrida && ($isDryRun ? aiEstaEnHorarioAtencion() : aiPuedeEnviarProactivoAhora($pdo))) {
    $pendienteSeguimiento = aiFindConversationsNeedingFollowup($pdo)[0] ?? null;
    if ($pendienteSeguimiento !== null) {
        $huboSeguimientoEnEstaCorrida = true;
    }

    if ($isDryRun) {
        if ($pendienteSeguimiento !== null) {
            $seguimientosEnviados++;
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

// 4) Recompra proactiva de productos por caducar (solo con --recompra-ofertas). Tambien es contacto
//    no solicitado, asi que comparte EL MISMO cupo de 1/hora del seguimiento de 24h
//    (aiPuedeEnviarRecompraAhora() lo consulta) y suma un tope diario propio. Solo si esta corrida
//    no mando ya otro mensaje real (catch-up o seguimiento): nunca dos mensajes en una ejecucion.
//    Si el modelo no genera un texto valido NO se manda nada (jamas un texto fijo de respaldo, ver
//    core/alex_recompra_utils.php) y se prueba con el siguiente candidato, hasta
//    AI_RECOMPRA_INTENTOS_POR_CORRIDA.
if ($recompraActiva && !$huboCatchupEnEstaCorrida && !$huboSeguimientoEnEstaCorrida
    && ($isDryRun ? aiRecompraEnHorario() : aiPuedeEnviarRecompraAhora($pdo))) {
    $candidatos = array_slice(aiFindRecompraCandidatos($pdo), 0, AI_RECOMPRA_INTENTOS_POR_CORRIDA);

    if ($isDryRun) {
        if ($candidatos !== []) {
            $recompras++;
        }
    } else {
        $config = aiGetConfig($pdo);
        foreach ($candidatos as $candidato) {
            $texto = aiGenerarTextoRecompraUnico($pdo, $candidato, $config);
            if ($texto === '') {
                continue;
            }
            $ok = aiSendRecompraMessage($pdo, $candidato, $texto);
            aiRegistrarEnvioProactivo($pdo);
            if ($ok) {
                $recompras++;
            } else {
                $recomprasFallidas++;
            }
            break; // uno por corrida, siempre
        }
    }
}

fwrite(
    STDOUT,
    sprintf(
        "RUN %s | dry-run=%s | reactivadas_por_inactividad=%d | seguimientos_enviados=%d | seguimientos_fallidos=%d | resueltas=%d | cerradas_por_inactividad=%d | retomadas=%d | retomadas_fallidas=%d | recompras=%d | recompras_fallidas=%d%s",
        date('Y-m-d H:i:s'),
        $isDryRun ? 'yes' : 'no',
        $reactivadasPorInactividad,
        $seguimientosEnviados,
        $seguimientosFallidos,
        $resueltas,
        $cerradasPorInactividad,
        $retomadas,
        $retomadasFallidas,
        $recompras,
        $recomprasFallidas,
        PHP_EOL
    )
);

exit(0);
