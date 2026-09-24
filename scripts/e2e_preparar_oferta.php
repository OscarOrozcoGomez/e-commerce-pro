<?php
declare(strict_types=1);

// Uso: php scripts/e2e_preparar_oferta.php
//
// Deja listo (y SIEMPRE "sin oferta") el producto "Playwright E2E Oferta Product" para las pruebas de "Poner en oferta"
// (tests/e2e/caducidades-oferta.staff.spec.ts): costo 100, precio 199 (el piso de la escalera, costo+$50=150, queda por
// debajo del precio), 500 en stock y un lote a 20 dias. Lo llama la propia spec en su beforeAll, asi se puede repetir sin
// resembrar. Solo toca ese producto y su lote "E2E-OFERTA-LOTE".

require_once __DIR__ . '/../core/config.php';

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    http_response_code(403);
    echo "Este script solo se puede ejecutar por CLI o phpdbg.\n";
    exit(1);
}

const NOMBRE = 'Playwright E2E Oferta Product';
const BARRAS = 'E2E-PLAYWRIGHT-TEST-0009';
const LOTE = 'E2E-OFERTA-LOTE';

$pdo = getPDO();
$idAlmacen = (int) $pdo->query("SELECT id_almacen FROM almacenes WHERE estado = 'activo' ORDER BY id_almacen ASC LIMIT 1")->fetchColumn();
if ($idAlmacen <= 0) {
    fwrite(STDERR, "No hay almacen activo. ¿Se importo database.sql?\n");
    exit(1);
}

$pdo->beginTransaction();
try {
    $pdo->prepare(
        'INSERT INTO productos (nombre, codigo_barras, precio_venta, precio_costo, estado)
         VALUES (:n, :b, 199.00, 100.00, "activo")
         ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), precio_venta = 199.00, precio_costo = 100.00, precio_oferta = NULL, estado = "activo"'
    )->execute(['n' => NOMBRE, 'b' => BARRAS]);
    $stmt = $pdo->prepare('SELECT id_producto FROM productos WHERE codigo_barras = ?');
    $stmt->execute([BARRAS]);
    $idProducto = (int) $stmt->fetchColumn();

    $pdo->prepare(
        'INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual) VALUES (?, ?, 500) ON DUPLICATE KEY UPDATE cantidad_actual = 500'
    )->execute([$idProducto, $idAlmacen]);

    // Fuera de la categoria Ofertas y de la gestion automatica.
    $pdo->prepare(
        'DELETE pc FROM producto_categorias pc JOIN categorias c ON c.id_categoria = pc.id_categoria WHERE pc.id_producto = ? AND c.nombre = "Ofertas"'
    )->execute([$idProducto]);
    $hayGestion = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'oferta_caducidad_gestion'")->fetchColumn() > 0;
    if ($hayGestion) {
        $pdo->prepare('DELETE FROM oferta_caducidad_gestion WHERE id_producto = ?')->execute([$idProducto]);
    }

    // Lote a 20 dias: se reutiliza el de corridas anteriores (puede estar referenciado por ventas FEFO).
    $stmt = $pdo->prepare('SELECT id_lote FROM lotes_inventario WHERE id_producto = ? AND codigo_lote = ? LIMIT 1');
    $stmt->execute([$idProducto, LOTE]);
    $idLote = (int) $stmt->fetchColumn();
    $caducidad = (new DateTimeImmutable('+20 days'))->format('Y-m-d');
    if ($idLote <= 0) {
        $pdo->prepare(
            'INSERT INTO lotes_inventario (id_producto, id_almacen, codigo_lote, fecha_caducidad, fecha_ingreso, cantidad_inicial, cantidad_restante, costo_unitario, estado)
             VALUES (?, ?, ?, ?, CURDATE(), 500, 500, 100.00, "activo")'
        )->execute([$idProducto, $idAlmacen, LOTE, $caducidad]);
    } else {
        $pdo->prepare(
            'UPDATE lotes_inventario SET fecha_caducidad = ?, cantidad_inicial = 500, cantidad_restante = 500, estado = "activo", en_oferta = 0, alerta_atendida = 0, id_almacen = ? WHERE id_lote = ?'
        )->execute([$caducidad, $idAlmacen, $idLote]);
    }
    $pdo->commit();
    echo "OK: " . NOMBRE . " id_producto={$idProducto}, sin oferta, lote a {$caducidad}\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
