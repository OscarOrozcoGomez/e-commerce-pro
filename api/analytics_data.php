<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/finance_utils.php';

header('Content-Type: application/json');

if (!isAuthenticated() || !isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$pdo = getPDO();

try {
    // 1. Ventas por Mes (Tendencia Anual)
    $sqlVentasMes = "SELECT MONTH(fecha_creacion) as mes, SUM(total) as total 
                    FROM pedidos WHERE estado != 'cancelado' AND YEAR(fecha_creacion) = YEAR(NOW())
                    GROUP BY mes ORDER BY mes";
    $ventasMesRaw = $pdo->query($sqlVentasMes)->fetchAll(PDO::FETCH_KEY_PAIR);
    
    $ventas_mensuales = [];
    for ($i = 1; $i <= 12; $i++) {
        $ventas_mensuales[] = (float)($ventasMesRaw[$i] ?? 0);
    }

    // 2. Top 10 Productos
    $sqlTop = "SELECT p.id_producto, p.nombre, SUM(dp.cantidad) as cantidad 
               FROM detalle_pedidos dp 
               JOIN productos p ON dp.id_producto = p.id_producto 
               GROUP BY dp.id_producto ORDER BY cantidad DESC LIMIT 10";
    $top_productos = $pdo->query($sqlTop)->fetchAll();

    // 3. Predicción de Inventario
    $totalDiasResult = $pdo->query("SELECT DATEDIFF(NOW(), MIN(fecha_creacion)) + 1 FROM pedidos")->fetchColumn();
    $totalDias = ($totalDiasResult && $totalDiasResult > 0) ? (int)$totalDiasResult : 1;

    $sqlPred = "SELECT p.id_producto, p.nombre, p.precio_venta, p.precio_costo, ia.cantidad_actual,
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
                ORDER BY ia.cantidad_actual ASC LIMIT 25";
    $rawPredicciones = $pdo->query($sqlPred)->fetchAll();

    $predicciones = [];
    foreach ($rawPredicciones as $p) {
        $promedio = (float)$p['promedio_diario'];
        $stock = (int)$p['cantidad_actual'];
        $ventasTotales = (int)$p['ventas_totales'];
        $dias = $ventasTotales > 0 && $promedio > 0 ? floor($stock / $promedio) : '—';
        $sinConfig = ((float)$p['precio_venta'] <= 0 || (float)$p['precio_costo'] <= 0);
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
            'estado' => $estado
        ];
    }

    echo json_encode([
        'success' => true,
        'total_dias_historial' => $totalDias,
        'ventas_mensuales' => $ventas_mensuales,
        'top_productos' => $top_productos,
        'predicciones' => $predicciones
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}