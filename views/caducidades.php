<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/lote_caducidad_utils.php';

requireAuth();
if (!isAdmin() && !isEncargado()) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$pageTitle = 'Control de Caducidades';
$pdo = getPDO();

$filtros = [];
$fSeveridad = trim((string) ($_GET['severidad'] ?? ''));
$fQ = trim((string) ($_GET['q'] ?? ''));
$fAlmacen = (int) ($_GET['id_almacen'] ?? 0);
$fCategoria = trim((string) ($_GET['categoria'] ?? ''));
$fSoloExcedente = !empty($_GET['solo_con_excedente']);

if ($fSeveridad !== '') {
    $filtros['severidad'] = $fSeveridad;
}
if ($fQ !== '') {
    $filtros['q'] = $fQ;
}
if ($fAlmacen > 0) {
    $filtros['id_almacen'] = $fAlmacen;
}
if ($fCategoria !== '') {
    $filtros['categoria'] = $fCategoria;
}
if ($fSoloExcedente) {
    $filtros['solo_con_excedente'] = true;
}

$proy = ['lotes' => [], 'ventana_dias' => 90];
try {
    $proy = loteFetchProyecciones($pdo, $filtros);
} catch (Throwable $e) {
    error_log('caducidades.php: ' . $e->getMessage());
}
$lotes = $proy['lotes'];

$resumen = loteResumenSeveridad($pdo);

// Catálogos para los filtros.
$almacenes = [];
try {
    $almacenes = $pdo->query('SELECT id_almacen, nombre FROM almacenes ORDER BY nombre')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $almacenes = [];
}
$categorias = [];
try {
    $categorias = $pdo->query(
        "SELECT DISTINCT categoria FROM productos WHERE categoria IS NOT NULL AND TRIM(categoria) <> '' ORDER BY categoria"
    )->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $categorias = [];
}

$SEV = [
    'caducado'      => ['t' => 'Caducado',       'c' => 'black white-text'],
    'critico'       => ['t' => 'Crítico',        'c' => 'red darken-1 white-text'],
    'urgente'       => ['t' => 'Urgente',        'c' => 'deep-orange darken-1 white-text'],
    'planificar'    => ['t' => 'Planificar',     'c' => 'amber darken-2 white-text'],
    'vigilar'       => ['t' => 'Vigilar',        'c' => 'blue-grey lighten-1 white-text'],
    'sin_rotacion'  => ['t' => 'Sin rotación',   'c' => 'grey darken-1 white-text'],
    'sin_historico' => ['t' => 'Sin histórico',  'c' => 'grey lighten-1'],
    'ok'            => ['t' => 'Ok',             'c' => 'green lighten-1 white-text'],
];

include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="col s12">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-top:20px; flex-wrap:wrap; gap:10px;">
                <h4 style="margin:0;"><i class="material-icons left" style="color:#e65100;">event_busy</i> Control de Caducidades</h4>
                <a href="<?php echo BASE_URL; ?>views/dashboard.php" class="btn blue darken-4 waves-effect waves-light"><i class="material-icons left">dashboard</i> Dashboard</a>
            </div>
            <p class="grey-text" style="margin-top:4px;">
                Lotes ordenados por los días que faltan para caducar. El "excedente proyectado" son las unidades que —a la velocidad de venta de los últimos <?php echo (int) ($proy['ventana_dias'] ?? 90); ?> días— <strong>no</strong> se alcanzarían a vender antes de caducar. Ponlos en oferta a tiempo.
            </p>
        </div>
    </div>

    <div class="row">
        <div class="col s12">
            <?php
            $chips = [
                'caducado'   => ['Caducados', 'black white-text'],
                'critico'    => ['Críticos', 'red darken-1 white-text'],
                'urgente'    => ['Urgentes', 'deep-orange darken-1 white-text'],
                'planificar' => ['A planificar', 'amber darken-2 white-text'],
                'vigilar'    => ['A vigilar', 'blue-grey lighten-1 white-text'],
            ];
            foreach ($chips as $k => [$label, $cls]):
            ?>
                <a href="?severidad=<?php echo $k; ?>" class="chip <?php echo $cls; ?>" style="text-decoration:none;">
                    <?php echo esc($label); ?>: <strong><?php echo (int) ($resumen[$k] ?? 0); ?></strong>
                </a>
            <?php endforeach; ?>
            <a href="<?php echo BASE_URL; ?>views/caducidades.php" class="chip">Ver todos: <strong><?php echo (int) ($resumen['total'] ?? 0); ?></strong></a>
        </div>
    </div>

    <div class="row">
        <div class="col s12">
            <form method="GET" class="card-panel grey lighten-4" style="padding:12px;">
                <div class="row" style="margin-bottom:0;">
                    <div class="input-field col s12 m3">
                        <select name="severidad">
                            <option value="">Severidad (todas)</option>
                            <?php foreach ($SEV as $k => $v): ?>
                                <option value="<?php echo $k; ?>" <?php echo $fSeveridad === $k ? 'selected' : ''; ?>><?php echo esc($v['t']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="input-field col s12 m3">
                        <select name="id_almacen">
                            <option value="0">Almacén (todos)</option>
                            <?php foreach ($almacenes as $a): ?>
                                <option value="<?php echo (int) $a['id_almacen']; ?>" <?php echo $fAlmacen === (int) $a['id_almacen'] ? 'selected' : ''; ?>><?php echo esc((string) $a['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="input-field col s12 m3">
                        <select name="categoria">
                            <option value="">Categoría (todas)</option>
                            <?php foreach ($categorias as $c): ?>
                                <option value="<?php echo esc((string) $c); ?>" <?php echo $fCategoria === (string) $c ? 'selected' : ''; ?>><?php echo esc((string) $c); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="input-field col s12 m3">
                        <input type="text" name="q" id="q" value="<?php echo esc($fQ); ?>" placeholder="Producto, SKU o lote">
                        <label for="q" class="active">Buscar</label>
                    </div>
                </div>
                <div class="row" style="margin-bottom:0;">
                    <div class="col s12 m6">
                        <label>
                            <input type="checkbox" name="solo_con_excedente" value="1" <?php echo $fSoloExcedente ? 'checked' : ''; ?> />
                            <span>Solo lotes con excedente proyectado</span>
                        </label>
                    </div>
                    <div class="col s12 m6" style="text-align:right;">
                        <a href="<?php echo BASE_URL; ?>views/caducidades.php" class="btn-flat">Limpiar</a>
                        <button type="submit" class="btn orange darken-3 waves-effect waves-light"><i class="material-icons left">filter_list</i>Filtrar</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="row">
        <div class="col s12">
            <?php if (empty($lotes)): ?>
                <div class="card-panel">No hay lotes que coincidan con el filtro.</div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                <table class="striped highlight">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th>Lote</th>
                            <th>Caduca</th>
                            <th class="right-align">Días</th>
                            <th class="right-align">Restante</th>
                            <th class="right-align">Vel/día</th>
                            <th class="right-align">Excedente</th>
                            <th>Severidad</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($lotes as $l):
                            $sev = $SEV[$l['severidad']] ?? ['t' => (string) $l['severidad'], 'c' => 'grey'];
                            $exc = $l['excedente_proyectado'];
                            $desc = (int) ($l['descuento_sugerido_pct'] ?? 0);
                        ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc((string) ($l['producto_nombre'] ?? '')); ?></strong>
                                    <?php if (!empty($l['producto_sku'])): ?><br><small class="grey-text"><?php echo esc((string) $l['producto_sku']); ?></small><?php endif; ?>
                                    <?php if (!empty($l['descuadre'])): ?>
                                        <br><span class="new badge red white-text" data-badge-caption="" title="La suma de lotes no cuadra con el stock del sistema">descuadre: lotes <?php echo (int) $l['stock_lotes']; ?> / sistema <?php echo (int) $l['stock_sistema']; ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc((string) ($l['codigo_lote'] ?? '')); ?></td>
                                <td>
                                    <?php echo esc((string) ($l['fecha_caducidad'] ?? '')); ?>
                                    <?php if (!empty($l['caducidad_aproximada'])): ?><small class="grey-text">(aprox)</small><?php endif; ?>
                                </td>
                                <td class="right-align"><?php echo (int) $l['dias_hasta_caducar']; ?></td>
                                <td class="right-align"><?php echo (int) $l['cantidad_restante']; ?></td>
                                <td class="right-align"><?php echo number_format((float) ($l['vel_diaria'] ?? 0), 2); ?></td>
                                <td class="right-align">
                                    <?php if ($exc === null): ?>
                                        <span class="grey-text">—</span>
                                    <?php elseif ((int) $exc > 0): ?>
                                        <strong class="red-text text-darken-2"><?php echo (int) $exc; ?></strong>
                                        <?php if ($desc > 0): ?><br><small class="grey-text">oferta −<?php echo $desc; ?>%</small><?php endif; ?>
                                    <?php else: ?>
                                        <span class="green-text">0</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="new badge <?php echo $sev['c']; ?>" data-badge-caption=""><?php echo esc($sev['t']); ?></span>
                                    <?php if (!empty($l['no_vendible'])): ?>
                                        <br><span class="new badge red darken-3 white-text" data-badge-caption="" title="Un envase comprado hoy no se termina antes de caducar">NO VENDIBLE</span>
                                    <?php endif; ?>
                                </td>
                                <td style="white-space:nowrap;">
                                    <a class="btn-flat btn-small" title="Marcar en oferta / atendida" onclick="marcarOferta(<?php echo (int) $l['id_lote']; ?>)"><i class="material-icons">local_offer</i></a>
                                    <a class="btn-flat btn-small" title="Ver producto" href="<?php echo BASE_URL; ?>views/products.php?id_producto=<?php echo (int) $l['id_producto']; ?>"><i class="material-icons">open_in_new</i></a>
                                    <a class="btn-flat btn-small red-text" title="Retirar lote" onclick="retirarLote(<?php echo (int) $l['id_lote']; ?>)"><i class="material-icons">block</i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php echo csrfInput(); ?>
<script>
    const LOTES_API = '<?php echo BASE_URL; ?>api/lotes_manager.php';
    const CSRF = document.querySelector('input[name="csrf_token"]').value;

    function postLote(payload) {
        payload.csrf_token = CSRF;
        return fetch(LOTES_API, { method: 'POST', body: new URLSearchParams(payload) })
            .then(r => r.json());
    }

    function tras(res) {
        M.toast({ html: res.message || 'Listo', classes: res.success ? 'green' : 'red' });
        if (res.success) setTimeout(() => location.reload(), 600);
    }

    window.marcarOferta = function (id) {
        const enOferta = confirm('¿Ya lo pusiste en oferta? Aceptar = sí · Cancelar = solo marcar como revisado.');
        postLote({ accion: 'marcar_atendida', id_lote: id, en_oferta: enOferta ? '1' : '' }).then(tras);
    };

    window.retirarLote = function (id) {
        if (!confirm('¿Retirar este lote? Deja de contar para caducidades y stock.')) return;
        postLote({ accion: 'cambiar_estado', id_lote: id, estado: 'retirado' }).then(tras);
    };

    document.addEventListener('DOMContentLoaded', function () {
        if (window.M && M.FormSelect) {
            M.FormSelect.init(document.querySelectorAll('select'));
        }
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
