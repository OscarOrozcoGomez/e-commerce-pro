<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

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
        }
    }
}

// Obtener sucursales actuales
$sucursales = $pdo->query("SELECT * FROM almacenes ORDER BY nombre ASC")->fetchAll();

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