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
    $ventas = dbGetSalesReport(
        $fecha_inicio,
        $fecha_fin,
        (int)($usuario['id_almacen'] ?? 0),
        (int)$usuario['id_usuario'],
        isAdmin()
    );

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=reporte_ventas_' . $fecha_inicio . '_a_' . $fecha_fin . '.csv');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, ['Número Pedido', 'Vendedor', 'Almacén', 'Método Pago', 'Productos Vendidos', 'Total', 'Fecha']);
    
    foreach ($ventas as $v) {
        $productosRaw = (string)($v['productos_vendidos'] ?? '');
        $productosLista = array_values(array_filter(array_map('trim', explode('|', $productosRaw))));
        $productosCsv = 'Sin detalle';
        if (!empty($productosLista)) {
            $productosCsv = "- " . implode(PHP_EOL . '- ', $productosLista);
        }

        fputcsv($output, [
            $v['numero_pedido'],
            $v['vendedor'],
            $v['almacen'],
            $v['metodo'] ?? 'N/A',
            $productosCsv,
            number_format((float)$v['total'], 2, '.', ''),
            date('d/m/Y H:i', strtotime($v['fecha_creacion']))
        ]);
    }
    fclose($output);
    exit;
} catch (Exception $e) {
    die("Error al generar el reporte: " . $e->getMessage());
}