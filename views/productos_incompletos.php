<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
requirePermission('gestionar_productos', BASE_URL . 'views/dashboard.php');

$pageTitle = 'Productos sin configuración';
$pdo = getPDO();

$error = '';
$productos = [];
$totalProblemas = 0;
$resumen = [
    'sin_precio_venta' => 0,
    'sin_precio_costo' => 0,
    'sin_sku' => 0,
    'sin_codigo_barras' => 0,
    'sin_inventario' => 0,
];

// Filtro opcional: mostrar solo los que les falta un dato concreto (chips de arriba).
$faltaFiltro = trim((string)($_GET['falta'] ?? ''));
$faltasValidas = ['precio_venta', 'precio_costo', 'sku', 'codigo_barras', 'inventario'];
if (!in_array($faltaFiltro, $faltasValidas, true)) {
    $faltaFiltro = '';
}

try {
    // Deteccion en caliente de la columna sku (algunos despliegues antiguos no la tienen).
    $stmtSku = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'sku'");
    $stmtSku->execute();
    $hasSku = ((int)$stmtSku->fetchColumn()) > 0;

    $skuFaltaExpr = $hasSku ? "(p.sku IS NULL OR TRIM(p.sku) = '')" : '0';
    $precioVentaFaltaExpr = '(p.precio_venta <= 0)';
    $precioCostoFaltaExpr = '(p.precio_costo <= 0)';
    $codigoBarrasFaltaExpr = "(p.codigo_barras IS NULL OR TRIM(p.codigo_barras) = '')";
    $inventarioFaltaExpr = 'NOT EXISTS (SELECT 1 FROM inventario_almacen ia WHERE ia.id_producto = p.id_producto)';

    // Solo productos "vendibles": se excluyen los archivados y los productos padre que tienen
    // variantes (esos no llevan precio/inventario propios; los datos van en cada variante).
    $sql = "SELECT * FROM (
                SELECT
                    p.id_producto,
                    p.nombre,
                    p.nombre_variante,
                    p.categoria,
                    p.estado,
                    p.id_padre,
                    {$precioVentaFaltaExpr}   AS falta_precio_venta,
                    {$precioCostoFaltaExpr}   AS falta_precio_costo,
                    {$skuFaltaExpr}           AS falta_sku,
                    {$codigoBarrasFaltaExpr}  AS falta_codigo_barras,
                    {$inventarioFaltaExpr}    AS falta_inventario
                FROM productos p
                WHERE p.estado <> 'archivado'
                  AND NOT EXISTS (SELECT 1 FROM productos hijo WHERE hijo.id_padre = p.id_producto)
            ) t
            WHERE (
                t.falta_precio_venta = 1
                OR t.falta_precio_costo = 1
                OR t.falta_sku = 1
                OR t.falta_codigo_barras = 1
                OR t.falta_inventario = 1
            )
            ORDER BY (t.falta_precio_venta + t.falta_precio_costo + t.falta_sku + t.falta_codigo_barras + t.falta_inventario) DESC,
                     t.nombre ASC, t.nombre_variante ASC
            LIMIT 1000";

    $productosRaw = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    $totalProblemas = count($productosRaw);

    foreach ($productosRaw as $row) {
        $flags = [
            'precio_venta' => (int)($row['falta_precio_venta'] ?? 0) === 1,
            'precio_costo' => (int)($row['falta_precio_costo'] ?? 0) === 1,
            'sku' => (int)($row['falta_sku'] ?? 0) === 1,
            'codigo_barras' => (int)($row['falta_codigo_barras'] ?? 0) === 1,
            'inventario' => (int)($row['falta_inventario'] ?? 0) === 1,
        ];

        if ($flags['precio_venta']) { $resumen['sin_precio_venta']++; }
        if ($flags['precio_costo']) { $resumen['sin_precio_costo']++; }
        if ($flags['sku']) { $resumen['sin_sku']++; }
        if ($flags['codigo_barras']) { $resumen['sin_codigo_barras']++; }
        if ($flags['inventario']) { $resumen['sin_inventario']++; }

        // Si hay filtro activo, saltar los que no aplican (el resumen si cuenta el total real).
        if ($faltaFiltro !== '' && empty($flags[$faltaFiltro])) {
            continue;
        }

        $row['_flags'] = $flags;
        $productos[] = $row;
    }
} catch (Throwable $e) {
    $error = 'No se pudo obtener el listado: ' . $e->getMessage();
    $productos = [];
}

include __DIR__ . '/includes/header.php';

$faltaLabels = [
    'precio_venta' => 'Precio de venta',
    'precio_costo' => 'Precio de costo',
    'sku' => 'SKU',
    'codigo_barras' => 'Codigo de barras',
    'inventario' => 'Inventario base',
];
?>

<div class="container">
    <div class="row">
        <div class="col s12">
            <div class="pi-header">
                <h4 style="margin:0;"><i class="material-icons left">rule</i> Productos sin configuración</h4>
                <a href="<?php echo BASE_URL; ?>views/dashboard.php" class="btn blue darken-4 waves-effect waves-light">
                    <i class="material-icons left">dashboard</i> Volver al Dashboard
                </a>
            </div>
            <p class="grey-text">
                Aqui aparecen los productos vendibles que todavia no tienen algun dato clave:
                precio de venta, precio de costo, SKU, codigo de barras o inventario base.
                Ve completandolos poco a poco; cada boton <strong>Completar</strong> abre el producto listo para editar.
            </p>
        </div>
    </div>

    <?php if ($error !== ''): ?>
        <div class="card-panel red lighten-4 red-text text-darken-4"><?php echo esc($error); ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col s12">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Resumen de pendientes</span>
                    <div class="pi-chips">
                        <?php
                            $chips = [
                                ['', 'Ver todos', $totalProblemas, 'blue-grey'],
                                ['precio_venta', 'Sin precio venta', $resumen['sin_precio_venta'], 'red darken-1'],
                                ['precio_costo', 'Sin precio costo', $resumen['sin_precio_costo'], 'red darken-1'],
                                ['sku', 'Sin SKU', $resumen['sin_sku'], 'deep-orange darken-1'],
                                ['codigo_barras', 'Sin codigo de barras', $resumen['sin_codigo_barras'], 'deep-orange darken-1'],
                                ['inventario', 'Sin inventario base', $resumen['sin_inventario'], 'amber darken-3'],
                            ];
                        ?>
                        <?php foreach ($chips as [$key, $label, $num, $color]): ?>
                            <?php $activo = ($faltaFiltro === $key); ?>
                            <a href="?<?php echo $key === '' ? '' : ('falta=' . urlencode($key)); ?>"
                               class="pi-chip <?php echo $color; ?> <?php echo $activo ? 'pi-chip-active' : ''; ?> white-text">
                                <?php echo esc($label); ?>
                                <span class="pi-chip-count"><?php echo (int)$num; ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($faltaFiltro !== ''): ?>
                        <p class="grey-text" style="margin:12px 0 0;">
                            Mostrando solo los que les falta: <strong><?php echo esc($faltaLabels[$faltaFiltro] ?? $faltaFiltro); ?></strong>.
                            <a href="?">Quitar filtro</a>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col s12">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">
                        Listado (<?php echo (int)count($productos); ?><?php echo count($productos) >= 1000 ? '+' : ''; ?>)
                    </span>

                    <?php if (empty($productos)): ?>
                        <p class="center grey-text" style="padding:30px 0;">
                            <i class="material-icons" style="font-size:3rem; display:block;">check_circle</i>
                            <?php echo $faltaFiltro !== '' ? 'Ningun producto con ese pendiente. ¡Bien!' : 'Todos los productos vendibles tienen su configuración completa. ¡Bien!'; ?>
                        </p>
                    <?php else: ?>
                        <div class="pi-table-scroll">
                            <table class="striped highlight pi-table">
                                <thead>
                                    <tr>
                                        <th>Producto</th>
                                        <th>Categoría</th>
                                        <th>Estado</th>
                                        <th>Le falta</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($productos as $p): ?>
                                        <?php
                                            $nombre = (string)($p['nombre'] ?? 'Producto');
                                            $variante = trim((string)($p['nombre_variante'] ?? ''));
                                            $nombreFinal = $variante !== '' ? ($nombre . ' - ' . $variante) : $nombre;
                                            $idProd = (int)($p['id_producto'] ?? 0);
                                        ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo esc($nombreFinal); ?></strong><br>
                                                <small class="grey-text">ID <?php echo $idProd; ?><?php echo $p['id_padre'] !== null ? ' · variante' : ''; ?></small>
                                            </td>
                                            <td><?php echo esc((string)($p['categoria'] ?? '') ?: '—'); ?></td>
                                            <td>
                                                <span class="pi-estado pi-estado-<?php echo esc((string)$p['estado']); ?>">
                                                    <?php echo esc(ucfirst((string)$p['estado'])); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php foreach ($p['_flags'] as $flagKey => $flagOn): ?>
                                                    <?php if ($flagOn): ?>
                                                        <span class="pi-badge pi-badge-<?php echo esc($flagKey); ?>"><?php echo esc($faltaLabels[$flagKey] ?? $flagKey); ?></span>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </td>
                                            <td>
                                                <a href="<?php echo BASE_URL; ?>views/products.php?id_producto=<?php echo $idProd; ?>"
                                                   class="btn-small green darken-1 waves-effect waves-light">
                                                    <i class="material-icons left" style="margin-right:4px;">edit</i>Completar
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if (count($productos) >= 1000): ?>
                            <p class="grey-text" style="margin-top:10px;">Se muestran los primeros 1000. Completa algunos y vuelve a cargar para ver el resto.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .pi-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 20px;
    }
    .pi-chips {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 12px;
    }
    .pi-chip {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 7px 12px;
        border-radius: 999px;
        font-size: 0.85rem;
        font-weight: 600;
        line-height: 1;
    }
    .pi-chip-count {
        background: rgba(255, 255, 255, 0.28);
        border-radius: 999px;
        padding: 2px 8px;
        font-size: 0.8rem;
    }
    .pi-chip-active {
        outline: 3px solid rgba(0, 0, 0, 0.35);
        outline-offset: 1px;
    }
    .pi-table-scroll {
        width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }
    .pi-table {
        min-width: 760px;
    }
    .pi-badge {
        display: inline-block;
        margin: 2px 3px 2px 0;
        padding: 3px 8px;
        border-radius: 6px;
        font-size: 0.74rem;
        font-weight: 700;
        color: #fff;
        background: #d32f2f;
    }
    .pi-badge-sku,
    .pi-badge-codigo_barras { background: #e64a19; }
    .pi-badge-inventario { background: #ff8f00; }
    .pi-estado {
        display: inline-block;
        padding: 2px 8px;
        border-radius: 6px;
        font-size: 0.76rem;
        font-weight: 600;
        background: #e8f5e9;
        color: #2e7d32;
    }
    .pi-estado-inactivo { background: #f5f5f5; color: #616161; }

    @media only screen and (max-width: 600px) {
        .pi-header { flex-direction: column; align-items: flex-start; }
        .pi-header .btn { width: 100%; }
        .pi-chip { flex: 1 1 45%; justify-content: space-between; }
    }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
