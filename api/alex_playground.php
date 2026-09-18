<?php
declare(strict_types=1);

// Playground LOCAL para probar a Alex de principio a fin sin tocar WhatsApp real: en vez de
// llamar a aiRunAssistantTurn() directo, este endpoint hace las MISMAS peticiones HTTP que
// hace el puente Node.js/Baileys en el VPS -- POST a api/whatsapp_webhook.php con el payload
// exacto del puente, espera un delay (configurable, simula el de 60-120s real) y despues
// POST a api/whatsapp_confirmar_envio.php, exactamente como /opt/wa-bridge/app/index.js.
// Asi se prueba el mismo codigo que corre en produccion (incluyendo el token, el parseo de
// JSON, y la condicion de carrera que arregla aiConfirmarEnvioWhatsapp()), no un atajo.
//
// NUNCA debe existir en produccion (ver el bloqueo IS_PRODUCTION abajo) y todo wa_id que
// acepta debe empezar con AI_PLAYGROUND_WA_PREFIX ("000...", nunca letras -- waParseBridgePayload()
// le quita cualquier caracter no numerico a sender_phone) -- nunca puede tocar una
// conversacion real por accidente aunque el llamador se equivoque.
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/ai_assistant.php';

header('Content-Type: application/json');

if (IS_PRODUCTION) {
    http_response_code(404);
    exit;
}

refreshSessionPermissions();
if (!isAuthenticated() || (!hasPermission('gestionar_asistente_ia') && !isAdmin())) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No autorizado.']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metodo no permitido.']);
    exit;
}

$data = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'JSON invalido.']);
    exit;
}

if (!validateCsrfToken((string)($data['csrf_token'] ?? ''))) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Token de seguridad invalido, recarga la pagina.']);
    exit;
}

$waId = trim((string)($data['wa_id'] ?? ''));
if ($waId === '' || !aiEsConversacionDePrueba($waId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'wa_id invalido: debe empezar con ' . AI_PLAYGROUND_WA_PREFIX . '.']);
    exit;
}

function alexPlaygroundLoopbackUrl(string $rutaRelativa): string
{
    $https = (string)($_SERVER['HTTPS'] ?? '');
    $esquema = ($https !== '' && strtolower($https) !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');

    return $esquema . '://' . $host . BASE_URL . $rutaRelativa;
}

/**
 * @return array{ok:bool, http_code?:int, data?:array, message?:string}
 */
function alexPlaygroundPostJson(string $url, array $payload, string $token): array
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'X-Webhook-Token: ' . $token]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    // El puente real no tiene timeout aqui; DeepSeek + varias rondas de tool-calling pueden
    // tardar. 120s es generoso mientras seguimos en localhost.
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    $body = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        return ['ok' => false, 'message' => 'cURL: ' . $curlError];
    }

    $decoded = json_decode((string)$body, true);
    if (!is_array($decoded)) {
        return ['ok' => false, 'message' => "Respuesta no-JSON (HTTP {$httpCode}): " . substr((string)$body, 0, 300)];
    }

    return ['ok' => true, 'http_code' => $httpCode, 'data' => $decoded];
}

$pdo = getPDO();
$accion = (string)($data['accion'] ?? '');

if ($accion === 'estado') {
    $stmt = $pdo->prepare('SELECT id_conversacion, estado_bot FROM whatsapp_conversaciones WHERE wa_id = ?');
    $stmt->execute([$waId]);
    $conv = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($conv)) {
        echo json_encode(['success' => true, 'estado_bot' => 'activo', 'mensajes' => []]);
        exit;
    }

    $stmtMsgs = $pdo->prepare(
        "SELECT rol, contenido, enviado_whatsapp, creado_en FROM whatsapp_mensajes
         WHERE id_conversacion = ? AND rol IN ('user', 'assistant', 'humano') AND contenido IS NOT NULL AND contenido <> ''
         ORDER BY id_mensaje ASC"
    );
    $stmtMsgs->execute([(int)$conv['id_conversacion']]);
    echo json_encode([
        'success' => true,
        'estado_bot' => $conv['estado_bot'],
        'mensajes' => $stmtMsgs->fetchAll(PDO::FETCH_ASSOC) ?: [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($accion === 'reiniciar') {
    // Doble candado: ademas del chequeo de arriba, el WHERE repite el prefijo -- nunca debe
    // poder borrar una conversacion real aunque algo mas arriba fallara.
    $stmt = $pdo->prepare("SELECT id_conversacion FROM whatsapp_conversaciones WHERE wa_id = ? AND wa_id LIKE ?");
    $stmt->execute([$waId, AI_PLAYGROUND_WA_PREFIX . '%']);
    $idConversacion = $stmt->fetchColumn();
    if ($idConversacion) {
        $pdo->prepare('DELETE FROM whatsapp_mensajes WHERE id_conversacion = ?')->execute([(int)$idConversacion]);
        $pdo->prepare('DELETE FROM whatsapp_conversacion_etiquetas WHERE id_conversacion = ?')->execute([(int)$idConversacion]);
        $pdo->prepare('DELETE FROM whatsapp_conversaciones WHERE id_conversacion = ?')->execute([(int)$idConversacion]);
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($accion !== 'mensaje_cliente' && $accion !== 'mensaje_asesor') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Accion desconocida.']);
    exit;
}

$texto = trim((string)($data['texto'] ?? ''));
if ($texto === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Falta el texto del mensaje.']);
    exit;
}

$token = getEnvVar('WA_WEBHOOK_TOKEN');
if ($token === null || trim($token) === '') {
    echo json_encode(['success' => false, 'message' => 'WA_WEBHOOK_TOKEN no esta configurado localmente (ver core/app_secrets.qa.php).']);
    exit;
}

$esAsesor = $accion === 'mensaje_asesor';
$messageKind = $esAsesor ? 'text' : (trim((string)($data['message_kind'] ?? 'text')) ?: 'text');

$payloadPuente = [
    'sender_phone' => $waId,
    'message' => $texto,
    'message_kind' => $messageKind,
    'message_id' => 'TESTMSG-' . bin2hex(random_bytes(6)),
    'from_me' => $esAsesor,
    'profile_name' => $esAsesor ? '' : 'Tester Local',
    'sender_jid' => $waId . '@s.whatsapp.net',
];

$respuestaWebhook = alexPlaygroundPostJson(alexPlaygroundLoopbackUrl('api/whatsapp_webhook.php'), $payloadPuente, $token);
if (!$respuestaWebhook['ok']) {
    echo json_encode(['success' => false, 'message' => 'Fallo llamando a whatsapp_webhook.php: ' . $respuestaWebhook['message']]);
    exit;
}

if ($esAsesor) {
    $stmt = $pdo->prepare('SELECT estado_bot FROM whatsapp_conversaciones WHERE wa_id = ?');
    $stmt->execute([$waId]);
    echo json_encode(['success' => true, 'estado_bot' => $stmt->fetchColumn() ?: 'activo']);
    exit;
}

$replyParts = is_array($respuestaWebhook['data']['reply'] ?? null) ? $respuestaWebhook['data']['reply'] : [];
$delaySegundos = max(0, min(30, (int)($data['delay_segundos'] ?? 3)));

$resultado = [
    'success' => true,
    'reply' => $replyParts,
    'delay_segundos' => 0,
    'enviado' => true,
];

if ($replyParts !== []) {
    $idMensajeTexto = null;
    foreach ($replyParts as $parte) {
        if (($parte['type'] ?? '') === 'text' && isset($parte['id_mensaje'])) {
            $idMensajeTexto = (int)$parte['id_mensaje'];
            break;
        }
    }

    // Delay humanizado real del puente (60-120s), acortado aqui para poder iterar. Se hace
    // DESPUES de tener el id_mensaje (igual que el puente real) para que dé tiempo de mandar
    // "mensaje_asesor" desde otra pestaña MIENTRAS este request esta dormido, y reproducir a
    // proposito la condicion de carrera que arregla aiConfirmarEnvioWhatsapp().
    if ($delaySegundos > 0) {
        sleep($delaySegundos);
    }
    $resultado['delay_segundos'] = $delaySegundos;

    if ($idMensajeTexto !== null) {
        $respuestaConfirmar = alexPlaygroundPostJson(
            alexPlaygroundLoopbackUrl('api/whatsapp_confirmar_envio.php'),
            ['wa_id' => $waId, 'id_mensaje' => $idMensajeTexto],
            $token
        );
        if ($respuestaConfirmar['ok']) {
            $resultado['enviado'] = (bool)($respuestaConfirmar['data']['enviar'] ?? true);
        }
    }
}

echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
