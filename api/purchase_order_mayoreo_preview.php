<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/purchase_order_utils.php';

header('Content-Type: application/json');

// Refresca permisos por si se revocaron/concedieron desde el panel hace poco.
refreshSessionPermissions();
// Permiso 'inventario' abre este endpoint; el rol se mantiene como respaldo.
if (!isAuthenticated() || (!hasPermission('inventario') && !isAdmin() && !isEncargado())) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!is_array($data)) {
    $data = [];
}

if (!validateCsrfToken($data['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido']);
    exit;
}

/**
 * El pedido puede llegar de dos formas:
 *  - 'pedido':  el objeto JSON completo (lo que deja scripts/mayoreo_pedidos.mjs),
 *               pegado por el usuario. Funciona en cualquier entorno.
 *  - 'archivo': solo el nombre de archivo (p.ej. "pedido-BLM015728.json"); se lee
 *               de scripts/.mayoreo/. Comodidad SOLO en local (el directorio no se
 *               despliega).
 */
$pedido = null;

if (isset($data['pedido']) && is_array($data['pedido'])) {
    $pedido = $data['pedido'];
} elseif (!empty($data['archivo'])) {
    $nombre = basename((string) $data['archivo']); // corta cualquier intento de path traversal
    if (!preg_match('/^pedido-[A-Za-z0-9_-]+\.json$/', $nombre) && $nombre !== 'pedidos.json') {
        echo json_encode(['success' => false, 'message' => 'Nombre de archivo no válido']);
        exit;
    }
    $ruta = __DIR__ . '/../scripts/.mayoreo/' . $nombre;
    if (!is_file($ruta)) {
        echo json_encode(['success' => false, 'message' => "No encuentro scripts/.mayoreo/{$nombre}. Corre primero: node scripts/mayoreo_pedidos.mjs"]);
        exit;
    }
    $raw = (string) file_get_contents($ruta);
    $decoded = json_decode($raw, true);
    // pedidos.json es un array de pedidos; tomamos el primero.
    if (is_array($decoded) && array_is_list($decoded)) {
        $decoded = $decoded[0] ?? null;
    }
    if (!is_array($decoded)) {
        echo json_encode(['success' => false, 'message' => "El archivo {$nombre} no es un JSON de pedido válido"]);
        exit;
    }
    $pedido = $decoded;
} elseif (is_string($data['texto'] ?? null) && trim($data['texto']) !== '') {
    // El usuario pego el contenido del .json como texto.
    $decoded = json_decode((string) $data['texto'], true);
    if (is_array($decoded) && array_is_list($decoded)) {
        $decoded = $decoded[0] ?? null;
    }
    if (!is_array($decoded)) {
        echo json_encode(['success' => false, 'message' => 'El texto pegado no es un JSON de pedido válido']);
        exit;
    }
    $pedido = $decoded;
}

if ($pedido === null) {
    echo json_encode(['success' => false, 'message' => 'No se recibió el pedido (pega el JSON o indica el archivo)']);
    exit;
}

$pdo = getPDO();

try {
    $idAlmacen = (int) ($data['id_almacen'] ?? 0);

    // Un encargado sólo analiza contra su propia sucursal.
    if (!isAdmin()) {
        $actual = getCurrentAlmacenId();
        if ($actual === null) {
            echo json_encode(['success' => false, 'message' => 'No tienes una sucursal asignada']);
            exit;
        }
        $idAlmacen = (int) $actual;
    } else {
        $chk = $pdo->prepare('SELECT 1 FROM almacenes WHERE id_almacen = ?');
        $chk->execute([$idAlmacen]);
        if ($chk->fetchColumn() === false) {
            echo json_encode(['success' => false, 'message' => 'Sucursal inválida']);
            exit;
        }
    }

    $preview = purchaseOrderBuildMayoreoPreview($pdo, $pedido, $idAlmacen);

    echo json_encode([
        'success' => true,
        'numero' => $preview['numero'],
        'fecha' => $preview['fecha'],
        'total_txt' => $preview['total_txt'],
        'id_almacen' => $idAlmacen,
        'rows' => $preview['rows'],
        'warnings' => $preview['warnings'],
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => 'Error en el servidor: ' . $e->getMessage()]);
}
