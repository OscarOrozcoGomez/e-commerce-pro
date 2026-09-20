<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/inventory_recommendations.php';

header('Content-Type: application/json');

// Refresca permisos por si se revocaron/concedieron desde el panel hace poco.
refreshSessionPermissions();
// Permiso 'ver_analitica_negocio' abre este endpoint; el admin entra siempre (short-circuit).
if (!isAuthenticated() || (!hasPermission('ver_analitica_negocio') && !isAdmin())) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$pdo = getPDO();

try {
    // Los productos de pruebas automatizadas (Playwright/E2E) se dejan fuera de todo: contaminaban el
    // top de ventas y las tendencias con cientos de piezas ficticias.
    $noPrueba = analiticaSqlProductoNoPrueba('pr');

    // 1. Ventas por Mes (Tendencia Anual): pedidos no cancelados y sin lineas de productos de prueba.
    $sqlVentasMes = "SELECT MONTH(pe.fecha_creacion) as mes, SUM(pe.total) as total
                    FROM pedidos pe
                    WHERE pe.estado != 'cancelado' AND YEAR(pe.fecha_creacion) = YEAR(NOW())
                      AND NOT EXISTS (
                          SELECT 1 FROM detalle_pedidos dpx
                          JOIN productos pr ON pr.id_producto = dpx.id_producto
                          WHERE dpx.id_pedido = pe.id_pedido AND NOT {$noPrueba}
                      )
                    GROUP BY mes ORDER BY mes";
    $ventasMesRaw = $pdo->query($sqlVentasMes)->fetchAll(PDO::FETCH_KEY_PAIR);

    $ventas_mensuales = [];
    for ($i = 1; $i <= 12; $i++) {
        $ventas_mensuales[] = (float)($ventasMesRaw[$i] ?? 0);
    }

    // 2. Top 10 productos: sin cancelados, sin lo que el cliente rechazo en la entrega y sin pruebas.
    $sqlTop = "SELECT pr.id_producto, pr.nombre, SUM(dp.cantidad) as cantidad
               FROM detalle_pedidos dp
               JOIN pedidos pe ON pe.id_pedido = dp.id_pedido
               JOIN productos pr ON dp.id_producto = pr.id_producto
               WHERE pe.estado != 'cancelado'
                 AND (dp.estado_entrega IS NULL OR dp.estado_entrega != 'rechazado')
                 AND {$noPrueba}
               GROUP BY dp.id_producto ORDER BY cantidad DESC LIMIT 10";
    $top_productos = $pdo->query($sqlTop)->fetchAll();

    // 3. Que reponer / poner en aparador / mover (reglas explicables; ver core/inventory_recommendations.php)
    $recomendaciones = analiticaRecomendaciones($pdo, 40);

    echo json_encode([
        'success' => true,
        'ventas_mensuales' => $ventas_mensuales,
        'top_productos' => $top_productos,
        'recomendaciones' => $recomendaciones,
    ]);
} catch (Throwable $e) {
    // Throwable, no solo Exception: un \Error/\TypeError sin atrapar se escaparia al
    // set_exception_handler global (redirect HTML a views/error.php) y el fetch() del
    // cliente lo recibiria como "Unexpected token '<'" al hacer r.json().
    http_response_code(200);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}