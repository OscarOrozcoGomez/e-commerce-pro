<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/ventas_features.php';
require_once __DIR__ . '/../core/stock_prediction.php';

requireAuth();
// Permiso 'gestionar_campanas' abre esta vista (ver + crear/eliminar campanas);
// el admin entra siempre (short-circuit).
if (!hasPermission('gestionar_campanas') && !isAdmin()) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$pageTitle = 'Stock vs. Calendario de Campañas';
$pdo = getPDO();
$error = '';
$success = '';

$featureActiva = ventasFeatureIsActive($pdo, 'stock_calendario_campanas');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF inválido.';
    } elseif (($_POST['accion'] ?? '') === 'crear_campana') {
        $nombre = trim((string) ($_POST['nombre'] ?? ''));
        $canal = trim((string) ($_POST['canal'] ?? 'otro'));
        $fechaInicio = trim((string) ($_POST['fecha_inicio'] ?? ''));
        $fechaFin = trim((string) ($_POST['fecha_fin'] ?? ''));
        $productos = array_filter(array_map('intval', $_POST['productos_destacados'] ?? []));
        $notas = trim((string) ($_POST['notas'] ?? ''));

        if ($nombre === '' || $fechaInicio === '' || $fechaFin === '') {
            $error = 'Nombre, fecha de inicio y fecha de fin son obligatorios.';
        } elseif ($fechaFin < $fechaInicio) {
            $error = 'La fecha de fin no puede ser anterior a la fecha de inicio.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO calendario_campanas (nombre, canal, fecha_inicio, fecha_fin, productos_destacados, notas, id_usuario_creador)
                     VALUES (:nombre, :canal, :fecha_inicio, :fecha_fin, :productos, :notas, :id_usuario)'
                );
                $stmt->execute([
                    ':nombre' => $nombre,
                    ':canal' => $canal,
                    ':fecha_inicio' => $fechaInicio,
                    ':fecha_fin' => $fechaFin,
                    ':productos' => empty($productos) ? null : implode(',', $productos),
                    ':notas' => $notas !== '' ? $notas : null,
                    ':id_usuario' => (int) ($_SESSION['usuario']['id_usuario'] ?? 0) ?: null,
                ]);
                $success = 'Campaña registrada.';
            } catch (Throwable $e) {
                $error = 'No se pudo guardar: ' . $e->getMessage();
            }
        }
    } elseif (($_POST['accion'] ?? '') === 'eliminar_campana') {
        $idCampana = (int) ($_POST['id_campana'] ?? 0);
        if ($idCampana > 0) {
            $pdo->prepare('DELETE FROM calendario_campanas WHERE id_campana = ?')->execute([$idCampana]);
            $success = 'Campaña eliminada.';
        }
    }
}

$campanas = $pdo->query(
    "SELECT * FROM calendario_campanas
     WHERE fecha_fin >= CURDATE()
     ORDER BY fecha_inicio ASC"
)->fetchAll();

$productos = $pdo->query("SELECT id_producto, nombre FROM productos WHERE estado = 'activo' ORDER BY nombre")->fetchAll();
$productosPorId = [];
foreach ($productos as $p) {
    $productosPorId[(int) $p['id_producto']] = $p['nombre'];
}

$alertas = [];
if ($featureActiva) {
    $predicciones = getPrediccionesInventario($pdo, 500);
    $alertas = getCampaignStockAlerts($campanas, $predicciones);
}

$canalLabels = ['google_ads' => 'Google Ads', 'facebook_ads' => 'Facebook Ads', 'whatsapp' => 'WhatsApp', 'otro' => 'Otro'];

include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid" style="padding: 20px;">
    <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; flex-wrap: wrap; gap: 10px;">
        <h4 style="margin: 0;"><i class="material-icons left" style="font-size: 2.5rem;">event_note</i> Stock vs. Calendario de Campañas</h4>
        <a href="dashboard.php" class="btn blue darken-4 waves-effect waves-light"><i class="material-icons left">dashboard</i> Volver al Dashboard</a>
    </div>

    <?php if (!$featureActiva): ?>
        <div class="card-panel amber lighten-4">
            Las alertas de stock están apagadas. Actívalas en <a href="ventas_features_config.php">Nuevas Iniciativas de Ventas</a>. Aún puedes registrar campañas mientras tanto.
        </div>
    <?php endif; ?>

    <?php if ($error): ?><div class="card-panel red lighten-4 red-text text-darken-4"><?php echo esc($error); ?></div><?php endif; ?>
    <?php if ($success): ?><div class="card-panel green lighten-4 green-text text-darken-4"><?php echo esc($success); ?></div><?php endif; ?>

    <?php if ($featureActiva && !empty($alertas)): ?>
        <div class="card-panel red lighten-4">
            <strong><i class="material-icons left">warning</i> Productos que se agotan antes de que termine su campaña:</strong>
            <ul style="margin-top: 10px;">
                <?php foreach ($alertas as $alerta): ?>
                    <li>
                        <strong><?php echo esc($alerta['producto']); ?></strong>
                        en la campaña "<?php echo esc($alerta['campana']); ?>" —
                        quedan ~<?php echo (int) $alerta['dias_restantes_stock']; ?> días de stock,
                        pero la campaña termina en <?php echo (int) $alerta['dias_para_fin_campana']; ?> días
                        (<?php echo esc($alerta['fecha_fin_campana']); ?>).
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-content">
            <span class="card-title">Registrar Campaña</span>
            <form method="POST">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="accion" value="crear_campana">
                <div class="row" style="margin-bottom: 0;">
                    <div class="input-field col s12 m4">
                        <input type="text" name="nombre" id="nombre" required>
                        <label for="nombre">Nombre de la campaña</label>
                    </div>
                    <div class="input-field col s12 m2">
                        <select name="canal" class="browser-default">
                            <?php foreach ($canalLabels as $val => $label): ?>
                                <option value="<?php echo esc($val); ?>"><?php echo esc($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="input-field col s6 m3">
                        <input type="date" name="fecha_inicio" id="fecha_inicio" required>
                        <label for="fecha_inicio" class="active">Fecha inicio</label>
                    </div>
                    <div class="input-field col s6 m3">
                        <input type="date" name="fecha_fin" id="fecha_fin" required>
                        <label for="fecha_fin" class="active">Fecha fin</label>
                    </div>
                </div>
                <div class="input-field col s12">
                    <select name="productos_destacados[]" id="select-productos-campana" multiple>
                        <option value="" disabled>Elige productos destacados (opcional)</option>
                        <?php foreach ($productos as $p): ?>
                            <option value="<?php echo (int) $p['id_producto']; ?>"><?php echo esc($p['nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label>Productos destacados en esta campaña</label>
                </div>
                <div class="input-field col s12">
                    <textarea name="notas" id="notas" class="materialize-textarea"></textarea>
                    <label for="notas">Notas (opcional)</label>
                </div>
                <button type="submit" class="btn indigo darken-3 waves-effect waves-light">Guardar Campaña</button>
            </form>
        </div>
    </div>

    <div class="card">
        <div class="card-content">
            <span class="card-title">Campañas Vigentes / Próximas</span>
            <?php if (empty($campanas)): ?>
                <p class="grey-text">No hay campañas registradas.</p>
            <?php else: ?>
                <table class="striped">
                    <thead>
                        <tr>
                            <th>Nombre</th>
                            <th>Canal</th>
                            <th>Inicio</th>
                            <th>Fin</th>
                            <th>Productos</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($campanas as $c): ?>
                            <tr>
                                <td><?php echo esc($c['nombre']); ?></td>
                                <td><?php echo esc($canalLabels[$c['canal']] ?? $c['canal']); ?></td>
                                <td><?php echo esc($c['fecha_inicio']); ?></td>
                                <td><?php echo esc($c['fecha_fin']); ?></td>
                                <td>
                                    <small>
                                        <?php
                                        $ids = array_filter(array_map('intval', explode(',', (string) ($c['productos_destacados'] ?? ''))));
                                        $nombres = array_map(static fn($id) => $productosPorId[$id] ?? "#$id", $ids);
                                        echo esc(implode(', ', $nombres));
                                        ?>
                                    </small>
                                </td>
                                <td>
                                    <form method="POST" onsubmit="return confirm('¿Eliminar esta campaña?');" style="margin: 0;">
                                        <?php echo csrfInput(); ?>
                                        <input type="hidden" name="accion" value="eliminar_campana">
                                        <input type="hidden" name="id_campana" value="<?php echo (int) $c['id_campana']; ?>">
                                        <button type="submit" class="btn-flat red-text"><i class="material-icons">delete</i></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var elems = document.querySelectorAll('select');
        M.FormSelect.init(elems);
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
