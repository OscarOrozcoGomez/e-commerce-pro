<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

// Validar autenticación y permisos
requireAuth();
requirePermission('realizar_ventas', BASE_URL . 'views/dashboard.php');

header('Content-Type: application/json');

$pdo = getPDO();
$usuario = $_SESSION['usuario'];
$response = ['success' => false, 'message' => ''];

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
    $descuento = floatval($_POST['descuento'] ?? 0);
    $observaciones = htmlspecialchars($_POST['observaciones'] ?? '');
    
    if ($id_metodo_pago <= 0) {
        throw new Exception('Debe seleccionar un método de pago');
    }

    if ($descuento < 0) {
        throw new Exception('El descuento no puede ser negativo');
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

    $total = $subtotal - $descuento;

    if ($total < 0) {
        throw new Exception('El descuento no puede ser mayor al subtotal');
    }

    // Iniciar transacción
    $pdo->beginTransaction();

    try {
        // Generar número de pedido único
        $numero_pedido = 'PED-' . date('YmdHis') . '-' . substr(uniqid(), -4);

        // Insertar pedido
        $sql = "INSERT INTO pedidos 
                (numero_pedido, id_usuario, id_almacen, id_metodo_pago, estado, subtotal, descuento_total, total, observaciones) 
                VALUES (:numero_pedido, :usuario, :almacen, :metodo_pago, 'pagado', :subtotal, :descuento, :total, :observaciones)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':numero_pedido' => $numero_pedido,
            ':usuario' => $usuario['id_usuario'],
            ':almacen' => $usuario['id_almacen'],
            ':metodo_pago' => $id_metodo_pago,
            ':subtotal' => $subtotal,
            ':descuento' => $descuento,
            ':total' => $total,
            ':observaciones' => $observaciones,
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
                ':almacen' => $usuario['id_almacen'],
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
                ':almacen' => $usuario['id_almacen'],
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
        $response['numero_pedido'] = $numero_pedido;

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
