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

if (!validateCsrfToken($data['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido']);
    exit;
}

$texto = is_string($data['texto'] ?? null) ? $data['texto'] : '';
if (trim($texto) === '') {
    echo json_encode(['success' => false, 'message' => 'No se recibió texto para analizar']);
    exit;
}
if (strlen($texto) > 20000) {
    $texto = substr($texto, 0, 20000);
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

    $preview = purchaseOrderBuildImportPreview($pdo, $texto, $idAlmacen);

    echo json_encode([
        'success' => true,
        'id_almacen' => $idAlmacen,
        'rows' => $preview['rows'],
        'warnings' => $preview['warnings'],
    ]);
} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => 'Error en el servidor: ' . $e->getMessage()]);
}
