<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/articulo_libre_utils.php';

requireAuth();
// Reporte de ventas de articulos libres (fuera de catalogo): mismo permiso que venderlos.
if (!hasPermission('vender_articulo_libre')) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$pageTitle = 'Artículos libres';
$pdo = getPDO();
$rango = articuloLibreRangoMes(isset($_GET['mes']) ? (string) $_GET['mes'] : null);

// Alcance de datos (el permiso abre la vista; el rol solo acota): admin ve todo, encargado
// su sucursal, cualquier otro solo sus propias ventas.
$idAlmacenScope = null;
$idUsuarioScope = null;
$alcanceTexto = 'Todas las sucursales';
if (!isAdmin()) {
    if (isEncargado() && getCurrentAlmacenId() !== null) {
        $idAlmacenScope = getCurrentAlmacenId();
        $alcanceTexto = 'Tu sucursal';
    } else {
        $idUsuarioScope = (int) ($_SESSION['usuario']['id_usuario'] ?? 0);
        $alcanceTexto = 'Tus ventas';
    }
}

$error = '';
$lineas = [];
try {
    $lineas = articuloLibreFetchLineas($pdo, $rango['inicio'], $rango['fin'], $idAlmacenScope, $idUsuarioScope);
} catch (Throwable $e) {
    error_log('articulos_libres: ' . $e->getMessage());
    $error = 'No se pudo cargar el reporte. Si es la primera vez, verifica que la migración de artículos libres ya se aplicó.';
}
$resumen = articuloLibreResumir($lineas);
$totales = $resumen['totales'];
$fmt = static fn(float $n): string => '$' . number_format($n, 2);

$mesAnterior = (new DateTimeImmutable($rango['inicio']))->modify('-1 month')->format('Y-m');
$mesSiguiente = (new DateTimeImmutable($rango['inicio']))->modify('+1 month')->format('Y-m');
$esMesActual = $rango['mes'] === date('Y-m');
$mesesNombre = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
$inicioDt = new DateTimeImmutable($rango['inicio']);
$mesTexto = ucfirst($mesesNombre[(int) $inicioDt->format('n') - 1]) . ' ' . $inicioDt->format('Y');

include __DIR__ . '/includes/header.php';
?>
<div class="container">
    <div class="row" style="margin-top: 20px; margin-bottom: 0;">
        <div class="col s12" style="display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:10px;">
            <div>
                <h4 style="margin:0;"><i class="material-icons left deep-purple-text" style="font-size:2.2rem;">add_box</i>Artículos libres</h4>
                <p class="grey-text text-darken-1" style="margin:4px 0 0;">Ventas de productos fuera de catálogo (otras marcas). Ya están incluidas en las ventas y la ganancia del mes del dashboard. · <?php echo esc($alcanceTexto); ?></p>
            </div>
            <div style="display:flex; align-items:center; gap:6px;">
                <a class="btn-flat waves-effect" href="?mes=<?php echo esc($mesAnterior); ?>" title="Mes anterior"><i class="material-icons">chevron_left</i></a>
                <strong><?php echo esc($mesTexto); ?></strong>
                <?php if (!$esMesActual): ?>
                    <a class="btn-flat waves-effect" href="?mes=<?php echo esc($mesSiguiente); ?>" title="Mes siguiente"><i class="material-icons">chevron_right</i></a>
                <?php endif; ?>
                <a class="btn-small waves-effect waves-light blue darken-3" href="<?php echo BASE_URL; ?>views/sales.php"><i class="material-icons left">point_of_sale</i>Ir a ventas</a>
            </div>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="card-panel red lighten-4 red-text text-darken-4"><?php echo esc($error); ?></div>
    <?php endif; ?>

    <div class="row">
        <?php
        $tarjetas = [
            ['Ventas', $fmt($totales['ventas']), 'blue-text text-darken-3'],
            ['Costo', $fmt($totales['costo']), 'grey-text text-darken-2'],
            ['Ganancia', $fmt($totales['ganancia']), $totales['ganancia'] < 0 ? 'red-text text-darken-2' : 'green-text text-darken-2'],
            ['Piezas', (string) $totales['piezas'], 'deep-purple-text'],
        ];
        foreach ($tarjetas as [$titulo, $valor, $clase]): ?>
            <div class="col s6 m3">
                <div class="card-panel" style="padding:14px 16px;">
                    <div class="grey-text" style="font-size:0.85rem;"><?php echo esc($titulo); ?></div>
                    <div class="<?php echo esc($clase); ?>" style="font-size:1.5rem; font-weight:700;"><?php echo esc($valor); ?></div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <div class="card-content">
            <span class="card-title">Por marca</span>
            <?php if ($resumen['por_marca'] === []): ?>
                <p class="grey-text">Sin ventas de artículos libres en <?php echo esc($mesTexto); ?>.</p>
            <?php else: ?>
                <div style="overflow-x:auto;">
                <table class="striped">
                    <thead><tr><th>Marca</th><th class="right-align">Piezas</th><th class="right-align">Ventas</th><th class="right-align">Costo</th><th class="right-align">Ganancia</th><th class="right-align">Margen</th></tr></thead>
                    <tbody>
                    <?php foreach ($resumen['por_marca'] as $m): ?>
                        <tr>
                            <td><?php echo esc($m['marca']); ?></td>
                            <td class="right-align"><?php echo (int) $m['piezas']; ?></td>
                            <td class="right-align"><?php echo esc($fmt($m['ventas'])); ?></td>
                            <td class="right-align"><?php echo esc($fmt($m['costo'])); ?></td>
                            <td class="right-align <?php echo $m['ganancia'] < 0 ? 'red-text' : 'green-text text-darken-2'; ?>"><?php echo esc($fmt($m['ganancia'])); ?></td>
                            <td class="right-align"><?php echo $m['margen_pct'] !== null ? esc(number_format($m['margen_pct'], 1) . '%') : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($lineas !== []): ?>
    <div class="card">
        <div class="card-content">
            <span class="card-title">Detalle de ventas</span>
            <div style="overflow-x:auto;">
            <table class="striped">
                <thead><tr><th>Fecha</th><th>Folio</th><th>Marca</th><th>Descripción</th><th class="right-align">Cant.</th><th class="right-align">Costo c/u</th><th class="right-align">Precio c/u</th><th class="right-align">Desc.</th><th class="right-align">Cobrado</th><th class="right-align">Ganancia</th><th>Vendió</th></tr></thead>
                <tbody>
                <?php foreach ($lineas as $l):
                    $costoLinea = (float) $l['costo_unitario'] * (int) $l['cantidad'];
                    $gananciaLinea = round((float) $l['subtotal'] - $costoLinea, 2); ?>
                    <tr>
                        <td style="white-space:nowrap;"><?php echo esc(date('d/m/Y H:i', strtotime((string) $l['fecha_creacion']))); ?></td>
                        <td style="white-space:nowrap;"><?php echo esc((string) $l['numero_pedido']); ?></td>
                        <td><?php echo esc((string) $l['marca_libre']); ?></td>
                        <td><?php echo esc((string) $l['descripcion_libre']); ?></td>
                        <td class="right-align"><?php echo (int) $l['cantidad']; ?></td>
                        <td class="right-align"><?php echo esc($fmt((float) $l['costo_unitario'])); ?></td>
                        <td class="right-align"><?php echo esc($fmt((float) $l['precio_unitario'])); ?></td>
                        <td class="right-align"><?php echo (float) $l['monto_descuento'] > 0 ? esc($fmt((float) $l['monto_descuento'])) : '—'; ?></td>
                        <td class="right-align"><?php echo esc($fmt((float) $l['subtotal'])); ?></td>
                        <td class="right-align <?php echo $gananciaLinea < 0 ? 'red-text' : 'green-text text-darken-2'; ?>"><?php echo esc($fmt($gananciaLinea)); ?></td>
                        <td><?php echo esc((string) ($l['vendedor'] ?? '')); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
