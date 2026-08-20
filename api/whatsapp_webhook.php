<?php
declare(strict_types=1);

// Endpoint publico: el puente propio de DigitalOcean (Node.js + Baileys) llama aqui
// directamente, por eso NO usa requireAuth(). El flujo es SINCRONO: el puente hace POST
// del mensaje entrante y espera la respuesta en la misma llamada HTTP para reenviarla
// el mismo a WhatsApp (no hay endpoint de "enviar" que este archivo tenga que llamar).
// Control de acceso: token compartido en el header X-Webhook-Token, validado contra
// WA_WEBHOOK_TOKEN (el puente es un servidor propio, no un proveedor externo con firma
// criptografica como Meta, asi que este token es la barrera real).
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
    error_log('WARNING: whatsapp_webhook rechazo POST por token invalido o WA_WEBHOOK_TOKEN no configurado.');
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No autorizado.']);
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';

if (waPayloadTooLarge($rawBody)) {
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

$inbound = waParseBridgePayload($payload);
if ($inbound === null) {
    // Sin telefono o sin texto: no hay nada que procesar, pero se responde 200 para
    // que el puente no lo reintente indefinidamente.
    echo json_encode(['success' => true, 'reply' => []]);
    exit;
}

try {
    if ($inbound['from_me']) {
        // Un asesor/repartidor escribio directamente desde el celular: no se llama a la
        // IA, solo se registra y se pausa el bot para ese chat. Nada que reenviar a WhatsApp,
        // el mensaje ya lo mando el humano.
        aiHandleHumanOutboundMessage(getPDO(), $inbound['wa_id'], $inbound['texto'], $inbound['wa_message_id']);
        echo json_encode(['success' => true, 'reply' => []]);
        exit;
    }

    $replyParts = aiRunAssistantTurn($inbound['wa_id'], null, $inbound['texto'], $inbound['wa_message_id']);
    echo json_encode(['success' => true, 'reply' => $replyParts], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    // Nunca se exponen detalles internos en la respuesta; solo se registran en el log del servidor.
    error_log('ERROR en whatsapp_webhook: ' . $e->getMessage());

    // El texto que se le muestra al cliente promete que un companero lo va a contactar,
    // asi que aqui se deja el chat realmente pausado y marcado para el equipo (best-effort:
    // si hasta esto falla -p.ej. la BD esta caida- no debe tumbar la respuesta al cliente).
    try {
        $pdoFallback = getPDO();
        $conversacionFallback = aiGetOrCreateConversation($pdoFallback, $inbound['wa_id'], null);
        $idConversacionFallback = (int)$conversacionFallback['id_conversacion'];
        aiLogDiagnosticError($pdoFallback, $idConversacionFallback, 'webhook_excepcion', $inbound['texto'], ['excepcion' => $e->getMessage()]);
        aiSetConversationState($pdoFallback, $idConversacionFallback, 'pausado', 'Fallo tecnico en el webhook: ' . $e->getMessage());
    } catch (Throwable $eFallback) {
        error_log('ERROR adicional al intentar pausar/notificar tras fallo de webhook: ' . $eFallback->getMessage());
    }

    echo json_encode([
        'success' => false,
        'reply' => [['type' => 'text', 'text' => 'Dame un segundo, te transfiero con un companero del equipo para que te de el detalle exacto de inmediato.']],
    ], JSON_UNESCAPED_UNICODE);
}
