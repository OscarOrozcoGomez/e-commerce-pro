<?php
declare(strict_types=1);

// Endpoint publico: el puente de WhatsApp lo llama justo antes de mandar de verdad una
// respuesta a WhatsApp, DESPUES del delay humanizado de 60-120s que aplica a proposito para
// no verse como un bot (ver enviarReplyParts()/messages.upsert en /opt/wa-bridge/app/index.js,
// codigo del puente, vive en el VPS, sin control de versiones en este repo). El re-chequeo de
// estado_bot que ya hace aiRunAssistantTurn() pasa ANTES de ese delay y no alcanza a cubrir
// una intervencion humana (un asesor escribiendo desde el celular) que ocurra mientras el
// mensaje ya generado esta esperando su turno de envio -- este endpoint es el segundo
// chequeo, justo a tiempo, que si la cubre. Ver aiConfirmarEnvioWhatsapp() para el detalle.
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/ai_assistant.php';
require_once __DIR__ . '/../core/whatsapp_helper.php';

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo no permitido.']);
    exit;
}

$expectedToken = getEnvVar('WA_WEBHOOK_TOKEN');
$providedToken = $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? null;

if (!waVerifyWebhookToken(is_string($providedToken) ? $providedToken : null, $expectedToken)) {
    error_log('WARNING: whatsapp_confirmar_envio rechazo POST por token invalido o WA_WEBHOOK_TOKEN no configurado.');
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No autorizado.']);
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';

// El payload real son dos campos chicos (wa_id + id_mensaje); 4KB es de sobra y evita
// depender solo de post_max_size ante un cliente que mande basura.
if (waPayloadTooLarge($rawBody, 4096)) {
    http_response_code(413);
    echo json_encode(['success' => false, 'message' => 'Payload demasiado grande.']);
    exit;
}

$payload = json_decode($rawBody, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'JSON invalido.']);
    exit;
}

$inbound = waParseConfirmarEnvioPayload($payload);
if ($inbound === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Faltan o son invalidos "wa_id"/"id_mensaje".']);
    exit;
}

try {
    $enviar = aiConfirmarEnvioWhatsapp(getPDO(), $inbound['wa_id'], $inbound['id_mensaje']);
    echo json_encode(['success' => true, 'enviar' => $enviar]);
} catch (Throwable $e) {
    error_log('ERROR en whatsapp_confirmar_envio: ' . $e->getMessage());

    // Ante cualquier falla (BD caida, etc.) el default es NO mandar. Un mensaje que se queda
    // sin enviar por un error aqui es recuperable (el cliente puede volver a escribir, o un
    // asesor ya esta viendo la conversacion); uno que se manda encima de un asesor en vivo
    // durante una falla no se puede deshacer y es justo el patron que puso la cuenta de
    // WhatsApp en revision el 2026-09-13 (ver CLAUDE.md).
    //
    // Se intenta ademas marcar el mensaje como no enviado (best-effort, ya estamos en el
    // catch de un error): si aiConfirmarEnvioWhatsapp() truena DESPUES de validar el mensaje
    // pero antes de decidir, el insert optimista original lo dejo en enviado_whatsapp=1 --
    // sin este ajuste, aiLoadConversationHistory() no lo distinguiria de un mensaje que si
    // se mando, y Alex "recordaria" haber dicho algo que el puente nunca llego a enviar.
    try {
        aiMarcarMensajeNoEnviado(getPDO(), $inbound['id_mensaje']);
    } catch (Throwable $e2) {
        error_log('ERROR adicional marcando no-enviado tras fallo de whatsapp_confirmar_envio: ' . $e2->getMessage());
    }

    http_response_code(200);
    echo json_encode(['success' => true, 'enviar' => false]);
}
