<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json');
if (!isAuthenticated()) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$pdo = getPDO();
$usuario = $_SESSION['usuario'];
$rol = $usuario['rol'];
$idAlmacen = $usuario['id_almacen'];
$idUsuario = $usuario['id_usuario'];

try {
    $stats = [];

    if ($rol === 'admin') {
        // Ventas hoy
        $stmt = $pdo->query("SELECT COUNT(*) as total, COALESCE(SUM(total), 0) as monto FROM pedidos WHERE DATE(fecha_creacion) = CURDATE() AND estado != 'cancelado'");
        $stats['ventas_hoy'] = $stmt->fetch();

        // Clientes y Usuarios
        $stats['clientes'] = $pdo->query("SELECT COUNT(*) as total FROM clientes WHERE estado = 'activo'")->fetch();
        $stats['usuarios'] = $pdo->query("SELECT COUNT(*) as total FROM usuarios WHERE estado = 'activo'")->fetch();
        $stats['productos'] = $pdo->query("SELECT COUNT(*) as total FROM productos WHERE estado = 'activo'")->fetch();
        
        // Ingresos mes
        $stats['ingresos_mes'] = $pdo->query("SELECT COALESCE(SUM(total), 0) as total FROM pedidos WHERE YEAR(fecha_creacion) = YEAR(NOW()) AND MONTH(fecha_creacion) = MONTH(NOW()) AND estado != 'cancelado'")->fetch();
        
        // Stock bajo
        $stats['stock_bajo'] = $pdo->query("SELECT COUNT(*) as total FROM inventario_almacen ia JOIN productos p ON ia.id_producto = p.id_producto WHERE ia.cantidad_actual <= ia.stock_minimo AND p.estado = 'activo'")->fetch();
        
        // Auditoría
        $stats['incompletos'] = $pdo->query("SELECT COUNT(DISTINCT p.id_producto) as total FROM productos p LEFT JOIN inventario_almacen ia ON p.id_producto = ia.id_producto WHERE p.precio_venta <= 0 OR p.precio_costo <= 0 OR ia.id_producto IS NULL")->fetch();

        // Blogs
        $stats['blogs'] = $pdo->query("SELECT COUNT(*) as total FROM blogs")->fetch();

    } elseif ($rol === 'encargado') {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total, COALESCE(SUM(total), 0) as monto FROM pedidos WHERE id_almacen = ? AND DATE(fecha_creacion) = CURDATE() AND estado != 'cancelado'");
        $stmt->execute([$idAlmacen]);
        $stats['ventas_hoy'] = $stmt->fetch();

        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM inventario_almacen WHERE id_almacen = ?");
        $stmt->execute([$idAlmacen]);
        $stats['productos'] = $stmt->fetch();

        $stats['ingresos_mes'] = $pdo->prepare("SELECT COALESCE(SUM(total), 0) as total FROM pedidos WHERE id_almacen = ? AND YEAR(fecha_creacion) = YEAR(NOW()) AND MONTH(fecha_creacion) = MONTH(NOW()) AND estado != 'cancelado'");
        $stats['ingresos_mes']->execute([$idAlmacen]);
        $stats['ingresos_mes'] = $stats['ingresos_mes']->fetch();

        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM inventario_almacen ia JOIN productos p ON ia.id_producto = p.id_producto WHERE ia.id_almacen = ? AND ia.cantidad_actual <= ia.stock_minimo AND p.estado = 'activo'");
        $stmt->execute([$idAlmacen]);
        $stats['stock_bajo'] = $stmt->fetch();

        $stmt = $pdo->prepare("SELECT COUNT(*) as total FROM pedidos WHERE id_repartidor IS NOT NULL AND estado IN ('pendiente_pago','pagado','en_reparto') AND id_almacen = ?");
        $stmt->execute([$idAlmacen]);
        $stats['por_entregar'] = $stmt->fetch();

    } elseif ($rol === 'repartidor') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM pedidos WHERE id_repartidor = ? AND estado IN ('pendiente_pago','pagado','en_reparto')");
        $stmt->execute([$idUsuario]);
        $stats['entregas_hoy'] = ['total' => $stmt->fetchColumn()];

    } elseif ($rol === 'vendedor') {
        $stmt = $pdo->prepare("SELECT COUNT(*) as total, COALESCE(SUM(total), 0) as monto FROM pedidos WHERE id_usuario = ? AND DATE(fecha_creacion) = CURDATE() AND estado != 'cancelado'");
        $stmt->execute([$idUsuario]);
        $stats['ventas_hoy'] = $stmt->fetch();

        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT id_cliente) as total FROM pedidos WHERE id_usuario = ? AND MONTH(fecha_creacion) = MONTH(NOW())");
        $stmt->execute([$idUsuario]);
        $stats['clientes_mes'] = $stmt->fetch();

        $stmt = $pdo->prepare("SELECT COALESCE(SUM(total), 0) as total FROM pedidos WHERE id_usuario = ? AND YEAR(fecha_creacion) = YEAR(NOW()) AND MONTH(fecha_creacion) = MONTH(NOW()) AND estado != 'cancelado'");
        $stmt->execute([$idUsuario]);
        $stats['ingresos_mes'] = $stmt->fetch();
    }

    echo json_encode(['success' => true, 'data' => $stats]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}