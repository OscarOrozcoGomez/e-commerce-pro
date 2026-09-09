<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/lote_caducidad_utils.php';

header('Content-Type: application/json');

// Refresca permisos por si se revocaron/concedieron desde el panel hace poco.
refreshSessionPermissions();
// Permiso 'gestionar_caducidades' abre este endpoint; el rol se mantiene como respaldo.
if (!isAuthenticated() || (!hasPermission('gestionar_caducidades') && !isAdmin() && !isEncargado())) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$pdo = getPDO();
$userId = (int) ($_SESSION['usuario']['id_usuario'] ?? 0);

// Lectura GET:
//   ?id_producto=N       -> lotes de un producto (para la ficha en products.php)
//   ?modo=lista [+filtros] -> TODOS los lotes con proyección (para views/caducidades.php):
//                             severidad, id_almacen, categoria, q, solo_con_excedente
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $idProducto = (int) ($_GET['id_producto'] ?? 0);
    $modoLista = ($_GET['modo'] ?? '') === 'lista';

    if ($idProducto <= 0 && !$modoLista) {
        echo json_encode(['success' => false, 'message' => 'Producto inválido']);
        exit;
    }

    try {
        if ($idProducto > 0) {
            $filtros = ['id_producto' => $idProducto];
        } else {
            $filtros = [];
            foreach (['severidad', 'id_almacen', 'categoria', 'q'] as $k) {
                if (isset($_GET[$k]) && trim((string) $_GET[$k]) !== '') {
                    $filtros[$k] = $_GET[$k];
                }
            }
            if (!empty($_GET['solo_con_excedente'])) {
                $filtros['solo_con_excedente'] = true;
            }
        }
        $proy = loteFetchProyecciones($pdo, $filtros);
        echo json_encode(
            ['success' => true, 'data' => $proy['lotes'], 'ventana_dias' => $proy['ventana_dias']],
            JSON_UNESCAPED_UNICODE
        );
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

        case 'poner_producto_en_oferta':
            $res = lotePonerProductoEnOferta(
                $pdo,
                (int) ($data['id_producto'] ?? 0),
                (int) ($data['id_lote'] ?? 0),
                $userId
            );
            logAudit(
                'PRODUCTO_EN_OFERTA',
                'productos',
                (int) ($data['id_producto'] ?? 0),
                'Precio de oferta $' . number_format($res['precio_oferta'], 2) . ' - ' . $res['nombre']
            );
            $msg = $res['ya_estaba']
                ? 'Ya estaba en Ofertas. Precio de oferta: $' . number_format($res['precio_oferta'], 2)
                : $res['nombre'] . ' en Ofertas a $' . number_format($res['precio_oferta'], 2);
            echo json_encode(['success' => true, 'message' => $msg, 'data' => $res]);
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
