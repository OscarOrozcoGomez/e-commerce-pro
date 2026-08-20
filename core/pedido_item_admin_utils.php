<?php
declare(strict_types=1);

/**
 * Agrega un producto a un pedido ya existente (asignado a repartidor, en reparto o incluso
 * ya entregado): descuenta el inventario del almacen del pedido, registra el movimiento y
 * suma el importe al total del pedido, como parte de la misma venta. Pensado para que
 * admin/encargado puedan corregir un pedido despues de asignarlo (views/asignar_entregas.php),
 * incluyendo "reabrir" uno ya entregado para agregarle mas productos sin crear una venta nueva.
 *
 * No se permite agregar a un pedido cancelado.
 *
 * @return array{success: bool, message: string}
 */
function dbAdminAgregarProductoPedido(PDO $pdo, int $idPedido, int $idProducto, int $cantidad, ?int $idUsuarioAccion = null): array
{
    if ($idPedido <= 0 || $idProducto <= 0 || $cantidad <= 0) {
        return ['success' => false, 'message' => 'Datos invalidos.'];
    }

    // FOR UPDATE serializa acciones concurrentes en MySQL; SQLite (pruebas) no lo soporta.
    $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
    $lockClause = $isMysql ? ' FOR UPDATE' : '';

    try {
        $pdo->beginTransaction();

        $stmtPedido = $pdo->prepare("SELECT estado, id_almacen FROM pedidos WHERE id_pedido = :id_pedido{$lockClause}");
        $stmtPedido->execute([':id_pedido' => $idPedido]);
        $pedido = $stmtPedido->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$pedido) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'No se encontro el pedido.'];
        }
        if ((string)($pedido['estado'] ?? '') === 'cancelado') {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Este pedido esta cancelado; no se le pueden agregar productos.'];
        }

        $idAlmacen = (int)($pedido['id_almacen'] ?? 0);
        if ($idAlmacen <= 0) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'El pedido no tiene un almacen valido.'];
        }

        $stmtProducto = $pdo->prepare("SELECT nombre, precio_venta, precio_costo, estado FROM productos WHERE id_producto = :id_producto{$lockClause}");
        $stmtProducto->execute([':id_producto' => $idProducto]);
        $producto = $stmtProducto->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$producto) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'El producto no existe.'];
        }
        if ((string)($producto['estado'] ?? 'activo') !== 'activo') {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Este producto no esta activo para venta.'];
        }

        $precioUnitario = round((float)($producto['precio_venta'] ?? 0), 2);
        if ($precioUnitario <= 0) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Este producto no tiene un precio de venta valido.'];
        }
        $costoUnitario = (float)($producto['precio_costo'] ?? 0);
        $subtotalLinea = round($precioUnitario * $cantidad, 2);

        // Descuenta stock solo si hay suficiente disponible, igual que en una venta normal.
        $stmtStock = $pdo->prepare('UPDATE inventario_almacen SET cantidad_actual = cantidad_actual - ? WHERE id_producto = ? AND id_almacen = ? AND cantidad_actual >= ?');
        $stmtStock->execute([$cantidad, $idProducto, $idAlmacen, $cantidad]);
        if ($stmtStock->rowCount() === 0) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'No hay suficiente inventario disponible de este producto en la sucursal del pedido.'];
        }

        $stmtInsertDetalle = $pdo->prepare(
            "INSERT INTO detalle_pedidos (id_pedido, id_producto, cantidad, precio_original, precio_unitario, costo_unitario, porcentaje_descuento, monto_descuento, subtotal, estado_entrega)
             VALUES (:pedido, :producto, :cantidad, :precio, :precio2, :costo, 0, 0, :subtotal, 'entregado')"
        );
        $stmtInsertDetalle->execute([
            ':pedido' => $idPedido,
            ':producto' => $idProducto,
            ':cantidad' => $cantidad,
            ':precio' => $precioUnitario,
            ':precio2' => $precioUnitario,
            ':costo' => $costoUnitario,
            ':subtotal' => $subtotalLinea,
        ]);

        $stmtMovSalida = $pdo->prepare(
            "INSERT INTO movimientos_inventario (id_producto, tipo_movimiento, id_almacen_origen, cantidad, id_usuario, observacion)
             VALUES (:producto, 'salida', :almacen, :cantidad, :usuario, :observacion)"
        );
        $stmtMovSalida->execute([
            ':producto' => $idProducto,
            ':almacen' => $idAlmacen,
            ':cantidad' => $cantidad,
            ':usuario' => $idUsuarioAccion,
            ':observacion' => 'Producto agregado al pedido #' . $idPedido . ' por staff (ajuste posterior a la asignacion).',
        ]);

        $stmtAjustarTotales = $pdo->prepare('UPDATE pedidos SET subtotal = subtotal + ?, total = total + ? WHERE id_pedido = ?');
        $stmtAjustarTotales->execute([$subtotalLinea, $subtotalLinea, $idPedido]);

        $pdo->commit();

        if (function_exists('logAudit')) {
            logAudit('PRODUCTO_AGREGADO_A_PEDIDO', 'pedidos', $idPedido, 'Se agrego el producto #' . $idProducto . ' (x' . $cantidad . ') al pedido #' . $idPedido);
        }

        return ['success' => true, 'message' => 'Producto agregado al pedido correctamente.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
