<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/pickup_offer_utils.php';

// Validar autenticación y permisos
requireAuth();
requirePermission('realizar_ventas', BASE_URL . 'views/dashboard.php');

header('Content-Type: application/json');

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

try {
    // Validar método POST
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Método no permitido');
    }

    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        throw new Exception('Token CSRF inválido o expirado.');
    }

    // Obtener y validar datos
    $id_metodo_pago = intval($_POST['id_metodo_pago'] ?? 0);
    $descuentoManualInput = floatval($_POST['descuento'] ?? 0);
    $descuentoManual = isAdmin() ? $descuentoManualInput : 0.0;
    $clienteNombre = trim((string)($_POST['cliente_nombre'] ?? ''));
    $clienteTelefonoRaw = trim((string)($_POST['cliente_telefono'] ?? ''));
    $clienteTelefono = $normalizeCounterPhone($clienteTelefonoRaw);
    $observaciones = htmlspecialchars($_POST['observaciones'] ?? '');
    
    if ($id_metodo_pago <= 0) {
        throw new Exception('Debe seleccionar un método de pago');
    }

    if ($descuentoManualInput < 0) {
        throw new Exception('El descuento no puede ser negativo');
    }

    if ($clienteTelefono === null) {
        throw new Exception('Si capturas telefono, debe tener 10 digitos.');
    }

    // Validar que exista al menos un producto
    $productos = [];
    $subtotal = 0;

    foreach ($_POST as $key => $value) {
        if (strpos($key, 'producto_') === 0) {
            $index = str_replace('producto_', '', $key);
            $id_producto = intval($value);
            $cantidad = intval($_POST["cantidad_$index"] ?? 0);
            $precio = floatval($_POST["precio_$index"] ?? 0);

            if ($id_producto > 0 && $cantidad > 0 && $precio > 0) {
                $productos[] = [
                    'id_producto' => $id_producto,
                    'cantidad' => $cantidad,
                    'precio_unitario' => $precio,
                    'subtotal' => $cantidad * $precio,
                ];
                $subtotal += $cantidad * $precio;
            }
        }
    }

    if (empty($productos)) {
        throw new Exception('Debe agregar al menos un producto a la venta');
    }

    $totalPiezas = (int)array_reduce($productos, static fn($acc, $item) => $acc + max(0, (int)$item['cantidad']), 0);
    $pickupSettings = getPickupOfferSettings($pdo);
    $pieceMap = parsePickupPieceDiscountMap($pickupSettings['descuentos_por_pieza'] ?? ($pickupSettings['descuento_por_piezas_json'] ?? []));

    $resolveTierDiscountPerPiece = static function (int $pieces, array $map): float {
        if ($pieces <= 0 || empty($map)) {
            return 0.0;
        }

        if (isset($map[$pieces])) {
            return (float)$map[$pieces];
        }

        $eligible = 0.0;
        foreach ($map as $minPieces => $discountPerPiece) {
            if ($pieces >= (int)$minPieces) {
                $eligible = (float)$discountPerPiece;
                continue;
            }
            break;
        }

        return round(max(0.0, $eligible), 2);
    };

    $descuentoPorPieza = (!empty($pickupSettings['activo']) && $totalPiezas > 0)
        ? $resolveTierDiscountPerPiece($totalPiezas, $pieceMap)
        : 0.0;
    $descuentoIncentivoBruto = round($descuentoPorPieza * $totalPiezas, 2);
    $descuentoIncentivo = round(min($descuentoIncentivoBruto, (float)$subtotal), 2);

    $descuento = round($descuentoManual + $descuentoIncentivo, 2);
    $total = $subtotal - $descuento;

    if ($total < 0) {
        throw new Exception('El descuento no puede ser mayor al subtotal');
    }

    // Iniciar transacción
    $pdo->beginTransaction();

    try {
        $idCliente = null;

        if ($clienteNombre !== '' || $clienteTelefono !== '') {
            if ($clienteTelefono !== '') {
                $stmtCliente = $pdo->prepare("SELECT id_cliente, nombre FROM clientes WHERE telefono = ? ORDER BY id_cliente DESC LIMIT 1");
                $stmtCliente->execute([$clienteTelefono]);
                $existingCliente = $stmtCliente->fetch(PDO::FETCH_ASSOC) ?: null;

                if ($existingCliente) {
                    $idCliente = (int)($existingCliente['id_cliente'] ?? 0);

                    // Solo actualizar si llega nombre y el guardado esta vacio.
                    if ($idCliente > 0 && $clienteNombre !== '' && trim((string)($existingCliente['nombre'] ?? '')) === '') {
                        $pdo->prepare("UPDATE clientes SET nombre = ? WHERE id_cliente = ?")
                            ->execute([$clienteNombre, $idCliente]);
                    }
                }
            }

            if ($idCliente === null) {
                $nombreFinal = $clienteNombre !== '' ? $clienteNombre : 'Cliente Mostrador';
                $stmtInsCliente = $pdo->prepare("INSERT INTO clientes (nombre, telefono, estado) VALUES (?, ?, 'activo')");
                $stmtInsCliente->execute([$nombreFinal, $clienteTelefono !== '' ? $clienteTelefono : null]);
                $idCliente = (int)$pdo->lastInsertId();
            }
        }

        // Generar número de pedido único
        $numero_pedido = 'PED-' . date('YmdHis') . '-' . substr(uniqid(), -4);

        // Insertar pedido
        $sql = "INSERT INTO pedidos 
                (numero_pedido, id_cliente, id_usuario, id_almacen, id_metodo_pago, estado, subtotal, descuento_total, total, observaciones) 
                VALUES (:numero_pedido, :id_cliente, :usuario, :almacen, :metodo_pago, 'pagado', :subtotal, :descuento, :total, :observaciones)";
        
        $stmt = $pdo->prepare($sql);
        $observacionesFinales = $observaciones;
        if ($descuentoIncentivo > 0) {
            $tagIncentivo = sprintf('Incentivo sucursal aplicado: -$%.2f ($%.2f por pieza x %d pieza(s))', $descuentoIncentivo, $descuentoPorPieza, $totalPiezas);
            $observacionesFinales = $observacionesFinales !== '' ? ($tagIncentivo . '. ' . $observacionesFinales) : $tagIncentivo;
        }

        if ($almacenVentaId <= 0) {
            throw new Exception('No tienes una sucursal asignada para registrar la venta.');
        }

        $stmt->execute([
            ':numero_pedido' => $numero_pedido,
            ':id_cliente' => $idCliente,
            ':usuario' => $usuario['id_usuario'],
            ':almacen' => $almacenVentaId,
            ':metodo_pago' => $id_metodo_pago,
            ':subtotal' => $subtotal,
            ':descuento' => $descuento,
            ':total' => $total,
            ':observaciones' => $observacionesFinales,
        ]);

        $id_pedido = $pdo->lastInsertId();

        // Insertar detalles de pedido y actualizar inventario
        foreach ($productos as $prod) {
            $stmtCosto = $pdo->prepare("SELECT COALESCE(precio_costo, 0) FROM productos WHERE id_producto = ?");
            $stmtCosto->execute([$prod['id_producto']]);
            $costoUnitario = (float)($stmtCosto->fetchColumn() ?: 0);

            // Insertar detalle
            $sql = "INSERT INTO detalle_pedidos 
                    (id_pedido, id_producto, cantidad, precio_original, precio_unitario, costo_unitario, subtotal) 
                    VALUES (:pedido, :producto, :cantidad, :precio_original, :precio_unitario, :costo_unitario, :subtotal)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':pedido' => $id_pedido,
                ':producto' => $prod['id_producto'],
                ':cantidad' => $prod['cantidad'],
                ':precio_original' => $prod['precio_unitario'],
                ':precio_unitario' => $prod['precio_unitario'],
                ':costo_unitario' => $costoUnitario,
                ':subtotal' => $prod['subtotal'],
            ]);

            // Actualizar inventario
            $sql = "UPDATE inventario_almacen 
                    SET cantidad_actual = cantidad_actual - :cantidad1
                    WHERE id_producto = :producto AND id_almacen = :almacen
                    AND cantidad_actual >= :cantidad2";
            
            $stmt = $pdo->prepare($sql);
            $result = $stmt->execute([
                ':cantidad1' => $prod['cantidad'],
                ':cantidad2' => $prod['cantidad'],
                ':producto' => $prod['id_producto'],
                ':almacen' => $almacenVentaId,
            ]);

            if ($stmt->rowCount() === 0) {
                throw new Exception('Stock insuficiente para el producto ID: ' . $prod['id_producto']);
            }

            // Registrar movimiento de inventario
            $sql = "INSERT INTO movimientos_inventario 
                    (id_producto, tipo_movimiento, id_almacen_origen, cantidad, id_usuario, observacion) 
                    VALUES (:producto, 'salida', :almacen, :cantidad, :usuario, :observacion)";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':producto' => $prod['id_producto'],
                ':almacen' => $almacenVentaId,
                ':cantidad' => $prod['cantidad'],
                ':usuario' => $usuario['id_usuario'],
                ':observacion' => 'Venta ' . $numero_pedido,
            ]);
        }

        // Confirmar transacción
        $pdo->commit();

        // Registrar en auditoría
        logAudit('VENTA_REALIZADA', 'pedidos', (int)$id_pedido, "Venta registrada: $numero_pedido. Total: $total");

        $response['success'] = true;
        $response['message'] = 'Venta registrada correctamente: ' . $numero_pedido;
        $response['id_pedido'] = $id_pedido;
        $response['id_cliente'] = $idCliente;
        $response['numero_pedido'] = $numero_pedido;
        $response['descuento_manual'] = round($descuentoManual, 2);
        $response['descuento_por_pieza'] = round($descuentoPorPieza, 2);
        $response['descuento_incentivo'] = round($descuentoIncentivo, 2);
        $response['descuento_total'] = round($descuento, 2);
        $response['total'] = round($total, 2);

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
