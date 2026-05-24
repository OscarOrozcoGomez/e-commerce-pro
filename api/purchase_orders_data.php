<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json');
if (!isAuthenticated() || (!isAdmin() && !isEncargado())) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$pdo = getPDO();
$idAlmacen = getCurrentAlmacenId();

try {
    // 1. Obtener productos bajo el stock mínimo
    $sql = "SELECT p.id_producto, p.nombre, p.sku, p.precio_costo, p.precio_venta, ia.cantidad_actual, ia.stock_minimo, ia.stock_maximo, a.nombre as sucursal, ia.id_almacen
            FROM productos p
            JOIN inventario_almacen ia ON p.id_producto = ia.id_producto
            JOIN almacenes a ON ia.id_almacen = a.id_almacen
            WHERE ia.cantidad_actual <= ia.stock_minimo AND p.estado = 'activo'";

    if (!isAdmin()) {
        $sql .= " AND ia.id_almacen = " . (int)$idAlmacen;
    }
    $sql .= " ORDER BY a.nombre, p.nombre";
    $listaCompra = $pdo->query($sql)->fetchAll();

    // 2. Obtener datos para el gráfico
    $sqlChart = "SELECT COALESCE(c.nombre, 'Sin Categoría') as categoria, COUNT(DISTINCT p.id_producto) as total
                 FROM productos p
                 JOIN inventario_almacen ia ON p.id_producto = ia.id_producto
                 LEFT JOIN producto_categorias pc ON p.id_producto = pc.id_producto
                 LEFT JOIN categorias c ON pc.id_categoria = c.id_categoria
                 WHERE ia.cantidad_actual <= ia.stock_minimo AND p.estado = 'activo'";

    if (!isAdmin()) {
        $sqlChart .= " AND ia.id_almacen = " . (int)$idAlmacen;
    }
    $sqlChart .= " GROUP BY categoria ORDER BY total DESC";
    $chartData = $pdo->query($sqlChart)->fetchAll();

    echo json_encode([
        'success' => true,
        'listaCompra' => $listaCompra,
        'chartData' => $chartData
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}