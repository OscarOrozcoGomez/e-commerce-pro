<?php
declare(strict_types=1);

/**
 * Estados de pedido en los que el repartidor todavia puede editar la lista de productos
 * (marcar uno como no entregado sin cancelar todo el pedido).
 */
const PEDIDO_ENTREGA_EDITABLE_ESTADOS = ['pendiente_pago', 'pagado', 'en_reparto'];

/**
 * Marca un producto de un pedido como rechazado/no entregado por el cliente (p.ej. ya no
 * lo quiso al momento de la entrega): regresa la cantidad al inventario del almacen del
 * pedido, registra el movimiento y descuenta el producto del total a cobrar. No permite
 * dejar el pedido sin ningun producto entregado; para eso existe la cancelacion completa
 * del pedido (dbCancelOrderByCustomer / accion=cancelar_entrega).
 *
 * @return array{success: bool, message: string}
 */
function dbMarkProductoNoEntregado(PDO $pdo, int $idPedido, int $idDetalle, int $idRepartidor, string $motivoEtiqueta, ?int $idUsuarioAccion = null): array
{
    if ($idPedido <= 0 || $idDetalle <= 0 || $idRepartidor <= 0) {
        return ['success' => false, 'message' => 'Datos invalidos.'];
    }

    $motivoEtiqueta = trim($motivoEtiqueta);
    if ($motivoEtiqueta === '') {
        return ['success' => false, 'message' => 'Debes indicar un motivo.'];
    }

    // FOR UPDATE serializa acciones concurrentes sobre el mismo pedido en MySQL; SQLite
    // (usado en pruebas) no soporta esta clausula.
    $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $lockClause = $isMysql ? ' FOR UPDATE' : '';

    try {
        $pdo->beginTransaction();

        $stmtPedido = $pdo->prepare("SELECT estado, id_almacen, subtotal, descuento_total, total FROM pedidos WHERE id_pedido = :id_pedido AND id_repartidor = :id_repartidor{$lockClause}");
        $stmtPedido->execute([':id_pedido' => $idPedido, ':id_repartidor' => $idRepartidor]);
        $pedido = $stmtPedido->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$pedido) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'No se encontro el pedido o no esta asignado a ti.'];
        }

        $estadoPedido = (string)($pedido['estado'] ?? '');
        if (!in_array($estadoPedido, PEDIDO_ENTREGA_EDITABLE_ESTADOS, true)) {
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

        if ($idProducto > 0 && $cantidad > 0 && $idAlmacenPedido > 0) {
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
