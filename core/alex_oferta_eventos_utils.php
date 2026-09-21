<?php
declare(strict_types=1);

/**
 * Bitacora de lo que Alex hace con las ofertas (tabla alex_oferta_eventos) y las metricas que
 * salen de ella. Sirve para dos cosas: saber si la estrategia de productos por caducar
 * realmente vende (panel "Alex y las ofertas" en views/caducidades.php) y topar el ritmo de las
 * recompras proactivas (alexOfertaContarHoy()).
 *
 * Todo es "mejor esfuerzo": registrar un evento NUNCA debe tumbar una conversacion ni un
 * pedido, y si la tabla aun no existe (deploy en curso) no hace nada.
 */

require_once __DIR__ . '/lote_caducidad_utils.php';

const ALEX_OFERTA_EVENTO_CONSULTADA = 'consultada';
const ALEX_OFERTA_EVENTO_VENDIDA = 'vendida';
const ALEX_OFERTA_EVENTO_RECOMPRA = 'recompra_enviada';
const ALEX_OFERTA_EVENTO_SEGUIMIENTO = 'seguimiento_con_oferta';

/** Dias tras una recompra en los que una compra del mismo producto cuenta como conversion. */
const ALEX_OFERTA_VENTANA_CONVERSION_DIAS = 14;

/**
 * Registra un evento. $datos admite: id_conversacion, id_cliente, id_pedido, cantidad,
 * precio_unitario, precio_normal, severidad, paquete. Una consulta repetida de la misma
 * conversacion y producto el mismo dia no se duplica (Alex puede llamar a la funcion varias
 * veces en una charla y eso inflaria las cifras).
 */
function alexOfertaRegistrarEvento(PDO $pdo, string $tipo, int $idProducto, array $datos = []): void
{
    try {
        if ($idProducto <= 0 || !loteTablaExiste($pdo, 'alex_oferta_eventos')) {
            return;
        }

        $idConversacion = isset($datos['id_conversacion']) && (int) $datos['id_conversacion'] > 0 ? (int) $datos['id_conversacion'] : null;
        $ahora = date('Y-m-d H:i:s');

        if ($tipo === ALEX_OFERTA_EVENTO_CONSULTADA && $idConversacion !== null) {
            $dup = $pdo->prepare(
                'SELECT 1 FROM alex_oferta_eventos WHERE tipo = ? AND id_conversacion = ? AND id_producto = ? AND creado_en >= ? LIMIT 1'
            );
            $dup->execute([$tipo, $idConversacion, $idProducto, date('Y-m-d 00:00:00')]);
            if ($dup->fetchColumn() !== false) {
                return;
            }
        }

        $pdo->prepare(
            'INSERT INTO alex_oferta_eventos
                (tipo, id_conversacion, id_cliente, id_producto, id_pedido, cantidad, precio_unitario, precio_normal, severidad, paquete, creado_en)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $tipo,
            $idConversacion,
            isset($datos['id_cliente']) && (int) $datos['id_cliente'] > 0 ? (int) $datos['id_cliente'] : null,
            $idProducto,
            isset($datos['id_pedido']) && (int) $datos['id_pedido'] > 0 ? (int) $datos['id_pedido'] : null,
            isset($datos['cantidad']) ? (int) $datos['cantidad'] : null,
            isset($datos['precio_unitario']) ? round((float) $datos['precio_unitario'], 2) : null,
            isset($datos['precio_normal']) ? round((float) $datos['precio_normal'], 2) : null,
            isset($datos['severidad']) && $datos['severidad'] !== '' ? (string) $datos['severidad'] : null,
            !empty($datos['paquete']) ? 1 : 0,
            $ahora,
        ]);
    } catch (Throwable $e) {
        error_log('WARNING: no se pudo registrar evento de oferta de Alex (' . $tipo . '): ' . $e->getMessage());
    }
}

/** Cuantos eventos de ese tipo hay desde las 00:00 de hoy (para topes diarios). */
function alexOfertaContarHoy(PDO $pdo, string $tipo): int
{
    try {
        if (!loteTablaExiste($pdo, 'alex_oferta_eventos')) {
            return 0;
        }
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM alex_oferta_eventos WHERE tipo = ? AND creado_en >= ?');
        $stmt->execute([$tipo, date('Y-m-d 00:00:00')]);

        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Metricas de los ultimos $dias dias. La "conversion" es aproximada a proposito: una
 * conversacion cuenta como convertida si despues de que Alex le mostro una oferta se agendo
 * un pedido con algun producto en oferta en ESA conversacion (no se sabe cual de los productos
 * listados alcanzo a mencionar el modelo, por eso se mide por conversacion y no por producto).
 *
 * @return array{
 *   disponible:bool, dias:int,
 *   consultas:array{conversaciones:int,productos:int},
 *   ventas:array{pedidos:int,unidades:int,ingreso:float,ahorro_clientes:float,unidades_paquete:int,unidades_urgentes:int},
 *   conversion_pct:?float,
 *   recompra:array{enviadas:int,convertidas:int},
 *   seguimiento:array{enviados:int,convertidos:int},
 *   top_productos:array<int,array{id_producto:int,nombre:string,unidades:int,ingreso:float}>
 * }
 */
function alexOfertaMetricas(PDO $pdo, int $dias = 30): array
{
    $vacio = [
        'disponible' => false,
        'dias' => $dias,
        'consultas' => ['conversaciones' => 0, 'productos' => 0],
        'ventas' => ['pedidos' => 0, 'unidades' => 0, 'ingreso' => 0.0, 'ahorro_clientes' => 0.0, 'unidades_paquete' => 0, 'unidades_urgentes' => 0],
        'conversion_pct' => null,
        'recompra' => ['enviadas' => 0, 'convertidas' => 0],
        'seguimiento' => ['enviados' => 0, 'convertidos' => 0],
        'top_productos' => [],
    ];

    try {
        if (!loteTablaExiste($pdo, 'alex_oferta_eventos')) {
            return $vacio;
        }

        $desde = date('Y-m-d 00:00:00', strtotime('-' . max(1, $dias) . ' days'));
        $stmt = $pdo->prepare(
            'SELECT e.*, p.nombre AS producto_nombre
             FROM alex_oferta_eventos e
             LEFT JOIN productos p ON p.id_producto = e.id_producto
             WHERE e.creado_en >= ?
             ORDER BY e.creado_en, e.id_evento
             LIMIT 20000'
        );
        $stmt->execute([$desde]);
        $eventos = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        error_log('WARNING: alexOfertaMetricas fallo: ' . $e->getMessage());

        return $vacio;
    }

    $m = $vacio;
    $m['disponible'] = true;

    $convConsulta = [];   // id_conversacion => primer instante en que se le mostro una oferta
    $productosConsulta = [];
    $ventas = [];
    $pedidos = [];
    $top = [];
    $recompras = [];
    $seguimientos = [];

    foreach ($eventos as $ev) {
        $tipo = (string) $ev['tipo'];
        $idProducto = (int) $ev['id_producto'];
        $ts = strtotime((string) $ev['creado_en']) ?: 0;

        if ($tipo === ALEX_OFERTA_EVENTO_CONSULTADA) {
            $productosConsulta[$idProducto] = true;
            if (!empty($ev['id_conversacion']) && !isset($convConsulta[(int) $ev['id_conversacion']])) {
                $convConsulta[(int) $ev['id_conversacion']] = $ts;
            }
        } elseif ($tipo === ALEX_OFERTA_EVENTO_VENDIDA) {
            $cant = max(0, (int) ($ev['cantidad'] ?? 0));
            $unit = (float) ($ev['precio_unitario'] ?? 0);
            $normal = (float) ($ev['precio_normal'] ?? 0);
            $ventas[] = ['conv' => (int) ($ev['id_conversacion'] ?? 0), 'cliente' => (int) ($ev['id_cliente'] ?? 0), 'producto' => $idProducto, 'ts' => $ts];
            if (!empty($ev['id_pedido'])) {
                $pedidos[(int) $ev['id_pedido']] = true;
            }
            $m['ventas']['unidades'] += $cant;
            $m['ventas']['ingreso'] += $cant * $unit;
            $m['ventas']['ahorro_clientes'] += $cant * max(0.0, $normal - $unit);
            if (!empty($ev['paquete'])) {
                $m['ventas']['unidades_paquete'] += $cant;
            }
            if (in_array((string) ($ev['severidad'] ?? ''), ['critico', 'urgente'], true)) {
                $m['ventas']['unidades_urgentes'] += $cant;
            }
            $top[$idProducto] ??= ['id_producto' => $idProducto, 'nombre' => (string) ($ev['producto_nombre'] ?? ('#' . $idProducto)), 'unidades' => 0, 'ingreso' => 0.0];
            $top[$idProducto]['unidades'] += $cant;
            $top[$idProducto]['ingreso'] += $cant * $unit;
        } elseif ($tipo === ALEX_OFERTA_EVENTO_RECOMPRA) {
            $recompras[] = ['cliente' => (int) ($ev['id_cliente'] ?? 0), 'producto' => $idProducto, 'ts' => $ts];
        } elseif ($tipo === ALEX_OFERTA_EVENTO_SEGUIMIENTO) {
            $seguimientos[] = ['conv' => (int) ($ev['id_conversacion'] ?? 0), 'producto' => $idProducto, 'ts' => $ts];
        }
    }

    $m['consultas'] = ['conversaciones' => count($convConsulta), 'productos' => count($productosConsulta)];
    $m['ventas']['pedidos'] = count($pedidos);
    $m['ventas']['ingreso'] = round($m['ventas']['ingreso'], 2);
    $m['ventas']['ahorro_clientes'] = round($m['ventas']['ahorro_clientes'], 2);

    if ($convConsulta !== []) {
        $convertidas = [];
        foreach ($ventas as $v) {
            if ($v['conv'] > 0 && isset($convConsulta[$v['conv']]) && $v['ts'] >= $convConsulta[$v['conv']]) {
                $convertidas[$v['conv']] = true;
            }
        }
        $m['conversion_pct'] = round(100 * count($convertidas) / count($convConsulta), 1);
    }

    $ventana = ALEX_OFERTA_VENTANA_CONVERSION_DIAS * 86400;
    $m['recompra']['enviadas'] = count($recompras);
    foreach ($recompras as $r) {
        foreach ($ventas as $v) {
            if ($v['cliente'] === $r['cliente'] && $v['cliente'] > 0 && $v['producto'] === $r['producto']
                && $v['ts'] >= $r['ts'] && $v['ts'] <= $r['ts'] + $ventana) {
                $m['recompra']['convertidas']++;
                break;
            }
        }
    }

    $m['seguimiento']['enviados'] = count($seguimientos);
    foreach ($seguimientos as $s) {
        foreach ($ventas as $v) {
            if ($v['conv'] === $s['conv'] && $s['conv'] > 0 && $v['ts'] >= $s['ts'] && $v['ts'] <= $s['ts'] + $ventana) {
                $m['seguimiento']['convertidos']++;
                break;
            }
        }
    }

    uasort($top, static fn(array $a, array $b): int => [$b['unidades'], $b['ingreso']] <=> [$a['unidades'], $a['ingreso']]);
    $m['top_productos'] = array_map(
        static fn(array $t): array => ['ingreso' => round($t['ingreso'], 2)] + $t,
        array_slice(array_values($top), 0, 5)
    );

    return $m;
}
