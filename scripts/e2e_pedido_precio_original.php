<?php
declare(strict_types=1);

// Uso: php scripts/e2e_pedido_precio_original.php <numero_pedido> <precio_original>
//
// Solo para tests/e2e/entregas-fotos-y-precios.staff.spec.ts: fija detalle_pedidos.precio_original de un pedido de
// PRUEBA para poder ver en la tarjeta de entrega el "precio original tachado" (que solo sale cuando
// precio_original > precio_unitario, p.ej. un precio de oferta). Armar ese caso por la UI exigiria una categoria de
// oferta completa; aqui basta el dato. Por seguridad SOLO toca renglones de productos "Playwright E2E ...".

require_once __DIR__ . '/../core/config.php';

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    http_response_code(403);
    echo "Este script solo se puede ejecutar por CLI o phpdbg.\n";
    exit(1);
}

// Escribe/borra datos de prueba: NUNCA contra produccion (APP_ENV=production es el valor por defecto en el VPS).
if (IS_PRODUCTION) {
    fwrite(STDERR, "Rechazado: este script de pruebas no corre con APP_ENV=production." . PHP_EOL);
    exit(1);
}

[$_, $numero, $precio] = array_pad($argv, 3, '');

if (!preg_match('/^[A-Za-z0-9-]{6,40}$/', $numero) || !is_numeric($precio) || (float) $precio <= 0) {
    fwrite(STDERR, "Uso: php scripts/e2e_pedido_precio_original.php <numero_pedido> <precio_original>\n");
    exit(2);
}

$pdo = getPDO();
$stmt = $pdo->prepare(
    "UPDATE detalle_pedidos dp
     JOIN pedidos pe ON pe.id_pedido = dp.id_pedido
     JOIN productos pr ON pr.id_producto = dp.id_producto
     SET dp.precio_original = :precio
     WHERE pe.numero_pedido = :numero AND pr.nombre LIKE 'Playwright E2E %'"
);
$stmt->execute(['precio' => (float) $precio, 'numero' => $numero]);

if ($stmt->rowCount() === 0) {
    fwrite(STDERR, "Ningun renglon de prueba actualizado para el pedido '{$numero}'.\n");
    exit(3);
}
echo "OK: pedido {$numero} precio_original={$precio} ({$stmt->rowCount()} renglon/es)\n";
