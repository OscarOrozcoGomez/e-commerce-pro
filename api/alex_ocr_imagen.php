<?php
declare(strict_types=1);

// Endpoint servidor-a-servidor: el puente de WhatsApp (VPS) manda aqui la foto que recibio de un
// cliente y recibe el texto leido con Google Vision (TEXT_DETECTION), que rinde mucho mejor que
// Tesseract con letras decorativas sobre un frasco curvo y con reflejos (caso real 2026-09-20:
// Tesseract solo leyo "SUPLEMENTO ALIMENTICIO" y perdio "WOMENS MULT MATUR3"). Si esto falla o
// no devuelve texto, el puente debe seguir con su OCR de siempre -- ver docs/alex_ocr_vision_puente.md.
//
// Sin sesion: el control de acceso es el token compartido del puente en el header X-Webhook-Token
// (WA_WEBHOOK_TOKEN, comparado con hash_equals), igual que api/whatsapp_webhook.php; nunca por ?token=.
// No escribe nada en la BD ni manda nada a WhatsApp: solo lee la imagen y devuelve texto.
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/whatsapp_helper.php';
require_once __DIR__ . '/../core/api_security_utils.php';
require_once __DIR__ . '/../core/google_secret_manager.php';
require_once __DIR__ . '/../core/ai_foto_producto_utils.php';

header('Content-Type: application/json');

apiRequerirMetodo('POST');

$providedToken = $_SERVER['HTTP_X_WEBHOOK_TOKEN'] ?? null;
if (!waVerifyWebhookToken(is_string($providedToken) ? $providedToken : null, getEnvVar('WA_WEBHOOK_TOKEN'))) {
    error_log('WARNING: alex_ocr_imagen rechazo POST por token invalido o WA_WEBHOOK_TOKEN no configurado.');
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No autorizado.']);
    exit;
}

// Cada llamada cuesta una consulta a Google Vision: tope defensivo por minuto (el puente atiende
// una foto por mensaje, muy por debajo de esto).
apiLimitarPeticiones('alex_ocr_imagen', 60);

$rawBody = file_get_contents('php://input') ?: '';
// ~8 MB de base64 (~6 MB de foto) es de sobra: WhatsApp ya comprime las fotos.
if (strlen($rawBody) > 8 * 1024 * 1024 + 4096) {
    http_response_code(413);
    echo json_encode(['success' => false, 'message' => 'La foto es demasiado grande.']);
    exit;
}

$payload = json_decode($rawBody, true);
$imagenBase64 = is_array($payload) ? ($payload['imagen_base64'] ?? null) : null;
if (!is_string($imagenBase64) || trim($imagenBase64) === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Falta imagen_base64.']);
    exit;
}

// Acepta tambien un data URI ("data:image/jpeg;base64,....").
if (str_starts_with($imagenBase64, 'data:')) {
    $imagenBase64 = substr($imagenBase64, (int) strpos($imagenBase64, ',') + 1);
}
$imagenBase64 = trim($imagenBase64);

$bytes = base64_decode($imagenBase64, true);
$info = $bytes !== false ? @getimagesizefromstring($bytes) : false;
if ($bytes === false || $info === false || !in_array($info[2] ?? 0, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'La imagen no es valida (se espera JPEG, PNG, WebP o GIF en base64).']);
    exit;
}

try {
    $resultado = aiFotoVisionDetectarTexto($imagenBase64, GOOGLE_VISION_API_KEY);
} catch (Throwable $e) {
    error_log('alex_ocr_imagen: ' . $e->getMessage());
    http_response_code(502);
    echo json_encode(['success' => false, 'message' => 'Error al leer la imagen.']);
    exit;
}

if (!$resultado['ok']) {
    // Sin texto legible NO es un error del servidor: el puente cae a su OCR de siempre.
    error_log('alex_ocr_imagen: ' . $resultado['error']);
    echo json_encode(['success' => false, 'message' => $resultado['error']], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['success' => true, 'texto' => $resultado['texto'], 'fuente' => 'google_vision'], JSON_UNESCAPED_UNICODE);
