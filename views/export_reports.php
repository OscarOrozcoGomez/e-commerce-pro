<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
requirePermission('ver_reportes', BASE_URL . 'views/dashboard.php');

$pdo = getPDO();
$usuario = $_SESSION['usuario'];

$fecha_inicio = isset($_GET['fecha_inicio']) ? htmlspecialchars($_GET['fecha_inicio']) : date('Y-m-01');
$fecha_fin = isset($_GET['fecha_fin']) ? htmlspecialchars($_GET['fecha_fin']) : date('Y-m-d');

try {
    if (isAdmin()) {
        $sql = "SELECT p.numero_pedido, u.nombre as vendedor, a.nombre as almacen, mp.nombre as metodo, p.total, p.fecha_creacion
                FROM pedidos p
                JOIN usuarios u ON p.id_usuario = u.id_usuario
                JOIN almacenes a ON p.id_almacen = a.id_almacen
                LEFT JOIN metodos_pago mp ON p.id_metodo_pago = mp.id_metodo
                WHERE DATE(p.fecha_creacion) BETWEEN :inicio AND :fin
                AND p.estado != 'cancelado'
                ORDER BY p.fecha_creacion DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':inicio' => $fecha_inicio, ':fin' => $fecha_fin]);
    } else {
        $sql = "SELECT p.numero_pedido, u.nombre as vendedor, a.nombre as almacen, mp.nombre as metodo, p.total, p.fecha_creacion
                FROM pedidos p
                JOIN usuarios u ON p.id_usuario = u.id_usuario
                JOIN almacenes a ON p.id_almacen = a.id_almacen
                LEFT JOIN metodos_pago mp ON p.id_metodo_pago = mp.id_metodo
                WHERE (p.id_usuario = :usuario OR p.id_almacen = :almacen)
                AND DATE(p.fecha_creacion) BETWEEN :inicio AND :fin
                AND p.estado != 'cancelado'
                ORDER BY p.fecha_creacion DESC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':usuario' => $usuario['id_usuario'],
            ':almacen' => $usuario['id_almacen'] ?? 0,
            ':inicio' => $fecha_inicio,
            ':fin' => $fecha_fin,
        ]);
    }
    
    $ventas = $stmt->fetchAll();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=reporte_ventas_' . $fecha_inicio . '_a_' . $fecha_fin . '.csv');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, ['Número Pedido', 'Vendedor', 'Almacén', 'Método Pago', 'Total', 'Fecha']);
    
    foreach ($ventas as $v) {
        fputcsv($output, [
            $v['numero_pedido'],
            $v['vendedor'],
            $v['almacen'],
            $v['metodo'] ?? 'N/A',
            number_format((float)$v['total'], 2, '.', ''),
            date('d/m/Y H:i', strtotime($v['fecha_creacion']))
        ]);
    }
    fclose($output);
    exit;
} catch (Exception $e) {
    die("Error al generar el reporte: " . $e->getMessage());
}