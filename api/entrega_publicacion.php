<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/social_post_utils.php';
require_once __DIR__ . '/../core/entrega_publicacion_utils.php';

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

/**
 * El token guardado en FB_PAGE_ACCESS_TOKEN normalmente es un token de System User con permisos
 * sobre la pagina (asi lo entrega el generador de tokens de Meta Business), NO el Page Access
 * Token real que exige el endpoint de publicar. Facebook los distingue: /me con un token de
 * System User devuelve la identidad del bot, no la de la pagina, y /{page}/photos lo rechaza con
 * un error de permisos enganoso ("publish_actions deprecated"). Aqui lo intercambiamos por el
 * Page Access Token real en cada publicacion, asi no importa si regeneran el token guardado y
 * olvidan este paso otra vez. Si la llamada falla (p.ej. ya guardaron el Page Access Token
 * directo), se usa el valor guardado tal cual como respaldo.
 */
function epResolvePageAccessToken(string $pageId, string $storedToken): string
{
    $ch = curl_init("https://graph.facebook.com/v21.0/{$pageId}?fields=access_token&access_token=" . urlencode($storedToken));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);
    if ($curlError !== '') {
        error_log("entrega_publicacion.php: epResolvePageAccessToken curl fallo: {$curlError}");
    }

    $decoded = is_string($response) ? json_decode($response, true) : null;
    $derivedToken = is_array($decoded) ? ($decoded['access_token'] ?? null) : null;

    return is_string($derivedToken) && $derivedToken !== '' ? $derivedToken : $storedToken;
}

/**
 * POST multipart a la Graph API de Facebook. Devuelve [respuestaDecodificada|null, mensajeError].
 * $mensajeError viene vacio cuando curl no fallo a nivel de transporte (el llamador aun debe
 * revisar si la respuesta trae error['message'] de Facebook).
 *
 * @param array<string, mixed> $fields
 * @return array{0: array<mixed>|null, 1: string}
 */
function epGraphPost(string $url, array $fields, int $timeout = 30): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => $fields,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    curl_close($ch);

    $decoded = is_string($response) ? json_decode($response, true) : null;

    return [is_array($decoded) ? $decoded : null, $curlError];
}

// Todo lo que sigue (incluida la conexion a BD) va dentro del try: si algo truena aqui sin
// capturarlo, el manejador global de core/config.php redirige a error.php (HTML) y el fetch()
// del navegador ya no puede parsear JSON (ver nota en el catch de abajo).
try {

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
 * Busca el pedido y confirma que pertenece al repartidor (o que el usuario es admin), sin
 * restringir por estado. Los llamadores aplican su propio filtro de estado segun el caso
 * (ver epFindPedidoParaFoto / epFindPedidoEntregado).
 *
 * @return array<string, mixed>|null
 */
function epFindPedidoBase(PDO $pdo, int $idPedido, int $idRepartidor, bool $esAdmin): ?array
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

    if (!$pedido) {
        return null;
    }
    if (!$esAdmin && (int)$pedido['id_repartidor'] !== $idRepartidor) {
        return null;
    }

    $pedido['direccion'] = epDecryptIfNeeded($pedido['direccion'] ?? '');
    return $pedido;
}

/**
 * Igual que epFindPedidoBase, pero solo acepta pedidos en un estado donde tiene sentido
 * adjuntarles una foto de entrega: la foto se puede subir ANTES de marcar la entrega (flujo
 * nuevo: foto -> cobrar -> publicar, con el pedido todavia en_reparto) o despues (compatibilidad
 * con el flujo anterior).
 *
 * @return array<string, mixed>|null
 */
function epFindPedidoParaFoto(PDO $pdo, int $idPedido, int $idRepartidor, bool $esAdmin): ?array
{
    $estadosPermitidos = ['pendiente_pago', 'pagado', 'en_reparto', 'entregado'];
    $pedido = epFindPedidoBase($pdo, $idPedido, $idRepartidor, $esAdmin);
    if (!$pedido || !in_array((string)$pedido['estado'], $estadosPermitidos, true)) {
        return null;
    }

    return $pedido;
}

/**
 * Igual que epFindPedidoBase, pero solo acepta pedidos ya entregados (se usa para generar el
 * texto sugerido y para publicar en Facebook, que solo debe pasar despues del cobro).
 *
 * @return array<string, mixed>|null
 */
function epFindPedidoEntregado(PDO $pdo, int $idPedido, int $idRepartidor, bool $esAdmin): ?array
{
    $pedido = epFindPedidoBase($pdo, $idPedido, $idRepartidor, $esAdmin);
    if (!$pedido || (string)$pedido['estado'] !== 'entregado') {
        return null;
    }

    return $pedido;
}

/**
 * Determina que numero de entrega es esta (Primera, Segunda...) para el texto de la
 * publicacion. Preferimos el valor que manda el navegador (posicion dentro de la ruta
 * optimizada generada, que solo vive en localStorage del repartidor); si no lo manda
 * (no genero ruta, o la limpio), caemos a contar cuantos pedidos lleva entregados hoy.
 */
function epResolverNumeroEntrega(PDO $pdo, int $idRepartidor, mixed $numeroEntregaCliente): int
{
    $numero = is_numeric($numeroEntregaCliente) ? (int)$numeroEntregaCliente : 0;
    if ($numero > 0) {
        return $numero;
    }

    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM pedidos WHERE id_repartidor = :id_repartidor AND estado = 'entregado' AND DATE(fecha_entrega) = CURDATE()"
    );
    $stmt->execute([':id_repartidor' => $idRepartidor]);
    return max(1, (int)$stmt->fetchColumn());
}

$idUsuario = (int)($usuario['id_usuario'] ?? 0);

// La subida de foto llega como multipart/form-data (no JSON), por eso se detecta aparte.
if (isset($_FILES['foto'])) {
    if (!validateCsrfToken((string)($_POST['csrf_token'] ?? ''))) {
        epJsonResponse(['success' => false, 'error' => 'Token CSRF invalido.'], 419);
    }

    $idPedido = (int)($_POST['id_pedido'] ?? 0);
    $pedido = epFindPedidoParaFoto($pdo, $idPedido, $idUsuario, $esAdmin);
    if (!$pedido) {
        epJsonResponse(['success' => false, 'error' => 'Pedido no encontrado o no disponible para publicar.'], 404);
    }

    // El repartidor puede adjuntar VARIAS fotos a la misma entrega (input con `multiple`).
    // epNormalizeFotoUploads() aplana el caso simple (una foto) y el multiple (arrays
    // paralelos) a una sola lista, ya recortada a ENTREGA_PUBLICACION_MAX_FOTOS.
    $fotos = epNormalizeFotoUploads($_FILES['foto']);
    if ($fotos === []) {
        epJsonResponse(['success' => false, 'error' => 'No se recibio ninguna foto.'], 400);
    }

    $maxBytes = 8 * 1024 * 1024;
    $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    $baseDir = rtrim(dirname(__DIR__), '/\\') . '/assets/img/entregas/';
    $targetDir = $baseDir . $idPedido . '/';
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        epJsonResponse(['success' => false, 'error' => 'No se pudo crear la carpeta de destino.'], 500);
    }

    $colonia = extractColoniaFromAddress($pedido['direccion']);
    $texto = trim((string)($_POST['texto'] ?? '')) ?: buildDeliveryPostText(
        $colonia,
        epResolverNumeroEntrega($pdo, (int)$pedido['id_repartidor'], $_POST['numero_entrega'] ?? null)
    );

    $stmtInsert = $pdo->prepare(
        "INSERT INTO pedido_publicaciones (id_pedido, id_repartidor, colonia_detectada, texto, ruta_foto)
         VALUES (:id_pedido, :id_repartidor, :colonia, :texto, :ruta_foto)"
    );

    // Cada foto valida se guarda como su propia fila. Si UNA falla la validacion no se
    // aborta el lote entero (comun en movil: un HEIC o una foto corrupta entre varias
    // buenas); se reporta aparte en `errores` y se guardan las que si sirvieron.
    $subidas = [];
    $errores = [];
    foreach ($fotos as $indice => $foto) {
        $etiqueta = $foto['name'] !== '' ? $foto['name'] : ('foto ' . ($indice + 1));

        if ($foto['error'] !== UPLOAD_ERR_OK) {
            $errores[] = "{$etiqueta}: error al recibir la foto.";
            continue;
        }
        if ($foto['size'] > $maxBytes) {
            $errores[] = "{$etiqueta}: excede el tamano maximo de 8MB.";
            continue;
        }

        $imageInfo = @getimagesize($foto['tmp_name']);
        $detectedMime = $imageInfo['mime'] ?? null;
        if (!$imageInfo || !isset($allowedMimes[$detectedMime])) {
            $errores[] = "{$etiqueta}: debe ser JPG, PNG o WEBP valida.";
            continue;
        }

        $fileName = bin2hex(random_bytes(8)) . '.' . $allowedMimes[$detectedMime];
        $targetFile = $targetDir . $fileName;
        if (!move_uploaded_file($foto['tmp_name'], $targetFile)) {
            $errores[] = "{$etiqueta}: no se pudo guardar en el servidor.";
            continue;
        }

        $rutaRelativa = $idPedido . '/' . $fileName;
        $stmtInsert->execute([
            ':id_pedido' => $idPedido,
            ':id_repartidor' => (int)$pedido['id_repartidor'],
            ':colonia' => $colonia !== '' ? $colonia : null,
            ':texto' => $texto,
            ':ruta_foto' => $rutaRelativa,
        ]);
        $idPublicacion = (int)$pdo->lastInsertId();

        logAudit('PEDIDO_PUBLICACION_FOTO_SUBIDA', 'pedido_publicaciones', $idPublicacion, 'Foto de entrega subida para pedido #' . $idPedido);

        $subidas[] = [
            'id_publicacion' => $idPublicacion,
            'foto_url' => BASE_URL . 'assets/img/entregas/' . $rutaRelativa,
        ];
    }

    if ($subidas === []) {
        epJsonResponse([
            'success' => false,
            'error' => 'No se pudo guardar ninguna foto. ' . implode(' ', $errores),
        ], 400);
    }

    epJsonResponse([
        'success' => true,
        // Campos en plural para el flujo nuevo (varias fotos)...
        'fotos' => $subidas,
        'errores' => $errores,
        // ...y en singular = la primera, por compatibilidad con clientes viejos.
        'id_publicacion' => $subidas[0]['id_publicacion'],
        'foto_url' => $subidas[0]['foto_url'],
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
    $numeroEntrega = epResolverNumeroEntrega($pdo, (int)$pedido['id_repartidor'], $payload['numero_entrega'] ?? null);

    // Todas las fotos ya subidas para este pedido (flujo nuevo: el repartidor pudo adjuntar
    // varias antes de cobrar). El modal las muestra como miniaturas y deja agregar mas.
    $stmtFotos = $pdo->prepare(
        "SELECT id_publicacion, ruta_foto, publicado_facebook, compartido_manual
         FROM pedido_publicaciones WHERE id_pedido = :id_pedido ORDER BY id_publicacion ASC"
    );
    $stmtFotos->execute([':id_pedido' => $idPedido]);
    $fotos = [];
    foreach ($stmtFotos->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fotos[] = [
            'id_publicacion' => (int)$row['id_publicacion'],
            'foto_url' => BASE_URL . 'assets/img/entregas/' . $row['ruta_foto'],
            'publicado_facebook' => (int)$row['publicado_facebook'] === 1,
            'compartido_manual' => (int)$row['compartido_manual'] === 1,
        ];
    }
    $ultimaFoto = $fotos !== [] ? $fotos[count($fotos) - 1] : null;

    epJsonResponse([
        'success' => true,
        'colonia_detectada' => $colonia,
        'numero_entrega' => $numeroEntrega,
        'texto' => buildDeliveryPostText($colonia, $numeroEntrega),
        'fotos' => $fotos,
        // Singular = la mas reciente, por compatibilidad con clientes viejos.
        'id_publicacion' => $ultimaFoto['id_publicacion'] ?? null,
        'foto_url' => $ultimaFoto['foto_url'] ?? null,
    ]);
}

if ($action === 'marcar_compartido') {
    // Acepta `id_publicaciones` (lista) o `id_publicacion` suelto. Si no viene ninguno, se
    // marcan TODAS las fotos del pedido (la tarjeta publica sin manejar ids uno por uno).
    $ids = epNormalizeIdPublicaciones($payload['id_publicaciones'] ?? null, $payload['id_publicacion'] ?? null);
    if ($ids !== []) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $pdo->prepare(
            "UPDATE pedido_publicaciones SET compartido_manual = 1
             WHERE id_pedido = ? AND id_publicacion IN ($placeholders)"
        );
        $stmt->execute(array_merge([$idPedido], $ids));
    } else {
        $stmt = $pdo->prepare("UPDATE pedido_publicaciones SET compartido_manual = 1 WHERE id_pedido = ?");
        $stmt->execute([$idPedido]);
    }
    epJsonResponse(['success' => true]);
}

if ($action === 'publicar_facebook') {
    $pedido = epFindPedidoEntregado($pdo, $idPedido, $idUsuario, $esAdmin);
    if (!$pedido) {
        epJsonResponse(['success' => false, 'error' => 'Pedido no encontrado o no disponible para publicar.'], 404);
    }

    // El texto es opcional: si el cliente (la tarjeta) no manda uno, se arma el sugerido.
    $texto = trim((string)($payload['texto'] ?? ''));
    if ($texto === '') {
        $colonia = extractColoniaFromAddress($pedido['direccion']);
        $texto = buildDeliveryPostText(
            $colonia,
            epResolverNumeroEntrega($pdo, (int)$pedido['id_repartidor'], $payload['numero_entrega'] ?? null)
        );
    }

    // Si no viene lista de ids (la tarjeta publica "todas las fotos de este pedido"), se toman
    // todas las de pedido_publicaciones; el filtro de pendientes mas abajo descarta las ya subidas.
    $ids = epNormalizeIdPublicaciones($payload['id_publicaciones'] ?? null, $payload['id_publicacion'] ?? null);
    if ($ids === []) {
        $stmtAll = $pdo->prepare('SELECT id_publicacion FROM pedido_publicaciones WHERE id_pedido = ? ORDER BY id_publicacion ASC');
        $stmtAll->execute([$idPedido]);
        $ids = array_map('intval', $stmtAll->fetchAll(PDO::FETCH_COLUMN));
    }
    if ($ids === []) {
        epJsonResponse(['success' => false, 'error' => 'Primero sube una foto para este pedido.'], 400);
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmtPub = $pdo->prepare(
        "SELECT id_publicacion, ruta_foto, publicado_facebook FROM pedido_publicaciones
         WHERE id_pedido = ? AND id_publicacion IN ($placeholders) ORDER BY id_publicacion ASC"
    );
    $stmtPub->execute(array_merge([$idPedido], $ids));
    $filas = $stmtPub->fetchAll(PDO::FETCH_ASSOC);
    if ($filas === []) {
        epJsonResponse(['success' => false, 'error' => 'Primero sube una foto para este pedido.'], 400);
    }

    // Solo las que aun no se publicaron y cuyo archivo sigue en disco (si el repartidor
    // reabre el modal despues de publicar, no se re-postean).
    $baseFotos = rtrim(dirname(__DIR__), '/\\') . '/assets/img/entregas/';
    $pendientes = [];
    foreach ($filas as $fila) {
        if ((int)$fila['publicado_facebook'] === 1) {
            continue;
        }
        $ruta = $baseFotos . $fila['ruta_foto'];
        if (is_file($ruta)) {
            $pendientes[] = ['id' => (int)$fila['id_publicacion'], 'path' => $ruta];
        }
    }

    if ($pendientes === []) {
        // Ya estaban todas publicadas: no es un error, solo no hay nada que hacer.
        epJsonResponse(['success' => true, 'ya_publicado' => true]);
    }

    $pageId = getEnvVar('FB_PAGE_ID');
    $storedToken = getEnvVar('FB_PAGE_ACCESS_TOKEN');
    if (!$pageId || !$storedToken) {
        epJsonResponse(['success' => false, 'error' => 'Facebook no esta configurado (falta FB_PAGE_ID / FB_PAGE_ACCESS_TOKEN). Usa "Compartir" mientras tanto.'], 500);
    }

    $idsPendientes = array_map(static fn (array $p): int => $p['id'], $pendientes);
    $placeholdersPend = implode(',', array_fill(0, count($idsPendientes), '?'));

    // Logging defensivo: si el proceso muere a medias (timeout, worker sin memoria...) un 502
    // de infraestructura no dispara nuestro manejador de excepciones. Estas lineas se escriben
    // ANTES de cada llamada de red, asi queda registrado hasta donde alcanzo a llegar.
    error_log("entrega_publicacion.php: publicar_facebook id_pedido={$idPedido} ids=" . implode(',', $idsPendientes) . ' - resolviendo page token');
    $pageToken = epResolvePageAccessToken($pageId, $storedToken);

    $marcarError = static function (string $mensaje) use ($pdo, $idsPendientes, $placeholdersPend): void {
        $stmt = $pdo->prepare("UPDATE pedido_publicaciones SET facebook_error = ? WHERE id_publicacion IN ($placeholdersPend)");
        $stmt->execute(array_merge([$mensaje], $idsPendientes));
    };

    if (count($pendientes) === 1) {
        // Una sola foto: post directo a /photos con caption (igual que siempre).
        error_log("entrega_publicacion.php: publicar_facebook id_pedido={$idPedido} - 1 foto, subiendo a /photos");
        [$decoded, $curlError] = epGraphPost("https://graph.facebook.com/v21.0/{$pageId}/photos", [
            'source' => new CURLFile($pendientes[0]['path']),
            'caption' => $texto,
            'access_token' => $pageToken,
        ]);
        $facebookPostId = $decoded['post_id'] ?? $decoded['id'] ?? null;
        if ($curlError !== '' || $decoded === null || !$facebookPostId) {
            $errorMsg = $decoded['error']['message'] ?? ($curlError !== '' ? $curlError : 'Respuesta invalida de Facebook.');
            error_log("entrega_publicacion.php: publicar_facebook id_pedido={$idPedido} - fallo: {$errorMsg}");
            $marcarError((string)$errorMsg);
            epJsonResponse(['success' => false, 'error' => 'Facebook rechazo la publicacion: ' . $errorMsg], 502);
        }
    } else {
        // Varias fotos: se suben una por una con published=false para obtener su media_fbid y
        // luego se crea UN post de feed que las adjunta todas (album).
        error_log('entrega_publicacion.php: publicar_facebook id_pedido=' . $idPedido . ' - ' . count($pendientes) . ' fotos, subiendo album');
        $fbids = [];
        foreach ($pendientes as $p) {
            [$decoded, $curlError] = epGraphPost("https://graph.facebook.com/v21.0/{$pageId}/photos", [
                'source' => new CURLFile($p['path']),
                'published' => 'false',
                'access_token' => $pageToken,
            ]);
            $mediaId = $decoded['id'] ?? null;
            if ($curlError !== '' || $decoded === null || !$mediaId) {
                $errorMsg = $decoded['error']['message'] ?? ($curlError !== '' ? $curlError : 'Respuesta invalida de Facebook.');
                error_log("entrega_publicacion.php: publicar_facebook id_pedido={$idPedido} - fallo subiendo foto del album: {$errorMsg}");
                $marcarError((string)$errorMsg);
                epJsonResponse(['success' => false, 'error' => 'Facebook rechazo una de las fotos: ' . $errorMsg], 502);
            }
            $fbids[] = (string)$mediaId;
        }

        [$decoded, $curlError] = epGraphPost("https://graph.facebook.com/v21.0/{$pageId}/feed", array_merge(
            ['message' => $texto, 'access_token' => $pageToken],
            epBuildAttachedMediaFields($fbids)
        ));
        $facebookPostId = $decoded['id'] ?? $decoded['post_id'] ?? null;
        if ($curlError !== '' || $decoded === null || !$facebookPostId) {
            $errorMsg = $decoded['error']['message'] ?? ($curlError !== '' ? $curlError : 'Respuesta invalida de Facebook.');
            error_log("entrega_publicacion.php: publicar_facebook id_pedido={$idPedido} - fallo creando post de album: {$errorMsg}");
            $marcarError((string)$errorMsg);
            epJsonResponse(['success' => false, 'error' => 'Facebook rechazo el album: ' . $errorMsg], 502);
        }
    }

    $stmtOk = $pdo->prepare(
        "UPDATE pedido_publicaciones
         SET publicado_facebook = 1, facebook_post_id = ?, facebook_error = NULL, texto = ?
         WHERE id_publicacion IN ($placeholdersPend)"
    );
    $stmtOk->execute(array_merge([(string)$facebookPostId, $texto], $idsPendientes));

    logAudit('PEDIDO_PUBLICACION_FACEBOOK', 'pedido_publicaciones', $pendientes[0]['id'], 'Publicado en Facebook (' . count($pendientes) . ' foto(s)), post_id=' . $facebookPostId);

    epJsonResponse(['success' => true, 'facebook_post_id' => (string)$facebookPostId, 'fotos_publicadas' => count($pendientes)]);
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
