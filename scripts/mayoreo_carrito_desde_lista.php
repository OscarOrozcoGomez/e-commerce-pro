<?php
declare(strict_types=1);
/**
 * Genera scripts/.mayoreo/carrito.json con los renglones que mayoreo_llenar_carrito.mjs
 * va a meter al carrito de B Life.
 *
 * Fuentes:
 *   - Una Orden de Compra ya generada (la mas comun: seleccionaste productos en
 *     la Lista de Compra y le diste "Generar Orden de Compra"; esa orden YA NO
 *     aparece en la lista sugerida, asi que se lee directo de ella):
 *       php scripts/mayoreo_carrito_desde_lista.php --orden OC-20260910-193145-1
 *       php scripts/mayoreo_carrito_desde_lista.php --orden 12          (o por id numerico)
 *   - Lista de Compra Sugerida: productos bajo el minimo en un almacen que TODAVIA
 *     no estan en ninguna orden abierta.
 *       php scripts/mayoreo_carrito_desde_lista.php --almacen 1
 *       php scripts/mayoreo_carrito_desde_lista.php --admin        (todas las sucursales)
 *   - Manual (para pruebas): pares SKU:CANTIDAD separados por coma.
 *       php scripts/mayoreo_carrito_desde_lista.php --items "BLIFE-APP-017:6,BLIFE-CITMAG-120:4"
 *
 * Solo incluye productos de B Life (sku que empieza con "BLIFE"). Los demas se
 * ignoran (no se compran en mayoreo.blife.mx).
 */

putenv('APP_ENV=' . (getenv('APP_ENV') ?: 'qa'));
if (getenv('DISABLE_GSM') === false) {
    putenv('DISABLE_GSM=1');
}

require __DIR__ . '/../core/config.php';
require __DIR__ . '/../core/purchase_order_utils.php';

$argvv = $argv;
function argVal(array $argv, string $name, ?string $def = null): ?string
{
    $i = array_search($name, $argv, true);
    return ($i !== false && isset($argv[$i + 1])) ? (string) $argv[$i + 1] : $def;
}
$esManual = in_array('--items', $argvv, true);
$esAdmin = in_array('--admin', $argvv, true);
$esOrden = in_array('--orden', $argvv, true);
$idAlmacen = (int) (argVal($argvv, '--almacen', '0') ?? 0);

$pdo = getPDO();
$outDir = __DIR__ . '/.mayoreo';
if (!is_dir($outDir)) {
    mkdir($outDir, 0777, true);
}
$outFile = $outDir . '/carrito.json';

/** @var array<int, array{sku:string, cantidad:int}> $pedidos */
$pedidos = [];

if ($esOrden) {
    $ref = trim((string) argVal($argvv, '--orden', ''));
    if ($ref === '') {
        fwrite(STDERR, "Falta el numero/referencia de la orden. Ejemplo: --orden OC-20260910-193145-1\n");
        exit(1);
    }
    $stmtOc = ctype_digit($ref)
        ? $pdo->prepare('SELECT id_orden_compra, referencia, id_almacen, estado FROM ordenes_compra WHERE id_orden_compra = ?')
        : $pdo->prepare('SELECT id_orden_compra, referencia, id_almacen, estado FROM ordenes_compra WHERE referencia = ?');
    $stmtOc->execute([$ref]);
    $oc = $stmtOc->fetch(PDO::FETCH_ASSOC);
    if (!$oc) {
        fwrite(STDERR, "No encontre la orden '{$ref}'.\n");
        exit(1);
    }
    if (!in_array($oc['estado'], ['borrador', 'enviada', 'parcial'], true)) {
        fwrite(STDERR, "La orden '{$ref}' ya esta '{$oc['estado']}' (no esta abierta).\n");
        exit(1);
    }
    // Cantidad a comprar = lo que falta por recibir de esa orden (por si ya se
    // surtio parcialmente).
    $stmtLin = $pdo->prepare(
        "SELECT p.sku, GREATEST(0, doc.cantidad_solicitada - doc.cantidad_recibida) AS cantidad
         FROM detalle_orden_compra doc JOIN productos p ON p.id_producto = doc.id_producto
         WHERE doc.id_orden_compra = ?"
    );
    $stmtLin->execute([(int) $oc['id_orden_compra']]);
    foreach ($stmtLin->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $cant = (int) $row['cantidad'];
        if ($cant > 0 && (string) $row['sku'] !== '') {
            $pedidos[] = ['sku' => (string) $row['sku'], 'cantidad' => $cant];
        }
    }
    echo "Orden {$oc['referencia']} (#{$oc['id_orden_compra']}, {$oc['estado']}): " . count($pedidos) . " renglon(es) pendientes.\n";
    if ($pedidos === []) {
        fwrite(STDERR, "Esa orden no tiene renglones pendientes por comprar.\n");
        exit(1);
    }
} elseif ($esManual) {
    $spec = (string) argVal($argvv, '--items', '');
    foreach (array_filter(array_map('trim', explode(',', $spec))) as $par) {
        [$sku, $cant] = array_pad(explode(':', $par, 2), 2, '1');
        $sku = trim($sku);
        $cant = (int) trim((string) $cant);
        if ($sku !== '' && $cant > 0) {
            $pedidos[] = ['sku' => $sku, 'cantidad' => $cant];
        }
    }
    if ($pedidos === []) {
        fwrite(STDERR, "No entendi --items. Formato: --items \"BLIFE-APP-017:6,BLIFE-CITMAG-120:4\"\n");
        exit(1);
    }
} else {
    if (!$esAdmin && $idAlmacen <= 0) {
        fwrite(STDERR, "Falta --almacen N (o --admin para todas). Almacenes:\n");
        foreach ($pdo->query('SELECT id_almacen, nombre FROM almacenes ORDER BY id_almacen') as $a) {
            fwrite(STDERR, "  {$a['id_almacen']}  {$a['nombre']}\n");
        }
        exit(1);
    }
    $sug = purchaseOrderFetchSuggestions($pdo, $esAdmin, $esAdmin ? null : $idAlmacen);
    foreach ($sug['listaCompra'] as $row) {
        $aComprar = max(0, (int) $row['stock_maximo'] - (int) $row['cantidad_actual']);
        if ($aComprar > 0 && (string) $row['sku'] !== '') {
            $pedidos[] = ['sku' => (string) $row['sku'], 'cantidad' => $aComprar];
        }
    }
    if ($pedidos === []) {
        fwrite(STDERR, "La lista de compra sugerida esta vacia para ese almacen.\n");
        exit(1);
    }
}

// Enriquecer con datos del producto; filtrar a B Life.
$carrito = [];
$noBlife = [];
$noEncontrado = [];
$stmt = $pdo->prepare(
    "SELECT id_producto, nombre, sku, nombre_variante, mayoreo_url, precio_costo
     FROM productos WHERE sku = ? LIMIT 1"
);
foreach ($pedidos as $p) {
    $stmt->execute([$p['sku']]);
    $prod = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$prod) {
        $noEncontrado[] = $p['sku'];
        continue;
    }
    if (stripos((string) $prod['sku'], 'BLIFE') !== 0) {
        $noBlife[] = $prod['sku'] . ' (' . $prod['nombre'] . ')';
        continue;
    }
    $carrito[] = [
        'id_producto' => (int) $prod['id_producto'],
        'sku' => (string) $prod['sku'],
        'nombre' => (string) $prod['nombre'],
        'nombre_variante' => (string) ($prod['nombre_variante'] ?? ''),
        'mayoreo_url' => (string) ($prod['mayoreo_url'] ?? ''),
        'precio_costo' => (float) $prod['precio_costo'],
        'cantidad' => (int) $p['cantidad'],
    ];
}

file_put_contents($outFile, json_encode($carrito, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

echo "OK -> {$outFile}\n";
echo count($carrito) . " renglon(es) de B Life:\n";
foreach ($carrito as $c) {
    $via = $c['mayoreo_url'] !== '' ? 'URL guardada' : 'buscar por nombre';
    echo "  x{$c['cantidad']}  {$c['sku']}  {$c['nombre']}" . ($c['nombre_variante'] !== '' ? "  [{$c['nombre_variante']}]" : '') . "  ({$via})\n";
}
if ($noBlife) {
    echo "\nIgnorados (no son B Life, se compran en otro lado):\n  - " . implode("\n  - ", $noBlife) . "\n";
}
if ($noEncontrado) {
    echo "\nSKU no encontrado en el catalogo:\n  - " . implode("\n  - ", $noEncontrado) . "\n";
}
echo "\nAhora corre:  node scripts/mayoreo_llenar_carrito.mjs\n";
