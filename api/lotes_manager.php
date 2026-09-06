<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/lote_caducidad_utils.php';

header('Content-Type: application/json');

if (!isAuthenticated() || (!isAdmin() && !isEncargado())) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$pdo = getPDO();
$userId = (int) ($_SESSION['usuario']['id_usuario'] ?? 0);

// Lectura: lotes (con proyección) de un producto, para pintarlos al editarlo en products.php.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $idProducto = (int) ($_GET['id_producto'] ?? 0);
    if ($idProducto <= 0) {
        echo json_encode(['success' => false, 'message' => 'Producto inválido']);
        exit;
    }
    try {
        $proy = loteFetchProyecciones($pdo, ['id_producto' => $idProducto]);
        echo json_encode(['success' => true, 'data' => $proy['lotes'], 'ventana_dias' => $proy['ventana_dias']], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        error_log('lotes_manager (GET): ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'No se pudieron cargar los lotes.']);
    }
    exit;
}

$data = $_POST;
if (!validateCsrfToken((string) ($data['csrf_token'] ?? ''))) {
    echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido']);
    exit;
}

$accion = (string) ($data['accion'] ?? '');

try {
    switch ($accion) {
        case 'guardar':
            $id = loteGuardar($pdo, [
                'id_lote' => (int) ($data['id_lote'] ?? 0),
                'id_producto' => (int) ($data['id_producto'] ?? 0),
                'id_almacen' => $data['id_almacen'] ?? null,
                'codigo_lote' => $data['codigo_lote'] ?? '',
                'fecha_caducidad' => $data['fecha_caducidad'] ?? '',
                'caducidad_aproximada' => $data['caducidad_aproximada'] ?? 0,
                'cantidad' => $data['cantidad'] ?? 0,
                'costo_unitario' => $data['costo_unitario'] ?? null,
                'notas' => $data['notas'] ?? null,
            ], $userId);
            logAudit('LOTE_GUARDADO', 'lotes_inventario', $id, 'Lote ' . ($data['codigo_lote'] ?? ''));
            echo json_encode(['success' => true, 'message' => 'Lote guardado', 'id_lote' => $id]);
            break;

        case 'ajustar':
            loteAjustarCantidad($pdo, (int) ($data['id_lote'] ?? 0), (int) ($data['cantidad'] ?? 0), $userId);
            echo json_encode(['success' => true, 'message' => 'Cantidad ajustada']);
            break;

        case 'cambiar_estado':
            loteCambiarEstado($pdo, (int) ($data['id_lote'] ?? 0), (string) ($data['estado'] ?? ''), $userId);
            echo json_encode(['success' => true, 'message' => 'Estado actualizado']);
            break;

        case 'marcar_atendida':
            loteMarcarAtendida(
                $pdo,
                (int) ($data['id_lote'] ?? 0),
                !empty($data['en_oferta']),
                $data['notas'] ?? null,
                $userId
            );
            echo json_encode(['success' => true, 'message' => 'Alerta marcada como atendida']);
            break;

        case 'eliminar':
            loteEliminar($pdo, (int) ($data['id_lote'] ?? 0));
            logAudit('LOTE_ELIMINADO', 'lotes_inventario', (int) ($data['id_lote'] ?? 0), 'Lote eliminado');
            echo json_encode(['success' => true, 'message' => 'Lote eliminado']);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Acción no reconocida']);
    }
} catch (InvalidArgumentException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('lotes_manager: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'No se pudo completar la operación.']);
}
