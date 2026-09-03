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

$data = $_POST;
if (!validateCsrfToken((string) ($data['csrf_token'] ?? ''))) {
    echo json_encode(['success' => false, 'message' => 'Token de seguridad inválido']);
    exit;
}

$pdo = getPDO();
$userId = (int) ($_SESSION['usuario']['id_usuario'] ?? 0);
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
                'foto_evidencia' => $data['foto_evidencia'] ?? null,
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

        case 'actualizar_capsulas_producto':
            $idProd = (int) ($data['id_producto'] ?? 0);
            $caps = ($data['capsulas_por_envase'] ?? '') !== '' ? max(0, (int) $data['capsulas_por_envase']) : null;
            $porcion = ($data['porcion_capsulas'] ?? '') !== '' ? max(0, (int) $data['porcion_capsulas']) : null;
            if ($idProd <= 0) {
                throw new InvalidArgumentException('Producto inválido.');
            }
            $pdo->prepare('UPDATE productos SET capsulas_por_envase = :c, porcion_capsulas = :p WHERE id_producto = :id')
                ->execute([':c' => $caps, ':p' => $porcion, ':id' => $idProd]);
            logAudit('PRODUCTO_CAPSULAS_ACTUALIZADAS', 'productos', $idProd, "cápsulas/envase={$caps}, porción={$porcion}");
            echo json_encode(['success' => true, 'message' => 'Datos de cápsulas guardados en el producto']);
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
