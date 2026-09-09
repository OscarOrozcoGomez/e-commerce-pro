<?php
declare(strict_types=1);

/**
 * Control de caducidades por lote.
 *
 * Proyecta, para cada lote de inventario, si alcanzara a venderse antes de su
 * fecha de caducidad comparando la cantidad restante contra la velocidad de
 * venta del producto en los ultimos LOTE_VENTANA_DIAS dias (patron tomado de
 * api/analytics_data.php "Prediccion de Abastecimiento"), aplicando consumo
 * FEFO (first-expired-first-out) entre los lotes de un mismo producto.
 *
 * Todas las funciones reciben PDO como primer argumento y su SQL es compatible
 * con SQLite para poder probarse en tests/Unit/LoteCaducidadUtilsTest.php.
 */

if (!defined('LOTE_VENTANA_DIAS')) {
    define('LOTE_VENTANA_DIAS', 90);      // ventana movil de velocidad de venta
}
if (!defined('LOTE_DIAS_CRITICO')) {
    define('LOTE_DIAS_CRITICO', 30);      // < 30 dias y con excedente -> critico
}
if (!defined('LOTE_DIAS_URGENTE')) {
    define('LOTE_DIAS_URGENTE', 90);      // 30-90 -> urgente
}
if (!defined('LOTE_DIAS_PLANIFICAR')) {
    define('LOTE_DIAS_PLANIFICAR', 180);  // 90-180 -> planificar ; >=180 -> vigilar
}
/*
 * Piso de severidad por RUNWAY real (dias_efectivos_venta = caducidad - dias de
 * tratamiento que rinde el envase). Manda la cercania a quedarse sin margen para
 * vender, aunque el modelo de velocidad diga que el lote se coloca a tiempo:
 *   < 45      -> critico
 *   45 - 119  -> urgente
 *   120 - 269 -> planificar
 *   270 - 449 -> vigilar
 *   >= 450    -> ok
 */
if (!defined('LOTE_RUNWAY_CRITICO'))    { define('LOTE_RUNWAY_CRITICO', 45); }
if (!defined('LOTE_RUNWAY_URGENTE'))    { define('LOTE_RUNWAY_URGENTE', 120); }
if (!defined('LOTE_RUNWAY_PLANIFICAR')) { define('LOTE_RUNWAY_PLANIFICAR', 270); }
if (!defined('LOTE_RUNWAY_VIGILAR'))    { define('LOTE_RUNWAY_VIGILAR', 450); }

require_once __DIR__ . '/oferta_pricing.php';

/**
 * Estados de lote que se consideran "vivos" en la vista de caducidades.
 */
const LOTE_ESTADOS_VISIBLES = ['activo', 'caducado'];

/**
 * Detecta si una columna existe (MySQL via information_schema, SQLite via PRAGMA).
 */
function loteColumnaExiste(PDO $pdo, string $tabla, string $columna): bool
{
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        // PRAGMA no acepta placeholders; sanitizamos el nombre de tabla.
        $stmt = $pdo->query("PRAGMA table_info('" . str_replace("'", '', $tabla) . "')");
        if ($stmt === false) {
            return false;
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $col) {
            if (strcasecmp((string) ($col['name'] ?? ''), $columna) === 0) {
                return true;
            }
        }
        return false;
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c'
    );
    $stmt->execute([':t' => $tabla, ':c' => $columna]);

    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Detecta si una tabla existe.
 */
function loteTablaExiste(PDO $pdo, string $tabla): bool
{
    $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name = :t");
        $stmt->execute([':t' => $tabla]);
        return (int) $stmt->fetchColumn() > 0;
    }

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
    );
    $stmt->execute([':t' => $tabla]);

    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Dias enteros entre dos fechas (solo parte de fecha). Positivo = $hasta futuro.
 */
function loteDiasEntre(string $desde, string $hasta): int
{
    $d1 = new DateTimeImmutable(substr($desde, 0, 10));
    $d2 = new DateTimeImmutable(substr($hasta, 0, 10));

    return (int) $d1->diff($d2)->format('%r%a');
}

/**
 * Velocidad de venta por producto en la ventana movil.
 *
 * @param  int[] $idsProducto  filtra a estos productos (vacio = todos)
 * @return array<int, array{
 *     unidades_ventana:int, vel_diaria:float, dias_efectivos:int,
 *     sin_historico:bool, sin_rotacion:bool
 * }>  indexado por id_producto; solo incluye productos con al menos una venta historica
 */
function loteVelocidadVentas(PDO $pdo, array $idsProducto = [], int $ventanaDias = LOTE_VENTANA_DIAS): array
{
    $ventanaDias = max(1, $ventanaDias);
    $cutoff = (new DateTimeImmutable("-{$ventanaDias} days"))->format('Y-m-d 00:00:00');
    $hoy = (new DateTimeImmutable('today'))->format('Y-m-d');

    $baseWhere = ["pe.estado <> 'cancelado'"];
    if (loteColumnaExiste($pdo, 'pedidos', 'afecta_inventario')) {
        $baseWhere[] = 'pe.afecta_inventario = 1';
    }
    if (loteColumnaExiste($pdo, 'detalle_pedidos', 'estado_entrega')) {
        $baseWhere[] = "dp.estado_entrega = 'entregado'";
    }

    $params = [];
    $idsFilter = '';
    $ids = array_values(array_unique(array_map('intval', $idsProducto)));
    if ($ids !== []) {
        $ph = [];
        foreach ($ids as $i => $id) {
            $key = ":p{$i}";
            $ph[] = $key;
            $params[$key] = $id;
        }
        $idsFilter = ' AND dp.id_producto IN (' . implode(',', $ph) . ')';
    }

    // Primera venta historica (define si hay historico y cuantos dias abarca).
    $sqlHist = 'SELECT dp.id_producto AS id_producto, MIN(pe.fecha_creacion) AS primera_venta
                FROM detalle_pedidos dp
                JOIN pedidos pe ON pe.id_pedido = dp.id_pedido
                WHERE ' . implode(' AND ', $baseWhere) . $idsFilter . '
                GROUP BY dp.id_producto';
    $stmtHist = $pdo->prepare($sqlHist);
    $stmtHist->execute($params);
    $historico = [];
    foreach ($stmtHist->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $historico[(int) $row['id_producto']] = (string) $row['primera_venta'];
    }

    if ($historico === []) {
        return [];
    }

    // Unidades vendidas dentro de la ventana.
    $sqlVent = 'SELECT dp.id_producto AS id_producto, SUM(dp.cantidad) AS unidades
                FROM detalle_pedidos dp
                JOIN pedidos pe ON pe.id_pedido = dp.id_pedido
                WHERE ' . implode(' AND ', $baseWhere) . $idsFilter . '
                  AND pe.fecha_creacion >= :cutoff
                GROUP BY dp.id_producto';
    $stmtVent = $pdo->prepare($sqlVent);
    $stmtVent->execute($params + [':cutoff' => $cutoff]);
    $ventana = [];
    foreach ($stmtVent->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $ventana[(int) $row['id_producto']] = (int) $row['unidades'];
    }

    $out = [];
    foreach ($historico as $idProducto => $primeraVenta) {
        $diasHistoricos = max(1, loteDiasEntre($primeraVenta, $hoy));
        $diasEfectivos = min($ventanaDias, $diasHistoricos);
        $unidades = $ventana[$idProducto] ?? 0;
        $vel = $unidades > 0 ? $unidades / $diasEfectivos : 0.0;

        $out[$idProducto] = [
            'unidades_ventana' => $unidades,
            'vel_diaria' => $vel,
            'dias_efectivos' => $diasEfectivos,
            'sin_historico' => false,
            'sin_rotacion' => $unidades <= 0,
        ];
    }

    return $out;
}

/**
 * Clasifica la severidad de un lote segun el tiempo de anticipacion.
 */
function loteSeveridad(int $diasHastaCaducar, ?int $excedente, int $cantidadRestante, bool $sinRotacion, bool $sinHistorico): string
{
    if ($diasHastaCaducar < 0) {
        return 'caducado';
    }
    if ($sinHistorico) {
        return 'sin_historico';
    }
    if ($sinRotacion) {
        return 'sin_rotacion';
    }
    if ($excedente === null || $excedente <= 0) {
        return 'ok';
    }
    if ($diasHastaCaducar < LOTE_DIAS_CRITICO) {
        return 'critico';
    }
    if ($diasHastaCaducar < LOTE_DIAS_URGENTE) {
        return 'urgente';
    }
    if ($diasHastaCaducar < LOTE_DIAS_PLANIFICAR) {
        return 'planificar';
    }

    return 'vigilar';
}

/**
 * Severidad SOLO por el runway real (dias efectivos para colocar el lote), sin
 * mirar velocidad ni excedente. Es el "piso": un lote con poco margen se marca
 * fuerte aunque el modelo diga que se vende.
 */
function loteSeveridadPorRunway(int $diasEfectivos): string
{
    if ($diasEfectivos < LOTE_RUNWAY_CRITICO) {
        return 'critico';
    }
    if ($diasEfectivos < LOTE_RUNWAY_URGENTE) {
        return 'urgente';
    }
    if ($diasEfectivos < LOTE_RUNWAY_PLANIFICAR) {
        return 'planificar';
    }
    if ($diasEfectivos < LOTE_RUNWAY_VIGILAR) {
        return 'vigilar';
    }

    return 'ok';
}

/**
 * Devuelve la peor de dos severidades segun este orden de gravedad:
 * ok < vigilar < sin_historico < planificar < sin_rotacion < urgente < critico < caducado.
 */
function loteSeveridadPeor(string $a, string $b): string
{
    static $rank = [
        'ok' => 0, 'vigilar' => 1, 'sin_historico' => 2, 'planificar' => 3,
        'sin_rotacion' => 4, 'urgente' => 5, 'critico' => 6, 'caducado' => 7,
    ];
    $ra = $rank[$a] ?? 0;
    $rb = $rank[$b] ?? 0;
    return $ra >= $rb ? $a : $b;
}

/**
 * Dias de tratamiento que rinde un envase = capsulas por envase / capsulas por
 * porcion (toma). Ej: 90 capsulas / 1 por dia = 90 dias. null si falta el dato.
 */
function loteDiasTratamiento(?int $capsulasPorEnvase, ?int $porcionCapsulas): ?int
{
    if ($capsulasPorEnvase === null || $capsulasPorEnvase <= 0) {
        return null;
    }
    $porcion = ($porcionCapsulas !== null && $porcionCapsulas > 0) ? $porcionCapsulas : 1;

    return (int) floor($capsulasPorEnvase / $porcion);
}

/**
 * Descuento sugerido (heuristica, solo informativo). Multiplos de 5, tope 50%.
 */
function loteDescuentoSugerido(int $excedente, int $cantidadRestante, int $diasHastaCaducar): int
{
    if ($excedente <= 0 || $cantidadRestante <= 0) {
        return 0;
    }

    $faltante = min(1.0, $excedente / $cantidadRestante);
    $urgencia = 1.0 - max(0.0, min(1.0, $diasHastaCaducar / 180));
    $pct = (0.15 + 0.35 * $faltante) * (0.5 + 0.5 * $urgencia) * 100;
    $pct = (int) (round($pct / 5) * 5);

    return max(5, min(50, $pct));
}

/**
 * Proyeccion FEFO para los lotes de UN producto. Funcion pura (sin DB).
 *
 * @param array<int, array<string,mixed>> $lotes  filas de lotes_inventario del mismo producto
 * @param array{vel_diaria:float, sin_historico:bool, sin_rotacion:bool} $vel
 * @return array<int, array<string,mixed>>  una fila por lote, en orden FEFO
 */
function loteComputeProyeccionProducto(array $lotes, array $vel, string $hoy): array
{
    usort($lotes, static function (array $a, array $b): int {
        $cmp = strcmp((string) $a['fecha_caducidad'], (string) $b['fecha_caducidad']);
        if ($cmp !== 0) {
            return $cmp;
        }
        $cmp = strcmp((string) ($a['fecha_ingreso'] ?? ''), (string) ($b['fecha_ingreso'] ?? ''));
        if ($cmp !== 0) {
            return $cmp;
        }
        return (int) ($a['id_lote'] ?? 0) <=> (int) ($b['id_lote'] ?? 0);
    });

    $velDiaria = (float) ($vel['vel_diaria'] ?? 0.0);
    $sinHistorico = (bool) ($vel['sin_historico'] ?? false);
    $sinRotacion = (bool) ($vel['sin_rotacion'] ?? false);

    $posicion = 0.0; // unidades del mismo producto que se venden antes que este lote
    $filas = [];

    foreach ($lotes as $lote) {
        $cantRestante = max(0, (int) $lote['cantidad_restante']);
        $diasHastaCaducar = loteDiasEntre($hoy, (string) $lote['fecha_caducidad']);

        // Margen de consumo: cuantos dias de "colchon" le quedan a un cliente que
        // compra el envase HOY. Si el envase rinde mas dias de los que faltan para
        // caducar, nadie alcanza a terminarselo -> no vendible a precio normal.
        $diasTratamiento = loteDiasTratamiento(
            isset($lote['capsulas_por_envase']) && $lote['capsulas_por_envase'] !== null ? (int) $lote['capsulas_por_envase'] : null,
            isset($lote['porcion_capsulas']) && $lote['porcion_capsulas'] !== null ? (int) $lote['porcion_capsulas'] : null
        );
        $margenConsumo = $diasTratamiento !== null ? $diasHastaCaducar - $diasTratamiento : null;
        $noVendible = $margenConsumo !== null && $cantRestante > 0 && $diasHastaCaducar >= 0 && $margenConsumo < 0;

        // Horizonte REAL para colocar cada unidad: si el envase rinde N dias, la
        // ultima fecha en que un cliente puede comprarlo y terminarselo antes de que
        // caduque es (caducidad - N). Vender despues de esa fecha = el cliente
        // consume producto vencido. Por eso la proyeccion y la severidad se miden
        // contra este horizonte, no contra la fecha de caducidad "cruda": asi la
        // alerta salta con la anticipacion suficiente para ponerlo en oferta.
        // Sin datos de capsulas/porcion, el horizonte es la propia fecha de caducidad.
        $diasEfectivos = $margenConsumo !== null ? max(0, $margenConsumo) : $diasHastaCaducar;

        $excedente = null;
        $diasParaAgotar = null;
        $velObjetivo = null;
        // Cuanto de la demanda futura (desde hoy) deja realmente "ocupada" este lote
        // para los que le siguen en la fila FEFO. Un lote que ya caduco, o del que no
        // conocemos velocidad, no le quita demanda a nadie mas -- lo unico que le quita
        // demanda al siguiente lote es lo que EN REALIDAD se alcanzo a vender de este.
        // (Bug corregido: antes se sumaba $cantRestante completo aqui, tratando la merma
        // de un lote ya vencido como si fuera venta ya cubierta, lo que inflaba el
        // excedente proyectado de los lotes siguientes que en la realidad si alcanzan a
        // venderse una vez que este ya no esta en el anaquel.)
        $consumidoPorEsteLote = 0.0;

        if ($diasHastaCaducar < 0) {
            // Ya caduco: todo lo que queda es merma. No compite por demanda futura.
            $excedente = $cantRestante;
        } elseif ($sinHistorico) {
            $excedente = null; // no se puede proyectar
        } elseif ($sinRotacion || $velDiaria <= 0) {
            $excedente = $cantRestante;
            $velObjetivo = $cantRestante / max(1, $diasEfectivos);
        } else {
            $demandaAntes = max(0.0, $velDiaria * $diasEfectivos);
            $vendidasATiempo = max(0.0, min((float) $cantRestante, $demandaAntes - $posicion));
            $excedente = (int) ceil($cantRestante - $vendidasATiempo);
            $excedente = max(0, $excedente);
            $diasParaAgotar = ($posicion + $cantRestante) / $velDiaria;
            $velObjetivo = ($posicion + $cantRestante) / max(1, $diasEfectivos);
            $consumidoPorEsteLote = $vendidasATiempo;
        }

        // La severidad se mide contra el horizonte efectivo (ver arriba), salvo
        // 'caducado', que depende de la fecha real ya cumplida.
        $severidad = $diasHastaCaducar < 0
            ? 'caducado'
            : loteSeveridad($diasEfectivos, $excedente, $cantRestante, $sinRotacion, $sinHistorico);

        // Piso por RUNWAY: la cercania a quedarse sin margen real para vender manda,
        // aunque el modelo diga que el lote se coloca a tiempo. Se toma la PEOR entre
        // lo que dio el modelo y lo que dicta el runway. Nunca baja una severidad.
        if ($diasHastaCaducar >= 0 && $cantRestante > 0 && $severidad !== 'caducado') {
            $severidad = loteSeveridadPeor($severidad, loteSeveridadPorRunway($diasEfectivos));
        }

        $descuento = ($excedente !== null && $excedente > 0)
            ? loteDescuentoSugerido($excedente, $cantRestante, $diasEfectivos)
            : 0;

        if ($noVendible) {
            // Un envase comprado hoy no se alcanza a terminar antes de caducar.
            $excedente = $cantRestante;
            $severidad = $severidad === 'caducado' ? 'caducado' : 'critico';
            $descuento = max($descuento, 30);
        }

        $filas[] = array_merge($lote, [
            'dias_hasta_caducar' => $diasHastaCaducar,
            'vel_diaria' => round($velDiaria, 3),
            'excedente_proyectado' => $excedente,
            'dias_para_agotar_lote' => $diasParaAgotar !== null ? (int) ceil($diasParaAgotar) : null,
            'vel_objetivo' => $velObjetivo !== null ? round($velObjetivo, 3) : null,
            'ritmo_ratio' => ($velObjetivo !== null && $velObjetivo > 0) ? round($velDiaria / $velObjetivo, 2) : null,
            'dias_tratamiento_envase' => $diasTratamiento,
            'margen_consumo_dias' => $margenConsumo,
            'dias_efectivos_venta' => $diasHastaCaducar < 0 ? $diasHastaCaducar : $diasEfectivos,
            'no_vendible' => $noVendible,
            'severidad' => $severidad,
            'descuento_sugerido_pct' => $descuento,
        ]);

        $posicion += $consumidoPorEsteLote;
    }

    return $filas;
}

/**
 * Carga lotes visibles con su proyeccion de caducidad.
 *
 * @param array{
 *   severidad?:string, id_almacen?:int, id_producto?:int, categoria?:string,
 *   q?:string, solo_con_excedente?:bool
 * } $filtros
 * @return array{lotes: array<int,array<string,mixed>>, ventana_dias:int}
 */
function loteFetchProyecciones(PDO $pdo, array $filtros = []): array
{
    if (!loteTablaExiste($pdo, 'lotes_inventario')) {
        return ['lotes' => [], 'ventana_dias' => LOTE_VENTANA_DIAS];
    }

    $hoy = (new DateTimeImmutable('today'))->format('Y-m-d');

    $where = ["l.estado IN ('activo','caducado')"];
    $params = [];

    if (!empty($filtros['id_producto'])) {
        $where[] = 'l.id_producto = :id_producto';
        $params[':id_producto'] = (int) $filtros['id_producto'];
    }
    if (!empty($filtros['id_almacen'])) {
        $where[] = 'l.id_almacen = :id_almacen';
        $params[':id_almacen'] = (int) $filtros['id_almacen'];
    }
    if (isset($filtros['categoria']) && trim((string) $filtros['categoria']) !== '') {
        $where[] = 'p.categoria = :categoria';
        $params[':categoria'] = trim((string) $filtros['categoria']);
    }
    if (isset($filtros['q']) && trim((string) $filtros['q']) !== '') {
        // Tres placeholders distintos para el mismo valor a proposito: MySQL/PDO con
        // ATTR_EMULATE_PREPARES=false no permite reusar un named param (:q, :q, :q) ->
        // SQLSTATE[HY093] (mismo patron de bug que loteGuardar()).
        $where[] = '(p.nombre LIKE :q1 OR l.codigo_lote LIKE :q2 OR ' . loteSkuExpr($pdo) . ' LIKE :q3)';
        $qLike = '%' . trim((string) $filtros['q']) . '%';
        $params[':q1'] = $qLike;
        $params[':q2'] = $qLike;
        $params[':q3'] = $qLike;
    }

    $sql = 'SELECT l.*, p.nombre AS producto_nombre, p.categoria AS producto_categoria,
                   ' . loteSkuExpr($pdo) . ' AS producto_sku,
                   ' . (loteColumnaExiste($pdo, 'productos', 'capsulas_por_envase') ? 'p.capsulas_por_envase' : 'NULL') . ' AS capsulas_por_envase,
                   ' . (loteColumnaExiste($pdo, 'productos', 'porcion_capsulas') ? 'p.porcion_capsulas' : 'NULL') . ' AS porcion_capsulas,
                   ' . loteAlmacenNombreExpr($pdo) . ' AS almacen_nombre
            FROM lotes_inventario l
            JOIN productos p ON p.id_producto = l.id_producto
            LEFT JOIN almacenes a ON a.id_almacen = l.id_almacen
            WHERE ' . implode(' AND ', $where);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($rows === []) {
        return ['lotes' => [], 'ventana_dias' => LOTE_VENTANA_DIAS];
    }

    $idsProducto = array_values(array_unique(array_map(static fn($r) => (int) $r['id_producto'], $rows)));
    $velocidades = loteVelocidadVentas($pdo, $idsProducto);
    $reconciliacion = loteReconciliacionStock($pdo, $idsProducto);

    // Agrupar lotes por producto y proyectar FEFO.
    $porProducto = [];
    foreach ($rows as $r) {
        $porProducto[(int) $r['id_producto']][] = $r;
    }

    $lotes = [];
    foreach ($porProducto as $idProducto => $lotesProducto) {
        $vel = $velocidades[$idProducto] ?? [
            'vel_diaria' => 0.0,
            'sin_historico' => true,
            'sin_rotacion' => false,
        ];
        foreach (loteComputeProyeccionProducto($lotesProducto, $vel, $hoy) as $fila) {
            $rec = $reconciliacion[$idProducto] ?? ['stock_sistema' => null, 'stock_lotes' => 0];
            $fila['stock_sistema'] = $rec['stock_sistema'];
            $fila['stock_lotes'] = $rec['stock_lotes'];
            $fila['descuadre'] = ($rec['stock_sistema'] !== null)
                && ((int) $rec['stock_sistema'] !== (int) $rec['stock_lotes']);
            $lotes[] = $fila;
        }
    }

    // Filtros que dependen de la proyeccion.
    if (!empty($filtros['solo_con_excedente'])) {
        $lotes = array_values(array_filter($lotes, static fn($l) => (int) ($l['excedente_proyectado'] ?? 0) > 0));
    }
    if (isset($filtros['severidad']) && trim((string) $filtros['severidad']) !== '') {
        $sev = trim((string) $filtros['severidad']);
        $lotes = array_values(array_filter($lotes, static fn($l) => $l['severidad'] === $sev));
    }

    // Orden de exhibicion: primero los que tienen menos margen REAL para venderse
    // (horizonte efectivo), con la fecha de caducidad cruda como desempate.
    usort($lotes, static function (array $a, array $b): int {
        $ea = $a['dias_efectivos_venta'] ?? $a['dias_hasta_caducar'];
        $eb = $b['dias_efectivos_venta'] ?? $b['dias_hasta_caducar'];
        return [$ea, $a['dias_hasta_caducar']] <=> [$eb, $b['dias_hasta_caducar']];
    });

    return ['lotes' => $lotes, 'ventana_dias' => LOTE_VENTANA_DIAS];
}

/**
 * Resumen para el tablero: cuantos lotes hay en cada severidad y cual es el mas
 * urgente. "urgen" = critico + urgente + caducado (los que hay que liquidar ya).
 *
 * @return array{
 *   critico:int, urgente:int, planificar:int, vigilar:int, caducado:int,
 *   sin_rotacion:int, sin_historico:int, ok:int,
 *   urgen:int, total:int, mas_urgente:?array<string,mixed>
 * }
 */
function loteResumenSeveridad(PDO $pdo): array
{
    $conteo = [
        'critico' => 0, 'urgente' => 0, 'planificar' => 0, 'vigilar' => 0,
        'caducado' => 0, 'sin_rotacion' => 0, 'sin_historico' => 0, 'ok' => 0,
    ];
    $resumen = $conteo + ['urgen' => 0, 'total' => 0, 'mas_urgente' => null];

    if (!loteTablaExiste($pdo, 'lotes_inventario')) {
        return $resumen;
    }

    $lotes = loteFetchProyecciones($pdo)['lotes'];
    $urgentes = ['critico', 'urgente', 'caducado'];
    $masUrgente = null;

    foreach ($lotes as $l) {
        $sev = (string) ($l['severidad'] ?? '');
        if (array_key_exists($sev, $conteo)) {
            $conteo[$sev]++;
        }
        if (in_array($sev, $urgentes, true)) {
            $efActual = (int) ($l['dias_efectivos_venta'] ?? $l['dias_hasta_caducar']);
            $efPrevio = $masUrgente === null
                ? PHP_INT_MAX
                : (int) ($masUrgente['dias_efectivos_venta'] ?? $masUrgente['dias_hasta_caducar']);
            if ($efActual < $efPrevio) {
                $masUrgente = $l;
            }
        }
    }

    $resumen = $conteo + [
        'urgen' => $conteo['critico'] + $conteo['urgente'] + $conteo['caducado'],
        'total' => count($lotes),
        'mas_urgente' => $masUrgente,
    ];

    return $resumen;
}

/**
 * SUM(cantidad_actual) del sistema vs SUM(cantidad_restante) de lotes, por producto.
 *
 * @param int[] $idsProducto
 * @return array<int, array{stock_sistema:?int, stock_lotes:int}>
 */
function loteReconciliacionStock(PDO $pdo, array $idsProducto): array
{
    $ids = array_values(array_unique(array_map('intval', $idsProducto)));
    if ($ids === []) {
        return [];
    }
    $ph = [];
    $params = [];
    foreach ($ids as $i => $id) {
        $key = ":r{$i}";
        $ph[] = $key;
        $params[$key] = $id;
    }
    $inClause = implode(',', $ph);

    $out = [];
    foreach ($ids as $id) {
        $out[$id] = ['stock_sistema' => null, 'stock_lotes' => 0];
    }

    if (loteTablaExiste($pdo, 'inventario_almacen')) {
        $stmt = $pdo->prepare(
            "SELECT id_producto, SUM(cantidad_actual) AS total
             FROM inventario_almacen WHERE id_producto IN ($inClause) GROUP BY id_producto"
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['id_producto']]['stock_sistema'] = (int) $row['total'];
        }
    }

    $stmt = $pdo->prepare(
        "SELECT id_producto, SUM(cantidad_restante) AS total
         FROM lotes_inventario
         WHERE id_producto IN ($inClause) AND estado IN ('activo','caducado')
         GROUP BY id_producto"
    );
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int) $row['id_producto']]['stock_lotes'] = (int) $row['total'];
    }

    return $out;
}

/* -------------------------------------------------------------------------- *
 *  CRUD                                                                      *
 * -------------------------------------------------------------------------- */

/**
 * Normaliza y valida los datos de un lote. Lanza InvalidArgumentException.
 *
 * @return array{id_producto:int, id_almacen:?int, codigo_lote:string, fecha_caducidad:string,
 *   caducidad_aproximada:int, cantidad:int, costo_unitario:?float, notas:?string, foto:?string}
 */
function loteNormalizarDatos(array $d): array
{
    $idProducto = (int) ($d['id_producto'] ?? 0);
    if ($idProducto <= 0) {
        throw new InvalidArgumentException('Producto invalido.');
    }

    $codigo = trim((string) ($d['codigo_lote'] ?? ''));
    if ($codigo === '' || mb_strlen($codigo) > 120) {
        throw new InvalidArgumentException('Codigo de lote invalido.');
    }

    // Validacion estricta YYYY-MM-DD + calendario real. strtotime() es demasiado
    // permisivo para este campo: acepta expresiones relativas ("tomorrow"), rellena
    // fechas de calendario invalidas en vez de rechazarlas (2029-02-30 se convertia
    // silenciosamente en 2029-03-02) y "0000-00-00" en una fecha negativa absurda.
    // El unico formato que envia <input type="date"> (el unico caller real) es
    // YYYY-MM-DD, asi que no se pierde ningun caso de uso legitimo al exigirlo.
    $fecha = trim((string) ($d['fecha_caducidad'] ?? ''));
    if (
        !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $fecha, $m)
        || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])
    ) {
        throw new InvalidArgumentException('Fecha de caducidad invalida.');
    }

    $cantidad = (int) ($d['cantidad'] ?? $d['cantidad_inicial'] ?? 0);
    if ($cantidad < 0) {
        throw new InvalidArgumentException('Cantidad invalida.');
    }

    $idAlmacen = isset($d['id_almacen']) && (int) $d['id_almacen'] > 0 ? (int) $d['id_almacen'] : null;
    $costo = isset($d['costo_unitario']) && $d['costo_unitario'] !== '' ? (float) $d['costo_unitario'] : null;
    $notas = isset($d['notas']) && trim((string) $d['notas']) !== '' ? mb_substr(trim((string) $d['notas']), 0, 500) : null;
    $foto = isset($d['foto_evidencia']) && trim((string) $d['foto_evidencia']) !== '' ? trim((string) $d['foto_evidencia']) : null;

    return [
        'id_producto' => $idProducto,
        'id_almacen' => $idAlmacen,
        'codigo_lote' => $codigo,
        'fecha_caducidad' => $fecha,
        'caducidad_aproximada' => !empty($d['caducidad_aproximada']) ? 1 : 0,
        'cantidad' => $cantidad,
        'costo_unitario' => $costo,
        'notas' => $notas,
        'foto' => $foto,
    ];
}

/**
 * Impide que la suma de cantidad_restante de los lotes activos/caducados de un
 * producto rebase el stock del sistema (SUM inventario_almacen.cantidad_actual).
 * Cuenta la cantidad que se va a guardar y EXCLUYE el lote que se edita.
 *
 * No bloquea si el producto no tiene registro de inventario, ni si su stock del
 * sistema es 0 (suele significar "no rastreado / aun no recibido"; bloquearlo
 * atraparia el flujo normal de capturar el lote conforme llega la mercancia).
 *
 * @throws InvalidArgumentException con un mensaje pensado para el usuario final.
 */
function loteAssertNoRebasaStockSistema(PDO $pdo, int $idProducto, int $cantidadNueva, int $idLoteExcluir = 0): void
{
    if ($idProducto <= 0
        || !loteTablaExiste($pdo, 'inventario_almacen')
        || !loteTablaExiste($pdo, 'lotes_inventario')
    ) {
        return;
    }

    // SUM() sobre cero renglones = NULL -> distingue "sin registro de inventario"
    // de "stock realmente en 0".
    $stmt = $pdo->prepare('SELECT SUM(cantidad_actual) FROM inventario_almacen WHERE id_producto = :p');
    $stmt->execute([':p' => $idProducto]);
    $raw = $stmt->fetchColumn();
    if ($raw === null || $raw === false || (int) $raw <= 0) {
        return;
    }
    $stockSistema = (int) $raw;

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(cantidad_restante), 0)
         FROM lotes_inventario
         WHERE id_producto = :p AND estado IN ('activo','caducado') AND id_lote <> :excl"
    );
    $stmt->execute([':p' => $idProducto, ':excl' => $idLoteExcluir]);
    $otrosLotes = (int) $stmt->fetchColumn();

    $totalProyectado = $otrosLotes + max(0, $cantidadNueva);
    if ($totalProyectado > $stockSistema) {
        throw new InvalidArgumentException(sprintf(
            'Los lotes de este producto sumarían %d unidades, pero el stock del sistema es %d. '
            . 'Baja la cantidad del lote o corrige el stock en Inventario antes de guardar.',
            $totalProyectado,
            $stockSistema
        ));
    }
}

/**
 * Crea o actualiza un lote por id_lote (0 = nuevo). Devuelve el id_lote.
 *
 * $validarContraStock: cuando es true (default, flujo de la ficha de producto)
 * rechaza el guardado si los lotes rebasarian el stock del sistema. El flujo de
 * "Entradas de Inventario" lo pasa en false porque ahi el stock y el lote suben
 * juntos en la misma operacion.
 */
function loteGuardar(PDO $pdo, array $datos, int $userId, bool $validarContraStock = true): int
{
    $idLote = (int) ($datos['id_lote'] ?? 0);
    $n = loteNormalizarDatos($datos);
    $hoy = date('Y-m-d');

    if ($validarContraStock) {
        loteAssertNoRebasaStockSistema($pdo, $n['id_producto'], $n['cantidad'], $idLote);
    }

    if ($idLote > 0) {
        $stmt = $pdo->prepare(
            'UPDATE lotes_inventario
             SET id_producto = :id_producto, id_almacen = :id_almacen, codigo_lote = :codigo,
                 fecha_caducidad = :fecha, caducidad_aproximada = :aprox,
                 cantidad_inicial = :cant_ini, cantidad_restante = :cant_rest,
                 costo_unitario = :costo, notas_seguimiento = :notas,
                 id_usuario_seguimiento = :uid
             WHERE id_lote = :id_lote'
        );
        $stmt->execute([
            ':id_producto' => $n['id_producto'],
            ':id_almacen' => $n['id_almacen'],
            ':codigo' => $n['codigo_lote'],
            ':fecha' => $n['fecha_caducidad'],
            ':aprox' => $n['caducidad_aproximada'],
            ':cant_ini' => $n['cantidad'],
            ':cant_rest' => $n['cantidad'],
            ':costo' => $n['costo_unitario'],
            ':notas' => $n['notas'],
            ':uid' => $userId,
            ':id_lote' => $idLote,
        ]);

        return $idLote;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO lotes_inventario
            (id_producto, id_almacen, codigo_lote, fecha_caducidad, caducidad_aproximada,
             fecha_ingreso, cantidad_inicial, cantidad_restante, costo_unitario,
             foto_evidencia, notas_seguimiento, creado_por)
         VALUES
            (:id_producto, :id_almacen, :codigo, :fecha, :aprox,
             :ingreso, :cant_ini, :cant_rest, :costo, :foto, :notas, :creado_por)'
    );
    $stmt->execute([
        ':id_producto' => $n['id_producto'],
        ':id_almacen' => $n['id_almacen'],
        ':codigo' => $n['codigo_lote'],
        ':fecha' => $n['fecha_caducidad'],
        ':aprox' => $n['caducidad_aproximada'],
        ':ingreso' => $hoy,
        // Un lote nuevo entra completo: restante = inicial. Dos placeholders
        // distintos a proposito: MySQL/PDO con ATTR_EMULATE_PREPARES=false NO
        // permite reusar un named param (:cant, :cant) -> SQLSTATE[HY093].
        ':cant_ini' => $n['cantidad'],
        ':cant_rest' => $n['cantidad'],
        ':costo' => $n['costo_unitario'],
        ':foto' => $n['foto'],
        ':notas' => $n['notas'],
        ':creado_por' => $userId,
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Registra una entrada de mercancia contra un lote: si el (producto, codigo) ya
 * existe suma la cantidad; si no, crea el lote. Para el flujo de
 * views/inventario_entradas.php. Devuelve el id_lote.
 */
function loteRegistrarEntrada(PDO $pdo, array $datos, int $userId): int
{
    $n = loteNormalizarDatos($datos);
    if ($n['cantidad'] <= 0) {
        throw new InvalidArgumentException('La cantidad de entrada debe ser mayor a cero.');
    }

    $stmt = $pdo->prepare(
        'SELECT id_lote, cantidad_inicial, cantidad_restante
         FROM lotes_inventario WHERE id_producto = :p AND codigo_lote = :c LIMIT 1'
    );
    $stmt->execute([':p' => $n['id_producto'], ':c' => $n['codigo_lote']]);
    $existente = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existente) {
        $idLote = (int) $existente['id_lote'];
        $upd = $pdo->prepare(
            "UPDATE lotes_inventario
             SET cantidad_inicial = cantidad_inicial + :inc_ini,
                 cantidad_restante = cantidad_restante + :inc_rest,
                 fecha_caducidad = :fecha,
                 caducidad_aproximada = :aprox,
                 estado = CASE WHEN estado IN ('agotado') THEN 'activo' ELSE estado END
             WHERE id_lote = :id"
        );
        $upd->execute([
            // Placeholders distintos: MySQL/PDO (EMULATE_PREPARES=false) no admite
            // reusar un named param en la misma sentencia -> SQLSTATE[HY093].
            ':inc_ini' => $n['cantidad'],
            ':inc_rest' => $n['cantidad'],
            ':fecha' => $n['fecha_caducidad'],
            ':aprox' => $n['caducidad_aproximada'],
            ':id' => $idLote,
        ]);

        return $idLote;
    }

    return loteGuardar($pdo, $datos + ['id_lote' => 0], $userId, false);
}

/**
 * Ajusta manualmente la cantidad restante de un lote.
 */
function loteAjustarCantidad(PDO $pdo, int $idLote, int $nuevaCantidad, int $userId): void
{
    if ($idLote <= 0 || $nuevaCantidad < 0) {
        throw new InvalidArgumentException('Datos de ajuste invalidos.');
    }

    $stmt = $pdo->prepare('SELECT id_producto FROM lotes_inventario WHERE id_lote = :id');
    $stmt->execute([':id' => $idLote]);
    $idProducto = (int) ($stmt->fetchColumn() ?: 0);
    loteAssertNoRebasaStockSistema($pdo, $idProducto, $nuevaCantidad, $idLote);

    $estado = $nuevaCantidad === 0 ? 'agotado' : 'activo';
    $stmt = $pdo->prepare(
        "UPDATE lotes_inventario
         SET cantidad_restante = :c,
             estado = CASE WHEN estado IN ('retirado','caducado') THEN estado ELSE :e END,
             id_usuario_seguimiento = :uid
         WHERE id_lote = :id"
    );
    $stmt->execute([':c' => $nuevaCantidad, ':e' => $estado, ':uid' => $userId, ':id' => $idLote]);
}

/**
 * Cambia el estado de un lote (activo|agotado|caducado|retirado).
 */
function loteCambiarEstado(PDO $pdo, int $idLote, string $estado, int $userId): void
{
    $validos = ['activo', 'agotado', 'caducado', 'retirado'];
    if ($idLote <= 0 || !in_array($estado, $validos, true)) {
        throw new InvalidArgumentException('Estado de lote invalido.');
    }
    $stmt = $pdo->prepare(
        'UPDATE lotes_inventario SET estado = :e, id_usuario_seguimiento = :uid WHERE id_lote = :id'
    );
    $stmt->execute([':e' => $estado, ':uid' => $userId, ':id' => $idLote]);
}

/**
 * Marca la alerta de un lote como atendida y registra si se puso en oferta.
 */
function loteMarcarAtendida(PDO $pdo, int $idLote, bool $enOferta, ?string $notas, int $userId): void
{
    if ($idLote <= 0) {
        throw new InvalidArgumentException('Lote invalido.');
    }
    $stmt = $pdo->prepare(
        'UPDATE lotes_inventario
         SET alerta_atendida = 1, en_oferta = :oferta,
             notas_seguimiento = :notas, id_usuario_seguimiento = :uid
         WHERE id_lote = :id'
    );
    $stmt->execute([
        ':oferta' => $enOferta ? 1 : 0,
        ':notas' => $notas !== null && trim($notas) !== '' ? mb_substr(trim($notas), 0, 500) : null,
        ':uid' => $userId,
        ':id' => $idLote,
    ]);
}

/**
 * Pone un producto "en oferta" desde el panel de Caducidades, de un clic:
 *  - lo agrega a la categoria de ofertas (la crea como "Oferta" si no existe),
 *  - le fija precio_oferta = costo + $50 si aun no tiene un override manual,
 *  - marca el lote como atendido y en_oferta (cuando se pasa un id_lote > 0).
 *
 * El catalogo, la ficha, el POS y Alex ya leen ese precio efectivo, asi que con
 * este unico paso el producto pasa a venderse al precio de oferta en todos lados.
 *
 * @return array{nombre:string, precio_costo:float, precio_oferta:float, precio_venta:float, ya_estaba:bool, precio_fijado:bool}
 */
function lotePonerProductoEnOferta(PDO $pdo, int $idProducto, int $idLote, int $userId): array
{
    if ($idProducto <= 0) {
        throw new InvalidArgumentException('Producto invalido.');
    }

    $stmt = $pdo->prepare('SELECT nombre, precio_costo, precio_venta, precio_oferta FROM productos WHERE id_producto = :id');
    $stmt->execute([':id' => $idProducto]);
    $prod = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($prod)) {
        throw new InvalidArgumentException('El producto ya no existe.');
    }

    $catId = ofertaResolverCategoriaId($pdo, true);
    if ($catId === null) {
        throw new RuntimeException('No se pudo resolver la categoria de ofertas.');
    }

    // Alta idempotente en producto_categorias (check-then-insert: portable MySQL/SQLite).
    $chk = $pdo->prepare('SELECT 1 FROM producto_categorias WHERE id_producto = :p AND id_categoria = :c');
    $chk->execute([':p' => $idProducto, ':c' => $catId]);
    $yaEstaba = $chk->fetchColumn() !== false;
    if (!$yaEstaba) {
        $pdo->prepare('INSERT INTO producto_categorias (id_producto, id_categoria) VALUES (:p, :c)')
            ->execute([':p' => $idProducto, ':c' => $catId]);
    }

    // El precio sugerido solo se escribe si no hay un override manual todavia
    // (no pisar un precio que alguien ya bajo a mano desde la ficha del producto).
    $tienePrecio = $prod['precio_oferta'] !== null && (float) $prod['precio_oferta'] > 0;
    $precioOferta = $tienePrecio
        ? round((float) $prod['precio_oferta'], 2)
        : ofertaPrecioSugerido((float) $prod['precio_costo']);
    if (!$tienePrecio) {
        $pdo->prepare('UPDATE productos SET precio_oferta = :po WHERE id_producto = :id')
            ->execute([':po' => $precioOferta, ':id' => $idProducto]);
    }

    if ($idLote > 0) {
        loteMarcarAtendida($pdo, $idLote, true, 'Producto puesto en oferta desde Caducidades', $userId);
    }

    return [
        'nombre'        => (string) $prod['nombre'],
        'precio_costo'  => round((float) $prod['precio_costo'], 2),
        'precio_oferta' => $precioOferta,
        'precio_venta'  => round((float) $prod['precio_venta'], 2),
        'ya_estaba'     => $yaEstaba,
        'precio_fijado' => !$tienePrecio,
    ];
}

/**
 * Elimina un lote.
 */
function loteEliminar(PDO $pdo, int $idLote): void
{
    if ($idLote <= 0) {
        throw new InvalidArgumentException('Lote invalido.');
    }
    $pdo->prepare('DELETE FROM lotes_inventario WHERE id_lote = :id')->execute([':id' => $idLote]);
}

/* -------------------------------------------------------------------------- *
 *  Helpers de expresiones SQL tolerantes a columnas ausentes                 *
 * -------------------------------------------------------------------------- */

function loteSkuExpr(PDO $pdo): string
{
    return loteColumnaExiste($pdo, 'productos', 'sku') ? 'p.sku' : "''";
}

function loteAlmacenNombreExpr(PDO $pdo): string
{
    return loteTablaExiste($pdo, 'almacenes') ? 'a.nombre' : 'NULL';
}
