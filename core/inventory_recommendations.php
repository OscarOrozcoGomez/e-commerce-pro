<?php
declare(strict_types=1);

/**
 * Recomendaciones de inventario para views/analytics.php: que reponer, que poner en el
 * aparador y que mover u ofertar.
 *
 * NO es un pronostico estadistico: con el volumen de ventas actual (pocas piezas por
 * producto) un modelo daria ruido. Son reglas simples y explicables sobre lo que ya se
 * guarda: velocidad de venta reciente, stock, stock minimo, margen, visitas al producto y
 * caducidad de lotes. Cada recomendacion trae su motivo en texto para que el equipo pueda
 * discrepar con conocimiento del negocio.
 *
 * analiticaClasificarProducto() es pura (sin BD) para poder probarla con datos fabricados;
 * analiticaMetricasProductos() reune los datos y solo usa SQL portable (fechas de corte
 * calculadas en PHP) para poder probarla tambien con SQLite.
 */

/** Ventana corta (visitas al sitio) y ventana de velocidad de venta, en dias. */
const INV_REC_VENTANA_CORTA_DIAS = 30;
const INV_REC_VENTANA_DIAS = 90;
/**
 * SUPUESTO: no se guarda cuanto tarda cada proveedor en entregar, asi que se asume este tiempo
 * mas un colchon. Es lo primero que conviene medir de verdad (ordenes de compra: enviada -> recibida).
 */
const INV_REC_DIAS_ENTREGA_PROVEEDOR = 14;
const INV_REC_DIAS_COLCHON = 7;
/** Con stock y sin ventas en estos dias, el producto esta estancado. */
const INV_REC_DIAS_SIN_VENTA_MOVER = 60;
/** Un lote que caduca en estos dias o menos se recomienda mover. */
const INV_REC_DIAS_CADUCIDAD_MOVER = 90;
/** Visitantes distintos en la ventana corta a partir de los cuales hay "interes" en un producto. */
const INV_REC_MIN_VISITANTES_INTERES = 3;
/** Margen (% sobre precio de venta) desde el que se considera alto. */
const INV_REC_MARGEN_ALTO_PCT = 40.0;
/**
 * Cuantos productos de "Mover" se muestran: los de mayor capital parado (los que caducan van
 * primero). La lista completa puede tener cientos y abruma; el resumen sigue contando todos.
 */
const INV_REC_LIMITE_MOVER = 15;
/** Cuantos productos sin precio/costo se listan en el aviso de configuracion. */
const INV_REC_LIMITE_SIN_CONFIGURACION = 20;

const INV_REC_ACCION_REPONER = 'reponer';
const INV_REC_ACCION_VENTAS_PERDIDAS = 'ventas_perdidas';
const INV_REC_ACCION_APARADOR = 'aparador';
const INV_REC_ACCION_MOVER = 'mover';
const INV_REC_ACCION_OK = 'ok';

/** Fragmentos de nombre que identifican productos creados por las pruebas automatizadas (E2E). */
const INV_REC_PATRONES_PRUEBA = ['Playwright', 'E2E'];

/**
 * True si el nombre pertenece a un producto de pruebas automatizadas. Esos productos
 * contaminan el top de ventas y las visitas, asi que la analitica los excluye.
 */
function analiticaEsProductoDePrueba(string $nombre): bool
{
    foreach (INV_REC_PATRONES_PRUEBA as $patron) {
        if (stripos($nombre, $patron) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Condicion SQL (AND-able) que deja fuera los productos de prueba. $alias es el alias de la tabla productos.
 */
function analiticaSqlProductoNoPrueba(string $alias = 'p'): string
{
    $alias = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'p';
    $condiciones = [];
    foreach (INV_REC_PATRONES_PRUEBA as $patron) {
        $condiciones[] = "{$alias}.nombre NOT LIKE '%{$patron}%'";
    }
    return '(' . implode(' AND ', $condiciones) . ')';
}

/**
 * Clasifica un producto. Funcion pura.
 *
 * Entradas ($m): stock (piezas totales), stock_minimo (suma de los minimos configurados),
 * v30, v90 (piezas vendidas en 30 / 90 dias), total_vendido, dias_sin_venta (?int; null =
 * nunca), visitantes30 (visitantes distintos del sitio en 30 dias), dias_a_caducar (?int,
 * lote activo mas proximo con piezas), precio_venta, precio_costo, dias_en_catalogo (?int).
 *
 * Orden de las reglas (la primera que aplica gana): reponer, ventas perdidas, mover por
 * caducidad, aparador, mover por estancado, sin accion.
 *
 * @param array<string, mixed> $m
 * @return array{accion:string, etiqueta:string, motivo:string, prioridad:float, cobertura_dias:?int, margen_pct:?float, capital:float, falta_config:?string}
 */
function analiticaClasificarProducto(array $m): array
{
    $stock = max(0, (int) ($m['stock'] ?? 0));
    $stockMinimo = max(0, (int) ($m['stock_minimo'] ?? 0));
    $v30 = max(0, (int) ($m['v30'] ?? 0));
    $v90 = max(0, (int) ($m['v90'] ?? 0));
    $totalVendido = max(0, (int) ($m['total_vendido'] ?? 0));
    $diasSinVenta = isset($m['dias_sin_venta']) ? (int) $m['dias_sin_venta'] : null;
    $visitantes = max(0, (int) ($m['visitantes30'] ?? 0));
    $diasACaducar = isset($m['dias_a_caducar']) ? (int) $m['dias_a_caducar'] : null;
    $precioVenta = (float) ($m['precio_venta'] ?? 0);
    $precioCosto = (float) ($m['precio_costo'] ?? 0);
    $diasEnCatalogo = isset($m['dias_en_catalogo']) ? (int) $m['dias_en_catalogo'] : null;

    $velocidad = $v90 / INV_REC_VENTANA_DIAS; // piezas por dia
    $cobertura = $velocidad > 0 ? (int) floor($stock / $velocidad) : null;
    $margen = ($precioVenta > 0 && $precioCosto > 0) ? round(($precioVenta - $precioCosto) / $precioVenta * 100, 1) : null;
    $capital = round($stock * max(0.0, $precioCosto), 2);
    $objetivoDias = INV_REC_DIAS_ENTREGA_PROVEEDOR + INV_REC_DIAS_COLCHON;
    $hayInteres = $visitantes >= INV_REC_MIN_VISITANTES_INTERES;
    $margenAlto = $margen !== null && $margen >= INV_REC_MARGEN_ALTO_PCT;

    // Sin precio de venta o sin costo no se puede evaluar bien (margen, capital parado, aparador):
    // se avisa en cada fila y en el listado de "sin configuracion".
    $faltaPrecio = $precioVenta <= 0;
    $faltaCosto = $precioCosto <= 0;
    $faltaConfig = $faltaPrecio && $faltaCosto ? 'precio y costo' : ($faltaPrecio ? 'precio de venta' : ($faltaCosto ? 'costo' : null));

    $resultado = static function (string $accion, string $etiqueta, string $motivo, float $prioridad) use ($cobertura, $margen, $capital, $faltaConfig): array {
        return [
            'accion' => $accion,
            'etiqueta' => $etiqueta,
            'motivo' => $motivo,
            'prioridad' => $prioridad,
            'cobertura_dias' => $cobertura,
            'margen_pct' => $margen,
            'capital' => $capital,
            'falta_config' => $faltaConfig,
        ];
    };

    // 1) Reponer: se vende y se esta acabando (o ya se acabo).
    if ($stock === 0 && $v90 > 0) {
        return $resultado(
            INV_REC_ACCION_REPONER,
            'Agotado',
            'Agotado; se vendieron ' . $v90 . ' pza en los ultimos ' . INV_REC_VENTANA_DIAS . ' dias.',
            1000 + $v90
        );
    }
    if ($stock > 0 && $cobertura !== null && $cobertura < $objetivoDias) {
        return $resultado(
            INV_REC_ACCION_REPONER,
            'Se acaba pronto',
            'Alcanza para ~' . $cobertura . ' dias al ritmo de los ultimos ' . INV_REC_VENTANA_DIAS
                . ' dias; el proveedor tarda ~' . INV_REC_DIAS_ENTREGA_PROVEEDOR . ' + ' . INV_REC_DIAS_COLCHON . ' de colchon.',
            500 + ($objetivoDias - $cobertura) + $v90
        );
    }
    if ($stock > 0 && $stockMinimo > 0 && $stock <= $stockMinimo && $totalVendido > 0) {
        return $resultado(
            INV_REC_ACCION_REPONER,
            'En su minimo',
            'Tiene ' . $stock . ' pza, en o por debajo de su stock minimo (' . $stockMinimo . ') y ya se ha vendido.',
            400 + $totalVendido
        );
    }

    // 2) Ventas perdidas: sin stock, sin ventas recientes, pero lo visitan.
    if ($stock === 0 && $hayInteres) {
        return $resultado(
            INV_REC_ACCION_VENTAS_PERDIDAS,
            'Lo piden y no hay',
            'Sin stock y ' . $visitantes . ' visitantes lo vieron en los ultimos ' . INV_REC_VENTANA_CORTA_DIAS . ' dias.',
            300 + $visitantes
        );
    }

    // 3) Mover: un lote esta por caducar (Caducidades lleva el detalle por lote).
    if ($stock > 0 && $diasACaducar !== null && $diasACaducar <= INV_REC_DIAS_CADUCIDAD_MOVER) {
        return $resultado(
            INV_REC_ACCION_MOVER,
            'Por caducar',
            $diasACaducar < 0
                ? 'Tiene un lote ya caducado con piezas en inventario.'
                : 'Un lote caduca en ' . $diasACaducar . ' dias y aun tiene piezas.',
            // Muy por encima del capital de cualquier estancado: lo que caduca se pierde, va primero.
            100000 + (INV_REC_DIAS_CADUCIDAD_MOVER - $diasACaducar)
        );
    }

    // 4) Aparador: se mueve, se ve o deja buen margen, y hay que vender.
    if ($stock > 0 && $precioVenta > 0) {
        $seVende = $v90 >= 2 || ($v90 >= 1 && ($hayInteres || $margenAlto));
        $seVeYDejaMargen = $hayInteres && $margenAlto;
        if ($seVende || $seVeYDejaMargen) {
            $razones = [];
            if ($v90 > 0) {
                $razones[] = 'se vendieron ' . $v90 . ' pza en ' . INV_REC_VENTANA_DIAS . ' dias';
            }
            if ($hayInteres) {
                $razones[] = $visitantes . ' visitantes en ' . INV_REC_VENTANA_CORTA_DIAS . ' dias';
            }
            if ($margenAlto) {
                $razones[] = 'margen ' . $margen . '%';
            }
            return $resultado(
                INV_REC_ACCION_APARADOR,
                $v90 > 0 ? 'Se vende' : 'Lo ven y deja margen',
                ucfirst(implode('; ', $razones)) . '.',
                $v90 * 10 + $visitantes + ($margenAlto ? 5 : 0)
            );
        }
    }

    // 5) Mover: tiene stock y no se vende.
    if ($stock > 0) {
        $estancadoPorVenta = $diasSinVenta !== null && $diasSinVenta >= INV_REC_DIAS_SIN_VENTA_MOVER;
        $nuncaVendidoYaViejo = $diasSinVenta === null && $totalVendido === 0
            && ($diasEnCatalogo === null || $diasEnCatalogo >= INV_REC_DIAS_SIN_VENTA_MOVER);
        if ($estancadoPorVenta || $nuncaVendidoYaViejo) {
            $sinNadaDeInteres = $totalVendido === 0 && !$hayInteres && $visitantes === 0;
            $motivo = $nuncaVendidoYaViejo
                ? 'Nunca se ha vendido' . ($diasEnCatalogo !== null ? ' en sus ' . $diasEnCatalogo . ' dias en catalogo' : '')
                : 'Sin ventas en ' . $diasSinVenta . ' dias';
            $motivo .= $sinNadaDeInteres ? ' y sin visitas registradas: no lo recompres.' : '; pruebalo en oferta o con mejor exhibicion.';
            if ($capital > 0) {
                $motivo .= ' Capital parado: $' . number_format($capital, 2) . '.';
            }
            return $resultado(
                INV_REC_ACCION_MOVER,
                $sinNadaDeInteres ? 'No recomprar' : 'Estancado',
                $motivo,
                $capital
            );
        }
    }

    return $resultado(INV_REC_ACCION_OK, 'Sin accion', '', 0.0);
}

/**
 * Reune las metricas de cada producto activo (sin los de prueba) para clasificarlas.
 *
 * Cuenta como venta toda pieza de un pedido no cancelado cuyo producto no fue rechazado en
 * la entrega. Es el mismo criterio del resto de la analitica (estado != cancelado).
 *
 * @return array<int, array<string, mixed>> Una fila por producto, lista para analiticaClasificarProducto().
 */
function analiticaMetricasProductos(PDO $pdo, ?DateTimeImmutable $ahora = null): array
{
    $ahora = $ahora ?? new DateTimeImmutable('now');
    $corte30 = $ahora->modify('-' . INV_REC_VENTANA_CORTA_DIAS . ' days')->format('Y-m-d H:i:s');
    $corte90 = $ahora->modify('-' . INV_REC_VENTANA_DIAS . ' days')->format('Y-m-d H:i:s');
    $noPrueba = analiticaSqlProductoNoPrueba('p');

    $productos = $pdo->query(
        "SELECT p.id_producto, p.nombre, p.precio_venta, p.precio_costo, p.fecha_creacion
         FROM productos p
         WHERE p.estado = 'activo' AND {$noPrueba}"
    )->fetchAll(PDO::FETCH_ASSOC);

    $stocks = [];
    foreach ($pdo->query(
        'SELECT id_producto, SUM(cantidad_actual) AS stock, SUM(stock_minimo) AS stock_minimo
         FROM inventario_almacen GROUP BY id_producto'
    )->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $stocks[(int) $fila['id_producto']] = $fila;
    }

    $ventas = [];
    $stmtVentas = $pdo->prepare(
        "SELECT dp.id_producto,
                SUM(CASE WHEN pe.fecha_creacion >= :c30 THEN dp.cantidad ELSE 0 END) AS v30,
                SUM(CASE WHEN pe.fecha_creacion >= :c90 THEN dp.cantidad ELSE 0 END) AS v90,
                SUM(dp.cantidad) AS total_vendido,
                MAX(pe.fecha_creacion) AS ultima_venta
         FROM detalle_pedidos dp
         JOIN pedidos pe ON pe.id_pedido = dp.id_pedido
         WHERE pe.estado <> 'cancelado'
           AND (dp.estado_entrega IS NULL OR dp.estado_entrega <> 'rechazado')
         GROUP BY dp.id_producto"
    );
    $stmtVentas->execute([':c30' => $corte30, ':c90' => $corte90]);
    foreach ($stmtVentas->fetchAll(PDO::FETCH_ASSOC) as $fila) {
        $ventas[(int) $fila['id_producto']] = $fila;
    }

    // Visitas del sitio (solo trafico externo). Tolera entornos donde la tabla/columna aun no existe.
    $visitas = [];
    try {
        $stmtVisitas = $pdo->prepare(
            'SELECT id_producto, COUNT(DISTINCT visitor_id) AS visitantes
             FROM logs_actividad
             WHERE es_interno = 0 AND id_producto IS NOT NULL AND fecha_creacion >= :c30
             GROUP BY id_producto'
        );
        $stmtVisitas->execute([':c30' => $corte30]);
        foreach ($stmtVisitas->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $visitas[(int) $fila['id_producto']] = (int) $fila['visitantes'];
        }
    } catch (Throwable $e) {
        $visitas = [];
    }

    // Lote activo con piezas que caduca primero.
    $caducidades = [];
    try {
        foreach ($pdo->query(
            "SELECT id_producto, MIN(fecha_caducidad) AS proxima
             FROM lotes_inventario
             WHERE estado = 'activo' AND cantidad_restante > 0 AND fecha_caducidad IS NOT NULL
             GROUP BY id_producto"
        )->fetchAll(PDO::FETCH_ASSOC) as $fila) {
            $caducidades[(int) $fila['id_producto']] = (string) $fila['proxima'];
        }
    } catch (Throwable $e) {
        $caducidades = [];
    }

    $hoy = $ahora->setTime(0, 0, 0);
    $diasDesde = static function (?string $fecha) use ($ahora): ?int {
        if ($fecha === null || trim($fecha) === '') {
            return null;
        }
        $ts = strtotime($fecha);
        return $ts === false ? null : (int) floor(($ahora->getTimestamp() - $ts) / 86400);
    };

    $filas = [];
    foreach ($productos as $p) {
        $id = (int) $p['id_producto'];
        $v = $ventas[$id] ?? null;
        $s = $stocks[$id] ?? null;
        $diasACaducar = null;
        if (isset($caducidades[$id])) {
            $tsCaducidad = strtotime($caducidades[$id]);
            $diasACaducar = $tsCaducidad === false ? null : (int) floor(($tsCaducidad - $hoy->getTimestamp()) / 86400);
        }

        $filas[] = [
            'id_producto' => $id,
            'nombre' => (string) $p['nombre'],
            'precio_venta' => (float) $p['precio_venta'],
            'precio_costo' => (float) $p['precio_costo'],
            'stock' => $s !== null ? (int) $s['stock'] : 0,
            'stock_minimo' => $s !== null ? (int) $s['stock_minimo'] : 0,
            'v30' => $v !== null ? (int) $v['v30'] : 0,
            'v90' => $v !== null ? (int) $v['v90'] : 0,
            'total_vendido' => $v !== null ? (int) $v['total_vendido'] : 0,
            'dias_sin_venta' => $v !== null ? $diasDesde((string) $v['ultima_venta']) : null,
            'visitantes30' => $visitas[$id] ?? 0,
            'dias_a_caducar' => $diasACaducar,
            'dias_en_catalogo' => $diasDesde((string) ($p['fecha_creacion'] ?? '')),
        ];
    }

    return $filas;
}

/**
 * Arma las tres listas de accion (comprar, aparador, mover) mas un resumen y el contexto de
 * cuanta evidencia hay detras, para que la vista sea honesta sobre lo poco o mucho que se sabe.
 *
 * "mover" muestra solo los INV_REC_LIMITE_MOVER de mayor prioridad (primero lo que caduca, luego
 * por capital parado); el resumen cuenta todos.
 *
 * @return array{resumen: array<string, int|float>, comprar: array<int, array<string, mixed>>, aparador: array<int, array<string, mixed>>, mover: array<int, array<string, mixed>>, sin_configuracion: array<int, array<string, mixed>>, contexto: array<string, mixed>}
 */
function analiticaRecomendaciones(PDO $pdo, int $limitePorLista = 40, ?DateTimeImmutable $ahora = null): array
{
    $ahora = $ahora ?? new DateTimeImmutable('now');
    $limitePorLista = max(1, $limitePorLista);

    $comprar = [];
    $aparador = [];
    $mover = [];
    $sinConfiguracion = [];
    $sinAccion = 0;
    $capitalParado = 0.0;

    foreach (analiticaMetricasProductos($pdo, $ahora) as $fila) {
        $clas = analiticaClasificarProducto($fila);
        $item = array_merge($fila, $clas);

        // Productos activos sin precio de venta o sin costo (aunque ademas tengan otra accion).
        if ($clas['falta_config'] !== null) {
            $sinConfiguracion[] = [
                'id_producto' => $fila['id_producto'],
                'nombre' => $fila['nombre'],
                'falta' => $clas['falta_config'],
                'stock' => $fila['stock'],
                'total_vendido' => $fila['total_vendido'],
            ];
        }

        switch ($clas['accion']) {
            case INV_REC_ACCION_REPONER:
            case INV_REC_ACCION_VENTAS_PERDIDAS:
                $comprar[] = $item;
                break;
            case INV_REC_ACCION_APARADOR:
                $aparador[] = $item;
                break;
            case INV_REC_ACCION_MOVER:
                $mover[] = $item;
                $capitalParado += $clas['capital'];
                break;
            default:
                $sinAccion++;
        }
    }

    $ordenar = static function (array $lista): array {
        usort($lista, static fn(array $a, array $b): int => $b['prioridad'] <=> $a['prioridad']);
        return $lista;
    };

    $totales = ['comprar' => count($comprar), 'aparador' => count($aparador), 'mover' => count($mover)];

    // Primero los que ya se han vendido (les urge un precio/costo bien), luego los que tienen mas stock.
    usort($sinConfiguracion, static fn(array $a, array $b): int => [$b['total_vendido'] > 0, $b['stock']] <=> [$a['total_vendido'] > 0, $a['stock']]);

    // Contexto: cuanta evidencia real hay (sin pedidos de prueba) para no sobrevender la precision.
    $noPrueba = analiticaSqlProductoNoPrueba('pr');
    $evidencia = $pdo->query(
        "SELECT COUNT(DISTINCT pe.id_pedido) AS pedidos, COALESCE(SUM(dp.cantidad), 0) AS piezas,
                COUNT(DISTINCT dp.id_producto) AS productos, MIN(pe.fecha_creacion) AS desde
         FROM detalle_pedidos dp
         JOIN pedidos pe ON pe.id_pedido = dp.id_pedido
         JOIN productos pr ON pr.id_producto = dp.id_producto
         WHERE pe.estado <> 'cancelado'
           AND (dp.estado_entrega IS NULL OR dp.estado_entrega <> 'rechazado')
           AND {$noPrueba}"
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $desde = (string) ($evidencia['desde'] ?? '');
    $tsDesde = $desde !== '' ? strtotime($desde) : false;

    return [
        'resumen' => [
            'comprar' => $totales['comprar'],
            'aparador' => $totales['aparador'],
            'mover' => $totales['mover'],
            'sin_accion' => $sinAccion,
            'sin_configuracion' => count($sinConfiguracion),
            'capital_parado' => round($capitalParado, 2),
        ],
        'comprar' => array_slice($ordenar($comprar), 0, $limitePorLista),
        'aparador' => array_slice($ordenar($aparador), 0, $limitePorLista),
        'mover' => array_slice($ordenar($mover), 0, min($limitePorLista, INV_REC_LIMITE_MOVER)),
        'sin_configuracion' => array_slice($sinConfiguracion, 0, INV_REC_LIMITE_SIN_CONFIGURACION),
        'contexto' => [
            'pedidos_reales' => (int) ($evidencia['pedidos'] ?? 0),
            'piezas_vendidas' => (int) ($evidencia['piezas'] ?? 0),
            'productos_vendidos' => (int) ($evidencia['productos'] ?? 0),
            'dias_historial' => $tsDesde === false ? 0 : max(1, (int) floor(($ahora->getTimestamp() - $tsDesde) / 86400) + 1),
            'supuestos' => [
                'ventana_dias' => INV_REC_VENTANA_DIAS,
                'dias_entrega_proveedor' => INV_REC_DIAS_ENTREGA_PROVEEDOR,
                'dias_colchon' => INV_REC_DIAS_COLCHON,
                'dias_sin_venta_mover' => INV_REC_DIAS_SIN_VENTA_MOVER,
                'dias_caducidad_mover' => INV_REC_DIAS_CADUCIDAD_MOVER,
                'margen_alto_pct' => INV_REC_MARGEN_ALTO_PCT,
                'min_visitantes_interes' => INV_REC_MIN_VISITANTES_INTERES,
                'limite_mover' => INV_REC_LIMITE_MOVER,
            ],
        ],
    ];
}
