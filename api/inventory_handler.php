<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/purchase_order_utils.php';
require_once __DIR__ . '/../core/lote_caducidad_utils.php';

header('Content-Type: application/json');

if (!isAuthenticated() || (!isAdmin() && !isEncargado())) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$data = $_POST;
if (!validateCsrfToken($data['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido']);
    exit;
}

$pdo = getPDO();
$usuario = $_SESSION['usuario'];
$almacenId = (int)($data['id_almacen'] ?? ($usuario['id_almacen'] ?: 0));

try {
    $accion = $data['accion'] ?? '';
    if ($accion === 'entrada_individual') {
        $id_producto = (int)($data['id_producto'] ?? 0);
        $cantidad = (int)($data['cantidad'] ?? 0);
        $observacion = htmlspecialchars(trim($data['observacion'] ?? 'Entrada manual'));

        purchaseOrderProcessSingleInbound(
            $pdo,
            $id_producto,
            $almacenId,
            $cantidad,
            (int)$usuario['id_usuario'],
            $observacion
        );

        // Si se capturó lote + caducidad, registrarlo/sumarlo (no bloquea la entrada).
        $codigoLote = trim((string)($data['codigo_lote'] ?? ''));
        $fechaCaducidad = trim((string)($data['fecha_caducidad'] ?? ''));
        $avisoLote = '';
        if ($codigoLote !== '' && $fechaCaducidad !== '' && loteTablaExiste($pdo, 'lotes_inventario')) {
            try {
                loteRegistrarEntrada($pdo, [
                    'id_producto' => $id_producto,
                    'id_almacen' => $almacenId,
                    'codigo_lote' => $codigoLote,
                    'fecha_caducidad' => $fechaCaducidad,
                    'caducidad_aproximada' => !empty($data['caducidad_aproximada']) ? 1 : 0,
                    'cantidad' => $cantidad,
                ], (int)$usuario['id_usuario']);
            } catch (Throwable $eLote) {
                error_log('inventory_handler lote: ' . $eLote->getMessage());
                $avisoLote = ' (el stock entró, pero no se pudo registrar el lote: ' . $eLote->getMessage() . ')';
            }
        }

        echo json_encode(['success' => true, 'message' => 'Stock actualizado correctamente' . $avisoLote]);
    } else {
        throw new Exception("Acción no permitida");
    }
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}