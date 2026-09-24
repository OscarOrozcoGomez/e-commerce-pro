<?php
declare(strict_types=1);

/**
 * Auditoria de precios y costos (SOLO LECTURA, sin datos de clientes): detecta capturas que no
 * tienen sentido antes de que lleguen a una oferta, al catalogo, a Alex o a un pedido.
 *
 *   Catalogo:   costo vacio, costo >= venta, margen muy bajo o extremo, precio tachado
 *               (precio_comparacion) menor al de venta, y presentaciones de una misma familia donde
 *               la mas grande sale MAS cara por capsula que la chica.
 *   Ofertas:    "oferta" que no es descuento (precio efectivo >= precio normal), oferta por debajo
 *               del costo, y precio_oferta capturado por encima del precio normal.
 *   Pedidos:    lineas cuyo subtotal no es cantidad x precio, cobradas por ENCIMA del precio original
 *               o por debajo del costo, y pedidos cuyo total no cuadra con sus lineas
 *               (subtotal - descuento + envio).
 *
 *   C:\xampp\php\php.exe scripts/auditar_precios_productos.php            # ejemplos (15 por seccion)
 *   C:\xampp\php\php.exe scripts/auditar_precios_productos.php --todo     # todos los casos
 *   C:\xampp\php\php.exe scripts/auditar_precios_productos.php --dias=90   # ventana de pedidos (default 120)
 *
 * En el VPS:  cd /home/bellezaybienestar/htdocs/bellezaybienestar.com.mx && APP_ENV=production php8.2 scripts/auditar_precios_productos.php
 * Se ignoran: los productos "Playwright E2E ..." y los pedidos que los incluyen (datos de las pruebas E2E) y las
 * lineas quitadas de un pedido (estado_entrega = 'rechazado', ver core/entrega_item_utils.php): esas ya no cuentan en el total.
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, 'Este script solo se puede ejecutar por CLI.' . PHP_EOL);
    exit(1);
}

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/oferta_pricing.php';

$opciones = getopt('', ['todo', 'dias:']);
$mostrarTodo = array_key_exists('todo', $opciones);
$dias = max(1, (int) ($opciones['dias'] ?? 120));
$limite = $mostrarTodo ? PHP_INT_MAX : 15;

$pdo = getPDO();
$hallazgos = [];   // seccion => lista de lineas
$fila = static fn(string $s) => $s;

$agregar = static function (string $seccion, string $linea) use (&$hallazgos): void {
    $hallazgos[$seccion][] = $linea;
};
$nombre = static fn(array $r): string => mb_substr(trim((string) (($r['nombre_corto'] ?? '') !== '' ? $r['nombre_corto'] : $r['nombre'])), 0, 42) . ' [#' . $r['id_producto'] . ']';
$m = static fn($v): string => '$' . number_format((float) $v, 2);

/* ---------------- Catalogo y ofertas ---------------- */

$productos = $pdo->query(
    "SELECT p.id_producto, p.id_padre, p.nombre, p.nombre_corto, p.precio_venta, p.precio_costo, p.precio_oferta,
            p.precio_comparacion, p.capsulas_por_envase, p.estado,
            (" . ofertaSqlEnOfertaExpr('p') . ") AS en_oferta,
            (SELECT COALESCE(SUM(ia.cantidad_actual), 0) FROM inventario_almacen ia WHERE ia.id_producto = p.id_producto) AS stock
     FROM productos p
     WHERE p.estado = 'activo' AND p.nombre NOT LIKE 'Playwright E2E%'
     ORDER BY p.nombre"
)->fetchAll(PDO::FETCH_ASSOC);

foreach ($productos as $p) {
    $v = (float) $p['precio_venta'];
    $c = (float) $p['precio_costo'];
    $o = ($p['precio_oferta'] !== null && (float) $p['precio_oferta'] > 0) ? (float) $p['precio_oferta'] : null;
    $enOferta = (int) $p['en_oferta'] === 1;
    $etq = $nombre($p);

    if ($v <= 0) {
        $agregar('CORREGIR | Precio de venta vacio o en cero', "{$etq}: venta " . $m($v));
        continue;
    }
    if ($c <= 0) {
        $agregar('CORREGIR | Costo vacio o en cero (no se puede calcular oferta ni margen)', "{$etq}: venta " . $m($v) . ", stock {$p['stock']}");
    } elseif ($c >= $v) {
        $agregar('CORREGIR | Costo mayor o igual al precio de venta (se vende a perdida o el costo esta mal)', "{$etq}: venta " . $m($v) . ' costo ' . $m($c) . ($enOferta ? '  ** EN OFERTAS **' : ''));
    } else {
        if ($v < $c * 1.10) {
            $agregar('REVISAR | Margen menor a 10% sobre el costo', "{$etq}: venta " . $m($v) . ' costo ' . $m($c));
        }
        if ($v > $c * 4) {
            $agregar('REVISAR | Precio de venta mas de 4 veces el costo (posible costo o precio mal capturado)', "{$etq}: venta " . $m($v) . ' costo ' . $m($c));
        }
    }

    $comparacion = $p['precio_comparacion'] !== null ? (float) $p['precio_comparacion'] : 0.0;
    if ($comparacion > 0 && $comparacion <= $v) {
        $agregar('REVISAR | Precio tachado (comparacion) no es mayor al precio de venta: no se ve como descuento', "{$etq}: venta " . $m($v) . ' comparacion ' . $m($comparacion));
    }

    if ($enOferta) {
        $efectivo = ofertaPrecioEfectivo($v, $c, $o, true);
        if ($o !== null && $o >= $v) {
            $agregar('CORREGIR | En Ofertas con precio_oferta >= precio normal (el cliente no ahorra nada)', "{$etq}: venta " . $m($v) . ' precio_oferta ' . $m($o) . ' costo ' . $m($c));
        } elseif ($o === null && $efectivo >= $v) {
            $agregar('CORREGIR | En Ofertas sin descuento real (costo + $50 alcanza o supera el precio de venta)', "{$etq}: venta " . $m($v) . ' costo ' . $m($c));
        } elseif ($c > 0 && $efectivo < $c) {
            $agregar('CORREGIR | Oferta POR DEBAJO del costo (se vende a perdida)', "{$etq}: oferta " . $m($efectivo) . ' costo ' . $m($c));
        } elseif ($c > 0 && $efectivo - $c < 10) {
            $agregar('REVISAR | Oferta con menos de $10 de ganancia sobre el costo', "{$etq}: oferta " . $m($efectivo) . ' costo ' . $m($c));
        }
    } elseif ($o !== null && $o >= $v) {
        $agregar('REVISAR | precio_oferta capturado pero no esta en Ofertas, y es >= precio normal (si lo meten a Ofertas no habria descuento)', "{$etq}: venta " . $m($v) . ' precio_oferta ' . $m($o));
    }
}

// Presentaciones de una misma familia (id_padre): la mas grande no deberia salir mas cara por capsula.
$familias = [];
foreach ($productos as $p) {
    $caps = (int) ($p['capsulas_por_envase'] ?? 0);
    $padre = (int) ($p['id_padre'] ?? 0);
    if ($caps > 0 && (float) $p['precio_venta'] > 0) {
        $familias[$padre > 0 ? $padre : (int) $p['id_producto']][] = $p + ['por_capsula' => (float) $p['precio_venta'] / $caps];
    }
}
foreach ($familias as $miembros) {
    if (count($miembros) < 2) {
        continue;
    }
    usort($miembros, static fn(array $a, array $b): int => (int) $a['capsulas_por_envase'] <=> (int) $b['capsulas_por_envase']);
    for ($i = 1; $i < count($miembros); $i++) {
        $chica = $miembros[$i - 1];
        $grande = $miembros[$i];
        if ((int) $grande['capsulas_por_envase'] > (int) $chica['capsulas_por_envase'] && $grande['por_capsula'] > $chica['por_capsula'] * 1.05) {
            $agregar(
                'REVISAR | Presentacion mas grande sale MAS cara por capsula que la chica (misma familia)',
                $nombre($grande) . " ({$grande['capsulas_por_envase']} caps, " . $m($grande['precio_venta']) . ', ' . $m($grande['por_capsula']) . '/cap) vs '
                . $nombre($chica) . " ({$chica['capsulas_por_envase']} caps, " . $m($chica['precio_venta']) . ', ' . $m($chica['por_capsula']) . '/cap)'
            );
        }
    }
}

/* ---------------- Pedidos ---------------- */

$desde = date('Y-m-d 00:00:00', strtotime("-{$dias} days"));

$lineas = $pdo->prepare(
    "SELECT pe.numero_pedido, pe.estado, pe.fecha_creacion, dp.id_producto, dp.cantidad, dp.precio_original, dp.precio_unitario,
            dp.costo_unitario, dp.monto_descuento, dp.subtotal, p.nombre, p.nombre_corto
     FROM detalle_pedidos dp
     JOIN pedidos pe ON pe.id_pedido = dp.id_pedido
     LEFT JOIN productos p ON p.id_producto = dp.id_producto
     WHERE pe.estado <> 'cancelado' AND pe.fecha_creacion >= ? AND dp.estado_entrega <> 'rechazado'
       AND NOT EXISTS (SELECT 1 FROM detalle_pedidos d2 JOIN productos p2 ON p2.id_producto = d2.id_producto WHERE d2.id_pedido = pe.id_pedido AND p2.nombre LIKE 'Playwright E2E%')
     ORDER BY pe.fecha_creacion DESC"
);
$lineas->execute([$desde]);
$totalLineas = 0;
foreach ($lineas->fetchAll(PDO::FETCH_ASSOC) as $l) {
    $totalLineas++;
    $etq = '#' . $l['numero_pedido'] . ' (' . substr((string) $l['fecha_creacion'], 0, 10) . ', ' . $l['estado'] . ') ' . mb_substr(trim((string) (($l['nombre_corto'] ?? '') !== '' ? $l['nombre_corto'] : ($l['nombre'] ?? '?'))), 0, 34);
    $cant = (int) $l['cantidad'];
    $unit = (float) $l['precio_unitario'];
    $orig = (float) $l['precio_original'];
    $costo = (float) $l['costo_unitario'];

    $descLinea = (float) $l['monto_descuento'];
    if (abs((float) $l['subtotal'] - ($cant * $unit - $descLinea)) > 0.011) {
        $agregar('CORREGIR | Linea de pedido: subtotal no es cantidad x precio - descuento de la linea', "{$etq}: {$cant} x " . $m($unit) . ($descLinea > 0 ? ' - ' . $m($descLinea) : '') . ' = ' . $m($cant * $unit - $descLinea) . ' pero subtotal ' . $m($l['subtotal']));
    }
    if ($orig > 0 && $unit > $orig + 0.011) {
        $agregar('CORREGIR | Linea de pedido cobrada POR ENCIMA del precio original', "{$etq}: precio original " . $m($orig) . ' cobrado ' . $m($unit));
    }
    if ($costo > 0 && $unit > 0 && $unit < $costo - 0.011) {
        $agregar('REVISAR | Linea de pedido vendida por debajo del costo', "{$etq}: precio " . $m($unit) . ' costo ' . $m($costo));
    }
}

$pedidos = $pdo->prepare(
    "SELECT pe.numero_pedido, pe.estado, pe.fecha_creacion, pe.subtotal, pe.descuento_total, pe.costo_envio, pe.total,
            (SELECT COALESCE(SUM(dp.subtotal), 0) FROM detalle_pedidos dp WHERE dp.id_pedido = pe.id_pedido AND dp.estado_entrega <> 'rechazado') AS suma_lineas,
            (SELECT COALESCE(SUM(dp.cantidad * dp.precio_unitario), 0) FROM detalle_pedidos dp WHERE dp.id_pedido = pe.id_pedido AND dp.estado_entrega <> 'rechazado') AS bruto_lineas,
            (SELECT COUNT(*) FROM detalle_pedidos dp WHERE dp.id_pedido = pe.id_pedido AND dp.estado_entrega <> 'rechazado') AS n_lineas
     FROM pedidos pe
     WHERE pe.estado <> 'cancelado' AND pe.fecha_creacion >= ? AND NOT EXISTS (SELECT 1 FROM detalle_pedidos d2 JOIN productos p2 ON p2.id_producto = d2.id_producto WHERE d2.id_pedido = pe.id_pedido AND p2.nombre LIKE 'Playwright E2E%')
     ORDER BY pe.fecha_creacion DESC"
);
$pedidos->execute([$desde]);
$totalPedidos = 0;
foreach ($pedidos->fetchAll(PDO::FETCH_ASSOC) as $pe) {
    $totalPedidos++;
    $etq = '#' . $pe['numero_pedido'] . ' (' . substr((string) $pe['fecha_creacion'], 0, 10) . ', ' . $pe['estado'] . ')';
    if ((int) $pe['n_lineas'] === 0) {
        $agregar('REVISAR | Pedido sin lineas de producto', "{$etq}: total " . $m($pe['total']));
        continue;
    }
    // El subtotal del pedido puede ser la suma de las lineas ya con su descuento, o el bruto (cantidad x precio)
    // con los descuentos de linea dentro de descuento_total: las dos formas cuadran.
    if (abs((float) $pe['subtotal'] - (float) $pe['suma_lineas']) > 0.011 && abs((float) $pe['subtotal'] - (float) $pe['bruto_lineas']) > 0.011) {
        $agregar('CORREGIR | Pedido: subtotal no coincide con la suma de sus lineas', "{$etq}: subtotal " . $m($pe['subtotal']) . ' vs lineas ' . $m($pe['suma_lineas']) . ' (bruto ' . $m($pe['bruto_lineas']) . ')');
    }
    $esperado = (float) $pe['subtotal'] - (float) $pe['descuento_total'] + (float) $pe['costo_envio'];
    if (abs((float) $pe['total'] - $esperado) > 0.011) {
        $agregar('CORREGIR | Pedido: total no cuadra (subtotal - descuento + envio)', "{$etq}: total " . $m($pe['total']) . ' esperado ' . $m($esperado)
            . ' (subtotal ' . $m($pe['subtotal']) . ', descuento ' . $m($pe['descuento_total']) . ', envio ' . $m($pe['costo_envio']) . ')');
    }
}

/* ---------------- Reporte ---------------- */

echo 'AUDITORIA DE PRECIOS | ' . date('Y-m-d H:i') . ' | BD: ' . $pdo->query('SELECT DATABASE()')->fetchColumn() . PHP_EOL;
echo 'Productos activos revisados: ' . count($productos) . " | pedidos no cancelados de los ultimos {$dias} dias: {$totalPedidos} ({$totalLineas} lineas)" . PHP_EOL . PHP_EOL;

if ($hallazgos === []) {
    echo 'Sin hallazgos.' . PHP_EOL;
    exit(0);
}

ksort($hallazgos); // CORREGIR antes que REVISAR
$totalCorregir = 0;
foreach ($hallazgos as $seccion => $lineasSeccion) {
    if (str_starts_with($seccion, 'CORREGIR')) {
        $totalCorregir += count($lineasSeccion);
    }
    echo '== ' . $seccion . ' (' . count($lineasSeccion) . ') ==' . PHP_EOL;
    foreach (array_slice($lineasSeccion, 0, $limite) as $l) {
        echo '  - ' . $l . PHP_EOL;
    }
    if (count($lineasSeccion) > $limite) {
        echo '  ... y ' . (count($lineasSeccion) - $limite) . ' mas (usa --todo)' . PHP_EOL;
    }
    echo PHP_EOL;
}
echo "Total a CORREGIR: {$totalCorregir}" . PHP_EOL;
exit($totalCorregir > 0 ? 2 : 0);
