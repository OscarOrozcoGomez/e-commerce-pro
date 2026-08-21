<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    http_response_code(403);
    echo "Este script solo se puede ejecutar por CLI o phpdbg.\n";
    exit(1);
}

// Datos fijos que los specs de tests/e2e/ referencian por nombre/codigo_barras.
// Idempotente: correr varias veces (local o CI) no duplica filas ni acumula stock,
// siempre lo deja en el valor fijo de abajo.
const E2E_PRODUCT_NAME = 'Playwright E2E Test Product';
const E2E_PRODUCT_BARCODE = 'E2E-PLAYWRIGHT-TEST-0001';

// Producto con stock deliberadamente bajo, para los tests negativos de
// "stock insuficiente" en checkout (ver tests/e2e/checkout-edge-cases.*.spec.ts).
const E2E_LOW_STOCK_PRODUCT_NAME = 'Playwright E2E Low Stock Product';
const E2E_LOW_STOCK_PRODUCT_BARCODE = 'E2E-PLAYWRIGHT-TEST-0002';

$productsToSeed = [
    ['nombre' => E2E_PRODUCT_NAME, 'codigo_barras' => E2E_PRODUCT_BARCODE, 'precio' => 99.99, 'stock' => 9999],
    ['nombre' => E2E_LOW_STOCK_PRODUCT_NAME, 'codigo_barras' => E2E_LOW_STOCK_PRODUCT_BARCODE, 'precio' => 49.99, 'stock' => 1],
];

$pdo = getPDO();

try {
    $pdo->beginTransaction();

    $idAlmacen = (int) $pdo->query(
        "SELECT id_almacen FROM almacenes WHERE estado = 'activo' ORDER BY id_almacen ASC LIMIT 1"
    )->fetchColumn();

    if ($idAlmacen <= 0) {
        throw new RuntimeException('No se pudo resolver id_almacen. ¿Se importó database.sql?');
    }

    foreach ($productsToSeed as $p) {
        $stmt = $pdo->prepare(
            'INSERT INTO productos (nombre, codigo_barras, precio_venta, estado)
             VALUES (:nombre, :codigo_barras, :precio, "activo")
             ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), precio_venta = VALUES(precio_venta), estado = "activo"'
        );
        $stmt->execute([
            'nombre' => $p['nombre'],
            'codigo_barras' => $p['codigo_barras'],
            'precio' => $p['precio'],
        ]);

        $stmt = $pdo->prepare('SELECT id_producto FROM productos WHERE codigo_barras = :codigo_barras');
        $stmt->execute(['codigo_barras' => $p['codigo_barras']]);
        $idProducto = (int) $stmt->fetchColumn();

        if ($idProducto <= 0) {
            throw new RuntimeException("No se pudo resolver id_producto para {$p['codigo_barras']}.");
        }

        $stmt = $pdo->prepare(
            'INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual)
             VALUES (:id_producto, :id_almacen, :cantidad)
             ON DUPLICATE KEY UPDATE cantidad_actual = VALUES(cantidad_actual)'
        );
        $stmt->execute([
            'id_producto' => $idProducto,
            'id_almacen' => $idAlmacen,
            'cantidad' => $p['stock'],
        ]);

        echo "Seed OK: {$p['nombre']} -> id_producto={$idProducto}, id_almacen={$idAlmacen}, stock={$p['stock']}\n";
    }

    $pdo->commit();
    exit(0);
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Seed error: ' . $e->getMessage() . "\n");
    exit(1);
}
