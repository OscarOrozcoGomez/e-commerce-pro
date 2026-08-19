<?php
declare(strict_types=1);

// Endpoint publico: el puente Node.js llama aqui cuando sincroniza las etiquetas nativas
// de WhatsApp Business (getLabels()/App State Sync) para reflejarlas en whatsapp_etiquetas.
// Mismo modelo de seguridad que api/whatsapp_webhook.php: token compartido en el header
// X-Webhook-Token (el puente es un servidor propio, no un proveedor externo con firma).
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
    error_log('WARNING: sync_labels rechazo POST por token invalido o WA_WEBHOOK_TOKEN no configurado.');
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

$labels = waParseLabelsSyncPayload($payload);
if ($labels === null) {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'Falta el arreglo "labels".']);
    exit;
}

try {
    $pdo = getPDO();
    $sincronizadas = 0;
    foreach ($labels as $label) {
        aiUpsertWhatsAppLabel($pdo, $label['id_etiqueta_wa'], $label['nombre'], $label['color'] !== '' ? $label['color'] : null);
        $sincronizadas++;
    }

    echo json_encode(['success' => true, 'sincronizadas' => $sincronizadas]);
} catch (Throwable $e) {
    error_log('ERROR en sync_labels: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'No se pudieron sincronizar las etiquetas.']);
}
