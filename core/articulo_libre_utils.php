<?php
declare(strict_types=1);

/**
 * "Articulo libre": vender en el POS algo que NO esta en el catalogo (p. ej. producto de otra
 * marca que no se quiere exhibir) solo para llevar la contabilidad.
 *
 * Como funciona:
 * - Existe UN producto interno de sistema (codigo_barras ARTICULO_LIBRE_CODIGO, estado
 *   'archivado') que solo sirve para cumplir la llave foranea de detalle_pedidos.id_producto.
 *   Al estar archivado no sale en el catalogo, ni en el buscador del POS, ni para Alex, ni en
 *   las recomendaciones de inventario. No lleva inventario ni lotes.
 * - Cada linea vendida guarda su propia marca, descripcion, costo y precio
 *   (detalle_pedidos.marca_libre / descripcion_libre / costo_unitario / precio_unitario). Por
 *   eso se puede usar las veces que sea y distinto cada vez.
 * - Las metricas del mes (dashboard: ingresos - costos) ya suman por linea con
 *   detalle_pedidos.costo_unitario, asi que estas ventas y su ganancia cuentan solas.
 * - Permiso: 'vender_articulo_libre' (el admin lo tiene siempre; se asigna a otros desde
 *   Roles y Permisos). Migracion 20260926_000001_articulo_libre.sql.
 */

const ARTICULO_LIBRE_CODIGO = 'SYS-ARTICULO-LIBRE';
const ARTICULO_LIBRE_MARCA_MAX = 120;
const ARTICULO_LIBRE_DESCRIPCION_MAX = 255;
const ARTICULO_LIBRE_PRECIO_MAX = 1000000.0;

/**
 * Id del producto interno de sistema; null si la migracion aun no corrio.
 * Se memoriza por conexion (en pruebas cada test trae su propio PDO).
 */
function articuloLibreIdProducto(PDO $pdo): ?int
{
    // WeakMap y no spl_object_id(): un id se recicla al destruir la conexion.
    static $cache = null;
    $cache ??= new WeakMap();
    if (isset($cache[$pdo])) {
        return $cache[$pdo]['id'];
    }
    try {
        $stmt = $pdo->prepare('SELECT id_producto FROM productos WHERE codigo_barras = ? LIMIT 1');
        $stmt->execute([ARTICULO_LIBRE_CODIGO]);
        $id = $stmt->fetchColumn();
        $cache[$pdo] = ['id' => ($id !== false && (int) $id > 0) ? (int) $id : null];
    } catch (Throwable $e) {
        // No se memoriza el fallo: puede ser transitorio.
        return null;
    }
    return $cache[$pdo]['id'];
}

function articuloLibreEsProducto(PDO $pdo, int $idProducto): bool
{
    if ($idProducto <= 0) {
        return false;
    }
    $idLibre = articuloLibreIdProducto($pdo);
    return $idLibre !== null && $idLibre === $idProducto;
}

/** Colapsa espacios y recorta a $max caracteres (multibyte). */
function articuloLibreLimpiarTexto(string $texto, int $max): string
{
    $texto = trim((string) preg_replace('/\s+/u', ' ', $texto));
    if (mb_strlen($texto) > $max) {
        $texto = rtrim(mb_substr($texto, 0, $max));
    }
    return $texto;
}

/**
 * Valida y normaliza una linea de articulo libre capturada en el POS. Funcion pura.
 *
 * @param array{marca?:mixed, descripcion?:mixed, costo?:mixed, precio?:mixed, cantidad?:mixed, descuento?:mixed} $raw
 * @return array{ok:bool, error:?string, linea:?array{marca:string, descripcion:string, costo:float, precio:float, cantidad:int, descuento:float, subtotal_base:float, subtotal:float, ganancia:float, bajo_costo:bool}}
 */
function articuloLibreNormalizarLinea(array $raw): array
{
    $falla = static fn(string $msg): array => ['ok' => false, 'error' => $msg, 'linea' => null];

    $marca = articuloLibreLimpiarTexto((string) ($raw['marca'] ?? ''), ARTICULO_LIBRE_MARCA_MAX);
    $descripcion = articuloLibreLimpiarTexto((string) ($raw['descripcion'] ?? ''), ARTICULO_LIBRE_DESCRIPCION_MAX);
    if ($marca === '') {
        return $falla('Captura la marca del artículo libre.');
    }
    if ($descripcion === '') {
        return $falla('Captura la descripción del artículo libre.');
    }

    foreach (['costo', 'precio', 'cantidad'] as $campo) {
        $valor = $raw[$campo] ?? '';
        if (!is_numeric($valor)) {
            return $falla("El {$campo} del artículo libre \"{$descripcion}\" no es un número válido.");
        }
    }
    $costo = round((float) $raw['costo'], 2);
    $precio = round((float) $raw['precio'], 2);
    $cantidadF = (float) $raw['cantidad'];
    $cantidad = (int) $cantidadF;
    $descuento = is_numeric($raw['descuento'] ?? null) ? round(max(0.0, (float) $raw['descuento']), 2) : 0.0;

    if ($cantidad <= 0 || $cantidadF !== (float) $cantidad) {
        return $falla("La cantidad del artículo libre \"{$descripcion}\" debe ser un entero mayor a 0.");
    }
    if ($costo < 0) {
        return $falla("El costo del artículo libre \"{$descripcion}\" no puede ser negativo.");
    }
    if ($precio <= 0) {
        return $falla("El precio del artículo libre \"{$descripcion}\" debe ser mayor a 0.");
    }
    if ($precio > ARTICULO_LIBRE_PRECIO_MAX || $costo > ARTICULO_LIBRE_PRECIO_MAX) {
        return $falla("Revisa el costo/precio del artículo libre \"{$descripcion}\": es demasiado alto.");
    }

    $subtotalBase = round($precio * $cantidad, 2);
    if ($descuento > $subtotalBase) {
        return $falla('El descuento manual no puede ser mayor al subtotal del producto.');
    }
    $subtotal = round($subtotalBase - $descuento, 2);

    return [
        'ok' => true,
        'error' => null,
        'linea' => [
            'marca' => $marca,
            'descripcion' => $descripcion,
            'costo' => $costo,
            'precio' => $precio,
            'cantidad' => $cantidad,
            'descuento' => $descuento,
            'subtotal_base' => $subtotalBase,
            'subtotal' => $subtotal,
            'ganancia' => round($subtotal - ($costo * $cantidad), 2),
            'bajo_costo' => $subtotal < round($costo * $cantidad, 2),
        ],
    ];
}

/** Nombre a mostrar de una linea libre: "Descripcion (Marca)". */
function articuloLibreEtiqueta(?string $marca, ?string $descripcion): string
{
    $marca = trim((string) $marca);
    $descripcion = trim((string) $descripcion);
    if ($descripcion === '') {
        $descripcion = 'Artículo libre';
    }
    return $marca !== '' ? "{$descripcion} ({$marca})" : $descripcion;
}

/**
 * true si detalle_pedidos ya tiene las columnas de articulo libre (la migracion ya corrio).
 * Memorizado por conexion: las vistas lo consultan una vez por peticion.
 */
function articuloLibreColumnasListas(PDO $pdo): bool
{
    static $cache = null;
    $cache ??= new WeakMap();
    if (!isset($cache[$pdo])) {
        try {
            $pdo->query('SELECT descripcion_libre, marca_libre FROM detalle_pedidos WHERE 1 = 0');
            $cache[$pdo] = true;
        } catch (Throwable $e) {
            $cache[$pdo] = false;
        }
    }
    return $cache[$pdo];
}

/**
 * Expresion SQL del nombre a mostrar de una linea de pedido: la descripcion libre si la
 * linea es un articulo libre, si no el nombre del producto. Si la migracion aun no corrio
 * devuelve solo el nombre del producto (la consulta no truena durante el deploy).
 */
function articuloLibreSqlNombreLinea(PDO $pdo, string $aliasDetalle = 'dp', string $aliasProducto = 'p'): string
{
    $dp = preg_replace('/[^a-zA-Z0-9_]/', '', $aliasDetalle) ?: 'dp';
    $p = preg_replace('/[^a-zA-Z0-9_]/', '', $aliasProducto) ?: 'p';
    if (!articuloLibreColumnasListas($pdo)) {
        return "{$p}.nombre";
    }
    return "COALESCE(NULLIF({$dp}.descripcion_libre, ''), {$p}.nombre)";
}

/** Igual que articuloLibreSqlNombreLinea() pero para la variante: la marca en lineas libres. */
function articuloLibreSqlVarianteLinea(PDO $pdo, string $aliasDetalle = 'dp', string $aliasProducto = 'p'): string
{
    $dp = preg_replace('/[^a-zA-Z0-9_]/', '', $aliasDetalle) ?: 'dp';
    $p = preg_replace('/[^a-zA-Z0-9_]/', '', $aliasProducto) ?: 'p';
    if (!articuloLibreColumnasListas($pdo)) {
        return "{$p}.nombre_variante";
    }
    return "CASE WHEN {$dp}.descripcion_libre IS NOT NULL AND {$dp}.descripcion_libre <> '' THEN {$dp}.marca_libre ELSE {$p}.nombre_variante END";
}

/**
 * Rango [inicio, fin) de un mes 'YYYY-MM'; si el texto no es valido usa el mes actual.
 *
 * @return array{mes:string, inicio:string, fin:string}
 */
function articuloLibreRangoMes(?string $mes, ?DateTimeImmutable $hoy = null): array
{
    $hoy = $hoy ?? new DateTimeImmutable('now');
    $mes = trim((string) $mes);
    $inicio = null;
    if (preg_match('/^(\d{4})-(\d{2})$/', $mes, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
        $inicio = DateTimeImmutable::createFromFormat('!Y-m-d', sprintf('%04d-%02d-01', (int) $m[1], (int) $m[2]));
    }
    if (!$inicio) {
        $inicio = $hoy->modify('first day of this month')->setTime(0, 0);
    }
    return [
        'mes' => $inicio->format('Y-m'),
        'inicio' => $inicio->format('Y-m-d H:i:s'),
        'fin' => $inicio->modify('+1 month')->format('Y-m-d H:i:s'),
    ];
}

/**
 * Agrupa lineas libres por marca y calcula totales. Funcion pura.
 *
 * @param array<int, array<string, mixed>> $lineas Filas con marca_libre, cantidad, subtotal, costo_unitario.
 * @return array{totales:array{piezas:int, ventas:float, costo:float, ganancia:float, lineas:int}, por_marca:array<int, array{marca:string, piezas:int, ventas:float, costo:float, ganancia:float, margen_pct:?float}>}
 */
function articuloLibreResumir(array $lineas): array
{
    $totales = ['piezas' => 0, 'ventas' => 0.0, 'costo' => 0.0, 'ganancia' => 0.0, 'lineas' => 0];
    $porMarca = [];
    foreach ($lineas as $l) {
        $marca = trim((string) ($l['marca_libre'] ?? '')) ?: 'Sin marca';
        $clave = mb_strtolower($marca);
        $piezas = (int) ($l['cantidad'] ?? 0);
        $ventas = (float) ($l['subtotal'] ?? 0);
        $costo = (float) ($l['costo_unitario'] ?? 0) * $piezas;

        if (!isset($porMarca[$clave])) {
            $porMarca[$clave] = ['marca' => $marca, 'piezas' => 0, 'ventas' => 0.0, 'costo' => 0.0, 'ganancia' => 0.0, 'margen_pct' => null];
        }
        $porMarca[$clave]['piezas'] += $piezas;
        $porMarca[$clave]['ventas'] += $ventas;
        $porMarca[$clave]['costo'] += $costo;

        $totales['piezas'] += $piezas;
        $totales['ventas'] += $ventas;
        $totales['costo'] += $costo;
        $totales['lineas']++;
    }

    foreach ($porMarca as &$m) {
        $m['ventas'] = round($m['ventas'], 2);
        $m['costo'] = round($m['costo'], 2);
        $m['ganancia'] = round($m['ventas'] - $m['costo'], 2);
        $m['margen_pct'] = $m['ventas'] > 0 ? round(($m['ganancia'] / $m['ventas']) * 100, 1) : null;
    }
    unset($m);
    $porMarca = array_values($porMarca);
    usort($porMarca, static fn(array $a, array $b): int => $b['ganancia'] <=> $a['ganancia'] ?: strcmp($a['marca'], $b['marca']));

    $totales['ventas'] = round($totales['ventas'], 2);
    $totales['costo'] = round($totales['costo'], 2);
    $totales['ganancia'] = round($totales['ventas'] - $totales['costo'], 2);

    return ['totales' => $totales, 'por_marca' => $porMarca];
}

/**
 * Lineas de articulo libre vendidas en un rango (sin pedidos cancelados ni lineas rechazadas
 * en la entrega). $idAlmacen / $idUsuario acotan (encargado: su sucursal; vendedor: lo suyo).
 *
 * @return array<int, array<string, mixed>>
 */
function articuloLibreFetchLineas(PDO $pdo, string $inicio, string $fin, ?int $idAlmacen = null, ?int $idUsuario = null): array
{
    $idLibre = articuloLibreIdProducto($pdo);
    if ($idLibre === null) {
        return [];
    }
    $sql = "SELECT dp.id_detalle, dp.id_pedido, pe.numero_pedido, pe.fecha_creacion, pe.id_almacen,
                   dp.marca_libre, dp.descripcion_libre, dp.cantidad, dp.precio_unitario,
                   COALESCE(dp.costo_unitario, 0) AS costo_unitario, COALESCE(dp.monto_descuento, 0) AS monto_descuento,
                   dp.subtotal, u.nombre AS vendedor
            FROM detalle_pedidos dp
            JOIN pedidos pe ON pe.id_pedido = dp.id_pedido
            LEFT JOIN usuarios u ON u.id_usuario = pe.id_usuario
            WHERE dp.id_producto = :id_libre
              AND pe.estado <> 'cancelado'
              AND (dp.estado_entrega IS NULL OR dp.estado_entrega <> 'rechazado')
              AND pe.fecha_creacion >= :inicio AND pe.fecha_creacion < :fin";
    $params = [':id_libre' => $idLibre, ':inicio' => $inicio, ':fin' => $fin];
    if ($idAlmacen !== null) {
        $sql .= ' AND pe.id_almacen = :almacen';
        $params[':almacen'] = $idAlmacen;
    }
    if ($idUsuario !== null) {
        $sql .= ' AND pe.id_usuario = :usuario';
        $params[':usuario'] = $idUsuario;
    }
    $sql .= ' ORDER BY pe.fecha_creacion DESC, dp.id_detalle DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * Marcas ya usadas (para autocompletar en el POS y no terminar con "Nutrilite" y "nutrilite ").
 *
 * @return string[]
 */
function articuloLibreMarcasUsadas(PDO $pdo, int $limite = 50): array
{
    $idLibre = articuloLibreIdProducto($pdo);
    if ($idLibre === null) {
        return [];
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT marca_libre, MAX(id_detalle) AS ultimo
             FROM detalle_pedidos
             WHERE id_producto = ? AND marca_libre IS NOT NULL AND marca_libre <> ''
             GROUP BY marca_libre
             ORDER BY ultimo DESC
             LIMIT " . max(1, min(200, $limite))
        );
        $stmt->execute([$idLibre]);
        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []));
    } catch (Throwable $e) {
        return [];
    }
}
