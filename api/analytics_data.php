<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/finance_utils.php';
require_once __DIR__ . '/../core/stock_prediction.php';

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
    $predicciones = getPrediccionesInventario($pdo, 25);

    echo json_encode([
        'success' => true,
        'total_dias_historial' => getTotalDiasHistorial($pdo),
        'ventas_mensuales' => $ventas_mensuales,
        'top_productos' => $top_productos,
        'predicciones' => $predicciones
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}