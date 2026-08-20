<?php
declare(strict_types=1);

// Endpoint publico: el puente Node.js llama aqui UNA SOLA VEZ, al conectarse por primera
// vez, para volcar el historial completo de WhatsApp (evento messaging-history.set de
// Baileys) en whatsapp_historial_importado. Mismo modelo de seguridad que los otros
// endpoints del puente: token compartido en el header X-Webhook-Token.
require_once __DIR__ . '/../../core/config.php';
require_once __DIR__ . '/../../core/ai_assistant.php';
require_once __DIR__ . '/../../core/whatsapp_helper.php';

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo no permitido.']);
    exit;
}

$expectedToken = getEnvVar('WA_WEBHOOK_TOKEN');
$providedToken = $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? null;

if (!waVerifyWebhookToken(is_string($providedToken) ? $providedToken : null, $expectedToken)) {
    error_log('WARNING: import_history rechazo POST por token invalido o WA_WEBHOOK_TOKEN no configurado.');
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No autorizado.']);
    exit;
}

$rawBody = file_get_contents('php://input') ?: '';

// El historial completo puede ser mucho mas grande que un lote de etiquetas; el puente
// debe paginar en varios POST, pero igual se mantiene un techo defensivo por peticion.
if (waPayloadTooLarge($rawBody, 2097152)) {
    http_response_code(413);
    echo json_encode(['success' => false, 'message' => 'Payload demasiado grande. Manda el historial en lotes mas pequenos.']);
    exit;
}

$payload = json_decode($rawBody, true);

if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'JSON invalido.']);
    exit;
}

$mensajes = waParseHistoryImportPayload($payload);
if ($mensajes === null) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Falta el arreglo "messages".']);
    exit;
}

$lote = waExtractScalarString($payload, 'lote') ?? date('Y-m-d\TH:i:s\Z');

try {
    $pdo = getPDO();
    $importados = aiStoreHistoryImportBatch($pdo, $mensajes, $lote);

    echo json_encode(['success' => true, 'importados' => $importados]);
} catch (Throwable $e) {
    error_log('ERROR en import_history: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'No se pudo importar el historial.']);
}
