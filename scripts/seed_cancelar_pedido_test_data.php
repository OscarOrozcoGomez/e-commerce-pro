<?php
declare(strict_types=1);

/**
 * Seed local de 4 entregas a domicilio ya asignadas a un repartidor de prueba, para probar a
 * mano (y sirven de referencia) el flujo de "cancelar pedido completo" agregado en
 * views/asignar_entregas.php (admin/encargado) y views/entregas.php (repartidor):
 *   - CANCEL-TEST-001: 3 productos, pagado -> probar quitar un producto Y cancelar el pedido completo.
 *   - CANCEL-TEST-002: 1 solo producto, pagado -> el boton "Quitar" debe salir deshabilitado;
 *     solo se puede cancelar el pedido completo.
 *   - CANCEL-TEST-003: 2 productos, en_reparto -> probar la cancelacion desde views/entregas.php
 *     (repartidor, boton "No pude entregar").
 *   - CANCEL-TEST-004: 1 solo producto, en_reparto -> el boton "No lo quiso" debe salir
 *     deshabilitado en views/entregas.php; solo "No pude entregar" cancela el pedido.
 *
 * Uso: C:\xampp\php\php.exe scripts\seed_cancelar_pedido_test_data.php
 * Idempotente: correr varias veces deja los mismos 4 pedidos en el mismo estado (borra y
 * vuelve a crear detalle_pedidos/pedidos por numero_pedido fijo).
 *
 * Cuenta de repartidor para iniciar sesion y probar views/entregas.php:
 *   email: repartidor.pruebas.cancelacion@local.test
 *   password: Prueba123!
 */

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    fwrite(STDERR, "Este script solo se puede ejecutar por CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../core/config.php';

const REPARTIDOR_NOMBRE = 'Repartidor Prueba Cancelacion';
const REPARTIDOR_EMAIL = 'repartidor.pruebas.cancelacion@local.test';
const REPARTIDOR_PASSWORD = 'Prueba123!';

const CLIENTE_NOMBRE = 'Cliente Prueba Cancelacion';
const CLIENTE_TELEFONO = '3310002233';
const CLIENTE_DIRECCION = 'Calle de Pruebas 123, Guadalajara, Jal.';

const PRODUCTO_A_NOMBRE = 'Producto Prueba Cancelacion A';
const PRODUCTO_A_BARCODE = 'CANCEL-TEST-PROD-A';
const PRODUCTO_B_NOMBRE = 'Producto Prueba Cancelacion B';
const PRODUCTO_B_BARCODE = 'CANCEL-TEST-PROD-B';
const STOCK_INICIAL = 20;

$pdo = getPDO();

try {
    $pdo->beginTransaction();

    $idAlmacen = (int) $pdo->query(
        "SELECT id_almacen FROM almacenes WHERE estado = 'activo' ORDER BY id_almacen ASC LIMIT 1"
    )->fetchColumn();
    if ($idAlmacen <= 0) {
        throw new RuntimeException('No se pudo resolver id_almacen. ¿Se importó database.sql?');
    }

    $idUsuarioCreador = (int) $pdo->query(
        "SELECT id_usuario FROM usuarios WHERE id_rol = (SELECT id_rol FROM roles WHERE nombre = 'admin') ORDER BY id_usuario ASC LIMIT 1"
    )->fetchColumn();
    if ($idUsuarioCreador <= 0) {
        $idUsuarioCreador = 1;
    }

    // --- Repartidor de prueba ---
    $idRolRepartidor = (int) $pdo->query("SELECT id_rol FROM roles WHERE nombre = 'repartidor'")->fetchColumn();
    if ($idRolRepartidor <= 0) {
        throw new RuntimeException("No existe el rol 'repartidor' en la tabla roles.");
    }
    $stmt = $pdo->prepare(
        'INSERT INTO usuarios (nombre, email, contrasena, id_rol, id_almacen, estado)
         VALUES (:nombre, :email, :contrasena, :id_rol, :id_almacen, "activo")
         ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), contrasena = VALUES(contrasena),
             id_rol = VALUES(id_rol), id_almacen = VALUES(id_almacen), estado = "activo"'
    );
    $stmt->execute([
        'nombre' => REPARTIDOR_NOMBRE,
        'email' => REPARTIDOR_EMAIL,
        'contrasena' => password_hash(REPARTIDOR_PASSWORD, PASSWORD_BCRYPT),
        'id_rol' => $idRolRepartidor,
        'id_almacen' => $idAlmacen,
    ]);
    $stmt = $pdo->prepare('SELECT id_usuario FROM usuarios WHERE email = :email');
    $stmt->execute(['email' => REPARTIDOR_EMAIL]);
    $idRepartidor = (int) $stmt->fetchColumn();
    echo 'Seed OK: repartidor -> ' . REPARTIDOR_EMAIL . " (id_usuario={$idRepartidor})\n";

    // --- Cliente de prueba ---
    $stmt = $pdo->prepare('SELECT id_cliente FROM clientes WHERE nombre = :nombre LIMIT 1');
    $stmt->execute(['nombre' => CLIENTE_NOMBRE]);
    $idCliente = (int) $stmt->fetchColumn();
    if ($idCliente <= 0) {
        $stmt = $pdo->prepare('INSERT INTO clientes (nombre, telefono, estado) VALUES (:nombre, :telefono, "activo")');
        $stmt->execute(['nombre' => CLIENTE_NOMBRE, 'telefono' => CLIENTE_TELEFONO]);
        $idCliente = (int) $pdo->lastInsertId();
    } else {
        $pdo->prepare('UPDATE clientes SET telefono = :telefono, estado = "activo" WHERE id_cliente = :id_cliente')
            ->execute(['telefono' => CLIENTE_TELEFONO, 'id_cliente' => $idCliente]);
    }
    echo 'Seed OK: cliente -> ' . CLIENTE_NOMBRE . " (id_cliente={$idCliente})\n";

    // --- Productos de prueba ---
    $idsProductos = [];
    foreach ([
        ['nombre' => PRODUCTO_A_NOMBRE, 'codigo_barras' => PRODUCTO_A_BARCODE, 'precio' => 100.00],
        ['nombre' => PRODUCTO_B_NOMBRE, 'codigo_barras' => PRODUCTO_B_BARCODE, 'precio' => 150.00],
    ] as $p) {
        $stmt = $pdo->prepare(
            'INSERT INTO productos (nombre, codigo_barras, precio_venta, estado)
             VALUES (:nombre, :codigo_barras, :precio, "activo")
             ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), precio_venta = VALUES(precio_venta), estado = "activo"'
        );
        $stmt->execute(['nombre' => $p['nombre'], 'codigo_barras' => $p['codigo_barras'], 'precio' => $p['precio']]);

        $stmt = $pdo->prepare('SELECT id_producto FROM productos WHERE codigo_barras = :codigo_barras');
        $stmt->execute(['codigo_barras' => $p['codigo_barras']]);
        $idProducto = (int) $stmt->fetchColumn();
        $idsProductos[$p['codigo_barras']] = ['id' => $idProducto, 'precio' => $p['precio']];

        $stmt = $pdo->prepare(
            'INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual)
             VALUES (:id_producto, :id_almacen, :cantidad)
             ON DUPLICATE KEY UPDATE cantidad_actual = VALUES(cantidad_actual)'
        );
        $stmt->execute(['id_producto' => $idProducto, 'id_almacen' => $idAlmacen, 'cantidad' => STOCK_INICIAL]);
        echo "Seed OK: {$p['nombre']} -> id_producto={$idProducto}, stock=" . STOCK_INICIAL . "\n";
    }
    $idProductoA = $idsProductos[PRODUCTO_A_BARCODE]['id'];
    $idProductoB = $idsProductos[PRODUCTO_B_BARCODE]['id'];
    $precioA = $idsProductos[PRODUCTO_A_BARCODE]['precio'];
    $precioB = $idsProductos[PRODUCTO_B_BARCODE]['precio'];

    // --- Pedidos de prueba (borra los previos con el mismo numero_pedido para poder re-correr) ---
    $pedidosDef = [
        [
            'numero' => 'CANCEL-TEST-001',
            'estado' => 'pagado',
            'items' => [[$idProductoA, 2, $precioA], [$idProductoB, 1, $precioB]],
        ],
        [
            'numero' => 'CANCEL-TEST-002',
            'estado' => 'pagado',
            'items' => [[$idProductoA, 1, $precioA]],
        ],
        [
            'numero' => 'CANCEL-TEST-003',
            'estado' => 'en_reparto',
            'items' => [[$idProductoA, 1, $precioA], [$idProductoB, 2, $precioB]],
        ],
        [
            'numero' => 'CANCEL-TEST-004',
            'estado' => 'en_reparto',
            'items' => [[$idProductoB, 1, $precioB]],
        ],
    ];

    $stmtBorrarViejo = $pdo->prepare('SELECT id_pedido FROM pedidos WHERE numero_pedido = :numero');
    $stmtBorrarDetalle = $pdo->prepare('DELETE FROM detalle_pedidos WHERE id_pedido = :id_pedido');
    $stmtBorrarPedido = $pdo->prepare('DELETE FROM pedidos WHERE id_pedido = :id_pedido');

    $totalConsumidoA = 0;
    $totalConsumidoB = 0;

    foreach ($pedidosDef as $def) {
        // Idempotencia: si ya existe un pedido con este numero (de una corrida anterior del
        // script), se borra junto con su detalle para recrearlo limpio.
        $stmtBorrarViejo->execute(['numero' => $def['numero']]);
        $idPedidoViejo = (int) $stmtBorrarViejo->fetchColumn();
        if ($idPedidoViejo > 0) {
            $stmtBorrarDetalle->execute(['id_pedido' => $idPedidoViejo]);
            $stmtBorrarPedido->execute(['id_pedido' => $idPedidoViejo]);
        }

        $subtotal = 0.0;
        foreach ($def['items'] as [$idProducto, $cantidad, $precio]) {
            $subtotal += $precio * $cantidad;
            if ($idProducto === $idProductoA) {
                $totalConsumidoA += $cantidad;
            } else {
                $totalConsumidoB += $cantidad;
            }
        }

        $stmtPedido = $pdo->prepare(
            'INSERT INTO pedidos (numero_pedido, id_cliente, id_usuario, id_almacen, id_repartidor,
                                   id_metodo_pago, estado, tipo_entrega, subtotal, descuento_total, total,
                                   direccion_entrega, telefono_entrega, observaciones, fecha_entrega_programada)
             VALUES (:numero_pedido, :id_cliente, :id_usuario, :id_almacen, :id_repartidor,
                     1, :estado, "Domicilio", :subtotal, 0, :total,
                     :direccion, :telefono, :observaciones, DATE_ADD(NOW(), INTERVAL 1 DAY))'
        );
        $stmtPedido->execute([
            'numero_pedido' => $def['numero'],
            'id_cliente' => $idCliente,
            'id_usuario' => $idUsuarioCreador,
            'id_almacen' => $idAlmacen,
            'id_repartidor' => $idRepartidor,
            'estado' => $def['estado'],
            'subtotal' => $subtotal,
            'total' => $subtotal,
            'direccion' => CLIENTE_DIRECCION,
            'telefono' => CLIENTE_TELEFONO,
            'observaciones' => 'ENTREGA: Domicilio | Pedido sembrado por scripts/seed_cancelar_pedido_test_data.php',
        ]);
        $idPedido = (int) $pdo->lastInsertId();

        $stmtDetalle = $pdo->prepare(
            'INSERT INTO detalle_pedidos (id_pedido, id_producto, cantidad, precio_original, precio_unitario,
                                           monto_descuento, subtotal, estado_entrega)
             VALUES (:id_pedido, :id_producto, :cantidad, :precio_original, :precio_unitario, 0, :subtotal, "entregado")'
        );
        foreach ($def['items'] as [$idProducto, $cantidad, $precio]) {
            $stmtDetalle->execute([
                'id_pedido' => $idPedido,
                'id_producto' => $idProducto,
                'cantidad' => $cantidad,
                'precio_original' => $precio,
                'precio_unitario' => $precio,
                'subtotal' => $precio * $cantidad,
            ]);
        }

        echo "Seed OK: pedido {$def['numero']} -> id_pedido={$idPedido}, estado={$def['estado']}, " . count($def['items']) . " producto(s)\n";
    }

    // Refleja en el inventario que estas cantidades ya estan "vendidas" (repartidas entre los
    // 4 pedidos de prueba), para que cancelar un pedido muestre un incremento real y visible.
    $pdo->prepare('UPDATE inventario_almacen SET cantidad_actual = :cantidad WHERE id_producto = :id_producto AND id_almacen = :id_almacen')
        ->execute(['cantidad' => STOCK_INICIAL - $totalConsumidoA, 'id_producto' => $idProductoA, 'id_almacen' => $idAlmacen]);
    $pdo->prepare('UPDATE inventario_almacen SET cantidad_actual = :cantidad WHERE id_producto = :id_producto AND id_almacen = :id_almacen')
        ->execute(['cantidad' => STOCK_INICIAL - $totalConsumidoB, 'id_producto' => $idProductoB, 'id_almacen' => $idAlmacen]);
    echo 'Seed OK: inventario ajustado -> Producto A stock=' . (STOCK_INICIAL - $totalConsumidoA) . ', Producto B stock=' . (STOCK_INICIAL - $totalConsumidoB) . "\n";

    $pdo->commit();
    echo "\nListo. Inicia sesion como admin/encargado y ve a views/asignar_entregas.php (pestana Asignadas)\n";
    echo "para ver los 4 pedidos CANCEL-TEST-00[1-4], o como repartidor (" . REPARTIDOR_EMAIL . " / " . REPARTIDOR_PASSWORD . ")\n";
    echo "en views/entregas.php para probar 'No pude entregar' con CANCEL-TEST-003/004.\n";
    exit(0);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Seed error: ' . $e->getMessage() . "\n");
    exit(1);
}
