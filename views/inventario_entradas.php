<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
if (!isAdmin() && !isEncargado()) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$pageTitle = 'Entradas de Inventario';
$pdo = getPDO();
$usuario = $_SESSION['usuario'];
$almacenId = $usuario['id_almacen'];
$error = '';
$success = '';

// Procesar entrada individual
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF inválido.';
    } else {
        if ($_POST['accion'] === 'entrada_individual') {
            $id_producto = intval($_POST['id_producto']);
            $cantidad = intval($_POST['cantidad']);
            $observacion = htmlspecialchars($_POST['observacion'] ?? 'Entrada manual');

            if ($id_producto > 0 && $cantidad > 0) {
                try {
                    $pdo->beginTransaction();
                    
                    // Actualizar stock
                    $stmt = $pdo->prepare("UPDATE inventario_almacen SET cantidad_actual = cantidad_actual + ? WHERE id_producto = ? AND id_almacen = ?");
                    $stmt->execute([$cantidad, $id_producto, $almacenId]);
                    
                    // Registrar movimiento
                    $stmtMov = $pdo->prepare("INSERT INTO movimientos_inventario (id_producto, tipo_movimiento, id_almacen_destino, cantidad, id_usuario, observacion) VALUES (?, 'entrada', ?, ?, ?, ?)");
                    $stmtMov->execute([$id_producto, $almacenId, $cantidad, $usuario['id_usuario'], $observacion]);
                    
                    $pdo->commit();
                    $success = 'Stock actualizado correctamente.';
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = 'Error al procesar la entrada: ' . $e->getMessage();
                }
            }
        }
    }
}

// Obtener productos para la lista de entrada rápida
$sql = "SELECT p.id_producto, p.nombre, p.sku, ia.cantidad_actual, ia.stock_minimo, ia.stock_maximo 
        FROM productos p 
        JOIN inventario_almacen ia ON p.id_producto = ia.id_producto 
        WHERE ia.id_almacen = :almacen AND p.estado = 'activo'
        ORDER BY p.nombre ASC";
$stmt = $pdo->prepare($sql);
$stmt->execute([':almacen' => $almacenId]);
$productos = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="col s12">
            <h4><i class="material-icons left" style="font-size: 2.5rem; color: #2e7d32;">add_business</i> Entradas de Inventario</h4>
            <p class="grey-text">Registra la llegada de mercancía al almacén. Puedes hacerlo uno por uno o en la lista rápida.</p>
        </div>
    </div>

    <?php if ($success): ?>
        <div class="card green lighten-4 green-text text-darken-4" style="padding: 10px;">
            <i class="material-icons left">check_circle</i> <?php echo esc($success); ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="card red lighten-4 red-text text-darken-4" style="padding: 10px;">
            <i class="material-icons left">error</i> <?php echo esc($error); ?>
        </div>
    <?php endif; ?>

    <div class="row">
        <!-- Entrada Individual -->
        <div class="col s12 m4">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Entrada Individual</span>
                    <form method="POST">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="accion" value="entrada_individual">
                        
                        <div class="input-field">
                            <input type="text" id="buscador-inbound" class="autocomplete" autocomplete="off">
                            <label for="buscador-inbound">Buscar Producto (SKU o Nombre)</label>
                            <input type="hidden" name="id_producto" id="id_producto_inbound">
                        </div>
                        
                        <div class="input-field">
                            <input type="number" name="cantidad" id="cantidad_inbound" min="1" required>
                            <label for="cantidad_inbound">Cantidad a Ingresar</label>
                        </div>
                        
                        <div class="input-field">
                            <input type="text" name="observacion" id="obs_inbound" value="Entrada de mercancía">
                            <label for="obs_inbound">Observación / Factura</label>
                        </div>
                        
                        <button type="submit" class="btn green waves-effect waves-light w-100">
                            REGISTRAR ENTRADA
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Lista Rápida / Masiva -->
        <div class="col s12 m8">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Carga Rápida de Inventario</span>
                    <p class="small grey-text">Escribe la cantidad que llegó y presiona el botón verde de cada fila.</p>
                    
                    <div style="max-height: 500px; overflow-y: auto; margin-top: 20px;">
                        <table class="striped condensed">
                            <thead>
                                <tr>
                                    <th>Producto</th>
                                    <th>Stock Actual</th>
                                    <th>Ingresar</th>
                                    <th>Acción</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($productos as $p): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo esc($p['nombre']); ?></strong><br>
                                            <small class="grey-text">SKU: <?php echo esc($p['sku']); ?></small>
                                        </td>
                                        <td class="center-align">
                                            <span class="badge <?php echo $p['cantidad_actual'] <= $p['stock_minimo'] ? 'red white-text' : 'grey lighten-2'; ?>" style="float: none;">
                                                <?php echo $p['cantidad_actual']; ?>
                                            </span>
                                        </td>
                                        <td>
                                            <input type="number" class="qty-input" id="qty_<?php echo $p['id_producto']; ?>" min="1" style="height: 1.5rem; width: 60px; margin: 0;">
                                        </td>
                                        <td>
                                            <button type="button" class="btn-floating btn-small green waves-effect waves-light" onclick="registrarEntradaRapida(<?php echo $p['id_producto']; ?>)">
                                                <i class="material-icons">add</i>
                                            </button>
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
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Autocomplete para entrada individual
        const productos = <?php echo json_encode($productos); ?>;
        const data = {};
        const map = {};
        productos.forEach(p => {
            const label = `[${p.sku}] ${p.nombre}`;
            data[label] = null;
            map[label] = p.id_producto;
        });

        const elem = document.getElementById('buscador-inbound');
        M.Autocomplete.init(elem, {
            data: data,
            onAutocomplete: function(val) {
                document.getElementById('id_producto_inbound').value = map[val];
            }
        });
    });

    function registrarEntradaRapida(id) {
        const qty = document.getElementById('qty_' + id).value;
        if (!qty || qty <= 0) {
            M.toast({html: 'Ingresa una cantidad válida', classes: 'red'});
            return;
        }

        // Crear un form temporal para enviar vía POST
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <?php echo csrfInput(); ?>
            <input type="hidden" name="accion" value="entrada_individual">
            <input type="hidden" name="id_producto" value="${id}">
            <input type="hidden" name="cantidad" value="${qty}">
            <input type="hidden" name="observacion" value="Carga rápida de inventario">
        `;
        document.body.appendChild(form);
        form.submit();
    }
</script>

<style>
    .w-100 { width: 100%; }
    .qty-input { text-align: center; font-weight: bold; }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
