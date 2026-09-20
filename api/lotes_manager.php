<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/lote_caducidad_utils.php';

header('Content-Type: application/json');

// Refresca permisos por si se revocaron/concedieron desde el panel hace poco.
refreshSessionPermissions();
// Permiso 'gestionar_caducidades' abre este endpoint (sin respaldo por rol: el panel de Roles y Permisos manda).
if (!isAuthenticated() || !hasPermission('gestionar_caducidades')) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$pdo = getPDO();
$userId = (int) ($_SESSION['usuario']['id_usuario'] ?? 0);

// Lectura GET:
//   ?id_producto=N          -> lotes de un producto (para la ficha en products.php)
//   ?modo=lista [+filtros]   -> TODOS los lotes con proyección (para views/caducidades.php):
//                              severidad, id_almacen, categoria, q, solo_con_excedente
//   ?modo=descuadres [+filtros] -> productos con stock del sistema != suma de lotes:
//                              id_almacen, q, tipo (faltante|sobrante)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $idProducto = (int) ($_GET['id_producto'] ?? 0);
    $modo = (string) ($_GET['modo'] ?? '');
    $modoLista = $modo === 'lista';
    $modoDescuadres = $modo === 'descuadres';

    if ($idProducto <= 0 && !$modoLista && !$modoDescuadres) {
        echo json_encode(['success' => false, 'message' => 'Producto inválido']);
        exit;
    }

    try {
        if ($modoDescuadres) {
            $filtros = [];
            foreach (['id_almacen', 'q', 'tipo'] as $k) {
                if (isset($_GET[$k]) && trim((string) $_GET[$k]) !== '') {
                    $filtros[$k] = $_GET[$k];
                }
            }
            echo json_encode(
                ['success' => true, 'data' => loteFetchDescuadres($pdo, $filtros)],
                JSON_UNESCAPED_UNICODE
            );
            exit;
        }

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
            $auditAntes = auditSnapshotFila($pdo, 'lotes_inventario', 'id_lote', (int) ($data['id_lote'] ?? 0), AUDIT_CAMPOS_LOTE);
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
            $auditDespues = auditSnapshotFila($pdo, 'lotes_inventario', 'id_lote', $id, AUDIT_CAMPOS_LOTE);
            if ($auditAntes === []) {
                logAudit(
                    'LOTE_GUARDADO',
                    'lotes_inventario',
                    $id,
                    'Lote nuevo ' . ($data['codigo_lote'] ?? '') . ' | cantidad ' . ($auditDespues['cantidad_restante'] ?? '?') . ', caduca ' . ($auditDespues['fecha_caducidad'] ?? '?'),
                    null,
                    auditDiff([], $auditDespues, array_keys($auditDespues))['despues']
                );
            } else {
                logAuditCambios('LOTE_GUARDADO', 'lotes_inventario', $id, $auditAntes, $auditDespues, [], [
                    'contexto' => 'Lote ' . ($auditDespues['codigo_lote'] ?? ''),
                ]);
            }
            echo json_encode(['success' => true, 'message' => 'Lote guardado', 'id_lote' => $id]);
            break;

        case 'ajustar':
            $auditIdLote = (int) ($data['id_lote'] ?? 0);
            $auditAntes = auditSnapshotFila($pdo, 'lotes_inventario', 'id_lote', $auditIdLote, AUDIT_CAMPOS_LOTE);
            loteAjustarCantidad($pdo, $auditIdLote, (int) ($data['cantidad'] ?? 0), $userId);
            logAuditCambios(
                'LOTE_AJUSTADO',
                'lotes_inventario',
                $auditIdLote,
                $auditAntes,
                auditSnapshotFila($pdo, 'lotes_inventario', 'id_lote', $auditIdLote, AUDIT_CAMPOS_LOTE),
                ['cantidad_restante', 'estado'],
                ['contexto' => 'Lote ' . ($auditAntes['codigo_lote'] ?? ('#' . $auditIdLote)) . ' (ajuste manual de cantidad)', 'severidad' => 'alerta']
            );
            echo json_encode(['success' => true, 'message' => 'Cantidad ajustada']);
            break;

        case 'cambiar_estado':
            $auditIdLote = (int) ($data['id_lote'] ?? 0);
            $auditAntes = auditSnapshotFila($pdo, 'lotes_inventario', 'id_lote', $auditIdLote, AUDIT_CAMPOS_LOTE);
            loteCambiarEstado($pdo, $auditIdLote, (string) ($data['estado'] ?? ''), $userId);
            logAuditCambios(
                'LOTE_ESTADO_CAMBIADO',
                'lotes_inventario',
                $auditIdLote,
                $auditAntes,
                auditSnapshotFila($pdo, 'lotes_inventario', 'id_lote', $auditIdLote, AUDIT_CAMPOS_LOTE),
                ['estado'],
                ['contexto' => 'Lote ' . ($auditAntes['codigo_lote'] ?? ('#' . $auditIdLote)), 'severidad' => 'alerta']
            );
            echo json_encode(['success' => true, 'message' => 'Estado actualizado']);
            break;

        case 'marcar_atendida':
            $auditIdLote = (int) ($data['id_lote'] ?? 0);
            $auditAntes = auditSnapshotFila($pdo, 'lotes_inventario', 'id_lote', $auditIdLote, AUDIT_CAMPOS_LOTE);
            loteMarcarAtendida(
                $pdo,
                $auditIdLote,
                !empty($data['en_oferta']),
                $data['notas'] ?? null,
                $userId
            );
            logAudit(
                'LOTE_ATENDIDO',
                'lotes_inventario',
                $auditIdLote,
                'Lote ' . ($auditAntes['codigo_lote'] ?? ('#' . $auditIdLote)) . ' marcado como atendido'
                    . (!empty($data['en_oferta']) ? ' y declarado EN OFERTA (solo marca el lote; no cambia precio ni categoría)' : ' (solo revisado, sin oferta)'),
                ['alerta_atendida' => $auditAntes['alerta_atendida'] ?? null, 'en_oferta' => $auditAntes['en_oferta'] ?? null],
                ['alerta_atendida' => 1, 'en_oferta' => !empty($data['en_oferta']) ? 1 : 0],
                ['severidad' => !empty($data['en_oferta']) ? 'alerta' : 'aviso']
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
                $res['nombre'] . ' | precio de oferta $' . number_format($res['precio_oferta'], 2)
                    . ' (costo $' . number_format($res['precio_costo'], 2) . ', venta normal $' . number_format($res['precio_venta'], 2) . ')'
                    . ($res['ya_estaba'] ? ' | ya estaba en la categoría Ofertas' : ' | se agregó a la categoría Ofertas')
                    . ($res['precio_fijado'] ? ' | se fijó el precio de oferta' : ' | conservó el precio de oferta que ya tenía')
                    . ((int) ($data['id_lote'] ?? 0) > 0 ? ' | desde el lote #' . (int) $data['id_lote'] : ''),
                null,
                [
                    'precio_oferta' => $res['precio_oferta'],
                    'precio_venta' => $res['precio_venta'],
                    'precio_costo' => $res['precio_costo'],
                    'categoria_oferta_agregada' => !$res['ya_estaba'],
                    'precio_oferta_fijado' => $res['precio_fijado'],
                ],
                ['severidad' => 'alerta']
            );
            $msg = $res['ya_estaba']
                ? 'Ya estaba en Ofertas. Precio de oferta: $' . number_format($res['precio_oferta'], 2)
                : $res['nombre'] . ' en Ofertas a $' . number_format($res['precio_oferta'], 2);
            echo json_encode(['success' => true, 'message' => $msg, 'data' => $res]);
            break;

        case 'eliminar':
            $auditAntes = auditSnapshotFila($pdo, 'lotes_inventario', 'id_lote', (int) ($data['id_lote'] ?? 0), AUDIT_CAMPOS_LOTE);
            loteEliminar($pdo, (int) ($data['id_lote'] ?? 0));
            logAudit(
                'LOTE_ELIMINADO',
                'lotes_inventario',
                (int) ($data['id_lote'] ?? 0),
                'Lote ' . ($auditAntes['codigo_lote'] ?? '') . ' eliminado (quedaban ' . ($auditAntes['cantidad_restante'] ?? '?') . ' u., caducidad ' . ($auditAntes['fecha_caducidad'] ?? '?') . ')',
                auditDiff($auditAntes, [], array_keys($auditAntes))['antes'],
                null,
                ['severidad' => 'alerta']
            );
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
