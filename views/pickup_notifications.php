<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
if (!isEncargado() && !isAdmin() && !isVendedor()) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$pageTitle = 'Notificaciones Pickup';
$pdo = getPDO();
$usuario = $_SESSION['usuario'];
$idUsuario = (int)($usuario['id_usuario'] ?? 0);
$idAlmacenUsuario = (int)($usuario['id_almacen'] ?? 0);
$error = '';
$success = '';
$successActionUrl = '';
$successActionLabel = '';
$cancelSupportReady = false;
$hasFechaApartada = false;
$cancelReasonOptions = [
    'cliente_no_llego' => 'Cliente no llego por su pedido',
    'cliente_solicito_cancelar' => 'Cliente solicito cancelar el pedido',
    'pago_no_confirmado' => 'Pago no confirmado en tiempo',
    'sin_contacto_cliente' => 'Sin respuesta del cliente',
    'error_captura_pedido' => 'Error en captura del pedido',
    'producto_danado' => 'Producto danado/no apto para entrega',
    'tiempo_espera_excedido' => 'Tiempo de espera excedido',
    'otro' => 'Otro',
];

// Si el descifrado falla (llave distinta, dato corrupto, etc.) NUNCA mostramos el texto
// cifrado crudo (ENCv1:...); se usa un fallback seguro.
$safeDecryptValue = static function (?string $value, string $fallback = ''): string {
    $raw = trim((string)$value);
    if ($raw === '') {
        return $fallback;
    }
    if (!function_exists('piiIsEncryptedValue') || !function_exists('piiDecryptValue') || !piiIsEncryptedValue($raw)) {
        return $raw;
    }
    $decrypted = trim((string)piiDecryptValue($raw));
    if ($decrypted === $raw || piiIsEncryptedValue($decrypted)) {
        return $fallback;
    }
    return $decrypted;
};

function normalizePickupProductLabel(string $value): string
{
    $txt = trim(mb_strtolower($value));
    $txt = str_replace(['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'], ['a', 'e', 'i', 'o', 'u', 'u', 'n'], $txt);
    $txt = preg_replace('/[^a-z0-9]+/u', ' ', $txt);
    return trim((string)$txt);
}

function parseSupportTransferItemsFromNotes(string $notes): array
{
    $result = [];
    $notes = trim($notes);
    if ($notes === '' || stripos($notes, 'TRASLADO_INTERNO_2_3H') === false) {
        return $result;
    }

    if (!preg_match('/TRASLADO_INTERNO_2_3H:\s*.*?\.(.+)$/i', $notes, $m)) {
        return $result;
    }

    $tail = trim((string)$m[1]);
    if ($tail === '') {
        return $result;
    }

    if (preg_match('/productos\s+pendientes\s+por\s+surtir/i', $tail)) {
        return $result;
    }

    if (preg_match_all('/([^,]+?)\s*\(faltan\s*(\d+)\)/iu', $tail, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $item) {
            $name = trim((string)($item[1] ?? ''));
            $faltan = max(0, (int)($item[2] ?? 0));
            if ($name === '' || $faltan <= 0) {
                continue;
            }
            $result[] = [
                'raw' => $name,
                'normalized' => normalizePickupProductLabel($name),
                'faltan' => $faltan,
            ];
        }
    }

    return $result;
}

try {
    $stmtMeta = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pickup_notificaciones'");
    $stmtMeta->execute();
    $hasPickupTable = ((int)$stmtMeta->fetchColumn()) > 0;
    if (!$hasPickupTable) {
        throw new RuntimeException('El modulo de pickup no esta habilitado aun. Aplica migraciones pendientes.');
    }

    $stmtCancelCol = $pdo->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pickup_notificaciones' AND COLUMN_NAME = 'estado' LIMIT 1");
    $stmtCancelCol->execute();
    $estadoColumnType = (string)($stmtCancelCol->fetchColumn() ?: '');
    $stmtFechaCancel = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pickup_notificaciones' AND COLUMN_NAME = 'fecha_cancelada'");
    $stmtFechaCancel->execute();
    $hasFechaCancelada = ((int)$stmtFechaCancel->fetchColumn()) > 0;
    $stmtFechaApartada = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pickup_notificaciones' AND COLUMN_NAME = 'fecha_apartada'");
    $stmtFechaApartada->execute();
    $hasFechaApartada = ((int)$stmtFechaApartada->fetchColumn()) > 0;
    $cancelSupportReady = (stripos($estadoColumnType, 'cancelada') !== false) && $hasFechaCancelada;

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $error = 'Token CSRF invalido.';
        } else {
            $accion = trim((string)($_POST['accion'] ?? ''));
            $idNotificacion = (int)($_POST['id_notificacion'] ?? 0);
            $estado = trim((string)($_POST['estado'] ?? 'nueva'));
            $motivoCancelacionKey = trim((string)($_POST['motivo_cancelacion'] ?? ''));
            $motivoCancelacionOtro = trim((string)($_POST['motivo_cancelacion_otro'] ?? ''));
            $nuevaDireccionEntrega = trim((string)($_POST['direccion_entrega'] ?? ''));
            $nuevoTelefonoEntrega = trim((string)($_POST['telefono_entrega'] ?? ''));
            $nuevoMapsLinkEntrega = trim((string)($_POST['maps_link_entrega'] ?? ''));

            if ($idNotificacion <= 0) {
                $error = 'Notificacion invalida.';
            } elseif (!in_array($accion, ['marcar_estado', 'cancelar_pedido', 'convertir_domicilio'], true)) {
                $error = 'Accion no permitida.';
            } elseif ($accion === 'marcar_estado' && !in_array($estado, ['vista', 'apartada', 'atendida'], true)) {
                $error = 'Estado invalido.';
            } elseif ($accion === 'cancelar_pedido' && !$cancelSupportReady) {
                $error = 'Para cancelar pickup y devolver inventario primero aplica migraciones pendientes.';
            } elseif ($accion === 'cancelar_pedido' && !isset($cancelReasonOptions[$motivoCancelacionKey])) {
                $error = 'Selecciona un motivo de cancelacion valido.';
            } elseif ($accion === 'cancelar_pedido' && $motivoCancelacionKey === 'otro' && $motivoCancelacionOtro === '') {
                $error = 'Escribe el motivo cuando selecciones "Otro".';
            } elseif ($accion === 'convertir_domicilio' && $nuevaDireccionEntrega === '') {
                $error = 'Captura la direccion de entrega del cliente para convertir a domicilio.';
            } elseif ($accion === 'convertir_domicilio' && mb_strlen($nuevaDireccionEntrega) < 8) {
                $error = 'La direccion capturada parece incompleta. Verificala.';
            } else {
                if ($error === '') {
                    $motivoCancelacionEtiqueta = '';
                    if ($accion === 'cancelar_pedido') {
                        $motivoCancelacionEtiqueta = (string)($cancelReasonOptions[$motivoCancelacionKey] ?? '');
                        if ($motivoCancelacionKey === 'otro') {
                            $motivoCancelacionEtiqueta = mb_substr($motivoCancelacionOtro, 0, 180);
                        }
                    }

                    $scopeWhere = '';
                    $scopeParams = [];
                    if (isEncargado() || isVendedor()) {
                        $scopeWhere = ' AND id_almacen = :scope_almacen';
                        $scopeParams[':scope_almacen'] = $idAlmacenUsuario;
                    }

                    $stmtCurrent = $pdo->prepare("SELECT id_pedido, estado FROM pickup_notificaciones WHERE id_notificacion = :id_notificacion{$scopeWhere} LIMIT 1");
                    $stmtCurrent->execute([
                        ':id_notificacion' => $idNotificacion,
                    ] + $scopeParams);
                    $current = $stmtCurrent->fetch(PDO::FETCH_ASSOC) ?: null;

                    if (!$current) {
                        $error = 'No se encontro la notificacion o no tienes permisos para modificarla.';
                    } else {
                        $estadoActual = (string)($current['estado'] ?? 'nueva');

                        if ($accion === 'cancelar_pedido') {
                            if (in_array($estadoActual, ['cancelada', 'atendida'], true)) {
                                if ($estadoActual === 'cancelada') {
                                    $success = 'La notificacion ya esta cancelada.';
                                } else {
                                    $error = 'No se puede cancelar una notificacion ya atendida.';
                                }
                            } else {
                                $pdo->beginTransaction();
                                try {
                                    $idPedido = (int)($current['id_pedido'] ?? 0);
                                    if ($idPedido <= 0) {
                                        throw new RuntimeException('Pedido pickup invalido para cancelar.');
                                    }

                                    $stmtPedido = $pdo->prepare("SELECT estado, id_almacen FROM pedidos WHERE id_pedido = :id_pedido FOR UPDATE");
                                    $stmtPedido->execute([':id_pedido' => $idPedido]);
                                    $pedido = $stmtPedido->fetch(PDO::FETCH_ASSOC) ?: null;
                                    if (!$pedido) {
                                        throw new RuntimeException('No se encontro el pedido asociado.');
                                    }

                                    $estadoPedido = (string)($pedido['estado'] ?? '');
                                    $idAlmacenPedido = (int)($pedido['id_almacen'] ?? 0);

                                    if ($estadoPedido !== 'cancelado') {
                                        $stmtDetalle = $pdo->prepare("SELECT id_producto, cantidad FROM detalle_pedidos WHERE id_pedido = :id_pedido AND cantidad > 0 FOR UPDATE");
                                        $stmtDetalle->execute([':id_pedido' => $idPedido]);
                                        $items = $stmtDetalle->fetchAll(PDO::FETCH_ASSOC);

                                        $stmtResurtir = $pdo->prepare("INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual, stock_minimo, stock_maximo)
                                                                       VALUES (:id_producto, :id_almacen, :cantidad, 2, 5)
                                                                       ON DUPLICATE KEY UPDATE cantidad_actual = cantidad_actual + VALUES(cantidad_actual)");

                                        foreach ($items as $it) {
                                            $idProducto = (int)($it['id_producto'] ?? 0);
                                            $cantidad = max(0, (int)($it['cantidad'] ?? 0));
                                            if ($idProducto <= 0 || $cantidad <= 0 || $idAlmacenPedido <= 0) {
                                                continue;
                                            }
                                            $stmtResurtir->execute([
                                                ':id_producto' => $idProducto,
                                                ':id_almacen' => $idAlmacenPedido,
                                                ':cantidad' => $cantidad,
                                            ]);
                                        }

                                        $stmtCancelarPedido = $pdo->prepare("UPDATE pedidos SET estado = 'cancelado' WHERE id_pedido = :id_pedido");
                                        $stmtCancelarPedido->execute([':id_pedido' => $idPedido]);
                                    }

                                    $stmtCancelarNotif = $pdo->prepare("UPDATE pickup_notificaciones
                                                                        SET estado = 'cancelada',
                                                                            id_usuario_seguimiento = :id_usuario,
                                                                            fecha_cancelada = NOW(),
                                                                            notas_seguimiento = CONCAT(COALESCE(notas_seguimiento, ''),
                                                                                CASE WHEN COALESCE(notas_seguimiento, '') = '' THEN '' ELSE ' | ' END,
                                                                                'CANCELADA_AUTO: pedido cancelado y stock devuelto. Motivo: ', :motivo_cancelacion),
                                                                            actualizado_en = NOW()
                                                                      WHERE id_notificacion = :id_notificacion{$scopeWhere}");
                                    $stmtCancelarNotif->execute([
                                        ':id_usuario' => $idUsuario,
                                        ':motivo_cancelacion' => $motivoCancelacionEtiqueta,
                                        ':id_notificacion' => $idNotificacion,
                                    ] + $scopeParams);

                                    $pdo->commit();
                                    $success = 'Pedido pickup cancelado y stock devuelto al inventario de sucursal.';
                                    logAudit('PICKUP_NOTIFICACION_CANCELADA', 'pickup_notificaciones', $idNotificacion, 'Cancelacion pickup con resurtido automatico');
                                } catch (Throwable $txe) {
                                    if ($pdo->inTransaction()) {
                                        $pdo->rollBack();
                                    }
                                    throw $txe;
                                }
                            }
                        }

                        if ($accion === 'marcar_estado') {
                            $transicionesPermitidas = [
                                'nueva' => ['vista'],
                                'vista' => ['apartada'],
                                'apartada' => ['atendida'],
                                'atendida' => [],
                                'cancelada' => [],
                            ];

                            if ($estado === $estadoActual) {
                                $success = 'La notificacion ya estaba en el estado seleccionado.';
                            } elseif (!in_array($estado, $transicionesPermitidas[$estadoActual] ?? [], true)) {
                                $error = 'Transicion de estado no permitida. Estado actual: ' . strtoupper($estadoActual);
                            } else {
                                $pdo->beginTransaction();
                                try {
                                    $setParts = [
                                        "estado = :estado",
                                        "id_usuario_seguimiento = :id_usuario",
                                        "fecha_vista = CASE WHEN :estado_vista IN ('vista','apartada','atendida') AND fecha_vista IS NULL THEN NOW() ELSE fecha_vista END",
                                        "fecha_atendida = CASE WHEN :estado_atendida = 'atendida' THEN NOW() ELSE fecha_atendida END",
                                        "actualizado_en = NOW()",
                                    ];

                                    if ($hasFechaApartada) {
                                        $setParts[] = "fecha_apartada = CASE WHEN :estado_apartada = 'apartada' AND fecha_apartada IS NULL THEN NOW() ELSE fecha_apartada END";
                                    }

                                    $sql = "UPDATE pickup_notificaciones
                                            SET " . implode(",\n                                                ", $setParts) . "
                                            WHERE id_notificacion = :id_notificacion{$scopeWhere}";

                                    $stmt = $pdo->prepare($sql);
                                    $params = [
                                        ':estado' => $estado,
                                        ':estado_vista' => $estado,
                                        ':estado_apartada' => $estado,
                                        ':estado_atendida' => $estado,
                                        ':id_usuario' => $idUsuario,
                                        ':id_notificacion' => $idNotificacion,
                                    ] + $scopeParams;

                                    $stmt->execute($params);

                                    if ($estado === 'atendida') {
                                        $idPedido = (int)($current['id_pedido'] ?? 0);
                                        if ($idPedido > 0) {
                                            $stmtPedido = $pdo->prepare("UPDATE pedidos
                                                                       SET estado = 'pagado',
                                                                           fecha_pago = IFNULL(fecha_pago, NOW())
                                                                     WHERE id_pedido = :id_pedido
                                                                       AND estado IN ('pendiente_pago', 'apartado', 'pagado')");
                                            $stmtPedido->execute([':id_pedido' => $idPedido]);
                                        }
                                    }

                                    $pdo->commit();
                                    $success = 'Seguimiento de pickup actualizado.';
                                    logAudit('PICKUP_NOTIFICACION_ACTUALIZADA', 'pickup_notificaciones', $idNotificacion, "Estado actualizado de {$estadoActual} a {$estado}");
                                } catch (Throwable $txe) {
                                    if ($pdo->inTransaction()) {
                                        $pdo->rollBack();
                                    }
                                    throw $txe;
                                }
                            }
                        }

                        if ($accion === 'convertir_domicilio') {
                            if (in_array($estadoActual, ['cancelada', 'atendida'], true)) {
                                $error = 'No se puede convertir una notificacion ya ' . strtolower($estadoActual) . '.';
                            } else {
                                $idPedido = (int)($current['id_pedido'] ?? 0);
                                if ($idPedido <= 0) {
                                    $error = 'Pedido pickup invalido para convertir.';
                                } else {
                                    $pdo->beginTransaction();
                                    try {
                                        $stmtPedidoConv = $pdo->prepare("SELECT estado, observaciones FROM pedidos WHERE id_pedido = :id_pedido FOR UPDATE");
                                        $stmtPedidoConv->execute([':id_pedido' => $idPedido]);
                                        $pedidoConv = $stmtPedidoConv->fetch(PDO::FETCH_ASSOC) ?: null;
                                        if (!$pedidoConv) {
                                            throw new RuntimeException('No se encontro el pedido asociado.');
                                        }
                                        if ((string)($pedidoConv['estado'] ?? '') === 'cancelado') {
                                            throw new RuntimeException('Este pedido ya esta cancelado, no se puede convertir.');
                                        }

                                        $columnExistsConv = static function (PDO $pdo, string $table, string $column): bool {
                                            $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
                                            $stmt->execute([$table, $column]);
                                            return ((int)$stmt->fetchColumn()) > 0;
                                        };
                                        $hasTipoEntregaConv = $columnExistsConv($pdo, 'pedidos', 'tipo_entrega');
                                        $hasDireccionEntregaConv = $columnExistsConv($pdo, 'pedidos', 'direccion_entrega');
                                        $hasTelefonoEntregaConv = $columnExistsConv($pdo, 'pedidos', 'telefono_entrega');
                                        $hasMapsLinkEntregaConv = $columnExistsConv($pdo, 'pedidos', 'maps_link_entrega');

                                        // Reescribe los marcadores ENTREGA/Dir/Tel embebidos en observaciones,
                                        // que otras vistas (dashboard, entregas) usan como respaldo cuando
                                        // las columnas dedicadas no existen en el esquema.
                                        $obsActual = (string)($pedidoConv['observaciones'] ?? '');
                                        $obsNueva = preg_replace('/ENTREGA:\s*Sucursal/i', 'ENTREGA: Domicilio', $obsActual, 1) ?? $obsActual;
                                        if (preg_match('/Dir:\s*[^|]*/i', $obsNueva)) {
                                            $obsNueva = preg_replace('/Dir:\s*[^|]*/i', 'Dir: ' . $nuevaDireccionEntrega, $obsNueva, 1) ?? $obsNueva;
                                        } else {
                                            $obsNueva .= ' | Dir: ' . $nuevaDireccionEntrega;
                                        }
                                        if ($nuevoTelefonoEntrega !== '') {
                                            if (preg_match('/Tel:\s*[^|]*/i', $obsNueva)) {
                                                $obsNueva = preg_replace('/Tel:\s*[^|]*/i', 'Tel: ' . $nuevoTelefonoEntrega, $obsNueva, 1) ?? $obsNueva;
                                            } else {
                                                $obsNueva .= ' | Tel: ' . $nuevoTelefonoEntrega;
                                            }
                                        }
                                        $obsNueva .= ' | CONVERTIDO_A_DOMICILIO: ' . date('Y-m-d H:i') . ' por ' . (string)($usuario['nombre'] ?? 'usuario');

                                        $setPartsConv = ['observaciones = :observaciones'];
                                        $paramsConv = [':observaciones' => $obsNueva, ':id_pedido' => $idPedido];
                                        if ($hasTipoEntregaConv) {
                                            $setPartsConv[] = 'tipo_entrega = :tipo_entrega';
                                            $paramsConv[':tipo_entrega'] = 'Domicilio';
                                        }
                                        if ($hasDireccionEntregaConv) {
                                            $setPartsConv[] = 'direccion_entrega = :direccion_entrega';
                                            $paramsConv[':direccion_entrega'] = $nuevaDireccionEntrega;
                                        }
                                        if ($hasTelefonoEntregaConv && $nuevoTelefonoEntrega !== '') {
                                            $setPartsConv[] = 'telefono_entrega = :telefono_entrega';
                                            $paramsConv[':telefono_entrega'] = $nuevoTelefonoEntrega;
                                        }
                                        if ($hasMapsLinkEntregaConv && $nuevoMapsLinkEntrega !== '') {
                                            $setPartsConv[] = 'maps_link_entrega = :maps_link_entrega';
                                            $paramsConv[':maps_link_entrega'] = $nuevoMapsLinkEntrega;
                                        }

                                        $stmtUpdateConv = $pdo->prepare('UPDATE pedidos SET ' . implode(', ', $setPartsConv) . ' WHERE id_pedido = :id_pedido');
                                        $stmtUpdateConv->execute($paramsConv);

                                        // Ya no es un pickup: se elimina para que asignar_entregas.php lo tome
                                        // de inmediato (esa vista excluye cualquier pedido con notificacion pickup).
                                        $stmtDeleteNotif = $pdo->prepare("DELETE FROM pickup_notificaciones WHERE id_notificacion = :id_notificacion{$scopeWhere}");
                                        $stmtDeleteNotif->execute([':id_notificacion' => $idNotificacion] + $scopeParams);

                                        $pdo->commit();
                                        $success = 'Pedido convertido a entrega a domicilio. Ya esta disponible para asignar repartidor.';
                                        $successActionUrl = BASE_URL . 'views/asignar_entregas.php';
                                        $successActionLabel = 'Ir a Asignar Entregas';
                                        logAudit('PEDIDO_CONVERTIDO_A_DOMICILIO', 'pedidos', $idPedido, 'Convertido de pickup sucursal a entrega a domicilio');
                                    } catch (Throwable $txe) {
                                        if ($pdo->inTransaction()) {
                                            $pdo->rollBack();
                                        }
                                        $error = $txe->getMessage();
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    $estadoFiltro = trim((string)($_GET['estado'] ?? ''));
    $where = '1=1';
    $params = [];

    if (isEncargado() || isVendedor()) {
        $where .= ' AND pn.id_almacen = :almacen';
        $params[':almacen'] = $idAlmacenUsuario;
    }

    if (in_array($estadoFiltro, ['nueva', 'vista', 'apartada', 'atendida', 'cancelada'], true)) {
        $where .= ' AND pn.estado = :estado';
        $params[':estado'] = $estadoFiltro;
    }

    $sqlList = "SELECT
            pn.*,
            p.numero_pedido,
            p.total,
            p.estado AS estado_pedido,
            p.fecha_creacion AS fecha_pedido,
            c.nombre AS cliente,
            c.telefono AS cliente_telefono,
            a.nombre AS sucursal,
            TIMESTAMPDIFF(MINUTE, pn.creado_en, NOW()) AS minutos_atraso,
            TIMESTAMPDIFF(HOUR, pn.creado_en, NOW()) AS horas_atraso
        FROM pickup_notificaciones pn
        INNER JOIN pedidos p ON p.id_pedido = pn.id_pedido
        LEFT JOIN clientes c ON c.id_cliente = pn.id_cliente
        INNER JOIN almacenes a ON a.id_almacen = pn.id_almacen
        WHERE {$where}
        ORDER BY FIELD(pn.estado, 'nueva', 'vista', 'apartada', 'atendida', 'cancelada'), pn.creado_en DESC
        LIMIT 300";

    $stmtList = $pdo->prepare($sqlList);
    $stmtList->execute($params);
    $notificaciones = $stmtList->fetchAll(PDO::FETCH_ASSOC);

    // Direccion guardada por defecto de cada cliente, para prellenar el modal de conversion a domicilio.
    $direccionDefaultPorCliente = [];
    $idsClientesConv = array_values(array_unique(array_filter(array_map(
        static fn(array $n): int => (int)($n['id_cliente'] ?? 0),
        $notificaciones
    ), static fn(int $id): bool => $id > 0)));
    if (!empty($idsClientesConv)) {
        $stmtMetaDirTable = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'cliente_direcciones'");
        $stmtMetaDirTable->execute();
        if (((int)$stmtMetaDirTable->fetchColumn()) > 0) {
            $placeholdersConv = implode(', ', array_fill(0, count($idsClientesConv), '?'));
            $stmtDirConv = $pdo->prepare("SELECT id_cliente, direccion, maps_link FROM cliente_direcciones WHERE id_cliente IN ({$placeholdersConv}) ORDER BY id_cliente ASC, es_default DESC, id_direccion ASC");
            $stmtDirConv->execute($idsClientesConv);
            foreach ($stmtDirConv->fetchAll(PDO::FETCH_ASSOC) as $rowDir) {
                $idClienteDir = (int)($rowDir['id_cliente'] ?? 0);
                if ($idClienteDir <= 0 || isset($direccionDefaultPorCliente[$idClienteDir])) {
                    continue;
                }
                $direccionDescifrada = $safeDecryptValue($rowDir['direccion'] ?? '', '');
                if ($direccionDescifrada === '') {
                    continue;
                }
                $direccionDefaultPorCliente[$idClienteDir] = [
                    'direccion' => $direccionDescifrada,
                    'maps_link' => $safeDecryptValue($rowDir['maps_link'] ?? '', ''),
                ];
            }
        }
    }

    $detallesPorPedido = [];
    $pedidosIndexados = [];
    $pedidoIds = [];
    foreach ($notificaciones as $n) {
        $idPedidoTmp = (int)($n['id_pedido'] ?? 0);
        if ($idPedidoTmp > 0) {
            $pedidoIds[$idPedidoTmp] = $idPedidoTmp;
            if (!isset($pedidosIndexados[$idPedidoTmp])) {
                $pedidosIndexados[$idPedidoTmp] = [
                    'id_pedido' => $idPedidoTmp,
                    'numero_pedido' => (string)($n['numero_pedido'] ?? ''),
                    'cliente' => (string)($n['cliente'] ?? 'N/A'),
                    'sucursal' => (string)($n['sucursal'] ?? ''),
                    'total' => (float)($n['total'] ?? 0),
                    'fecha_pedido' => (string)($n['fecha_pedido'] ?? ''),
                    'notas_seguimiento' => (string)($n['notas_seguimiento'] ?? ''),
                ];
            }
        }
    }

    if (!empty($pedidoIds)) {
        $pedidoIds = array_values($pedidoIds);
        $placeholdersPedidos = implode(', ', array_fill(0, count($pedidoIds), '?'));
        $sqlDetalles = "SELECT dp.id_pedido,
                               dp.id_producto,
                               dp.cantidad,
                               COALESCE(dp.precio_unitario, dp.precio_original, 0) AS precio_unitario,
                               COALESCE(dp.subtotal, 0) AS subtotal,
                               p.nombre,
                               p.nombre_variante,
                               p.sku,
                               COALESCE(
                                   (SELECT pi.ruta_archivo
                                    FROM producto_imagenes pi
                                    WHERE pi.id_producto = p.id_producto
                                    ORDER BY pi.orden ASC
                                    LIMIT 1),
                                   p.imagen,
                                   p.imagen_url
                               ) AS imagen_resuelta
                        FROM detalle_pedidos dp
                        INNER JOIN productos p ON p.id_producto = dp.id_producto
                        WHERE dp.id_pedido IN ({$placeholdersPedidos})
                        ORDER BY dp.id_pedido ASC, p.nombre ASC, p.nombre_variante ASC";

        $stmtDetalles = $pdo->prepare($sqlDetalles);
        $stmtDetalles->execute($pedidoIds);
        $rowsDetalles = $stmtDetalles->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rowsDetalles as $rowDetalle) {
            $idPedidoDet = (int)($rowDetalle['id_pedido'] ?? 0);
            if ($idPedidoDet <= 0) {
                continue;
            }
            if (!isset($detallesPorPedido[$idPedidoDet])) {
                $detallesPorPedido[$idPedidoDet] = [];
            }
            $rowDetalle['imagen_render'] = getProductImageUrl((string)($rowDetalle['imagen_resuelta'] ?? ''));
            $detallesPorPedido[$idPedidoDet][] = $rowDetalle;
        }
    }

    $whereCounts = '1=1';
    $paramsCounts = [];
    if (isEncargado() || isVendedor()) {
        $whereCounts .= ' AND id_almacen = :almacen';
        $paramsCounts[':almacen'] = $idAlmacenUsuario;
    }

    $stmtCounts = $pdo->prepare("SELECT estado, COUNT(*) AS total FROM pickup_notificaciones WHERE {$whereCounts} GROUP BY estado");
    $stmtCounts->execute($paramsCounts);
    $rowsCounts = $stmtCounts->fetchAll(PDO::FETCH_ASSOC);
    $counts = ['nueva' => 0, 'vista' => 0, 'apartada' => 0, 'atendida' => 0, 'cancelada' => 0];
    foreach ($rowsCounts as $row) {
        $key = (string)($row['estado'] ?? '');
        if (isset($counts[$key])) {
            $counts[$key] = (int)($row['total'] ?? 0);
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
    $notificaciones = [];
    $detallesPorPedido = [];
    $pedidosIndexados = [];
    $counts = ['nueva' => 0, 'vista' => 0, 'apartada' => 0, 'atendida' => 0, 'cancelada' => 0];
    $estadoFiltro = '';
}

include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="col s12">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap; margin-top:20px;">
                <h4 style="margin:0;"><i class="material-icons left">notifications_active</i> Notificaciones Pickup</h4>
                <a href="<?php echo BASE_URL; ?>views/dashboard.php" class="btn blue darken-4 waves-effect waves-light">
                    <i class="material-icons left">dashboard</i> Volver al Dashboard
                </a>
            </div>
            <p class="grey-text">Seguimiento pickup por sucursal: nueva, vista, apartada y atendida. Las horas se registran automaticamente al cambiar estatus.</p>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="card-panel green lighten-4 green-text text-darken-4" style="display:flex; align-items:center; justify-content:space-between; gap:12px; flex-wrap:wrap;">
            <span><?php echo esc($success); ?></span>
            <?php if ($successActionUrl !== ''): ?>
                <a href="<?php echo esc($successActionUrl); ?>" class="btn green darken-2 waves-effect waves-light">
                    <?php echo esc($successActionLabel); ?> <i class="material-icons right">arrow_forward</i>
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="card-panel red lighten-4 red-text text-darken-4"><?php echo esc($error); ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col s12 m3">
            <div class="card deep-orange lighten-1 white-text">
                <div class="card-content">
                    <span class="card-title">Nuevas</span>
                    <p style="font-size:2.4rem; margin:0;"><?php echo (int)$counts['nueva']; ?></p>
                </div>
            </div>
        </div>
        <div class="col s12 m3">
            <div class="card amber darken-2 white-text">
                <div class="card-content">
                    <span class="card-title">Vistas</span>
                    <p style="font-size:2.4rem; margin:0;"><?php echo (int)$counts['vista']; ?></p>
                </div>
            </div>
        </div>
        <div class="col s12 m3">
            <div class="card blue darken-2 white-text">
                <div class="card-content">
                    <span class="card-title">Apartadas</span>
                    <p style="font-size:2.4rem; margin:0;"><?php echo (int)$counts['apartada']; ?></p>
                </div>
            </div>
        </div>
        <div class="col s12 m3">
            <div class="card green darken-2 white-text">
                <div class="card-content">
                    <span class="card-title">Atendidas</span>
                    <p style="font-size:2.4rem; margin:0;"><?php echo (int)$counts['atendida']; ?></p>
                </div>
            </div>
        </div>
        <div class="col s12 m3">
            <div class="card grey darken-2 white-text">
                <div class="card-content">
                    <span class="card-title">Canceladas</span>
                    <p style="font-size:2.4rem; margin:0;"><?php echo (int)$counts['cancelada']; ?></p>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col s12">
            <div class="card">
                <div class="card-content">
                    <form method="GET" class="row" style="margin-bottom:0;">
                        <div class="input-field col s12 m4">
                            <select name="estado" class="browser-default" style="border:1px solid #9e9e9e;">
                                <option value="">Todos los estados</option>
                                <option value="nueva" <?php echo $estadoFiltro === 'nueva' ? 'selected' : ''; ?>>Nueva</option>
                                <option value="vista" <?php echo $estadoFiltro === 'vista' ? 'selected' : ''; ?>>Vista</option>
                                <option value="apartada" <?php echo $estadoFiltro === 'apartada' ? 'selected' : ''; ?>>Apartada</option>
                                <option value="atendida" <?php echo $estadoFiltro === 'atendida' ? 'selected' : ''; ?>>Atendida</option>
                                <option value="cancelada" <?php echo $estadoFiltro === 'cancelada' ? 'selected' : ''; ?>>Cancelada</option>
                            </select>
                        </div>
                        <div class="col s12 m8" style="display:flex; align-items:center; gap:8px; margin-top:8px;">
                            <button type="submit" class="btn indigo waves-effect waves-light">Filtrar</button>
                            <a href="pickup_notifications.php" class="btn-flat">Limpiar</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col s12">
            <div class="card">
                <div class="card-content" style="overflow-x:auto;">
                    <span class="card-title">Listado de Alertas Pickup</span>
                    <?php $conversionModalsData = []; ?>
                    <?php if (empty($notificaciones)): ?>
                        <p class="center grey-text">No hay notificaciones pickup registradas.</p>
                    <?php else: ?>
                        <table class="striped highlight responsive-table">
                            <thead>
                                <tr>
                                    <th>Pedido</th>
                                    <th>Sucursal</th>
                                    <th>Cliente</th>
                                    <th>Estado</th>
                                    <th>Atraso</th>
                                    <th>Fecha Estimada Reabasto</th>
                                    <th>Seguimiento</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($notificaciones as $n): ?>
                                    <?php
                                        if (in_array((string)$n['estado'], ['nueva', 'vista', 'apartada'], true)) {
                                            $idClienteConv = (int)($n['id_cliente'] ?? 0);
                                            $conversionModalsData[(int)$n['id_notificacion']] = [
                                                'numero_pedido' => (string)$n['numero_pedido'],
                                                'cliente' => (string)($n['cliente'] ?? 'N/A'),
                                                'direccion_prefill' => $direccionDefaultPorCliente[$idClienteConv]['direccion'] ?? '',
                                                'maps_link_prefill' => $direccionDefaultPorCliente[$idClienteConv]['maps_link'] ?? '',
                                                'telefono_prefill' => $safeDecryptValue($n['cliente_telefono'] ?? '', ''),
                                            ];
                                        }
                                    ?>
                                    <tr>
                                        <td>
                                            <?php $idPedidoUi = (int)($n['id_pedido'] ?? 0); ?>
                                            <?php if ($idPedidoUi > 0): ?>
                                                <a href="#modal-pedido-<?php echo $idPedidoUi; ?>" class="modal-trigger pickup-order-link"><strong><?php echo esc((string)$n['numero_pedido']); ?></strong></a><br>
                                                <a href="#modal-pedido-<?php echo $idPedidoUi; ?>" class="modal-trigger pickup-order-link-helper">Ver detalle de productos</a><br>
                                            <?php else: ?>
                                                <strong><?php echo esc((string)$n['numero_pedido']); ?></strong><br>
                                            <?php endif; ?>
                                            <small class="grey-text">$<?php echo number_format((float)$n['total'], 2); ?> | <?php echo esc((string)$n['fecha_pedido']); ?></small>
                                        </td>
                                        <td>
                                            <?php echo esc((string)$n['sucursal']); ?><br>
                                            <small class="grey-text">ID almacén: <?php echo (int)($n['id_almacen'] ?? 0); ?></small>
                                        </td>
                                        <td><?php echo esc((string)($n['cliente'] ?? 'N/A')); ?></td>
                                        <td>
                                            <span class="badge <?php echo $n['estado'] === 'nueva' ? 'deep-orange' : ($n['estado'] === 'vista' ? 'amber darken-2' : ($n['estado'] === 'apartada' ? 'blue darken-2' : ($n['estado'] === 'cancelada' ? 'grey darken-2' : 'green'))); ?> white-text" style="float:none;">
                                                <?php echo strtoupper((string)$n['estado']); ?>
                                            </span>
                                            <?php if (stripos((string)($n['notas_seguimiento'] ?? ''), 'TRASLADO_INTERNO_2_3H') !== false): ?>
                                                <div class="amber lighten-5 amber-text text-darken-4" style="margin-top:6px; padding:6px 8px; border-radius:6px; border-left:4px solid #ff8f00; font-size:.82rem;">
                                                    Requiere traslado interno estimado de 2 a 3 horas.
                                                </div>
                                            <?php endif; ?>
                                            <div style="margin-top:6px; font-size:.82rem;" class="grey-text">
                                                Vista: <?php echo !empty($n['fecha_vista']) ? esc((string)$n['fecha_vista']) : '---'; ?>
                                                <br>
                                                Apartada: <?php echo !empty($n['fecha_apartada']) ? esc((string)$n['fecha_apartada']) : '---'; ?>
                                                <br>
                                                Atendida: <?php echo !empty($n['fecha_atendida']) ? esc((string)$n['fecha_atendida']) : '---'; ?>
                                                <br>
                                                Cancelada: <?php echo !empty($n['fecha_cancelada']) ? esc((string)$n['fecha_cancelada']) : '---'; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <?php
                                                $minAtraso = max(0, (int)($n['minutos_atraso'] ?? 0));
                                                $hrsAtraso = intdiv($minAtraso, 60);
                                                $minsRest = $minAtraso % 60;
                                                $atrasoTxt = $hrsAtraso . 'h ' . $minsRest . 'm';
                                                $atrasoClass = $minAtraso >= 1440 ? 'red lighten-4 red-text text-darken-4' : ($minAtraso >= 360 ? 'amber lighten-4 amber-text text-darken-4' : 'green lighten-5 green-text text-darken-4');
                                            ?>
                                            <span class="<?php echo $atrasoClass; ?>" style="display:inline-block; padding:4px 8px; border-radius:6px; font-weight:600;">
                                                <?php echo esc($atrasoTxt); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="grey-text">Hora automatica por estatus</span>
                                        </td>
                                        <td>
                                            <div style="display:flex; gap:6px; flex-wrap:wrap; min-width:260px;">
                                                <?php if ((string)$n['estado'] === 'nueva'): ?>
                                                    <form method="POST" style="margin:0;">
                                                        <?php echo csrfInput(); ?>
                                                        <input type="hidden" name="accion" value="marcar_estado">
                                                        <input type="hidden" name="id_notificacion" value="<?php echo (int)$n['id_notificacion']; ?>">
                                                        <input type="hidden" name="estado" value="vista">
                                                        <button type="submit" class="btn-small amber darken-2 waves-effect waves-light">Marcar vista</button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ((string)$n['estado'] === 'vista'): ?>
                                                    <form method="POST" style="margin:0;">
                                                        <?php echo csrfInput(); ?>
                                                        <input type="hidden" name="accion" value="marcar_estado">
                                                        <input type="hidden" name="id_notificacion" value="<?php echo (int)$n['id_notificacion']; ?>">
                                                        <input type="hidden" name="estado" value="apartada">
                                                        <button type="submit" class="btn-small blue darken-2 waves-effect waves-light">Marcar apartada</button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ((string)$n['estado'] === 'apartada'): ?>
                                                    <form method="POST" style="margin:0;">
                                                        <?php echo csrfInput(); ?>
                                                        <input type="hidden" name="accion" value="marcar_estado">
                                                        <input type="hidden" name="id_notificacion" value="<?php echo (int)$n['id_notificacion']; ?>">
                                                        <input type="hidden" name="estado" value="atendida">
                                                        <button type="submit" class="btn-small green darken-2 waves-effect waves-light">Marcar atendida y pagada</button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if ($cancelSupportReady && in_array((string)$n['estado'], ['nueva', 'vista', 'apartada'], true)): ?>
                                                    <form method="POST" data-cancel-form="1" style="margin:0; display:flex; gap:6px; flex-wrap:wrap; align-items:center;">
                                                        <?php echo csrfInput(); ?>
                                                        <input type="hidden" name="accion" value="cancelar_pedido">
                                                        <input type="hidden" name="id_notificacion" value="<?php echo (int)$n['id_notificacion']; ?>">
                                                        <select name="motivo_cancelacion" data-cancel-reason="1" class="browser-default" style="min-width:220px; max-width:280px; border:1px solid #9e9e9e; height:30px;">
                                                            <option value="" selected disabled>Motivo de cancelacion</option>
                                                            <?php foreach ($cancelReasonOptions as $reasonKey => $reasonLabel): ?>
                                                                <option value="<?php echo esc($reasonKey); ?>"><?php echo esc($reasonLabel); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <input type="text" name="motivo_cancelacion_otro" data-cancel-other="1" maxlength="180" placeholder="Si seleccionas Otro, especifica aqui" style="min-width:230px; max-width:320px; display:none;" />
                                                        <button type="submit" class="btn-small red darken-2 waves-effect waves-light">Cancelar y resurtir stock</button>
                                                    </form>
                                                <?php endif; ?>

                                                <?php if (in_array((string)$n['estado'], ['nueva', 'vista', 'apartada'], true)): ?>
                                                    <a href="#modal-convertir-<?php echo (int)$n['id_notificacion']; ?>" class="btn-small blue-grey darken-1 waves-effect waves-light modal-trigger">
                                                        <i class="material-icons left" style="margin-right:4px;">local_shipping</i>Cambiar a domicilio
                                                    </a>
                                                <?php endif; ?>

                                                <?php if ((string)$n['estado'] === 'atendida'): ?>
                                                    <span class="grey-text text-darken-1">Flujo completado.</span>
                                                <?php endif; ?>

                                                <?php if ((string)$n['estado'] === 'cancelada'): ?>
                                                    <span class="grey-text text-darken-1">Pedido cancelado y stock devuelto.</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($conversionModalsData)): ?>
    <?php foreach ($conversionModalsData as $idNotifModal => $convMeta): ?>
        <div id="modal-convertir-<?php echo (int)$idNotifModal; ?>" class="modal">
            <div class="modal-content">
                <h5 style="margin-top:0;">Cambiar a entrega a domicilio</h5>
                <p class="grey-text" style="margin-top:4px;">
                    Pedido <strong><?php echo esc($convMeta['numero_pedido']); ?></strong> · Cliente: <?php echo esc($convMeta['cliente']); ?>
                </p>
                <p class="orange-text text-darken-3" style="font-size:0.88rem;">
                    <i class="material-icons tiny">info</i> El cliente lo pidio para recoger en sucursal, pero cambio de opinion y quiere que se lo lleven. Captura la direccion real de entrega para continuar; el pedido quedara disponible en "Asignar Entregas" para asignarle un repartidor.
                </p>
                <form method="POST" class="convertir-domicilio-form">
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="accion" value="convertir_domicilio">
                    <input type="hidden" name="id_notificacion" value="<?php echo (int)$idNotifModal; ?>">
                    <div class="input-field">
                        <textarea name="direccion_entrega" class="materialize-textarea" required><?php echo esc($convMeta['direccion_prefill']); ?></textarea>
                        <label class="active">Direccion de entrega</label>
                        <?php if ($convMeta['direccion_prefill'] !== ''): ?>
                            <span class="helper-text">Prellenada con su domicilio guardado. Verificala o ajustala antes de continuar.</span>
                        <?php else: ?>
                            <span class="helper-text">Este cliente no tiene domicilio guardado. Captura la direccion completa.</span>
                        <?php endif; ?>
                    </div>
                    <div class="input-field">
                        <input type="tel" name="telefono_entrega" value="<?php echo esc($convMeta['telefono_prefill']); ?>" maxlength="19" inputmode="numeric">
                        <label class="active">Telefono de contacto</label>
                    </div>
                    <div class="input-field">
                        <input type="url" name="maps_link_entrega" value="<?php echo esc($convMeta['maps_link_prefill']); ?>">
                        <label class="active">Link de Google Maps (opcional)</label>
                    </div>
                    <div class="modal-footer" style="padding:0; background:transparent; text-align:left;">
                        <a href="#!" class="modal-close waves-effect btn-flat">Cancelar</a>
                        <button type="submit" class="btn blue-grey darken-1 waves-effect waves-light">
                            Confirmar cambio a domicilio <i class="material-icons right">local_shipping</i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (!empty($pedidosIndexados)): ?>
    <?php foreach ($pedidosIndexados as $idPedidoModal => $pedidoMeta): ?>
        <?php
            $rowsPedido = $detallesPorPedido[$idPedidoModal] ?? [];
            $subtotalPreview = 0.0;
            foreach ($rowsPedido as $prd) {
                $subtotalPreview += (float)($prd['subtotal'] ?? 0);
            }
            $transferItems = parseSupportTransferItemsFromNotes((string)($pedidoMeta['notas_seguimiento'] ?? ''));
            $hasSupportTransfer = !empty($transferItems);

            $transferLookup = [];
            foreach ($transferItems as $ti) {
                $keyNorm = (string)($ti['normalized'] ?? '');
                if ($keyNorm !== '') {
                    $transferLookup[$keyNorm] = $ti;
                }
            }
        ?>
        <div id="modal-pedido-<?php echo (int)$idPedidoModal; ?>" class="modal modal-fixed-footer">
            <div class="modal-content">
                <h5 style="margin-top:0;">Detalle de pedido: <?php echo esc((string)$pedidoMeta['numero_pedido']); ?></h5>
                <p class="grey-text" style="margin-top:4px;">
                    Cliente: <?php echo esc((string)$pedidoMeta['cliente']); ?>
                    | Sucursal: <?php echo esc((string)$pedidoMeta['sucursal']); ?>
                    | Fecha: <?php echo esc((string)$pedidoMeta['fecha_pedido']); ?>
                </p>

                <?php if ($hasSupportTransfer): ?>
                    <div class="pickup-transfer-alert z-depth-1">
                        <i class="material-icons left">warning</i>
                        <strong>ATENCION:</strong> Este pedido tiene faltantes en sucursal y <strong>debe surtirse desde almacen de apoyo</strong>.
                        Identifica y prioriza los productos marcados en rojo.
                    </div>
                <?php endif; ?>

                <?php if (empty($rowsPedido)): ?>
                    <div class="card-panel amber lighten-5 amber-text text-darken-4" style="margin-top:16px;">
                        No se encontraron productos en detalle para este pedido.
                    </div>
                <?php else: ?>
                    <table class="striped responsive-table" style="margin-top:12px;">
                        <thead>
                            <tr>
                                <th>Producto</th>
                                <th>Foto</th>
                                <th>SKU</th>
                                <th>Cantidad</th>
                                <th>P. Unitario</th>
                                <th>Subtotal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rowsPedido as $prd): ?>
                                <?php
                                    $nombrePrd = (string)($prd['nombre'] ?? 'Producto');
                                    $variantePrd = trim((string)($prd['nombre_variante'] ?? ''));
                                    $nombreFinal = $variantePrd !== '' ? ($nombrePrd . ' - ' . $variantePrd) : $nombrePrd;
                                    $normalizedName = normalizePickupProductLabel($nombreFinal);
                                    $supportHit = null;
                                    if (isset($transferLookup[$normalizedName])) {
                                        $supportHit = $transferLookup[$normalizedName];
                                    } else {
                                        foreach ($transferItems as $ti) {
                                            $nTi = (string)($ti['normalized'] ?? '');
                                            if ($nTi !== '' && (strpos($normalizedName, $nTi) !== false || strpos($nTi, $normalizedName) !== false)) {
                                                $supportHit = $ti;
                                                break;
                                            }
                                        }
                                    }
                                    $mustSupport = $supportHit !== null;
                                ?>
                                <tr class="<?php echo $mustSupport ? 'pickup-support-row' : ''; ?>">
                                    <td>
                                        <?php echo esc($nombreFinal); ?>
                                        <?php if ($mustSupport): ?>
                                            <div class="pickup-support-chip">SURTIR DESDE APOYO</div>
                                            <div class="pickup-support-note">Faltan <?php echo (int)($supportHit['faltan'] ?? 0); ?> en sucursal</div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <img src="<?php echo esc((string)($prd['imagen_render'] ?? getDefaultProductImageUrl())); ?>"
                                             onerror="this.onerror=null;this.src='<?php echo getDefaultProductImageUrl(); ?>';"
                                             class="pickup-product-thumb"
                                             alt="Producto <?php echo esc($nombreFinal); ?>">
                                    </td>
                                    <td><?php echo esc((string)($prd['sku'] ?? 'N/A')); ?></td>
                                    <td><?php echo (int)($prd['cantidad'] ?? 0); ?></td>
                                    <td>$<?php echo number_format((float)($prd['precio_unitario'] ?? 0), 2); ?></td>
                                    <td>$<?php echo number_format((float)($prd['subtotal'] ?? 0), 2); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>

                    <div class="right-align" style="margin-top:14px; padding:10px 12px; background:#f5f5f5; border-radius:6px;">
                        <strong>Total pedido:</strong> $<?php echo number_format((float)$pedidoMeta['total'], 2); ?>
                        <?php if ($subtotalPreview > 0): ?>
                            <br><small class="grey-text">Subtotal detalle: $<?php echo number_format($subtotalPreview, 2); ?></small>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button class="modal-close btn-flat">Cerrar</button>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<style>
    .pickup-order-link {
        color: #1a237e;
        text-decoration: underline;
        font-weight: 700;
    }
    .pickup-order-link-helper {
        color: #1565c0;
        font-size: 0.82rem;
    }
    .pickup-transfer-alert {
        margin-top: 12px;
        padding: 12px 14px;
        border-radius: 8px;
        border-left: 6px solid #c62828;
        background: #ffebee;
        color: #b71c1c;
        font-size: 0.96rem;
    }
    .pickup-support-row {
        background: #fff5f5;
        box-shadow: inset 4px 0 0 #d32f2f;
    }
    .pickup-support-chip {
        margin-top: 6px;
        display: inline-block;
        background: #d32f2f;
        color: #fff;
        padding: 2px 8px;
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.2px;
    }
    .pickup-support-note {
        margin-top: 4px;
        color: #b71c1c;
        font-size: 0.8rem;
        font-weight: 600;
    }
    .pickup-product-thumb {
        width: 52px;
        height: 52px;
        border-radius: 8px;
        object-fit: contain;
        background: #fff;
        border: 1px solid #e0e0e0;
    }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modalElems = document.querySelectorAll('.modal');
    if (typeof M !== 'undefined' && M.Modal) {
        M.Modal.init(modalElems);
    }

    document.querySelectorAll('form[data-cancel-form="1"]').forEach((formEl) => {
        const reasonSelect = formEl.querySelector('select[data-cancel-reason="1"]');
        const otherInput = formEl.querySelector('input[data-cancel-other="1"]');
        if (!reasonSelect || !otherInput) {
            return;
        }

        const syncOtherFieldVisibility = () => {
            const mustShow = reasonSelect.value === 'otro';
            otherInput.style.display = mustShow ? 'inline-block' : 'none';
            otherInput.required = mustShow;
            if (!mustShow) {
                otherInput.value = '';
            }
        };

        reasonSelect.addEventListener('change', syncOtherFieldVisibility);
        syncOtherFieldVisibility();
    });
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
