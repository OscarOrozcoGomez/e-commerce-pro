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

$rowsIn = (isset($data['rows']) && is_array($data['rows'])) ? $data['rows'] : [];
if ($rowsIn === []) {
    echo json_encode(['success' => false, 'message' => 'No hay renglones para cargar']);
    exit;
}

$pdo = getPDO();

try {
    $idAlmacen = (int) ($data['id_almacen'] ?? 0);

    // Un encargado sólo puede surtir su propia sucursal.
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

    // Deja pasar sólo los tres campos que entiende el core.
    $rows = [];
    foreach ($rowsIn as $r) {
        if (!is_array($r)) {
            continue;
        }
        $rows[] = [
            'id_producto' => (int) ($r['id_producto'] ?? 0),
            'cantidad' => (int) ($r['cantidad'] ?? 0),
            'id_detalle' => (isset($r['id_detalle']) && $r['id_detalle'] !== null && $r['id_detalle'] !== '')
                ? (int) $r['id_detalle']
                : null,
        ];
    }

    $res = purchaseOrderCommitImport($pdo, $rows, $idAlmacen, (int) $_SESSION['usuario']['id_usuario']);

    logAudit(
        'importar',
        'ordenes_compra',
        0,
        sprintf(
            'Import proveedor (sucursal %d): %d entradas directas, %d líneas de OC, %d órdenes cerradas, %d ignoradas',
            $idAlmacen,
            $res['entradas_directas'],
            $res['lineas_oc'],
            $res['ordenes_cerradas'],
            $res['ignoradas']
        )
    );

    echo json_encode([
        'success' => true,
        'message' => 'Inventario actualizado',
        'entradas_directas' => $res['entradas_directas'],
        'lineas_oc' => $res['lineas_oc'],
        'ordenes_cerradas' => $res['ordenes_cerradas'],
        'ignoradas' => $res['ignoradas'],
    ]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => 'Error en el servidor: ' . $e->getMessage()]);
}
