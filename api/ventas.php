<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();

header('Content-Type: application/json');

if (!canScheduleSalesOrders()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'No autorizado para agendar pedidos a domicilio.']);
    exit;
}

$pdo = getPDO();
$usuario = $_SESSION['usuario'];
$response = ['success' => false, 'message' => ''];
$almacenVentaId = resolveSalesWarehouseId($pdo);

$normalizeCounterPhone = static function (string $phone): ?string {
    $digits = preg_replace('/\D+/', '', $phone);
    if (!is_string($digits)) {
        return null;
    }
    if ($digits === '') {
        return '';
    }
    if (strlen($digits) !== 10) {
        return null;
    }

    return sprintf('(%s) - %s - %s', substr($digits, 0, 3), substr($digits, 3, 3), substr($digits, 6, 4));
};

$columnExists = static function (PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return ((int)$stmt->fetchColumn()) > 0;
};

$tableExists = static function (PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return ((int)$stmt->fetchColumn()) > 0;
};

$decryptValue = static function (?string $value): string {
    $value = trim((string)$value);
    if ($value !== '' && function_exists('piiIsEncryptedValue') && function_exists('piiDecryptValue') && piiIsEncryptedValue($value)) {
        return trim((string)piiDecryptValue($value));
    }
    return $value;
};

$storeValue = static function (?string $value): ?string {
    $value = $value !== null ? trim($value) : null;
    if ($value === null || $value === '') {
        return $value;
    }

    return function_exists('piiEncryptValue') ? piiEncryptValue($value) : $value;
};

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido');
    }

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        throw new Exception('Token CSRF inválido o expirado.');
    }

    $idMetodoPago = intval($_POST['id_metodo_pago'] ?? 0);
    $idMetodoPago = $idMetodoPago > 0 ? $idMetodoPago : null;
    $idClienteSeleccionado = intval($_POST['id_cliente'] ?? 0);
    $customerAddressSelection = trim((string)($_POST['customer_address_id'] ?? ''));
    $clienteNombre = trim((string)($_POST['cliente_nombre'] ?? ''));
    $clienteTelefonoRaw = trim((string)($_POST['cliente_telefono'] ?? ''));
    $clienteTelefono = $normalizeCounterPhone($clienteTelefonoRaw);
    $direccionEntrega = trim((string)($_POST['direccion_entrega'] ?? ''));
    $mapsLinkEntrega = trim((string)($_POST['maps_link_entrega'] ?? ''));
    $observaciones = trim((string)($_POST['observaciones'] ?? ''));

    if ($clienteTelefono === null) {
        throw new Exception('Si capturas teléfono, debe tener 10 dígitos.');
    }

    if ($idClienteSeleccionado <= 0) {
        throw new Exception('Debes seleccionar un cliente existente.');
    }

    if ($clienteTelefono === '') {
        throw new Exception('Debes capturar el teléfono de entrega.');
    }

    $hasPedidosTipoEntrega = $columnExists($pdo, 'pedidos', 'tipo_entrega');
    $hasPedidosDireccionEntrega = $columnExists($pdo, 'pedidos', 'direccion_entrega');
    $hasPedidosTelefonoEntrega = $columnExists($pdo, 'pedidos', 'telefono_entrega');
    $hasPedidosMapsLinkEntrega = $columnExists($pdo, 'pedidos', 'maps_link_entrega');
    $hasClienteDireccionesTable = $tableExists($pdo, 'cliente_direcciones');

    if ($hasClienteDireccionesTable && !ctype_digit($customerAddressSelection)) {
        throw new Exception('Debes seleccionar una direccion guardada del cliente.');
    }

    $productos = [];
    $subtotal = 0.0;
    $descuentoTotal = 0.0;

    foreach ($_POST as $key => $value) {
        if (strpos($key, 'producto_') !== 0) {
            continue;
        }

        $index = str_replace('producto_', '', $key);
        $idProducto = intval($value);
        $cantidad = intval($_POST["cantidad_$index"] ?? 0);
        $precio = round((float)($_POST["precio_$index"] ?? 0), 2);
        $descuentoLinea = round(max(0.0, (float)($_POST["descuento_linea_$index"] ?? 0)), 2);

        if ($idProducto <= 0 || $cantidad <= 0 || $precio <= 0) {
            continue;
        }

        $subtotalLineaBase = round($cantidad * $precio, 2);
        if ($descuentoLinea > $subtotalLineaBase) {
            throw new Exception('El descuento manual no puede ser mayor al subtotal del producto.');
        }

        $productos[] = [
            'id_producto' => $idProducto,
            'cantidad' => $cantidad,
            'precio_unitario' => $precio,
            'descuento_linea' => $descuentoLinea,
            'subtotal_base' => $subtotalLineaBase,
            'subtotal' => round($subtotalLineaBase - $descuentoLinea, 2),
        ];
        $subtotal += $subtotalLineaBase;
        $descuentoTotal += $descuentoLinea;
    }

    if (empty($productos)) {
        throw new Exception('Debe agregar al menos un producto al pedido.');
    }

    $subtotal = round($subtotal, 2);
    $descuentoTotal = round($descuentoTotal, 2);
    $total = round($subtotal - $descuentoTotal, 2);

    if ($total < 0) {
        throw new Exception('El descuento no puede ser mayor al subtotal.');
    }

    if ($almacenVentaId <= 0) {
        throw new Exception('No tienes una sucursal asignada para registrar el pedido.');
    }

    $pdo->beginTransaction();

    try {
        $idCliente = null;
        $clienteNombreFinal = $clienteNombre;
        $clienteTelefonoFinal = $clienteTelefono;
        $selectedAddressId = ctype_digit($customerAddressSelection) ? (int)$customerAddressSelection : 0;

        $stmtCliente = $pdo->prepare('SELECT id_cliente, nombre, telefono FROM clientes WHERE id_cliente = ? LIMIT 1');
        $stmtCliente->execute([$idClienteSeleccionado]);
        $clienteExistente = $stmtCliente->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$clienteExistente) {
            throw new Exception('El cliente seleccionado no existe.');
        }

        $idCliente = (int)$clienteExistente['id_cliente'];
        $nombreActual = $decryptValue((string)($clienteExistente['nombre'] ?? ''));
        $telefonoActual = $decryptValue((string)($clienteExistente['telefono'] ?? ''));
        $clienteNombreFinal = $nombreActual;
        $clienteTelefonoFinal = $telefonoActual;

        if ($clienteNombreFinal === '') {
            throw new Exception('El cliente seleccionado no tiene nombre valido.');
        }
        if ($clienteTelefonoFinal === '') {
            throw new Exception('El cliente seleccionado no tiene telefono. Actualizalo desde Administrar Clientes.');
        }

        if ($hasClienteDireccionesTable) {
            if ($selectedAddressId <= 0) {
                throw new Exception('Debes seleccionar una direccion guardada del cliente.');
            }

            $stmtDirValidacion = $pdo->prepare('SELECT direccion, maps_link FROM cliente_direcciones WHERE id_direccion = ? AND id_cliente = ? LIMIT 1');
            $stmtDirValidacion->execute([$selectedAddressId, $idCliente]);
            $direccionSeleccionada = $stmtDirValidacion->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$direccionSeleccionada) {
                throw new Exception('La direccion seleccionada no pertenece al cliente.');
            }

            $direccionEntrega = $decryptValue((string)($direccionSeleccionada['direccion'] ?? ''));
            $mapsLinkEntrega = $decryptValue((string)($direccionSeleccionada['maps_link'] ?? ''));
        }

        if ($direccionEntrega === '') {
            throw new Exception('La direccion guardada del cliente no es valida.');
        }

        $numeroPedido = 'DOM-' . date('YmdHis') . '-' . substr(uniqid(), -4);
        $observacionesChunks = [
            'ENTREGA: Domicilio',
            'Cliente: ' . $clienteNombreFinal,
            'Tel: ' . $clienteTelefonoFinal,
            'Dir: ' . $direccionEntrega,
        ];
        if ($observaciones !== '') {
            $observacionesChunks[] = 'Notas: ' . $observaciones;
        }
        if ($mapsLinkEntrega !== '') {
            $observacionesChunks[] = 'Maps: ' . $mapsLinkEntrega;
        }

        $pedidoColumns = [
            'numero_pedido',
            'id_cliente',
            'id_usuario',
            'id_almacen',
            'id_metodo_pago',
            'estado',
            'subtotal',
            'descuento_total',
            'total',
            'observaciones',
        ];
        $pedidoPlaceholders = [
            ':numero_pedido',
            ':id_cliente',
            ':usuario',
            ':almacen',
            ':metodo_pago',
            ':estado',
            ':subtotal',
            ':descuento_total',
            ':total',
            ':observaciones',
        ];
        $pedidoParams = [
            ':numero_pedido' => $numeroPedido,
            ':id_cliente' => $idCliente,
            ':usuario' => $usuario['id_usuario'],
            ':almacen' => $almacenVentaId,
            ':metodo_pago' => $idMetodoPago,
            ':estado' => 'pendiente_pago',
            ':subtotal' => $subtotal,
            ':descuento_total' => $descuentoTotal,
            ':total' => $total,
            ':observaciones' => implode(' | ', $observacionesChunks),
        ];

        if ($hasPedidosTipoEntrega) {
            $pedidoColumns[] = 'tipo_entrega';
            $pedidoPlaceholders[] = ':tipo_entrega';
            $pedidoParams[':tipo_entrega'] = 'Domicilio';
        }
        if ($hasPedidosDireccionEntrega) {
            $pedidoColumns[] = 'direccion_entrega';
            $pedidoPlaceholders[] = ':direccion_entrega';
            $pedidoParams[':direccion_entrega'] = $direccionEntrega;
        }
        if ($hasPedidosTelefonoEntrega) {
            $pedidoColumns[] = 'telefono_entrega';
            $pedidoPlaceholders[] = ':telefono_entrega';
            $pedidoParams[':telefono_entrega'] = $clienteTelefonoFinal;
        }
        if ($hasPedidosMapsLinkEntrega) {
            $pedidoColumns[] = 'maps_link_entrega';
            $pedidoPlaceholders[] = ':maps_link_entrega';
            $pedidoParams[':maps_link_entrega'] = $mapsLinkEntrega !== '' ? $mapsLinkEntrega : null;
        }

        $sqlPedido = sprintf(
            'INSERT INTO pedidos (%s) VALUES (%s)',
            implode(', ', $pedidoColumns),
            implode(', ', $pedidoPlaceholders)
        );
        $stmtPedido = $pdo->prepare($sqlPedido);
        $stmtPedido->execute($pedidoParams);

        $idPedido = (int)$pdo->lastInsertId();
        $auditNotes = [];

        foreach ($productos as $producto) {
            $stmtProducto = $pdo->prepare('SELECT nombre, COALESCE(precio_venta, 0) AS precio_venta, COALESCE(precio_costo, 0) AS precio_costo FROM productos WHERE id_producto = ? LIMIT 1');
            $stmtProducto->execute([$producto['id_producto']]);
            $productoDb = $stmtProducto->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$productoDb) {
                throw new Exception('Producto no encontrado para el pedido.');
            }

            $precioCatalogo = round((float)($productoDb['precio_venta'] ?? 0), 2);
            $costoUnitario = round((float)($productoDb['precio_costo'] ?? 0), 2);
            $descuentoLinea = round((float)$producto['descuento_linea'], 2);
            $porcentajeDescuento = $producto['subtotal_base'] > 0
                ? round(($descuentoLinea / (float)$producto['subtotal_base']) * 100, 2)
                : null;
            $nombreProducto = trim((string)($productoDb['nombre'] ?? ('Producto #' . $producto['id_producto'])));

            if (abs($precioCatalogo - (float)$producto['precio_unitario']) > 0.009) {
                $auditNotes[] = sprintf(
                    '%s x%d: precio catálogo $%.2f, precio capturado $%.2f',
                    $nombreProducto,
                    (int)$producto['cantidad'],
                    $precioCatalogo,
                    (float)$producto['precio_unitario']
                );
            }
            if ($descuentoLinea > 0) {
                $auditNotes[] = sprintf(
                    '%s x%d: descuento manual $%.2f',
                    $nombreProducto,
                    (int)$producto['cantidad'],
                    $descuentoLinea
                );
            }

            $stmtDetalle = $pdo->prepare(
                'INSERT INTO detalle_pedidos (id_pedido, id_producto, cantidad, precio_original, precio_unitario, costo_unitario, porcentaje_descuento, monto_descuento, subtotal) VALUES (:pedido, :producto, :cantidad, :precio_original, :precio_unitario, :costo_unitario, :porcentaje_descuento, :monto_descuento, :subtotal)'
            );
            $stmtDetalle->execute([
                ':pedido' => $idPedido,
                ':producto' => $producto['id_producto'],
                ':cantidad' => $producto['cantidad'],
                ':precio_original' => $precioCatalogo,
                ':precio_unitario' => $producto['precio_unitario'],
                ':costo_unitario' => $costoUnitario,
                ':porcentaje_descuento' => $porcentajeDescuento,
                ':monto_descuento' => $descuentoLinea,
                ':subtotal' => $producto['subtotal'],
            ]);

            $stmtStock = $pdo->prepare(
                'UPDATE inventario_almacen SET cantidad_actual = cantidad_actual - :cantidad1 WHERE id_producto = :producto AND id_almacen = :almacen AND cantidad_actual >= :cantidad2'
            );
            $stmtStock->execute([
                ':cantidad1' => $producto['cantidad'],
                ':cantidad2' => $producto['cantidad'],
                ':producto' => $producto['id_producto'],
                ':almacen' => $almacenVentaId,
            ]);
            if ($stmtStock->rowCount() === 0) {
                throw new Exception('Stock insuficiente para el producto ID: ' . $producto['id_producto']);
            }

            $stmtMov = $pdo->prepare(
                "INSERT INTO movimientos_inventario (id_producto, tipo_movimiento, id_almacen_origen, cantidad, id_usuario, observacion) VALUES (:producto, 'salida', :almacen, :cantidad, :usuario, :observacion)"
            );
            $stmtMov->execute([
                ':producto' => $producto['id_producto'],
                ':almacen' => $almacenVentaId,
                ':cantidad' => $producto['cantidad'],
                ':usuario' => $usuario['id_usuario'],
                ':observacion' => 'Pedido a domicilio ' . $numeroPedido,
            ]);
        }

        if (!empty($auditNotes)) {
            $stmtAuditObs = $pdo->prepare("UPDATE pedidos SET observaciones = CONCAT(COALESCE(observaciones, ''), ?) WHERE id_pedido = ?");
            $stmtAuditObs->execute([' | AJUSTES: ' . implode(' ; ', $auditNotes), $idPedido]);
        }

        $pdo->commit();

        logAudit('PEDIDO_DOMICILIO_AGENDADO', 'pedidos', $idPedido, "Pedido agendado: $numeroPedido. Total: $total");

        $response['success'] = true;
        $response['message'] = 'Pedido agendado correctamente: ' . $numeroPedido;
        $response['id_pedido'] = $idPedido;
        $response['id_cliente'] = $idCliente;
        $response['numero_pedido'] = $numeroPedido;
        $response['descuento_total'] = $descuentoTotal;
        $response['total'] = $total;
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = $e->getMessage();
    http_response_code(400);
}

echo json_encode($response);
?>
