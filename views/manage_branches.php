<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/pickup_offer_utils.php';

requireAuth();
if (!isAdmin()) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$pageTitle = 'Gestionar Sucursales';
$pdo = getPDO();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF inválido.';
    } else {
        $accion = $_POST['accion'];
        $nombre = htmlspecialchars(trim($_POST['nombre'] ?? ''));
        $direccion = htmlspecialchars(trim($_POST['direccion'] ?? ''));
        $telefono = htmlspecialchars(trim($_POST['telefono'] ?? ''));

        if ($accion === 'agregar') {
            try {
                $stmt = $pdo->prepare("INSERT INTO almacenes (nombre, direccion, telefono) VALUES (?, ?, ?)");
                $stmt->execute([$nombre, $direccion, $telefono]);
                $success = "Sucursal '$nombre' creada correctamente.";
            } catch (PDOException $e) {
                $error = "Error al crear sucursal: " . $e->getMessage();
            }
        } elseif ($accion === 'guardar_incentivo') {
            $activo = isset($_POST['activo']) ? 1 : 0;
            $porcentaje = max(0, min(100, (float)($_POST['descuento_porcentaje'] ?? 0)));
            $fijo = max(0, (float)($_POST['descuento_fijo'] ?? 0));
            $subtotalMinimo = max(0, (float)($_POST['subtotal_minimo'] ?? 0));
            $piezasMinimas = max(1, (int)($_POST['piezas_minimas'] ?? 1));
            $tope = max(0, (float)($_POST['tope_descuento'] ?? 0));
            $mensaje = trim((string)($_POST['mensaje_publico'] ?? ''));

            try {
                $stmt = $pdo->prepare("INSERT INTO sucursal_incentivos
                    (id_regla, activo, descuento_porcentaje, descuento_fijo, subtotal_minimo, piezas_minimas, tope_descuento, mensaje_publico)
                    VALUES (1, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                      activo = VALUES(activo),
                      descuento_porcentaje = VALUES(descuento_porcentaje),
                      descuento_fijo = VALUES(descuento_fijo),
                      subtotal_minimo = VALUES(subtotal_minimo),
                      piezas_minimas = VALUES(piezas_minimas),
                      tope_descuento = VALUES(tope_descuento),
                      mensaje_publico = VALUES(mensaje_publico)");
                $stmt->execute([$activo, $porcentaje, $fijo, $subtotalMinimo, $piezasMinimas, $tope, $mensaje]);
                $success = 'Configuración de incentivo para sucursal actualizada.';
            } catch (PDOException $e) {
                $error = 'Error al guardar incentivo: ' . $e->getMessage();
            }
        }
    }
}

// Obtener sucursales actuales
$sucursales = $pdo->query("SELECT * FROM almacenes ORDER BY nombre ASC")->fetchAll();
$pickupOffer = getPickupOfferSettings($pdo);

include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="col s12">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 20px; flex-wrap: wrap; gap: 10px;">
                <h4 style="margin: 0;"><i class="material-icons left">store</i> Gestionar Sucursales</h4>
                <a href="dashboard.php" class="btn blue darken-4 waves-effect waves-light"><i class="material-icons left">dashboard</i> Volver al Dashboard</a>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="card-panel red lighten-4 red-text"><?php echo esc($error); ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="card-panel green lighten-4 green-text"><?php echo esc($success); ?></div>
    <?php endif; ?>

    <div class="row">
        <div class="col s12 m5">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Nueva Sucursal</span>
                    <form method="POST">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="accion" value="agregar">
                        <div class="input-field">
                            <input type="text" name="nombre" id="nombre" required>
                            <label for="nombre">Nombre de la Sucursal</label>
                        </div>
                        <div class="input-field">
                            <input type="text" name="direccion" id="direccion">
                            <label for="direccion">Dirección</label>
                        </div>
                        <div class="input-field">
                            <input type="text" name="telefono" id="telefono">
                            <label for="telefono">Teléfono</label>
                        </div>
                        <button type="submit" class="btn blue darken-4 w-100">Crear Sucursal</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-content">
                    <span class="card-title"><i class="material-icons left">local_offer</i> Incentivo Sucursal</span>
                    <p class="grey-text" style="margin-top: 0;">Configura cuánto se le muestra de ahorro al cliente cuando recoge en sucursal.</p>
                    <form method="POST">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="accion" value="guardar_incentivo">

                        <p style="margin-top: 10px;">
                            <label>
                                <input type="checkbox" name="activo" value="1" <?php echo !empty($pickupOffer['activo']) ? 'checked' : ''; ?>>
                                <span>Activar incentivo de sucursal</span>
                            </label>
                        </p>

                        <div class="input-field">
                            <input type="number" min="0" max="100" step="0.01" name="descuento_porcentaje" id="descuento_porcentaje"
                                   value="<?php echo esc((string)$pickupOffer['descuento_porcentaje']); ?>" required>
                            <label for="descuento_porcentaje" class="active">Descuento porcentual (%)</label>
                        </div>

                        <div class="input-field">
                            <input type="number" min="0" step="0.01" name="descuento_fijo" id="descuento_fijo"
                                   value="<?php echo esc((string)$pickupOffer['descuento_fijo']); ?>" required>
                            <label for="descuento_fijo" class="active">Descuento fijo extra ($)</label>
                        </div>

                        <div class="input-field">
                            <input type="number" min="0" step="0.01" name="subtotal_minimo" id="subtotal_minimo"
                                   value="<?php echo esc((string)$pickupOffer['subtotal_minimo']); ?>" required>
                            <label for="subtotal_minimo" class="active">Subtotal mínimo para aplicar ($)</label>
                        </div>

                        <div class="input-field">
                            <input type="number" min="1" step="1" name="piezas_minimas" id="piezas_minimas"
                                   value="<?php echo esc((string)$pickupOffer['piezas_minimas']); ?>" required>
                            <label for="piezas_minimas" class="active">Piezas mínimas para aplicar</label>
                        </div>

                        <div class="input-field">
                            <input type="number" min="0" step="0.01" name="tope_descuento" id="tope_descuento"
                                   value="<?php echo esc((string)$pickupOffer['tope_descuento']); ?>" required>
                            <label for="tope_descuento" class="active">Tope máximo de descuento ($, 0 = sin tope)</label>
                        </div>

                        <div class="input-field">
                            <input type="text" maxlength="255" name="mensaje_publico" id="mensaje_publico"
                                   value="<?php echo esc((string)$pickupOffer['mensaje_publico']); ?>">
                            <label for="mensaje_publico" class="active">Mensaje al cliente</label>
                        </div>

                        <button type="submit" class="btn deep-purple darken-3 w-100">Guardar Incentivo</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col s12 m7">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Sucursales Activas</span>
                    <table class="striped">
                        <thead>
                            <tr>
                                <th>Nombre</th>
                                <th>Teléfono</th>
                                <th>Estado</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sucursales as $s): ?>
                                <tr>
                                    <td><strong><?php echo esc($s['nombre']); ?></strong><br><small><?php echo esc($s['direccion']); ?></small></td>
                                    <td><?php echo esc($s['telefono']); ?></td>
                                    <td>
                                        <span class="badge <?php echo $s['estado'] === 'activo' ? 'green' : 'red'; ?> white-text" style="float:none;">
                                            <?php echo strtoupper($s['estado']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>.w-100 { width: 100%; }</style>
<?php include __DIR__ . '/includes/footer.php'; ?>