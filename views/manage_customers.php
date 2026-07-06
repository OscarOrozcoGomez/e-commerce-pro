<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
requireAuth();
if (!isAdmin()) { header('Location: dashboard.php'); exit; }

$pdo = getPDO();
$error = ''; $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF inválido.';
    } else {
        $id_u = (int)($_POST['id_usuario'] ?? 0);
        $id_c = (int)($_POST['id_cliente'] ?? 0);
        if ($_POST['accion'] === 'activar') {
            if ($id_u > 0) {
                $pdo->prepare("UPDATE usuarios SET estado = 'activo' WHERE id_usuario = ?")->execute([$id_u]);
            }
            if ($id_c > 0) {
                $pdo->prepare("UPDATE clientes SET estado = 'activo' WHERE id_cliente = ?")->execute([$id_c]);
            }
            $success = 'Cliente activado.';
        } elseif ($_POST['accion'] === 'desactivar') {
            if ($id_u > 0) {
                $pdo->prepare("UPDATE usuarios SET estado = 'inactivo' WHERE id_usuario = ?")->execute([$id_u]);
            }
            if ($id_c > 0) {
                $pdo->prepare("UPDATE clientes SET estado = 'inactivo' WHERE id_cliente = ?")->execute([$id_c]);
            }
            $success = 'Cliente bloqueado.';
        }
    }
}

$clientes = $pdo->query("SELECT c.*, u.id_usuario, u.estado AS estado_usuario, u.contrasena, COALESCE(u.estado, c.estado, 'activo') AS estado_visible, CASE WHEN u.id_usuario IS NULL THEN 'sucursal' ELSE 'sitio_web' END AS origen_registro, CASE WHEN u.id_usuario IS NOT NULL AND u.contrasena IS NOT NULL AND TRIM(u.contrasena) <> '' THEN 1 ELSE 0 END AS tiene_acceso_web, (SELECT a.nombre FROM pedidos p0 INNER JOIN almacenes a ON a.id_almacen = p0.id_almacen WHERE p0.id_cliente = c.id_cliente ORDER BY p0.id_pedido ASC LIMIT 1) AS sucursal_origen FROM clientes c LEFT JOIN usuarios u ON c.id_usuario = u.id_usuario ORDER BY c.nombre ASC")->fetchAll();

$sucursalesOrigen = [];
foreach ($clientes as $cl) {
    $suc = trim((string)($cl['sucursal_origen'] ?? ''));
    if ($suc !== '') {
        $sucursalesOrigen[$suc] = true;
    }
}
$sucursalesOrigen = array_keys($sucursalesOrigen);
natcasesort($sucursalesOrigen);

$pageTitle = 'Administrar Clientes';
include __DIR__ . '/includes/header.php';
?>
<div class="container">
    <div class="row">
        <div class="col s12">
            <h4><i class="material-icons left blue-text">people</i> Gestión de Clientes</h4>
            <p class="grey-text">Listado maestro de clientes registrados en el sistema.</p>
        </div>
    </div>

    <?php if ($success): ?><div class="card-panel green lighten-4 green-text"><?php echo esc($success); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="card-panel red lighten-4 red-text"><?php echo esc($error); ?></div><?php endif; ?>

    <div class="card">
        <div class="card-content" style="padding-bottom: 10px;">
            <span class="card-title" style="font-size: 1.2rem; margin-bottom: 10px;">Filtros rápidos</span>
            <div class="row" style="margin-bottom: 0;">
                <div class="col s12 m8 l9" style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                    <button type="button" class="btn-small blue waves-effect waves-light js-filter-btn" data-filter="todos">Todos</button>
                    <button type="button" class="btn-small teal waves-effect waves-light js-filter-btn" data-filter="sitio_web">Sitio Web</button>
                    <button type="button" class="btn-small indigo waves-effect waves-light js-filter-btn" data-filter="sucursal">Sucursal</button>
                    <button type="button" class="btn-small green waves-effect waves-light js-filter-btn" data-filter="acceso_si">Con acceso</button>
                    <button type="button" class="btn-small grey darken-1 waves-effect waves-light js-filter-btn" data-filter="acceso_no">Sin acceso</button>
                    <button type="button" class="btn-small light-green darken-2 waves-effect waves-light js-filter-btn" data-filter="activo">Activos</button>
                    <button type="button" class="btn-small orange darken-2 waves-effect waves-light js-filter-btn" data-filter="inactivo">Inactivos</button>
                </div>
                <div class="col s12 m4 l3">
                    <label for="filtro-sucursal" class="active">Sucursal origen</label>
                    <select id="filtro-sucursal" class="browser-default" style="margin-top: 4px;">
                        <option value="__todas__">Todas</option>
                        <option value="__sin_sucursal__">Sin sucursal detectada</option>
                        <?php foreach ($sucursalesOrigen as $suc): ?>
                            <option value="<?php echo esc(strtolower($suc)); ?>"><?php echo esc($suc); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row" style="margin-top: 10px; margin-bottom: 0;">
                <div class="col s12">
                    <span class="grey-text text-darken-1">Mostrando <strong id="clientes-visibles"><?php echo count($clientes); ?></strong> de <strong id="clientes-total"><?php echo count($clientes); ?></strong> clientes.</span>
                </div>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-content">
            <table class="striped responsive-table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Email</th>
                        <th>Origen</th>
                        <th>Sucursal Origen</th>
                        <th>Acceso Web</th>
                        <th>Estado</th>
                        <th class="center-align">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($clientes as $c): ?>
                    <?php
                        $origenRegistro = (string)($c['origen_registro'] ?? 'sucursal');
                        $sucursalOrigen = trim((string)($c['sucursal_origen'] ?? ''));
                        $sucursalFiltro = $sucursalOrigen !== '' ? strtolower($sucursalOrigen) : '__sin_sucursal__';
                        $accesoWebFiltro = ((int)($c['tiene_acceso_web'] ?? 0) === 1) ? 'si' : 'no';
                        $estadoVisible = (string)($c['estado_visible'] ?? 'activo');
                    ?>
                    <tr data-origen="<?php echo esc($origenRegistro); ?>" data-acceso-web="<?php echo esc($accesoWebFiltro); ?>" data-estado="<?php echo esc($estadoVisible); ?>" data-sucursal="<?php echo esc($sucursalFiltro); ?>">
                        <td><strong><?php echo esc($c['nombre']); ?></strong></td>
                        <td><?php echo esc($c['email'] ?? 'N/A'); ?></td>
                        <td>
                            <?php if ($origenRegistro === 'sitio_web'): ?>
                                <span class="new badge teal" data-badge-caption="Sitio Web"></span>
                            <?php else: ?>
                                <span class="new badge indigo" data-badge-caption="Sucursal"></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($origenRegistro === 'sitio_web'): ?>
                                <span class="grey-text text-darken-1">N/A</span>
                            <?php elseif ($sucursalOrigen !== ''): ?>
                                <span><?php echo esc($sucursalOrigen); ?></span>
                            <?php else: ?>
                                <span class="grey-text text-darken-1">Sin sucursal detectada</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int)$c['tiene_acceso_web'] === 1): ?>
                                <span class="new badge green" data-badge-caption="Sí"></span>
                            <?php else: ?>
                                <span class="new badge grey" data-badge-caption="No"></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?php echo $estadoVisible === 'activo' ? 'green' : 'red'; ?> white-text" style="float:none;">
                                <?php echo strtoupper($estadoVisible); ?>
                            </span>
                        </td>
                        <td class="center-align">
                            <a href="#modal-dir-<?php echo $c['id_cliente']; ?>" class="btn-small blue waves-effect waves-light modal-trigger" title="Ver Direcciones">
                                <i class="material-icons">place</i>
                            </a>
                            <form method="POST" style="display:inline;">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="id_usuario" value="<?php echo (int)($c['id_usuario'] ?? 0); ?>">
                                <input type="hidden" name="id_cliente" value="<?php echo (int)$c['id_cliente']; ?>">
                                <input type="hidden" name="accion" value="<?php echo $estadoVisible === 'activo' ? 'desactivar' : 'activar'; ?>">
                                <button type="submit" class="btn-small <?php echo $estadoVisible === 'activo' ? 'orange' : 'green'; ?> waves-effect waves-light" 
                                        title="<?php echo $estadoVisible === 'activo' ? 'Bloquear' : 'Activar'; ?>">
                                    <i class="material-icons"><?php echo $estadoVisible === 'activo' ? 'block' : 'check'; ?></i>
                                </button>
                            </form>
                        </td>
                    </tr>

                    <!-- Modal Direcciones -->
                    <div id="modal-dir-<?php echo $c['id_cliente']; ?>" class="modal modal-fixed-footer" style="max-width: 500px;">
                        <div class="modal-content">
                            <h5>Direcciones de <?php echo esc($c['nombre']); ?></h5>
                            <div class="divider"></div>
                            <ul class="collection" style="margin-top: 20px;">
                                <?php
                                $stmtD = $pdo->prepare("SELECT * FROM cliente_direcciones WHERE id_cliente = ?");
                                $stmtD->execute([$c['id_cliente']]);
                                $dirs = $stmtD->fetchAll();
                                if (empty($dirs)):
                                ?>
                                    <li class="collection-item grey-text center">Sin direcciones registradas.</li>
                                <?php else: foreach ($dirs as $d): ?>
                                    <li class="collection-item">
                                        <strong><?php echo esc($d['alias']); ?></strong> 
                                        <?php if ($d['es_default']): ?><span class="new badge blue" data-badge-caption="Predeterminada"></span><?php endif; ?><br>
                                        <span class="grey-text text-darken-1"><?php echo esc($d['direccion']); ?></span>
                                    </li>
                                <?php endforeach; endif; ?>
                            </ul>
                        </div>
                        <div class="modal-footer">
                            <a href="#!" class="modal-close waves-effect waves-green btn-flat">Cerrar</a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    M.Modal.init(document.querySelectorAll('.modal'));

    const tableRows = Array.from(document.querySelectorAll('table tbody tr[data-origen]'));
    const btns = Array.from(document.querySelectorAll('.js-filter-btn'));
    const sucursalSelect = document.getElementById('filtro-sucursal');
    const visibleEl = document.getElementById('clientes-visibles');
    const totalEl = document.getElementById('clientes-total');
    const total = tableRows.length;
    let activeFilter = 'todos';

    if (totalEl) totalEl.textContent = String(total);

    function matchesQuickFilter(row, filter) {
        const origen = row.getAttribute('data-origen') || '';
        const acceso = row.getAttribute('data-acceso-web') || '';
        const estado = row.getAttribute('data-estado') || '';

        if (filter === 'todos') return true;
        if (filter === 'sitio_web') return origen === 'sitio_web';
        if (filter === 'sucursal') return origen === 'sucursal';
        if (filter === 'acceso_si') return acceso === 'si';
        if (filter === 'acceso_no') return acceso === 'no';
        if (filter === 'activo') return estado === 'activo';
        if (filter === 'inactivo') return estado === 'inactivo';
        return true;
    }

    function matchesSucursal(row, sucursalValue) {
        const sucursal = row.getAttribute('data-sucursal') || '';
        if (sucursalValue === '__todas__') return true;
        return sucursal === sucursalValue;
    }

    function applyFilters() {
        const sucursalValue = (sucursalSelect?.value || '__todas__').toLowerCase();
        let visibles = 0;

        tableRows.forEach((row) => {
            const ok = matchesQuickFilter(row, activeFilter) && matchesSucursal(row, sucursalValue);
            row.style.display = ok ? '' : 'none';
            if (ok) visibles++;
        });

        if (visibleEl) visibleEl.textContent = String(visibles);
    }

    btns.forEach((btn) => {
        btn.addEventListener('click', () => {
            activeFilter = btn.getAttribute('data-filter') || 'todos';
            btns.forEach((b) => {
                b.classList.remove('darken-4');
                b.classList.add('lighten-1');
            });
            btn.classList.remove('lighten-1');
            btn.classList.add('darken-4');
            applyFilters();
        });
    });

    if (sucursalSelect) {
        sucursalSelect.addEventListener('change', applyFilters);
    }

    const defaultBtn = document.querySelector('.js-filter-btn[data-filter="todos"]');
    if (defaultBtn) {
        defaultBtn.classList.add('darken-4');
    }
    applyFilters();
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>