<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/lote_ocr_utils.php';

header('Content-Type: application/json');

// Refresca permisos por si se revocaron/concedieron desde el panel hace poco.
refreshSessionPermissions();
if (!isAuthenticated() || (!hasPermission('gestionar_caducidades') && !isAdmin() && !isEncargado())) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Método no permitido']);
    exit;
}

if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
    echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido']);
    exit;
}

$imagen = (string) ($_POST['imagen'] ?? '');
// El navegador manda un data URL ("data:image/jpeg;base64,...."); Vision solo quiere el base64.
if (preg_match('/^data:image\/\w+;base64,/', $imagen)) {
    $imagen = substr($imagen, (int) strpos($imagen, ',') + 1);
}
$imagen = trim($imagen);

if ($imagen === '') {
    echo json_encode(['success' => false, 'message' => 'No se recibió ninguna imagen']);
    exit;
}

// ~8 MB de base64 es de sobra: la foto ya se reduce en el navegador antes de mandarse.
if (strlen($imagen) > 8 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'La foto es demasiado grande']);
    exit;
}

try {
    $resultado = loteOcrProcesarImagen($imagen, GOOGLE_VISION_API_KEY);
    echo json_encode($resultado, JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('lote_ocr: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error al procesar la imagen']);
}
