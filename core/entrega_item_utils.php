<?php
declare(strict_types=1);

require_once __DIR__ . '/articulo_libre_utils.php';

/**
 * Estados de pedido en los que el repartidor todavia puede editar la lista de productos
 * (marcar uno como no entregado sin cancelar todo el pedido).
 */
const PEDIDO_ENTREGA_EDITABLE_ESTADOS = ['pendiente_pago', 'pagado', 'en_reparto'];

/**
 * Marca un producto de un pedido como rechazado/no entregado por el cliente (p.ej. ya no
 * lo quiso al momento de la entrega, o el staff corrige el pedido): regresa la cantidad al
 * inventario del almacen del pedido, registra el movimiento y descuenta el producto del
 * total a cobrar. No permite dejar el pedido sin ningun producto entregado; para eso existe
 * la cancelacion completa del pedido (dbCancelOrderByCustomer / accion=cancelar_entrega).
 *
 * Usada tanto por el repartidor (views/entregas.php, con $idRepartidorFiltro = su propio
 * id_usuario y los estados por defecto) como por admin/encargado (views/asignar_entregas.php,
 * con $idRepartidorFiltro = null para poder editar el pedido de cualquier repartidor, y
 * $estadosPermitidos ampliado para incluir pedidos ya entregados).
 *
 * @param ?int $idRepartidorFiltro Si se da, solo permite editar pedidos asignados a ese
 *                                 repartidor (uso del propio repartidor). Null = sin filtro
 *                                 (uso de admin/encargado).
 * @param ?array $estadosPermitidos Estados de pedido desde los que se puede editar. Null usa
 *                                  PEDIDO_ENTREGA_EDITABLE_ESTADOS (el flujo del repartidor).
 * @return array{success: bool, message: string}
 */
function dbMarkProductoNoEntregado(PDO $pdo, int $idPedido, int $idDetalle, ?int $idRepartidorFiltro, string $motivoEtiqueta, ?int $idUsuarioAccion = null, ?array $estadosPermitidos = null): array
{
    if ($idPedido <= 0 || $idDetalle <= 0 || ($idRepartidorFiltro !== null && $idRepartidorFiltro <= 0)) {
        return ['success' => false, 'message' => 'Datos invalidos.'];
    }

    $motivoEtiqueta = trim($motivoEtiqueta);
    if ($motivoEtiqueta === '') {
        return ['success' => false, 'message' => 'Debes indicar un motivo.'];
    }

    $estadosPermitidos = $estadosPermitidos ?? PEDIDO_ENTREGA_EDITABLE_ESTADOS;

    // FOR UPDATE serializa acciones concurrentes sobre el mismo pedido en MySQL; SQLite
    // (usado en pruebas) no soporta esta clausula.
    $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $lockClause = $isMysql ? ' FOR UPDATE' : '';
    $repartidorFilterClause = $idRepartidorFiltro !== null ? ' AND id_repartidor = :id_repartidor' : '';

    try {
        $pdo->beginTransaction();

        $stmtPedido = $pdo->prepare("SELECT estado, id_almacen, subtotal, descuento_total, total FROM pedidos WHERE id_pedido = :id_pedido{$repartidorFilterClause}{$lockClause}");
        $paramsPedido = [':id_pedido' => $idPedido];
        if ($idRepartidorFiltro !== null) {
            $paramsPedido[':id_repartidor'] = $idRepartidorFiltro;
        }
        $stmtPedido->execute($paramsPedido);
        $pedido = $stmtPedido->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$pedido) {
            $pdo->rollBack();
            $mensajeNoEncontrado = $idRepartidorFiltro !== null ? 'No se encontro el pedido o no esta asignado a ti.' : 'No se encontro el pedido.';
            return ['success' => false, 'message' => $mensajeNoEncontrado];
        }

        $estadoPedido = (string)($pedido['estado'] ?? '');
        if (!in_array($estadoPedido, $estadosPermitidos, true)) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Este pedido ya no permite editar productos (estado actual: ' . strtoupper($estadoPedido) . ').'];
        }

        $idAlmacenPedido = (int)($pedido['id_almacen'] ?? 0);

        $stmtItem = $pdo->prepare("SELECT id_producto, cantidad, subtotal, monto_descuento, estado_entrega
                                    FROM detalle_pedidos
                                    WHERE id_detalle = :id_detalle AND id_pedido = :id_pedido{$lockClause}");
        $stmtItem->execute([':id_detalle' => $idDetalle, ':id_pedido' => $idPedido]);
        $item = $stmtItem->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$item) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'El producto no pertenece a este pedido.'];
        }
        if ((string)$item['estado_entrega'] === 'rechazado') {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Este producto ya estaba marcado como no entregado.'];
        }

        // No permite dejar el pedido sin ningun producto entregado: para eso ya existe
        // "No pude entregar" (cancelar_entrega), que cancela todo el pedido.
        $stmtRestantes = $pdo->prepare("SELECT COUNT(*) FROM detalle_pedidos
                                         WHERE id_pedido = :id_pedido AND id_detalle != :id_detalle AND estado_entrega = 'entregado'");
        $stmtRestantes->execute([':id_pedido' => $idPedido, ':id_detalle' => $idDetalle]);
        if ((int)$stmtRestantes->fetchColumn() <= 0) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'No puedes marcar el ultimo producto como no entregado; usa "No pude entregar" para cancelar todo el pedido.'];
        }

        $idProducto = (int)($item['id_producto'] ?? 0);
        $cantidad = max(0, (int)($item['cantidad'] ?? 0));
        $subtotalItem = (float)($item['subtotal'] ?? 0);
        $descuentoItem = (float)($item['monto_descuento'] ?? 0);
        $subtotalBaseItem = round($subtotalItem + $descuentoItem, 2);

        // Un articulo libre (fuera de catalogo) nunca tuvo inventario: no hay nada que regresar.
        if ($idProducto > 0 && $cantidad > 0 && $idAlmacenPedido > 0 && !articuloLibreEsProducto($pdo, $idProducto)) {
            if ($isMysql) {
                // Mismo patron de resurtido que 'cancelar_entrega' (dbCancelOrderByCustomer
                // usa UPDATE simple porque ahi la fila ya existe; aqui puede no existir si
                // el producto nunca tuvo stock en este almacen).
                $stmtResurtir = $pdo->prepare("INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual, stock_minimo, stock_maximo)
                                               VALUES (:id_producto, :id_almacen, :cantidad, 2, 5)
                                               ON DUPLICATE KEY UPDATE cantidad_actual = cantidad_actual + VALUES(cantidad_actual)");
                $stmtResurtir->execute([
                    ':id_producto' => $idProducto,
                    ':id_almacen' => $idAlmacenPedido,
                    ':cantidad' => $cantidad,
                ]);
            } else {
                // SQLite (pruebas): no soporta ON DUPLICATE KEY; intenta UPDATE y si no
                // afecta ninguna fila, inserta una nueva.
                $stmtUpd = $pdo->prepare('UPDATE inventario_almacen SET cantidad_actual = cantidad_actual + ? WHERE id_producto = ? AND id_almacen = ?');
                $stmtUpd->execute([$cantidad, $idProducto, $idAlmacenPedido]);
                if ($stmtUpd->rowCount() === 0) {
                    $pdo->prepare('INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual, stock_minimo, stock_maximo) VALUES (?, ?, ?, 2, 5)')
                        ->execute([$idProducto, $idAlmacenPedido, $cantidad]);
                }
            }

            $stmtMovEntrada = $pdo->prepare(
                "INSERT INTO movimientos_inventario (id_producto, tipo_movimiento, id_almacen_destino, cantidad, id_usuario, observacion)
                 VALUES (:producto, 'entrada', :almacen, :cantidad, :usuario, :observacion)"
            );
            $stmtMovEntrada->execute([
                ':producto' => $idProducto,
                ':almacen' => $idAlmacenPedido,
                ':cantidad' => $cantidad,
                ':usuario' => $idUsuarioAccion,
                ':observacion' => 'Producto no entregado, pedido #' . $idPedido . ': ' . $motivoEtiqueta,
            ]);

            // Regresa las unidades a sus lotes de origen (best-effort).
            $lotesRegresados = loteRegresarDetalleALotes($pdo, $idDetalle);
            if ($lotesRegresados > 0 && $lotesRegresados < $cantidad) {
                error_log("dbMarkProductoNoEntregado: detalle {$idDetalle} regreso solo {$lotesRegresados}/{$cantidad} unidades a lotes_inventario");
            }
        }

        $stmtRechazar = $pdo->prepare("UPDATE detalle_pedidos SET estado_entrega = 'rechazado', motivo_rechazo = :motivo WHERE id_detalle = :id_detalle");
        $stmtRechazar->execute([':motivo' => $motivoEtiqueta, ':id_detalle' => $idDetalle]);

        // Ajusta lo que se debe cobrar al entregar: ya no incluye este producto. Se calcula
        // en PHP (en vez de GREATEST() en SQL) para que sea portable entre MySQL y SQLite.
        $nuevoSubtotal = max(0.0, round((float)$pedido['subtotal'] - $subtotalBaseItem, 2));
        $nuevoDescuento = max(0.0, round((float)$pedido['descuento_total'] - $descuentoItem, 2));
        $nuevoTotal = max(0.0, round((float)$pedido['total'] - $subtotalItem, 2));

        $stmtAjustarTotales = $pdo->prepare('UPDATE pedidos SET subtotal = ?, descuento_total = ?, total = ? WHERE id_pedido = ?');
        $stmtAjustarTotales->execute([$nuevoSubtotal, $nuevoDescuento, $nuevoTotal, $idPedido]);

        $pdo->commit();

        if (function_exists('logAudit')) {
            logAudit('PRODUCTO_NO_ENTREGADO', 'detalle_pedidos', $idDetalle, 'Repartidor marco producto como no entregado en pedido #' . $idPedido . '. Motivo: ' . $motivoEtiqueta);
        }

        return ['success' => true, 'message' => 'Producto marcado como no entregado. El stock fue devuelto al inventario.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Cancela un pedido de entrega a domicilio completo (todos sus productos, no uno a uno):
 * regresa cada cantidad al inventario del almacen del pedido, registra los movimientos y
 * marca el pedido como 'cancelado'. Es el flujo que dbMarkProductoNoEntregado() senala
 * cuando ya no queda mas de un producto entregado ("usa 'No pude entregar'...").
 *
 * Usada tanto por el repartidor (views/entregas.php, accion=cancelar_entrega, con
 * $idRepartidorFiltro = su propio id_usuario) como por admin/encargado
 * (views/asignar_entregas.php, accion=cancelar_pedido_completo, con $idRepartidorFiltro =
 * null para poder cancelar el pedido de cualquier repartidor). El pedido permanece asignado
 * al repartidor (no se limpia id_repartidor) para conservar el historial: una vez
 * 'cancelado' deja de aparecer en "Por Asignar" y deja de ser editable en "Asignadas".
 *
 * @param ?int $idRepartidorFiltro Si se da, solo permite cancelar pedidos asignados a ese
 *                                 repartidor (uso del propio repartidor). Null = sin filtro
 *                                 (uso de admin/encargado).
 * @param ?array $estadosPermitidos Estados de pedido desde los que se puede cancelar. Null usa
 *                                  PEDIDO_ENTREGA_EDITABLE_ESTADOS.
 * @param string $obsMarcador Etiqueta que se agrega a observaciones como "| {marcador}: motivo".
 *                             El default lo usa admin/encargado; el repartidor (views/entregas.php)
 *                             pasa 'ENTREGA_NO_REALIZADA' para no romper
 *                             alexInsightsGetRepartidorCancellations() (core/alex_insights_utils.php),
 *                             que ya buscaba ese marcador literal en pedidos.observaciones.
 * @return array{success: bool, message: string}
 */
function dbCancelarPedidoCompleto(PDO $pdo, int $idPedido, ?int $idRepartidorFiltro, string $motivoEtiqueta, ?int $idUsuarioAccion = null, ?array $estadosPermitidos = null, string $auditAction = 'PEDIDO_ENTREGA_CANCELADA', string $obsMarcador = 'PEDIDO_CANCELADO'): array
{
    if ($idPedido <= 0 || ($idRepartidorFiltro !== null && $idRepartidorFiltro <= 0)) {
        return ['success' => false, 'message' => 'Datos invalidos.'];
    }

    $motivoEtiqueta = trim($motivoEtiqueta);
    if ($motivoEtiqueta === '') {
        return ['success' => false, 'message' => 'Debes indicar un motivo.'];
    }

    $estadosPermitidos = $estadosPermitidos ?? PEDIDO_ENTREGA_EDITABLE_ESTADOS;

    $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $lockClause = $isMysql ? ' FOR UPDATE' : '';
    $repartidorFilterClause = $idRepartidorFiltro !== null ? ' AND id_repartidor = :id_repartidor' : '';

    try {
        $pdo->beginTransaction();

        $stmtPedido = $pdo->prepare("SELECT estado, id_almacen, observaciones FROM pedidos WHERE id_pedido = :id_pedido{$repartidorFilterClause}{$lockClause}");
        $paramsPedido = [':id_pedido' => $idPedido];
        if ($idRepartidorFiltro !== null) {
            $paramsPedido[':id_repartidor'] = $idRepartidorFiltro;
        }
        $stmtPedido->execute($paramsPedido);
        $pedido = $stmtPedido->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$pedido) {
            $pdo->rollBack();
            $mensajeNoEncontrado = $idRepartidorFiltro !== null ? 'No se encontro el pedido o no esta asignado a ti.' : 'No se encontro el pedido.';
            return ['success' => false, 'message' => $mensajeNoEncontrado];
        }

        $estadoPedido = (string)($pedido['estado'] ?? '');
        if (!in_array($estadoPedido, $estadosPermitidos, true)) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Este pedido ya no se puede cancelar (estado actual: ' . strtoupper($estadoPedido) . ').'];
        }

        $idAlmacenPedido = (int)($pedido['id_almacen'] ?? 0);

        // Solo restituye lo que sigue 'entregado': un item ya 'rechazado' (quitado antes de
        // cancelar todo el pedido) ya devolvio su cantidad al inventario en ese momento.
        $stmtItems = $pdo->prepare("SELECT id_detalle, id_producto, cantidad FROM detalle_pedidos WHERE id_pedido = :id_pedido AND cantidad > 0 AND estado_entrega = 'entregado'{$lockClause}");
        $stmtItems->execute([':id_pedido' => $idPedido]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

        $stmtResurtir = $isMysql
            ? $pdo->prepare("INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual, stock_minimo, stock_maximo)
                              VALUES (:id_producto, :id_almacen, :cantidad, 2, 5)
                              ON DUPLICATE KEY UPDATE cantidad_actual = cantidad_actual + VALUES(cantidad_actual)")
            : null;
        // SQLite (pruebas): no soporta ON DUPLICATE KEY; intenta UPDATE y si no afecta ninguna
        // fila, inserta una nueva.
        $stmtUpdSqlite = $isMysql ? null : $pdo->prepare('UPDATE inventario_almacen SET cantidad_actual = cantidad_actual + ? WHERE id_producto = ? AND id_almacen = ?');
        $stmtInsSqlite = $isMysql ? null : $pdo->prepare('INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual, stock_minimo, stock_maximo) VALUES (?, ?, ?, 2, 5)');
        $stmtMovEntrada = $pdo->prepare(
            "INSERT INTO movimientos_inventario (id_producto, tipo_movimiento, id_almacen_destino, cantidad, id_usuario, observacion)
             VALUES (:producto, 'entrada', :almacen, :cantidad, :usuario, :observacion)"
        );

        foreach ($items as $it) {
            $idProducto = (int)($it['id_producto'] ?? 0);
            $cantidad = max(0, (int)($it['cantidad'] ?? 0));
            if ($idProducto <= 0 || $cantidad <= 0 || $idAlmacenPedido <= 0) {
                continue;
            }
            if (articuloLibreEsProducto($pdo, $idProducto)) {
                continue; // articulo libre: sin inventario que regresar
            }

            if ($isMysql) {
                $stmtResurtir->execute([
                    ':id_producto' => $idProducto,
                    ':id_almacen' => $idAlmacenPedido,
                    ':cantidad' => $cantidad,
                ]);
            } else {
                $stmtUpdSqlite->execute([$cantidad, $idProducto, $idAlmacenPedido]);
                if ($stmtUpdSqlite->rowCount() === 0) {
                    $stmtInsSqlite->execute([$idProducto, $idAlmacenPedido, $cantidad]);
                }
            }

            $stmtMovEntrada->execute([
                ':producto' => $idProducto,
                ':almacen' => $idAlmacenPedido,
                ':cantidad' => $cantidad,
                ':usuario' => $idUsuarioAccion,
                ':observacion' => 'Pedido #' . $idPedido . ' cancelado por completo. Motivo: ' . $motivoEtiqueta,
            ]);

            // Regresa las unidades a sus lotes de origen (best-effort).
            $idDetalleIt = (int)($it['id_detalle'] ?? 0);
            $lotesRegresados = loteRegresarDetalleALotes($pdo, $idDetalleIt);
            if ($lotesRegresados > 0 && $lotesRegresados < $cantidad) {
                error_log("dbCancelarPedidoCompleto: detalle {$idDetalleIt} regreso solo {$lotesRegresados}/{$cantidad} unidades a lotes_inventario");
            }
        }

        $obsActual = (string)($pedido['observaciones'] ?? '');
        $obsNueva = trim($obsActual . ' | ' . $obsMarcador . ': ' . $motivoEtiqueta);

        $stmtCancelar = $pdo->prepare("UPDATE pedidos SET estado = 'cancelado', observaciones = :observaciones WHERE id_pedido = :id_pedido");
        $stmtCancelar->execute([
            ':observaciones' => $obsNueva,
            ':id_pedido' => $idPedido,
        ]);

        $pdo->commit();

        if (function_exists('logAudit')) {
            logAudit($auditAction, 'pedidos', $idPedido, 'Pedido cancelado por completo. Motivo: ' . $motivoEtiqueta);
        }

        return ['success' => true, 'message' => 'Pedido cancelado correctamente. El stock fue devuelto al inventario.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => $e->getMessage()];
    }
}

/**
 * Precios de un renglon de la tarjeta de entrega (views/entregas.php): lo que el repartidor le dice al cliente.
 *
 * "Precio de lista" de una pieza = el MAYOR entre precio_original (p.ej. antes de una oferta) y precio_unitario
 * (lo que se cobro por pieza). Hay descuento visible cuando el precio de lista de todas las piezas supera el
 * subtotal cobrado; cubre las dos formas en que se guarda:
 *   - oferta: precio_original > precio_unitario (el subtotal ya es unitario x cantidad);
 *   - descuento de linea del POS (sales.php): precio_original = precio_unitario y detalle_pedidos.subtotal ya
 *     trae restado monto_descuento (ver dbMarkProductoNoEntregado: "100 base - 20 descuento = 80").
 * Antes solo se cubria la oferta, asi que un descuento del POS se veia como un subtotal rebajado sin explicacion.
 *
 * @param ?float $subtotal detalle_pedidos.subtotal; null = sin dato (se asume unitario x cantidad).
 * @return array{cantidad: int, subtotal: float, unitario: float, mostrar_unitario: bool, lista_total: float, con_descuento: bool}
 */
function entregaPreciosItem(int $cantidad, float $precioUnitario, float $precioOriginal, ?float $subtotal): array
{
    $cantidad = max(1, $cantidad);
    $precioUnitario = max(0.0, $precioUnitario);
    $subtotalCobrado = $subtotal !== null ? max(0.0, $subtotal) : $precioUnitario * $cantidad;
    $listaTotal = max($precioOriginal, $precioUnitario) * $cantidad;

    return [
        'cantidad' => $cantidad,
        'subtotal' => $subtotalCobrado,
        'unitario' => $precioUnitario,
        'mostrar_unitario' => $cantidad > 1 && $precioUnitario > 0,
        'lista_total' => $listaTotal,
        'con_descuento' => $precioUnitario > 0 && $listaTotal > $subtotalCobrado + 0.004,
    ];
}

/**
 * Productos que siguen en pie en un pedido (excluye los marcados como rechazados/no entregados
 * por el repartidor o por admin), con el nombre ya armado con su variante. Es lo que se le
 * lista al cliente en el aviso de WhatsApp con la hora estimada (routeBuildWaMessage en
 * views/entregas.php).
 *
 * @param array<int, array<string, mixed>> $detalles Filas de detalle_pedidos + nombre/nombre_variante.
 * @return list<array{nombre: string, cantidad: int}>
 */
function entregaWaProductosVigentes(array $detalles): array
{
    $productos = [];
    foreach ($detalles as $detalle) {
        // Sin columna o en NULL = entregado (mismo criterio que la tarjeta de entregas.php).
        if ((string)($detalle['estado_entrega'] ?? 'entregado') !== 'entregado') {
            continue;
        }
        $nombre = trim((string)($detalle['nombre'] ?? ''));
        $variante = trim((string)($detalle['nombre_variante'] ?? ''));
        if ($variante !== '') {
            $nombre .= ' - ' . $variante;
        }
        $productos[] = ['nombre' => $nombre, 'cantidad' => (int)($detalle['cantidad'] ?? 0)];
    }
    return $productos;
}

/**
 * Datos ACTUALES de cada pedido listado en entregas.php para el aviso de WhatsApp de la ruta:
 * la ruta guardada en localStorage trae los productos/total de cuando se genero, y desde
 * entonces pudieron quitarse productos o el cargo de envio.
 *
 * @param array<int, array<string, mixed>> $entregas Filas de pedidos (id_pedido, total, costo_envio).
 * @param array<int, array<int, array<string, mixed>>> $detallesPorPedido id_pedido => filas de detalle.
 * @return array<int, array{productos: list<array{nombre: string, cantidad: int}>, total: float, costo_envio: float}>
 */
function entregaWaDatosVivos(array $entregas, array $detallesPorPedido): array
{
    $datos = [];
    foreach ($entregas as $entrega) {
        $idPedido = (int)($entrega['id_pedido'] ?? 0);
        if ($idPedido <= 0) {
            continue;
        }
        $datos[$idPedido] = [
            'productos' => entregaWaProductosVigentes($detallesPorPedido[$idPedido] ?? []),
            'total' => (float)($entrega['total'] ?? 0),
            'costo_envio' => max(0.0, (float)($entrega['costo_envio'] ?? 0)),
        ];
    }
    return $datos;
}

/**
 * JSON de entregaWaDatosVivos() seguro para incrustar en un <script>: escapa < > & ' " (un
 * nombre de producto con "</script>" no puede cerrar la etiqueta) y nunca devuelve algo que
 * rompa la sintaxis del JS (UTF-8 invalido se sustituye; ante cualquier otro fallo, "{}" y el
 * aviso cae a los datos guardados con la ruta).
 */
function entregaWaDatosVivosJson(array $datos): string
{
    $json = json_encode(
        (object)$datos,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE
    );
    return $json === false ? '{}' : $json;
}
