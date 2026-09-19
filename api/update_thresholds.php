<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

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

if (empty($data['items'])) {
    echo json_encode(['success' => false, 'message' => 'No se enviaron datos']);
    exit;
}

$pdo = getPDO();
try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("UPDATE inventario_almacen SET stock_minimo = ?, stock_maximo = ? WHERE id_producto = ? AND id_almacen = ?");
    $stmtPrev = $pdo->prepare("SELECT ia.stock_minimo, ia.stock_maximo, p.nombre FROM inventario_almacen ia JOIN productos p ON p.id_producto = ia.id_producto WHERE ia.id_producto = ? AND ia.id_almacen = ?");

    // Auditoria: solo los productos cuyo minimo/maximo realmente cambio.
    $auditAntes = [];
    $auditDespues = [];
    $auditResumen = [];

    foreach ($data['items'] as $item) {
        $idProd = (int)$item['id_producto'];
        $idAlm = (int)$item['id_almacen'];
        $min = (int)$item['stock_minimo'];
        $max = (int)$item['stock_maximo'];

        $stmtPrev->execute([$idProd, $idAlm]);
        $prev = $stmtPrev->fetch(PDO::FETCH_ASSOC);
        if (is_array($prev) && ((int)$prev['stock_minimo'] !== $min || (int)$prev['stock_maximo'] !== $max)) {
            $clave = 'producto ' . $idProd . ' / almacén ' . $idAlm;
            $auditAntes[$clave] = ['minimo' => (int)$prev['stock_minimo'], 'maximo' => (int)$prev['stock_maximo']];
            $auditDespues[$clave] = ['minimo' => $min, 'maximo' => $max];
            $auditResumen[] = (string)$prev['nombre'] . ' (alm. ' . $idAlm . '): mín ' . (int)$prev['stock_minimo'] . '->' . $min . ', máx ' . (int)$prev['stock_maximo'] . '->' . $max;
        }

        $stmt->execute([$min, $max, $idProd, $idAlm]);
    }

    $pdo->commit();

    if ($auditResumen !== []) {
        logAudit(
            'UMBRALES_STOCK_CAMBIADOS',
            'inventario_almacen',
            null,
            count($auditResumen) . ' producto(s): ' . implode(' | ', array_slice($auditResumen, 0, 8)) . (count($auditResumen) > 8 ? ' | ...' : ''),
            $auditAntes,
            $auditDespues,
            ['severidad' => 'aviso']
        );
    }
    echo json_encode(['success' => true, 'message' => 'Reglas de stock actualizadas correctamente']);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}