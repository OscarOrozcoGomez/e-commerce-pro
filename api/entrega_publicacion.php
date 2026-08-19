<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/social_post_utils.php';

header('Content-Type: application/json');

if (!isAuthenticated()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autenticado.']);
    exit;
}

if (!isAdmin() && !isRepartidor()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado.']);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo no permitido.']);
    exit;
}

/**
 * @param array<string, mixed> $payload
 */
function epJsonResponse(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function epDecryptIfNeeded(?string $value): string
{
    $value = (string)$value;
    if ($value !== '' && function_exists('piiIsEncryptedValue') && function_exists('piiDecryptValue') && piiIsEncryptedValue($value)) {
        $value = (string)piiDecryptValue($value);
    }
    return trim($value);
}

$pdo = getPDO();
$usuario = $_SESSION['usuario'];
$esAdmin = isAdmin();

/**
 * Arma la expresion SQL para la direccion de entrega detectando en caliente que columnas/tablas
 * existen (igual que views/entregas.php y views/asignar_entregas.php), ya que no todos los
 * despliegues tienen 'clientes.direccion' ni 'pedidos.direccion_entrega'.
 */
function epBuildDireccionExpr(PDO $pdo): string
{
    static $expr = null;
    if ($expr !== null) {
        return $expr;
    }

    $hasClientesDireccion = ((int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'direccion'"
    )->fetchColumn()) > 0;
    $hasClienteDireccionesTable = ((int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cliente_direcciones'"
    )->fetchColumn()) > 0;
    $hasPedidosDireccionEntrega = ((int)$pdo->query(
        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME = 'direccion_entrega'"
    )->fetchColumn()) > 0;

    $fallback = $hasClientesDireccion && $hasClienteDireccionesTable
        ? "COALESCE(c.direccion, (SELECT cd.direccion FROM cliente_direcciones cd WHERE cd.id_cliente = c.id_cliente ORDER BY cd.es_default DESC, cd.id_direccion ASC LIMIT 1))"
        : ($hasClientesDireccion
            ? 'c.direccion'
            : ($hasClienteDireccionesTable
                ? "(SELECT cd.direccion FROM cliente_direcciones cd WHERE cd.id_cliente = c.id_cliente ORDER BY cd.es_default DESC, cd.id_direccion ASC LIMIT 1)"
                : 'NULL'));

    $expr = $hasPedidosDireccionEntrega
        ? "COALESCE(NULLIF(TRIM(p.direccion_entrega), ''), {$fallback})"
        : $fallback;

    return $expr;
}

/**
 * Confirma que el pedido esta entregado y pertenece al repartidor (o que el usuario es admin).
 * Devuelve la fila del pedido (incluyendo direccion ya descifrada) o null si no aplica.
 *
 * @return array<string, mixed>|null
 */
function epFindPedidoEntregado(PDO $pdo, int $idPedido, int $idRepartidor, bool $esAdmin): ?array
{
    $direccionExpr = epBuildDireccionExpr($pdo);
    $stmt = $pdo->prepare(
        "SELECT p.id_pedido, p.id_repartidor, p.estado, {$direccionExpr} AS direccion
         FROM pedidos p
         LEFT JOIN clientes c ON p.id_cliente = c.id_cliente
         WHERE p.id_pedido = :id_pedido
         LIMIT 1"
    );
    $stmt->execute([':id_pedido' => $idPedido]);
    $pedido = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$pedido || (string)$pedido['estado'] !== 'entregado') {
        return null;
    }
    if (!$esAdmin && (int)$pedido['id_repartidor'] !== $idRepartidor) {
        return null;
    }

    $pedido['direccion'] = epDecryptIfNeeded($pedido['direccion'] ?? '');
    return $pedido;
}

$idUsuario = (int)($usuario['id_usuario'] ?? 0);

try {

// La subida de foto llega como multipart/form-data (no JSON), por eso se detecta aparte.
if (isset($_FILES['foto'])) {
    if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) {
        epJsonResponse(['success' => false, 'error' => 'Token CSRF invalido.'], 419);
    }

    $idPedido = (int)($_POST['id_pedido'] ?? 0);
    $pedido = epFindPedidoEntregado($pdo, $idPedido, $idUsuario, $esAdmin);
    if (!$pedido) {
        epJsonResponse(['success' => false, 'error' => 'Pedido no encontrado o no disponible para publicar.'], 404);
    }

    $foto = $_FILES['foto'];
    if (($foto['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        epJsonResponse(['success' => false, 'error' => 'Error al recibir la foto.'], 400);
    }

    $maxBytes = 8 * 1024 * 1024;
    if ((int)($foto['size'] ?? 0) > $maxBytes) {
        epJsonResponse(['success' => false, 'error' => 'La foto excede el tamano maximo de 8MB.'], 400);
    }

    $imageInfo = @getimagesize($foto['tmp_name']);
    $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $detectedMime = $imageInfo['mime'] ?? null;
    if (!$imageInfo || !isset($allowedMimes[$detectedMime])) {
        epJsonResponse(['success' => false, 'error' => 'La foto debe ser JPG, PNG o WEBP valida.'], 400);
    }
    $ext = $allowedMimes[$detectedMime];

    $baseDir = rtrim(dirname(__DIR__), '/\\') . '/assets/img/entregas/';
    $targetDir = $baseDir . $idPedido . '/';
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        epJsonResponse(['success' => false, 'error' => 'No se pudo crear la carpeta de destino.'], 500);
    }

    $fileName = bin2hex(random_bytes(8)) . '.' . $ext;
    $targetFile = $targetDir . $fileName;
    if (!move_uploaded_file($foto['tmp_name'], $targetFile)) {
        epJsonResponse(['success' => false, 'error' => 'No se pudo guardar la foto en el servidor.'], 500);
    }

    $rutaRelativa = $idPedido . '/' . $fileName;
    $colonia = extractColoniaFromAddress($pedido['direccion']);
    $texto = trim((string)($_POST['texto'] ?? '')) ?: buildDeliveryPostText($colonia);

    $stmtInsert = $pdo->prepare(
        "INSERT INTO pedido_publicaciones (id_pedido, id_repartidor, colonia_detectada, texto, ruta_foto)
         VALUES (:id_pedido, :id_repartidor, :colonia, :texto, :ruta_foto)"
    );
    $stmtInsert->execute([
        ':id_pedido' => $idPedido,
        ':id_repartidor' => (int)$pedido['id_repartidor'],
        ':colonia' => $colonia !== '' ? $colonia : null,
        ':texto' => $texto,
        ':ruta_foto' => $rutaRelativa,
    ]);

    logAudit('PEDIDO_PUBLICACION_FOTO_SUBIDA', 'pedido_publicaciones', (int)$pdo->lastInsertId(), 'Foto de entrega subida para pedido #' . $idPedido);

    epJsonResponse([
        'success' => true,
        'id_publicacion' => (int)$pdo->lastInsertId(),
        'foto_url' => BASE_URL . 'assets/img/entregas/' . $rutaRelativa,
        'colonia_detectada' => $colonia,
        'texto' => $texto,
    ]);
}

$raw = json_decode((string)file_get_contents('php://input'), true);
$payload = is_array($raw) ? $raw : [];

if (!validateCsrfToken((string)($payload['csrf_token'] ?? ''))) {
    epJsonResponse(['success' => false, 'error' => 'Token CSRF invalido.'], 419);
}

$action = (string)($payload['action'] ?? '');
$idPedido = (int)($payload['id_pedido'] ?? 0);

if ($action === 'preparar') {
    $pedido = epFindPedidoEntregado($pdo, $idPedido, $idUsuario, $esAdmin);
    if (!$pedido) {
        epJsonResponse(['success' => false, 'error' => 'Pedido no encontrado o no disponible para publicar.'], 404);
    }

    $colonia = extractColoniaFromAddress($pedido['direccion']);
    epJsonResponse([
        'success' => true,
        'colonia_detectada' => $colonia,
        'texto' => buildDeliveryPostText($colonia),
    ]);
}

if ($action === 'marcar_compartido') {
    $idPublicacion = (int)($payload['id_publicacion'] ?? 0);
    $stmt = $pdo->prepare("UPDATE pedido_publicaciones SET compartido_manual = 1 WHERE id_publicacion = :id AND id_pedido = :id_pedido");
    $stmt->execute([':id' => $idPublicacion, ':id_pedido' => $idPedido]);
    epJsonResponse(['success' => true]);
}

if ($action === 'publicar_facebook') {
    $idPublicacion = (int)($payload['id_publicacion'] ?? 0);
    $texto = trim((string)($payload['texto'] ?? ''));
    if ($texto === '') {
        epJsonResponse(['success' => false, 'error' => 'El texto de la publicacion no puede estar vacio.'], 400);
    }

    $pedido = epFindPedidoEntregado($pdo, $idPedido, $idUsuario, $esAdmin);
    if (!$pedido) {
        epJsonResponse(['success' => false, 'error' => 'Pedido no encontrado o no disponible para publicar.'], 404);
    }

    $stmtPub = $pdo->prepare("SELECT id_publicacion, ruta_foto FROM pedido_publicaciones WHERE id_publicacion = :id AND id_pedido = :id_pedido LIMIT 1");
    $stmtPub->execute([':id' => $idPublicacion, ':id_pedido' => $idPedido]);
    $publicacion = $stmtPub->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$publicacion) {
        epJsonResponse(['success' => false, 'error' => 'Primero sube una foto para este pedido.'], 400);
    }

    $pageId = getEnvVar('FB_PAGE_ID');
    $pageToken = getEnvVar('FB_PAGE_ACCESS_TOKEN');
    if (!$pageId || !$pageToken) {
        epJsonResponse(['success' => false, 'error' => 'Facebook no esta configurado (falta FB_PAGE_ID / FB_PAGE_ACCESS_TOKEN). Usa "Compartir" mientras tanto.'], 500);
    }

    $fotoPath = rtrim(dirname(__DIR__), '/\\') . '/assets/img/entregas/' . $publicacion['ruta_foto'];
    if (!is_file($fotoPath)) {
        epJsonResponse(['success' => false, 'error' => 'La foto de esta publicacion ya no existe en el servidor.'], 404);
    }

    $ch = curl_init("https://graph.facebook.com/v21.0/{$pageId}/photos");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => [
            'source' => new CURLFile($fotoPath),
            'caption' => $texto,
            'access_token' => $pageToken,
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    $decoded = is_string($response) ? json_decode($response, true) : null;
    $facebookPostId = $decoded['post_id'] ?? $decoded['id'] ?? null;

    if ($curlError !== '' || !is_array($decoded) || !$facebookPostId) {
        $errorMsg = $decoded['error']['message'] ?? ($curlError !== '' ? $curlError : 'Respuesta invalida de Facebook.');
        $stmtErr = $pdo->prepare("UPDATE pedido_publicaciones SET facebook_error = :error WHERE id_publicacion = :id");
        $stmtErr->execute([':error' => (string)$errorMsg, ':id' => $idPublicacion]);
        epJsonResponse(['success' => false, 'error' => 'Facebook rechazo la publicacion: ' . $errorMsg], 502);
    }

    $stmtOk = $pdo->prepare(
        "UPDATE pedido_publicaciones
         SET publicado_facebook = 1, facebook_post_id = :post_id, facebook_error = NULL, texto = :texto
         WHERE id_publicacion = :id"
    );
    $stmtOk->execute([':post_id' => (string)$facebookPostId, ':texto' => $texto, ':id' => $idPublicacion]);

    logAudit('PEDIDO_PUBLICACION_FACEBOOK', 'pedido_publicaciones', $idPublicacion, 'Publicado en Facebook, post_id=' . $facebookPostId);

    epJsonResponse(['success' => true, 'facebook_post_id' => (string)$facebookPostId]);
}

epJsonResponse(['success' => false, 'error' => 'Accion no reconocida.'], 400);

} catch (Throwable $e) {
    // Red de seguridad: nunca dejar que un error inesperado salga de este endpoint como HTML
    // (el manejador global de core/config.php redirige a error.php, y el fetch() del navegador
    // ya no puede parsear JSON). Se registra el detalle para diagnostico.
    error_log('entrega_publicacion.php error: ' . $e->getMessage());
    epJsonResponse([
        'success' => false,
        'error' => 'Error interno al procesar la publicacion.',
        'detail' => $e->getMessage(),
    ], 500);
}
