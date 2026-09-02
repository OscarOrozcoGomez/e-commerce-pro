<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/google_secret_manager.php';
require_once __DIR__ . '/../core/ai_assistant.php';
require_once __DIR__ . '/../core/lote_ocr_utils.php';

header('Content-Type: application/json');

if (!isAuthenticated() || (!isAdmin() && !isEncargado())) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

if (!validateCsrfToken((string) ($_POST['csrf_token'] ?? ''))) {
    echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido']);
    exit;
}

/**
 * Valida y guarda una imagen subida. Devuelve la ruta local o null.
 */
function loteOcrGuardarSubida(string $campo): ?string
{
    if (!isset($_FILES[$campo]) || ($_FILES[$campo]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    $file = $_FILES[$campo];

    if ((int) ($file['size'] ?? 0) > 8 * 1024 * 1024) {
        throw new RuntimeException('La imagen excede 8MB.');
    }
    $info = @getimagesize($file['tmp_name']);
    $mimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!$info || !isset($mimes[$info['mime'] ?? ''])) {
        throw new RuntimeException('La imagen debe ser JPG, PNG o WEBP.');
    }

    $dir = rtrim(dirname(__DIR__), '/\\') . '/assets/img/lotes/';
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('No se pudo crear la carpeta de destino.');
    }
    $nombre = date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.' . $mimes[$info['mime']];
    $destino = $dir . $nombre;
    if (!move_uploaded_file($file['tmp_name'], $destino)) {
        throw new RuntimeException('No se pudo guardar la imagen.');
    }

    return $destino;
}

try {
    $rutaLote = loteOcrGuardarSubida('foto_lote');
    $rutaTabla = loteOcrGuardarSubida('foto_tabla');

    if ($rutaLote === null && $rutaTabla === null) {
        echo json_encode(['success' => false, 'message' => 'No se recibió ninguna imagen.']);
        exit;
    }

    $fotoRelativa = $rutaLote !== null
        ? 'assets/img/lotes/' . basename($rutaLote)
        : ($rutaTabla !== null ? 'assets/img/lotes/' . basename($rutaTabla) : null);

    if (!loteOcrHabilitado()) {
        echo json_encode([
            'success' => false,
            'message' => 'El lector automático está desactivado. Captura los datos manualmente.',
            'foto' => $fotoRelativa,
        ]);
        exit;
    }

    $rutas = [];
    if ($rutaLote !== null) {
        $rutas['lote'] = $rutaLote;
    }
    if ($rutaTabla !== null) {
        $rutas['tabla'] = $rutaTabla;
    }

    $datos = loteOcrProcesar($rutas);
    $datos['foto'] = $fotoRelativa;

    echo json_encode(['success' => true, 'data' => $datos], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('lote_ocr: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'No se pudo leer la imagen: ' . $e->getMessage() . '. Captura los datos manualmente.',
        'foto' => $fotoRelativa ?? null,
    ]);
}
