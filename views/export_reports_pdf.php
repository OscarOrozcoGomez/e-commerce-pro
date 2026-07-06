<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../vendor/autoload.php';

use Dompdf\Dompdf;
use Dompdf\Options;

requireAuth();
requirePermission('ver_reportes', BASE_URL . 'views/dashboard.php');

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

    $totalVentas = 0.0;
    foreach ($ventas as $venta) {
        $totalVentas += (float)($venta['total'] ?? 0);
    }
    $cantidadVentas = count($ventas);
    $promedioVenta = $cantidadVentas > 0 ? ($totalVentas / $cantidadVentas) : 0.0;

    $h = static function (mixed $value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    };

    $rowsHtml = '';
    foreach ($ventas as $venta) {
        $productosRaw = (string)($venta['productos_vendidos'] ?? '');
        $productosLista = array_values(array_filter(array_map('trim', explode('|', $productosRaw))));
        $productosHtml = 'Sin detalle';
        if (!empty($productosLista)) {
            $items = '';
            foreach ($productosLista as $productoItem) {
                $items .= '<li>' . $h($productoItem) . '</li>';
            }
            $productosHtml = '<ul class="products-list">' . $items . '</ul>';
        }

        $rowsHtml .= '<tr>'
            . '<td>' . $h($venta['numero_pedido'] ?? '') . '</td>'
            . '<td>' . $h($venta['vendedor'] ?? '') . '</td>'
            . '<td>' . $h($venta['almacen'] ?? '') . '</td>'
            . '<td>' . $h($venta['metodo'] ?? 'N/A') . '</td>'
            . '<td>' . $productosHtml . '</td>'
            . '<td>$' . number_format((float)($venta['total'] ?? 0), 2) . '</td>'
            . '<td>' . date('d/m/Y H:i', strtotime((string)($venta['fecha_creacion'] ?? 'now'))) . '</td>'
            . '</tr>';
    }

    if ($rowsHtml === '') {
        $rowsHtml = '<tr><td colspan="7" style="text-align:center;">No hay ventas en el periodo especificado.</td></tr>';
    }

    $html = '<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #222; }
    .header { margin-bottom: 12px; }
    .title { font-size: 18px; font-weight: bold; margin: 0 0 4px 0; }
    .meta { font-size: 11px; color: #444; margin: 2px 0; }
    .summary { margin: 8px 0 14px 0; }
    .summary span { display: inline-block; margin-right: 14px; font-size: 11px; }
    table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    th, td { border: 1px solid #cfcfcf; padding: 6px; vertical-align: top; word-wrap: break-word; }
    th { background: #f2f2f2; font-weight: bold; }
    .products-list { margin: 0; padding-left: 14px; }
    .products-list li { margin: 0 0 3px 0; }
</style>
</head>
<body>
    <div class="header">
        <p class="title">Reporte de Ventas</p>
        <p class="meta">Rango: ' . $h($fecha_inicio) . ' a ' . $h($fecha_fin) . '</p>
        <p class="meta">Generado: ' . date('d/m/Y H:i') . '</p>
    </div>
    <div class="summary">
        <span>Total ventas: <strong>' . $h((string)$cantidadVentas) . '</strong></span>
        <span>Monto total: <strong>$' . number_format($totalVentas, 2) . '</strong></span>
        <span>Promedio: <strong>$' . number_format($promedioVenta, 2) . '</strong></span>
    </div>
    <table>
        <thead>
            <tr>
                <th style="width: 12%;">Pedido</th>
                <th style="width: 13%;">Vendedor</th>
                <th style="width: 12%;">Almacen</th>
                <th style="width: 10%;">Metodo</th>
                <th style="width: 33%;">Productos vendidos</th>
                <th style="width: 10%;">Monto</th>
                <th style="width: 10%;">Fecha</th>
            </tr>
        </thead>
        <tbody>' . $rowsHtml . '</tbody>
    </table>
</body>
</html>';

    $options = new Options();
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();
    $dompdf->stream('reporte_ventas_' . $fecha_inicio . '_a_' . $fecha_fin . '.pdf', ['Attachment' => false]);
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    echo 'Error al generar PDF: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
