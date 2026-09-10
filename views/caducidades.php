<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/lote_caducidad_utils.php';

requireAuth();
// Permiso 'gestionar_caducidades' abre esta vista; el rol se mantiene como respaldo.
if (!hasPermission('gestionar_caducidades') && !isAdmin() && !isEncargado()) {
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

// Descuadres de inventario: productos donde el stock del sistema no coincide con
// la suma de sus lotes. Reusa los filtros de almacén/búsqueda de la página.
$fTipoDescuadre = trim((string) ($_GET['d_tipo'] ?? ''));
$descuadres = [];
try {
    $descuadres = loteFetchDescuadres($pdo, [
        'id_almacen' => $fAlmacen,
        'q' => $fQ,
        'tipo' => $fTipoDescuadre,
    ]);
} catch (Throwable $e) {
    error_log('caducidades.php (descuadres): ' . $e->getMessage());
}

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

// Pestaña activa (se preserva en los submits de los formularios de filtro).
$tab = (($_GET['tab'] ?? '') === 'inc') ? 'inc' : 'cad';
$totalDescuadres = (int) ($resumen['descuadres'] ?? 0);

include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="col s12">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-top:20px; flex-wrap:wrap; gap:10px;">
                <h4 style="margin:0;"><i class="material-icons left" style="color:#e65100;">event_busy</i> Control de Caducidades</h4>
                <a href="<?php echo BASE_URL; ?>views/dashboard.php" class="btn blue darken-4 waves-effect waves-light"><i class="material-icons left">dashboard</i> Dashboard</a>
            </div>
        </div>
    </div>

    <div class="row" style="margin-bottom:0;">
        <div class="col s12">
            <ul class="tabs">
                <li class="tab col s6"><a href="#tab-caducidades" class="<?php echo $tab === 'cad' ? 'active' : ''; ?>">Caducidades (<?php echo (int) ($resumen['total'] ?? 0); ?>)</a></li>
                <li class="tab col s6"><a href="#tab-inconsistencias" class="<?php echo $tab === 'inc' ? 'active' : ''; ?>">Inconsistencias stock/lotes<?php if ($totalDescuadres > 0): ?> (<?php echo $totalDescuadres; ?>)<?php endif; ?></a></li>
            </ul>
        </div>
    </div>

    <!-- ================= TAB 1: Caducidades ================= -->
    <div id="tab-caducidades">
        <div class="row"><div class="col s12">
            <p class="grey-text" style="margin:14px 0 6px;">
                Lotes ordenados por los días que faltan para caducar. El "excedente proyectado" son las unidades que —a la velocidad de venta de los últimos <?php echo (int) ($proy['ventana_dias'] ?? 90); ?> días— <strong>no</strong> se alcanzarían a vender antes de caducar. Ponlos en oferta a tiempo.
            </p>

            <div style="margin-bottom:10px;">
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
                    <a href="?severidad=<?php echo $k; ?>#tab-caducidades" class="chip <?php echo $cls; ?>" style="text-decoration:none;">
                        <?php echo esc($label); ?>: <strong><?php echo (int) ($resumen[$k] ?? 0); ?></strong>
                    </a>
                <?php endforeach; ?>
                <a href="<?php echo BASE_URL; ?>views/caducidades.php" class="chip">Ver todos: <strong><?php echo (int) ($resumen['total'] ?? 0); ?></strong></a>
            </div>

            <form method="GET" class="card-panel grey lighten-4" style="padding:12px;">
                <input type="hidden" name="tab" value="cad">
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
                        <a href="?tab=cad" class="btn-flat">Limpiar</a>
                        <button type="submit" class="btn orange darken-3 waves-effect waves-light"><i class="material-icons left">filter_list</i>Filtrar</button>
                    </div>
                </div>
            </form>

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
                            <th class="right-align" title="Días que quedan para colocar el lote: si el envase rinde N días de tratamiento, después de (caducidad − N) un cliente ya no lo termina a tiempo">Días p/ vender</th>
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
                                <td class="right-align">
                                    <?php
                                    $ef = $l['dias_efectivos_venta'] ?? $l['dias_hasta_caducar'];
                                    if (($l['dias_tratamiento_envase'] ?? null) !== null && (int) $ef !== (int) $l['dias_hasta_caducar']):
                                    ?>
                                        <strong><?php echo (int) $ef; ?></strong>
                                        <br><small class="grey-text" title="El envase rinde <?php echo (int) $l['dias_tratamiento_envase']; ?> días de tratamiento">caduca en <?php echo (int) $l['dias_hasta_caducar']; ?></small>
                                    <?php else: ?>
                                        <?php echo (int) $l['dias_hasta_caducar']; ?>
                                    <?php endif; ?>
                                </td>
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
                                    <a class="btn-flat btn-small green-text text-darken-2" title="Poner en oferta (1 clic): agrega el producto a la categoría Ofertas y le fija precio costo + $50" onclick="ponerEnOferta(<?php echo (int) $l['id_lote']; ?>, <?php echo (int) $l['id_producto']; ?>, '<?php echo addslashes(esc((string) ($l['producto_nombre'] ?? ''))); ?>')"><i class="material-icons">sell</i></a>
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
        </div></div>
    </div><!-- /#tab-caducidades -->

    <!-- ================= TAB 2: Inconsistencias stock vs. lotes ================= -->
    <div id="tab-inconsistencias">
        <div class="row"><div class="col s12">
            <p class="grey-text" style="margin:14px 0 10px;">
                Productos donde el <strong>stock del sistema</strong> (inventario_almacen) no coincide con la <strong>suma de sus lotes</strong> vivos. Aquí es donde puede haber mercancía sin registrar en lotes, o lotes mal capturados.
                <br><strong>Faltante</strong>: el sistema tiene más que los lotes → faltan lotes por registrar.
                <strong>Sobrante</strong>: los lotes suman más que el sistema → lote de más o stock sin actualizar.
            </p>

            <form method="GET" class="card-panel grey lighten-4" style="padding:12px;">
                <input type="hidden" name="tab" value="inc">
                <div class="row" style="margin-bottom:0;">
                    <div class="input-field col s12 m4">
                        <select name="id_almacen" class="browser-default" style="border:1px solid #ccc; border-radius:4px;">
                            <option value="0">Almacén (todos)</option>
                            <?php foreach ($almacenes as $a): ?>
                                <option value="<?php echo (int) $a['id_almacen']; ?>" <?php echo $fAlmacen === (int) $a['id_almacen'] ? 'selected' : ''; ?>><?php echo esc((string) $a['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="input-field col s12 m4">
                        <select name="d_tipo" class="browser-default" style="border:1px solid #ccc; border-radius:4px;">
                            <?php foreach (['' => 'Descuadre: todos', 'faltante' => 'Solo faltante', 'sobrante' => 'Solo sobrante'] as $k => $lbl): ?>
                                <option value="<?php echo $k; ?>" <?php echo $fTipoDescuadre === $k ? 'selected' : ''; ?>><?php echo $lbl; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="input-field col s12 m4">
                        <input type="text" name="q" id="q_inc" value="<?php echo esc($fQ); ?>" placeholder="Producto o SKU">
                        <label for="q_inc" class="active">Buscar</label>
                    </div>
                </div>
                <div class="row" style="margin-bottom:0;">
                    <div class="col s12" style="text-align:right;">
                        <a href="?tab=inc" class="btn-flat">Limpiar</a>
                        <button type="submit" class="btn orange darken-3 waves-effect waves-light"><i class="material-icons left">filter_list</i>Filtrar</button>
                    </div>
                </div>
            </form>

            <?php if (empty($descuadres)): ?>
                <div class="card-panel <?php echo $totalDescuadres > 0 ? '' : 'green lighten-5'; ?>">
                    <?php echo $totalDescuadres > 0 ? 'Ningún descuadre coincide con el filtro.' : '✓ Todo cuadra: cada producto con stock tiene sus lotes al día.'; ?>
                </div>
            <?php else: ?>
                <div style="overflow-x:auto;">
                <table class="striped highlight">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th class="right-align">Stock sistema</th>
                            <th class="right-align">Suma lotes</th>
                            <th class="right-align">Diferencia</th>
                            <th class="right-align" title="Lotes vivos registrados para este producto">Lotes</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($descuadres as $d): $dif = (int) $d['diferencia']; ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc((string) $d['producto_nombre']); ?></strong>
                                    <?php if (!empty($d['producto_sku'])): ?><br><small class="grey-text"><?php echo esc((string) $d['producto_sku']); ?></small><?php endif; ?>
                                </td>
                                <td class="right-align"><?php echo (int) $d['stock_sistema']; ?></td>
                                <td class="right-align"><?php echo (int) $d['stock_lotes']; ?></td>
                                <td class="right-align">
                                    <span class="new badge <?php echo $dif > 0 ? 'blue darken-1' : 'deep-orange darken-1'; ?> white-text" data-badge-caption="" style="float:none;">
                                        <?php echo ($dif > 0 ? '+' : '') . $dif; ?> <?php echo $dif > 0 ? 'faltante' : 'sobrante'; ?>
                                    </span>
                                </td>
                                <td class="right-align"><?php echo (int) $d['n_lotes']; ?></td>
                                <td style="white-space:nowrap;">
                                    <a class="btn-flat btn-small" title="Ver / editar lotes del producto" href="<?php echo BASE_URL; ?>views/products.php?id_producto=<?php echo (int) $d['id_producto']; ?>"><i class="material-icons">inventory_2</i></a>
                                    <a class="btn-flat btn-small" title="Entradas de inventario" href="<?php echo BASE_URL; ?>views/inventario_entradas.php"><i class="material-icons">add_business</i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </div></div>
    </div><!-- /#tab-inconsistencias -->
</div><!-- /container -->

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

    // Un clic: mete el producto a la categoría "Ofertas" y le fija el precio de
    // oferta (costo + $50) si no tiene uno manual. Desde ahí el catálogo, la ficha,
    // el POS y Alex lo venden a ese precio.
    window.ponerEnOferta = function (idLote, idProducto, nombre) {
        if (!confirm('¿Poner "' + nombre + '" en Ofertas?\n\nSe agrega a la categoría Ofertas y se le fija el precio de oferta (costo + $50) si aún no tiene uno capturado a mano.')) return;
        postLote({ accion: 'poner_producto_en_oferta', id_lote: idLote, id_producto: idProducto }).then(tras);
    };

    window.retirarLote = function (id) {
        if (!confirm('¿Retirar este lote? Deja de contar para caducidades y stock.')) return;
        postLote({ accion: 'cambiar_estado', id_lote: id, estado: 'retirado' }).then(tras);
    };

    document.addEventListener('DOMContentLoaded', function () {
        if (window.M && M.Tabs) {
            M.Tabs.init(document.querySelectorAll('.tabs'));
        }
        if (window.M && M.FormSelect) {
            M.FormSelect.init(document.querySelectorAll('select'));
        }
        // Si venimos de un submit de filtro de "Inconsistencias", abre esa pestaña.
        <?php if ($tab === 'inc'): ?>
        var tInc = document.querySelector('.tabs a[href="#tab-inconsistencias"]');
        if (tInc && window.M && M.Tabs) {
            var inst = M.Tabs.getInstance(tInc.closest('.tabs'));
            if (inst) inst.select('tab-inconsistencias');
        }
        <?php endif; ?>
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
