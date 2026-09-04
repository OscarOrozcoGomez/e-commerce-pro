<?php
declare(strict_types=1);

/**
 * Prediccion simple de agotamiento de inventario, basada en el promedio de
 * ventas diarias historico. Extraida de api/analytics_data.php para poder
 * reutilizarla tambien en el cruce con el calendario de campanas.
 *
 * @return array<int, array{id_producto:int, nombre:string, stock:int, ventas:int, promedio:float, dias_restantes:int|string, sin_configuracion:bool, estado:string}>
 */
function getTotalDiasHistorial(PDO $pdo): int
{
    $totalDiasResult = $pdo->query('SELECT DATEDIFF(NOW(), MIN(fecha_creacion)) + 1 FROM pedidos')->fetchColumn();
    return ($totalDiasResult && $totalDiasResult > 0) ? (int) $totalDiasResult : 1;
}

function getPrediccionesInventario(PDO $pdo, int $limit = 25): array
{
    $limit = max(1, $limit);
    $totalDias = getTotalDiasHistorial($pdo);

    $sql = "SELECT p.id_producto, p.nombre, p.precio_venta, p.precio_costo, ia.cantidad_actual,
                   COALESCE(ventas.total_qty, 0) as ventas_totales,
                   (COALESCE(ventas.total_qty, 0) / $totalDias) as promedio_diario
            FROM productos p
            JOIN inventario_almacen ia ON p.id_producto = ia.id_producto
            LEFT JOIN (
                SELECT id_producto, SUM(cantidad) as total_qty
                FROM detalle_pedidos dp
                JOIN pedidos pe ON dp.id_pedido = pe.id_pedido
                WHERE pe.estado != 'cancelado'
                GROUP BY id_producto
            ) ventas ON p.id_producto = ventas.id_producto
            WHERE p.estado = 'activo'
            ORDER BY ia.cantidad_actual ASC LIMIT " . $limit;
    $rawPredicciones = $pdo->query($sql)->fetchAll();

    $predicciones = [];
    foreach ($rawPredicciones as $p) {
        $promedio = (float) $p['promedio_diario'];
        $stock = (int) $p['cantidad_actual'];
        $ventasTotales = (int) $p['ventas_totales'];
        $dias = $ventasTotales > 0 && $promedio > 0 ? (int) floor($stock / $promedio) : '—';
        $sinConfig = ((float) $p['precio_venta'] <= 0 || (float) $p['precio_costo'] <= 0);
        $estado = 'Abastecido';

        if ($sinConfig) {
            $estado = 'Sin configuración';
        } elseif ($ventasTotales <= 0) {
            $estado = $stock > 0 ? 'Sin histórico' : 'Sin rotación';
        } elseif ($stock <= 0) {
            $estado = 'Agotado';
        } elseif ($dias !== '—' && $dias < 7) {
            $estado = 'Crítico';
        } elseif ($dias !== '—' && $dias < 15) {
            $estado = 'Reabastecer pronto';
        }

        $predicciones[] = [
            'id_producto' => (int) $p['id_producto'],
            'nombre' => $p['nombre'],
            'stock' => $stock,
            'ventas' => $ventasTotales,
            'promedio' => round($promedio, 2),
            'dias_restantes' => $dias,
            'sin_configuracion' => $sinConfig,
            'estado' => $estado,
        ];
    }

    return $predicciones;
}

/**
 * Cruza campanas registradas (calendario_campanas) con la prediccion de
 * inventario: devuelve una alerta por cada producto destacado de una campana
 * cuyo stock se agotaria antes de que termine dicha campana. Pura (sin PDO)
 * para poder probarla con fechas y datos fabricados.
 *
 * @param array<int, array{nombre:string, fecha_fin:string, productos_destacados: ?string}> $campanas
 * @param array<int, array{id_producto:int, nombre:string, dias_restantes:int|string}> $predicciones
 * @param string $hoy Fecha de referencia en formato Y-m-d (por defecto, hoy).
 * @return array<int, array{campana:string, producto:string, dias_restantes_stock:int, dias_para_fin_campana:int, fecha_fin_campana:string}>
 */
function getCampaignStockAlerts(array $campanas, array $predicciones, ?string $hoy = null): array
{
    $hoy = $hoy ?? date('Y-m-d');

    $prediccionesPorId = [];
    foreach ($predicciones as $pred) {
        if (!isset($pred['id_producto'])) {
            continue;
        }
        $prediccionesPorId[(int) $pred['id_producto']] = $pred;
    }

    $alertas = [];
    foreach ($campanas as $campana) {
        $rawProductos = trim((string) ($campana['productos_destacados'] ?? ''));
        if ($rawProductos === '') {
            continue;
        }

        $fechaFinTimestamp = strtotime((string) ($campana['fecha_fin'] ?? ''));
        $hoyTimestamp = strtotime($hoy);
        if ($fechaFinTimestamp === false || $hoyTimestamp === false) {
            // Fecha corrupta/no parseable: no se puede calcular el cruce, se omite
            // la campana en vez de tronar o inventar una fecha.
            continue;
        }
        $diasParaFin = (int) (($fechaFinTimestamp - $hoyTimestamp) / 86400);

        $idsDestacados = array_unique(array_filter(array_map('intval', explode(',', $rawProductos)), static fn($id) => $id > 0));

        foreach ($idsDestacados as $idProducto) {
            $pred = $prediccionesPorId[$idProducto] ?? null;
            if ($pred === null || !isset($pred['dias_restantes']) || !is_int($pred['dias_restantes'])) {
                continue;
            }

            if ($pred['dias_restantes'] < max(0, $diasParaFin)) {
                $alertas[] = [
                    'campana' => (string) ($campana['nombre'] ?? ''),
                    'producto' => (string) $pred['nombre'],
                    'dias_restantes_stock' => $pred['dias_restantes'],
                    'dias_para_fin_campana' => $diasParaFin,
                    'fecha_fin_campana' => (string) ($campana['fecha_fin'] ?? ''),
                ];
            }
        }
    }

    return $alertas;
}
