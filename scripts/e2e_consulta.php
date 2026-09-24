<?php
declare(strict_types=1);

// SOLO LECTURA. Uso: php scripts/e2e_consulta.php <producto|pedido|lote|regla> <argumento>
//
// Lo consume tests/e2e/db-utils.ts (consulta*): deja a una prueba comprobar lo que el SERVIDOR guardo de verdad
// (el precio con el que quedo un pedido, si un producto entro a Ofertas...) en vez de fiarse solo de lo que muestra la
// pantalla. Imprime un JSON en una linea; si no hay coincidencia imprime "null".
//   producto "<nombre>"      -> solo productos "Playwright E2E ..." (precios, stock, si esta en Ofertas, si esta gestionado)
//   pedido "<numero_pedido>" -> totales y renglones del pedido
//   lote "<codigo_lote>"     -> solo lotes "E2E-..." (estado, cantidad, marcas de oferta/atendida)
//   regla "<prefijo>"        -> reglas de aprendizaje de Alex cuyo contexto empieza con "Playwright" (cuantas hay y la mas reciente)

require_once __DIR__ . '/../core/config.php';

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    http_response_code(403);
    echo "Este script solo se puede ejecutar por CLI o phpdbg.\n";
    exit(1);
}

[$_, $tipo, $arg] = array_pad($argv, 3, '');
$pdo = getPDO();
$salida = null;

if ($tipo === 'producto' && str_starts_with($arg, 'Playwright E2E ')) {
    $stmt = $pdo->prepare('SELECT id_producto, nombre, precio_venta, precio_costo, precio_oferta, estado FROM productos WHERE nombre = ? AND id_padre IS NULL LIMIT 1');
    $stmt->execute([$arg]);
    if ($p = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $idProducto = (int) $p['id_producto'];
        $enOfertas = $pdo->prepare(
            'SELECT COUNT(*) FROM producto_categorias pc JOIN categorias c ON c.id_categoria = pc.id_categoria WHERE pc.id_producto = ? AND c.nombre = "Ofertas"'
        );
        $enOfertas->execute([$idProducto]);
        $gestionado = false;
        $hayTabla = (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'oferta_caducidad_gestion'")->fetchColumn() > 0;
        if ($hayTabla) {
            $g = $pdo->prepare('SELECT COUNT(*) FROM oferta_caducidad_gestion WHERE id_producto = ?');
            $g->execute([$idProducto]);
            $gestionado = (int) $g->fetchColumn() > 0;
        }
        $stock = $pdo->prepare('SELECT COALESCE(SUM(cantidad_actual), 0) FROM inventario_almacen WHERE id_producto = ?');
        $stock->execute([$idProducto]);
        $salida = [
            'id_producto' => $idProducto,
            'precio_venta' => (float) $p['precio_venta'],
            'precio_costo' => (float) $p['precio_costo'],
            'precio_oferta' => $p['precio_oferta'] !== null ? (float) $p['precio_oferta'] : null,
            'en_ofertas' => (int) $enOfertas->fetchColumn() > 0,
            'gestionado' => $gestionado,
            'stock' => (int) $stock->fetchColumn(),
        ];
    }
} elseif ($tipo === 'pedido' && preg_match('/^[A-Za-z0-9-]{6,40}$/', $arg)) {
    $stmt = $pdo->prepare('SELECT id_pedido, estado, tipo_entrega, subtotal, descuento_total, costo_envio, total FROM pedidos WHERE numero_pedido = ? LIMIT 1');
    $stmt->execute([$arg]);
    if ($ped = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $det = $pdo->prepare(
            'SELECT dp.id_producto, pr.nombre, dp.cantidad, dp.precio_original, dp.precio_unitario, dp.subtotal
             FROM detalle_pedidos dp JOIN productos pr ON pr.id_producto = dp.id_producto WHERE dp.id_pedido = ? ORDER BY dp.id_detalle'
        );
        $det->execute([(int) $ped['id_pedido']]);
        $renglones = array_map(static fn(array $r): array => [
            'id_producto' => (int) $r['id_producto'],
            'nombre' => $r['nombre'],
            'cantidad' => (int) $r['cantidad'],
            'precio_original' => (float) $r['precio_original'],
            'precio_unitario' => (float) $r['precio_unitario'],
            'subtotal' => (float) $r['subtotal'],
        ], $det->fetchAll(PDO::FETCH_ASSOC));
        $salida = [
            'id_pedido' => (int) $ped['id_pedido'],
            'estado' => $ped['estado'],
            'tipo_entrega' => $ped['tipo_entrega'],
            'subtotal' => (float) $ped['subtotal'],
            'descuento_total' => (float) $ped['descuento_total'],
            'costo_envio' => (float) $ped['costo_envio'],
            'total' => (float) $ped['total'],
            'renglones' => $renglones,
        ];
    }
} elseif ($tipo === 'lote' && str_starts_with($arg, 'E2E-')) {
    $stmt = $pdo->prepare('SELECT id_lote, id_producto, estado, cantidad_restante, en_oferta, alerta_atendida, fecha_caducidad FROM lotes_inventario WHERE codigo_lote = ? LIMIT 1');
    $stmt->execute([$arg]);
    if ($l = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $salida = [
            'id_lote' => (int) $l['id_lote'],
            'id_producto' => (int) $l['id_producto'],
            'estado' => $l['estado'],
            'cantidad_restante' => (int) $l['cantidad_restante'],
            'en_oferta' => (int) $l['en_oferta'] === 1,
            'alerta_atendida' => (int) $l['alerta_atendida'] === 1,
            'fecha_caducidad' => $l['fecha_caducidad'],
        ];
    }
} elseif ($tipo === 'regla' && str_starts_with($arg, 'Playwright')) {
    $stmt = $pdo->prepare('SELECT id_regla, contexto_o_pregunta, respuesta_o_accion_esperada, etiqueta_sugerida, activa FROM ai_reglas_aprendizaje WHERE contexto_o_pregunta LIKE ? ORDER BY id_regla DESC');
    $stmt->execute([$arg . '%']);
    $filas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $salida = $filas === [] ? null : ['total' => count($filas), 'ultima' => $filas[0]];
} else {
    fwrite(STDERR, "Uso: php scripts/e2e_consulta.php <producto \"Playwright E2E ...\"|pedido <numero>|lote \"E2E-...\"|regla \"Playwright...\">\n");
    exit(2);
}

echo json_encode($salida, JSON_UNESCAPED_UNICODE), "\n";
