<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/ventas_features.php';

requireAuth();
if (!isAdmin()) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$pageTitle = 'Comportamiento en el Sitio';
$pdo = getPDO();

$featureActiva = ventasFeatureIsActive($pdo, 'comportamiento_sitio');

$fecha_inicio = $_GET['fecha_inicio'] ?? date('Y-m-d', strtotime('-30 days'));
$fecha_fin = $_GET['fecha_fin'] ?? date('Y-m-d');

$totales = ['visitas' => 0, 'con_tiempo' => 0, 'promedio' => null];
$porProducto = [];
$porPagina = [];
$porElemento = [];

if ($featureActiva) {
    // id_usuario IS NULL en todas las consultas de aqui en adelante: descarta la
    // actividad de personal logueado (vendedores/almacen usando el panel admin) para
    // que el reporte refleje comportamiento de visitantes/compradores, no clics
    // internos del sistema (ej. "CONFIRMAR PEDIDO", "ASIGNAR ENTREGAS").
    $totalesStmt = $pdo->prepare(
        "SELECT COUNT(*) AS visitas, COUNT(duracion_segundos) AS con_tiempo, AVG(duracion_segundos) AS promedio
         FROM logs_actividad
         WHERE tipo_accion = 'visit' AND id_usuario IS NULL AND DATE(fecha_creacion) BETWEEN :inicio AND :fin"
    );
    $totalesStmt->execute([':inicio' => $fecha_inicio, ':fin' => $fecha_fin]);
    $totales = $totalesStmt->fetch() ?: $totales;

    // Productos: vistas + tiempo promedio en la pagina del producto, y cuantas de esas
    // vistas terminaron en un clic a "Agregar al Carrito" (columna "clics" de abajo) --
    // el INNER JOIN con la subconsulta de vistas es intencional: un producto sin
    // ninguna vista en el rango simplemente no aparece, no tiene caso mostrarlo en 0.
    $porProductoStmt = $pdo->prepare(
        "SELECT p.id_producto, p.nombre,
                v.vistas, v.tiempo_promedio,
                COALESCE(c.clics, 0) AS clics
         FROM productos p
         INNER JOIN (
             SELECT id_producto, COUNT(*) AS vistas, AVG(duracion_segundos) AS tiempo_promedio
             FROM logs_actividad
             WHERE tipo_accion = 'visit' AND id_producto IS NOT NULL AND id_usuario IS NULL
               AND DATE(fecha_creacion) BETWEEN :inicio1 AND :fin1
             GROUP BY id_producto
         ) v ON v.id_producto = p.id_producto
         LEFT JOIN (
             SELECT id_producto, COUNT(*) AS clics
             FROM logs_actividad
             WHERE tipo_accion = 'click' AND elemento_id = 'add_to_cart' AND id_producto IS NOT NULL AND id_usuario IS NULL
               AND DATE(fecha_creacion) BETWEEN :inicio2 AND :fin2
             GROUP BY id_producto
         ) c ON c.id_producto = p.id_producto
         ORDER BY v.vistas DESC
         LIMIT 20"
    );
    $porProductoStmt->execute([
        ':inicio1' => $fecha_inicio, ':fin1' => $fecha_fin,
        ':inicio2' => $fecha_inicio, ':fin2' => $fecha_fin,
    ]);
    $porProducto = $porProductoStmt->fetchAll();

    // Pagina = host+ruta sin query string, para que catalogo.php?search=a y
    // catalogo.php?search=b cuenten como la misma pagina (si no, el reporte se
    // fragmenta en decenas de filas casi vacias por cada busqueda distinta).
    $porPaginaStmt = $pdo->prepare(
        "SELECT SUBSTRING_INDEX(SUBSTRING_INDEX(url, '://', -1), '?', 1) AS pagina,
                COUNT(*) AS vistas,
                AVG(duracion_segundos) AS tiempo_promedio
         FROM logs_actividad
         WHERE tipo_accion = 'visit' AND url != '' AND id_usuario IS NULL
           AND DATE(fecha_creacion) BETWEEN :inicio AND :fin
         GROUP BY pagina
         ORDER BY vistas DESC
         LIMIT 20"
    );
    $porPaginaStmt->execute([':inicio' => $fecha_inicio, ':fin' => $fecha_fin]);
    $porPagina = $porPaginaStmt->fetchAll();

    $porElementoStmt = $pdo->prepare(
        "SELECT COALESCE(NULLIF(elemento_texto, ''), '(sin texto)') AS elemento, COUNT(*) AS clics
         FROM logs_actividad
         WHERE tipo_accion = 'click' AND id_usuario IS NULL AND DATE(fecha_creacion) BETWEEN :inicio AND :fin
         GROUP BY elemento
         ORDER BY clics DESC
         LIMIT 20"
    );
    $porElementoStmt->execute([':inicio' => $fecha_inicio, ':fin' => $fecha_fin]);
    $porElemento = $porElementoStmt->fetchAll();
}

function formatDuracion(?float $segundos): string
{
    if ($segundos === null) {
        return '—';
    }
    $segundos = (int) round($segundos);
    if ($segundos < 60) {
        return $segundos . 's';
    }
    return intdiv($segundos, 60) . 'm ' . ($segundos % 60) . 's';
}

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid" style="padding: 20px;">
    <div class="row">
        <div class="col s12">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; flex-wrap: wrap; gap: 10px;">
                <h4 style="margin: 0;"><i class="material-icons left" style="font-size: 2.5rem;">visibility</i> Comportamiento en el Sitio</h4>
                <a href="dashboard.php" class="btn blue darken-4 waves-effect waves-light"><i class="material-icons left">dashboard</i> Volver al Dashboard</a>
            </div>
            <p class="grey-text">Qué ve la gente una vez que ya está en tu sitio: productos y páginas con más atención, y qué tanto convierte esa atención en un clic a "Agregar al Carrito".</p>
        </div>
    </div>

    <?php if (!$featureActiva): ?>
        <div class="card-panel amber lighten-4">
            Esta sección está apagada. Actívala en <a href="ventas_features_config.php">Nuevas Iniciativas de Ventas</a>.
        </div>
    <?php else: ?>

        <!-- Filtros -->
        <div class="card">
            <div class="card-content">
                <form method="GET" class="row" style="margin-bottom: 0;">
                    <div class="input-field col s6 m3">
                        <input type="date" name="fecha_inicio" id="fecha_inicio" value="<?php echo esc($fecha_inicio); ?>">
                        <label for="fecha_inicio" class="active">Desde</label>
                    </div>
                    <div class="input-field col s6 m3">
                        <input type="date" name="fecha_fin" id="fecha_fin" value="<?php echo esc($fecha_fin); ?>">
                        <label for="fecha_fin" class="active">Hasta</label>
                    </div>
                    <div class="col s12 m2" style="padding-top: 15px;">
                        <button type="submit" class="btn indigo waves-effect waves-light">Filtrar</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Totales -->
        <div class="row">
            <div class="col s12 m4">
                <div class="card indigo darken-3">
                    <div class="card-content white-text">
                        <span class="card-title">Vistas de Página</span>
                        <p class="display-metric"><?php echo number_format((int) ($totales['visitas'] ?? 0)); ?></p>
                    </div>
                </div>
            </div>
            <div class="col s12 m4">
                <div class="card blue-grey darken-2">
                    <div class="card-content white-text">
                        <span class="card-title">Con Tiempo Medido</span>
                        <p class="display-metric"><?php echo number_format((int) ($totales['con_tiempo'] ?? 0)); ?></p>
                    </div>
                </div>
            </div>
            <div class="col s12 m4">
                <div class="card teal darken-2">
                    <div class="card-content white-text">
                        <span class="card-title">Tiempo Promedio</span>
                        <p class="display-metric"><?php echo formatDuracion(isset($totales['promedio']) ? (float) $totales['promedio'] : null); ?></p>
                    </div>
                </div>
            </div>
        </div>
        <p class="grey-text" style="margin-top: -10px;">"Con Tiempo Medido" suele ser menor al total de vistas: el tiempo solo se registra si el visitante permanece un momento visible en la página antes de irse (una visita que rebota de inmediato no alcanza a reportarlo).</p>

        <!-- Productos mas vistos -->
        <div class="card">
            <div class="card-content">
                <span class="card-title">Productos con Más Atención</span>
                <?php if (empty($porProducto)): ?>
                    <p class="grey-text">No hay vistas de producto registradas en el rango seleccionado.</p>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="striped">
                            <thead>
                                <tr>
                                    <th>Producto</th>
                                    <th>Vistas</th>
                                    <th>Tiempo Promedio</th>
                                    <th>Clics "Agregar al Carrito"</th>
                                    <th>Tasa de Interés</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($porProducto as $row): ?>
                                    <?php
                                    $vistas = (int) $row['vistas'];
                                    $clics = (int) $row['clics'];
                                    $tasa = $vistas > 0 ? round(($clics / $vistas) * 100, 1) : 0.0;
                                    ?>
                                    <tr>
                                        <td><?php echo esc($row['nombre']); ?></td>
                                        <td><?php echo number_format($vistas); ?></td>
                                        <td><?php echo formatDuracion(isset($row['tiempo_promedio']) ? (float) $row['tiempo_promedio'] : null); ?></td>
                                        <td><?php echo number_format($clics); ?></td>
                                        <td><?php echo esc((string) $tasa); ?>%</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="row">
            <!-- Paginas mas vistas -->
            <div class="col s12 m6">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Páginas con Más Atención</span>
                        <?php if (empty($porPagina)): ?>
                            <p class="grey-text">No hay datos en el rango seleccionado.</p>
                        <?php else: ?>
                            <table class="striped">
                                <thead>
                                    <tr>
                                        <th>Página</th>
                                        <th>Vistas</th>
                                        <th>Tiempo Prom.</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($porPagina as $row): ?>
                                        <tr>
                                            <td style="word-break: break-all;"><small><?php echo esc($row['pagina']); ?></small></td>
                                            <td><?php echo number_format((int) $row['vistas']); ?></td>
                                            <td><?php echo formatDuracion(isset($row['tiempo_promedio']) ? (float) $row['tiempo_promedio'] : null); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Elementos mas clickeados -->
            <div class="col s12 m6">
                <div class="card">
                    <div class="card-content">
                        <span class="card-title">Elementos Más Clickeados</span>
                        <?php if (empty($porElemento)): ?>
                            <p class="grey-text">No hay clics registrados en el rango seleccionado.</p>
                        <?php else: ?>
                            <table class="striped">
                                <thead>
                                    <tr>
                                        <th>Elemento</th>
                                        <th>Clics</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($porElemento as $row): ?>
                                        <tr>
                                            <td><?php echo esc($row['elemento']); ?></td>
                                            <td><?php echo number_format((int) $row['clics']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

    <?php endif; ?>
</div>

<style>
    .display-metric { font-size: 2.5rem; font-weight: bold; margin: 0; }
    input[type="date"] { margin-bottom: 0 !important; }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
