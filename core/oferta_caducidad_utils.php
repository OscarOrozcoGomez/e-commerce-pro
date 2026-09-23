<?php
declare(strict_types=1);

/**
 * Estrategia de venta de productos por caducar: lo que Alex puede decirle al cliente con
 * verdad (urgencia, fecha, margen de consumo), el precio de paquete ("llevate 2") y el
 * mantenimiento automatico de la categoria Ofertas (escalera de precio y salida cuando ya
 * no queda ningun lote en riesgo).
 *
 * Las reglas de precio puras viven en oferta_pricing.php (ofertaPrecioEscalera /
 * ofertaPrecioPaquete); el riesgo por lote en lote_caducidad_utils.php
 * (loteResumenRiesgoPorProducto). Aqui solo se combinan.
 */

require_once __DIR__ . '/oferta_pricing.php';
require_once __DIR__ . '/lote_caducidad_utils.php';

const OFERTA_CAD_MESES = [
    1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril', 5 => 'mayo', 6 => 'junio',
    7 => 'julio', 8 => 'agosto', 9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
];

/**
 * Urgencia comercial de una severidad de lote: 'alta' (critico), 'media' (urgente), 'baja'
 * (planificar / sin_rotacion) o null si no hay riesgo real de caducar.
 */
function ofertaCadUrgencia(?string $severidad): ?string
{
    switch ($severidad) {
        case 'critico':
            return 'alta';
        case 'urgente':
            return 'media';
        case 'planificar':
        case 'sin_rotacion':
            return 'baja';
        default:
            return null;
    }
}

/** Orden numerico de la urgencia (mayor = mas urgente) para ordenar ofertas. */
function ofertaCadUrgenciaRank(?string $urgencia): int
{
    return ['alta' => 3, 'media' => 2, 'baja' => 1][$urgencia ?? ''] ?? 0;
}

/** "15 de marzo de 2027" a partir de 'Y-m-d' (sin depender de locale). */
function ofertaCadFechaLegible(string $fechaYmd): string
{
    $partes = explode('-', substr($fechaYmd, 0, 10));
    if (count($partes) !== 3) {
        return $fechaYmd;
    }
    $mes = OFERTA_CAD_MESES[(int) $partes[1]] ?? '';

    return $mes === '' ? $fechaYmd : ((int) $partes[2]) . ' de ' . $mes . ' de ' . (int) $partes[0];
}

/** "12 dias" o "unos 4 meses". */
function ofertaCadDiasLegible(int $dias): string
{
    if ($dias <= 45) {
        return max(0, $dias) . ($dias === 1 ? ' dia' : ' dias');
    }

    return 'unos ' . (int) round($dias / 30) . ' meses';
}

/**
 * Texto FACTUAL, armado por codigo, para que Alex conteste con honestidad "por que esta en
 * oferta": fecha de caducidad real, cuanto rinde un envase y cuantas piezas quedan con esa
 * fecha. El modelo lo parafrasea pero no inventa datos. Vacio si el producto no tiene un lote
 * en riesgo (nada que explicar). Solo habla de rendimiento si el producto tiene capsulas por
 * envase y porcion capturadas -- nunca se asume una dosis.
 *
 * @param array{en_riesgo?:bool,fecha_caducidad?:?string,dias_para_caducar?:?int,dias_tratamiento?:?int,piezas_en_riesgo?:int} $resumen
 */
function ofertaCadArgumentoHonesto(array $resumen): string
{
    $fecha = (string) ($resumen['fecha_caducidad'] ?? '');
    if (empty($resumen['en_riesgo']) || $fecha === '') {
        return '';
    }

    $dias = isset($resumen['dias_para_caducar']) ? (int) $resumen['dias_para_caducar'] : null;
    $texto = 'Es producto de fecha de caducidad corta: las piezas mas proximas caducan el ' . ofertaCadFechaLegible($fecha)
        . ($dias !== null ? ' (en ' . ofertaCadDiasLegible($dias) . ')' : '') . '.';

    $diasTratamiento = isset($resumen['dias_tratamiento']) ? (int) $resumen['dias_tratamiento'] : 0;
    if ($diasTratamiento > 0 && $dias !== null && $dias >= $diasTratamiento) {
        $margen = $dias - $diasTratamiento;
        $texto .= " Un envase rinde unos {$diasTratamiento} dias con la dosis sugerida por la marca, asi que alcanza a terminarlo antes de que caduque (con unos {$margen} dias de margen).";
    }

    if ($diasTratamiento <= 0) {
        // Sin capsulas por envase y porcion capturadas, el modelo tiende a estimar cuanto dura un
        // envase por su cuenta (y a contradecirse entre una respuesta y otra): se le prohibe aqui.
        $texto .= ' No tenemos capturada la duracion de un envase de este producto: no le digas al cliente cuanto le dura, cuantos dias o meses rinde ni si alcanza a terminarlo antes de la fecha.';
    }

    $piezas = (int) ($resumen['piezas_en_riesgo'] ?? 0);
    if ($piezas > 0) {
        $texto .= ' Quedan ' . $piezas . ($piezas === 1 ? ' pieza' : ' piezas') . ' con esa fecha.';
    }

    return $texto;
}

/**
 * Precio de paquete de un producto en oferta, o null si no aplica. Solo hay paquete cuando de
 * verdad sobra producto por caducar: minimo OFERTA_PAQUETE_MIN_PIEZAS piezas en lotes en
 * riesgo (y de stock vendible), y todavia queda margen sobre el piso costo + $50. La cantidad
 * maxima es lo que hay en riesgo: el precio de paquete es unitario para toda la linea, asi que
 * no se ofrece por encima de las piezas que de verdad hay que mover (FEFO vende esas primero).
 *
 * @return array{cantidad_minima:int,cantidad_maxima:int,precio_unitario:float,ahorro_por_pieza:float,ahorro_vs_precio_normal_por_pieza:float}|null
 */
function ofertaCadPaquete(float $precioVenta, float $precioCosto, float $precioOferta, int $piezasEnRiesgo, int $stockVendible): ?array
{
    $maximo = min($piezasEnRiesgo, $stockVendible);
    if ($maximo < OFERTA_PAQUETE_MIN_PIEZAS) {
        return null;
    }

    $precio = ofertaPrecioPaquete($precioVenta, $precioCosto, $precioOferta);
    if ($precio === null) {
        return null;
    }

    return [
        'cantidad_minima' => OFERTA_PAQUETE_MIN_PIEZAS,
        'cantidad_maxima' => $maximo,
        'precio_unitario' => $precio,
        'ahorro_por_pieza' => round($precioOferta - $precio, 2),                  // contra el precio de oferta
        'ahorro_vs_precio_normal_por_pieza' => round($precioVenta - $precio, 2),   // contra el precio normal (visto: el modelo lo calculaba mal)
    ];
}

/** Quita al producto de la(s) categoria(s) de ofertas. Regresa cuantos enlaces borro. */
function ofertaCadRetirarDeOfertas(PDO $pdo, int $idProducto): int
{
    $stmt = $pdo->prepare(
        'DELETE FROM producto_categorias WHERE id_producto = ? AND id_categoria IN ('
        . 'SELECT id_categoria FROM categorias WHERE LOWER(nombre) IN ('
        . implode(', ', array_fill(0, count(OFERTA_CATEGORIA_NOMBRES), '?')) . '))'
    );
    $stmt->execute(array_merge([$idProducto], OFERTA_CATEGORIA_NOMBRES));

    return $stmt->rowCount();
}

/**
 * Mantenimiento de los productos que el sistema puso en Ofertas desde Caducidades (tabla
 * oferta_caducidad_gestion; los que el equipo metio a mano NUNCA se tocan):
 *
 *  - 'liberado': ya no esta en la categoria (alguien lo saco a mano) -> deja de gestionarse.
 *  - 'retirado': ya no le queda ningun lote vendible en riesgo -> sale de Ofertas y, si el
 *    precio de oferta lo habia puesto el sistema, se limpia. Evita que un precio rebajado se
 *    quede de por vida sobre lotes frescos.
 *  - 'precio_bajado': su lote se acerco a la fecha y sube un escalon (nunca sube el precio
 *    mientras siga en oferta: solo baja, hasta el piso costo + $50).
 *  - 'precio_manual': alguien cambio el precio a mano -> se respeta y deja de gestionarse el
 *    precio (sigue gestionada solo su permanencia).
 *
 * Con $dryRun no escribe nada, solo reporta. No audita: quien la llama (el cron) registra en
 * logs_auditoria las acciones devueltas, despues de que ya se aplicaron.
 *
 * @return array<int,array{accion:string,id_producto:int,nombre:string,severidad:?string,precio_anterior:?float,precio_nuevo:?float}>
 */
function ofertaCadReconciliar(PDO $pdo, bool $dryRun = false): array
{
    $acciones = [];
    if (!loteTablaExiste($pdo, 'oferta_caducidad_gestion')) {
        return $acciones;
    }

    $filas = $pdo->query(
        'SELECT g.id_producto, g.gestiona_precio, g.precio_aplicado,
                p.nombre, p.estado, p.precio_venta, p.precio_costo, p.precio_oferta
         FROM oferta_caducidad_gestion g
         LEFT JOIN productos p ON p.id_producto = g.id_producto
         ORDER BY g.id_producto'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($filas === []) {
        return $acciones;
    }

    $ids = array_map(static fn(array $f): int => (int) $f['id_producto'], $filas);
    $enOferta = ofertaFiltrarEnOferta($pdo, $ids);
    $resumenes = loteResumenRiesgoPorProducto(loteFetchProyecciones($pdo, ['ids_producto' => $ids])['lotes'], true);

    $borrarGestion = $pdo->prepare('DELETE FROM oferta_caducidad_gestion WHERE id_producto = ?');

    foreach ($filas as $f) {
        $id = (int) $f['id_producto'];
        $nombre = (string) ($f['nombre'] ?? ('#' . $id));
        $venta = (float) ($f['precio_venta'] ?? 0);
        $costo = (float) ($f['precio_costo'] ?? 0);
        $precioOferta = ($f['precio_oferta'] !== null && (float) $f['precio_oferta'] > 0) ? round((float) $f['precio_oferta'], 2) : null;
        $precioActual = ofertaPrecioEfectivo($venta, $costo, $precioOferta, true);
        $gestionaPrecio = (int) $f['gestiona_precio'] === 1;
        $resumen = $resumenes[$id] ?? null;
        $severidad = $resumen['severidad'] ?? null;

        $accion = static fn(string $tipo, ?float $nuevo = null): array => [
            'accion' => $tipo,
            'id_producto' => $id,
            'nombre' => $nombre,
            'severidad' => $severidad,
            'precio_anterior' => $precioActual,
            'precio_nuevo' => $nuevo,
        ];

        try {
            if ($f['nombre'] === null || ($f['estado'] ?? 'activo') !== 'activo' || !isset($enOferta[$id])) {
                if (!$dryRun) {
                    $borrarGestion->execute([$id]);
                }
                $acciones[] = $accion('liberado');
                continue;
            }

            if ($gestionaPrecio && $f['precio_aplicado'] !== null && $precioOferta !== null
                && abs($precioOferta - (float) $f['precio_aplicado']) >= 0.01) {
                $gestionaPrecio = false;
                if (!$dryRun) {
                    $pdo->prepare('UPDATE oferta_caducidad_gestion SET gestiona_precio = 0, actualizado_en = CURRENT_TIMESTAMP WHERE id_producto = ?')
                        ->execute([$id]);
                }
                $acciones[] = $accion('precio_manual');
            }

            if ($resumen === null || !$resumen['en_riesgo']) {
                if (!$dryRun) {
                    $pdo->beginTransaction();
                    ofertaCadRetirarDeOfertas($pdo, $id);
                    if ($gestionaPrecio) {
                        $pdo->prepare('UPDATE productos SET precio_oferta = NULL WHERE id_producto = ?')->execute([$id]);
                    }
                    $borrarGestion->execute([$id]);
                    $pdo->commit();
                }
                $acciones[] = $accion('retirado');
                continue;
            }

            if ($gestionaPrecio) {
                $objetivo = ofertaPrecioEscalera($venta, $costo, $severidad);
                $actual = $f['precio_aplicado'] !== null ? (float) $f['precio_aplicado'] : $precioActual;
                if ($actual - $objetivo >= 0.01) {
                    if (!$dryRun) {
                        $pdo->beginTransaction();
                        $pdo->prepare('UPDATE productos SET precio_oferta = ? WHERE id_producto = ?')->execute([$objetivo, $id]);
                        loteRegistrarGestionOferta($pdo, $id, (string) $severidad, true, $objetivo);
                        $pdo->commit();
                    }
                    $acciones[] = $accion('precio_bajado', $objetivo);
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('WARNING: ofertaCadReconciliar fallo con el producto #' . $id . ': ' . $e->getMessage());
        }
    }

    return $acciones;
}
