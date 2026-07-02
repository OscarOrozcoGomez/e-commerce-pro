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
            $descuentoPorPiezasRaw = trim((string)($_POST['descuento_por_piezas'] ?? ''));

            $descuentoPorPiezasMap = [];
            if ($descuentoPorPiezasRaw !== '') {
                $decodedMap = json_decode($descuentoPorPiezasRaw, true);
                if (!is_array($decodedMap)) {
                    $error = 'Formato inválido en descuento por piezas. Usa JSON, por ejemplo: {"1":15,"2":30,"3":45}';
                } else {
                    $descuentoPorPiezasMap = parsePickupPieceDiscountMap($decodedMap);
                    if (empty($descuentoPorPiezasMap)) {
                        $error = 'Define al menos un tramo válido en descuento por piezas.';
                    }
                }
            }

            $descuentoPorPiezasJson = json_encode($descuentoPorPiezasMap, JSON_UNESCAPED_UNICODE);
            if ($descuentoPorPiezasJson === false) {
                $error = 'No se pudo procesar la configuración de descuento por piezas.';
            }

            try {
                if ($error !== '') {
                    throw new RuntimeException($error);
                }

                $stmt = $pdo->prepare("INSERT INTO sucursal_incentivos
                    (id_regla, activo, descuento_porcentaje, descuento_fijo, subtotal_minimo, piezas_minimas, tope_descuento, mensaje_publico, descuento_por_piezas_json)
                    VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                      activo = VALUES(activo),
                      descuento_por_piezas_json = VALUES(descuento_por_piezas_json)");
                $stmt->execute([$activo, 0.0, 0.0, 0.0, 1, 0.0, '', $descuentoPorPiezasJson]);
                $success = 'Configuración de incentivo para sucursal actualizada.';
            } catch (Throwable $e) {
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
                    <p class="grey-text" style="margin-top: 0;">Define incentivo por piezas con un JSON de tramos. Solo se usa esta configuración.</p>
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
                            <textarea class="materialize-textarea" name="descuento_por_piezas" id="descuento_por_piezas" rows="3"><?php echo esc((string)($pickupOffer['descuento_por_piezas_json'] ?? '{}')); ?></textarea>
                            <label for="descuento_por_piezas" class="active">Descuento por piezas (JSON)</label>
                            <div class="helper-text" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom: 6px;">
                                <span>Configura el descuento total según cantidad de piezas.</span>
                                <span class="discount-help-icon" data-tip="Pasa el mouse para ayuda rápida. Da clic abajo para ver ejemplos." aria-label="Ayuda de descuento por piezas" title="Ayuda de descuento por piezas">
                                    <i class="material-icons tiny">priority_high</i>
                                </span>
                            </div>
                            <details class="discount-help-details">
                                <summary>Ver ejemplos de JSON</summary>
                                <div id="descuento-json-ejemplos" class="discount-help-panel" style="margin-top:10px;">
                                    <p style="margin:0 0 8px 0;"><strong>Ejemplos rápidos:</strong></p>
                                    <ul class="discount-examples-list" style="margin:0; padding-left:0; list-style:none;">
                                        <li class="discount-example-item">
                                            <code>{"1":15,"2":30,"3":45}</code>
                                            <span class="discount-example-text">Escalado lineal básico.</span>
                                            <button type="button" class="btn-flat blue-text text-darken-3 discount-example-action" data-action="use" data-example='{"1":15,"2":30,"3":45}'>Usar</button>
                                            <button type="button" class="btn-flat teal-text text-darken-3 discount-example-action" data-action="copy" data-example='{"1":15,"2":30,"3":45}'>Copiar</button>
                                        </li>
                                        <li class="discount-example-item">
                                            <code>{"1":10,"3":35,"5":70}</code>
                                            <span class="discount-example-text">Escalado por tramos (2 piezas usa 10, 4 usa 35).</span>
                                            <button type="button" class="btn-flat blue-text text-darken-3 discount-example-action" data-action="use" data-example='{"1":10,"3":35,"5":70}'>Usar</button>
                                            <button type="button" class="btn-flat teal-text text-darken-3 discount-example-action" data-action="copy" data-example='{"1":10,"3":35,"5":70}'>Copiar</button>
                                        </li>
                                        <li class="discount-example-item">
                                            <code>{"2":20,"4":50,"8":120}</code>
                                            <span class="discount-example-text">Incentivo para tickets medianos/altos.</span>
                                            <button type="button" class="btn-flat blue-text text-darken-3 discount-example-action" data-action="use" data-example='{"2":20,"4":50,"8":120}'>Usar</button>
                                            <button type="button" class="btn-flat teal-text text-darken-3 discount-example-action" data-action="copy" data-example='{"2":20,"4":50,"8":120}'>Copiar</button>
                                        </li>
                                        <li class="discount-example-item">
                                            <code>{"1":12.5,"2":27.5,"6":90}</code>
                                            <span class="discount-example-text">También acepta decimales.</span>
                                            <button type="button" class="btn-flat blue-text text-darken-3 discount-example-action" data-action="use" data-example='{"1":12.5,"2":27.5,"6":90}'>Usar</button>
                                            <button type="button" class="btn-flat teal-text text-darken-3 discount-example-action" data-action="copy" data-example='{"1":12.5,"2":27.5,"6":90}'>Copiar</button>
                                        </li>
                                    </ul>
                                    <p style="margin:8px 0 0 0; font-size:0.85rem; color:#555;">Tip: si no existe un tramo exacto, se usa el tramo menor más cercano.</p>
                                </div>
                            </details>
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    const textarea = document.getElementById('descuento_por_piezas');
    if (!textarea) {
        return;
    }

    const actionButtons = document.querySelectorAll('.discount-example-action');

    function notify(message, cssClass) {
        if (window.M && typeof M.toast === 'function') {
            M.toast({ html: message, classes: cssClass || 'blue darken-3' });
            return;
        }
        alert(message);
    }

    function useExample(value) {
        textarea.value = value;
        if (window.M && typeof M.textareaAutoResize === 'function') {
            M.textareaAutoResize(textarea);
        }
        notify('Ejemplo aplicado en el campo JSON.', 'green darken-2');
        textarea.focus();
    }

    async function copyExample(value) {
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(value);
                notify('Ejemplo copiado al portapapeles.', 'teal darken-2');
                return;
            }
            textarea.value = value;
            textarea.focus();
            textarea.select();
            const ok = document.execCommand('copy');
            notify(ok ? 'Ejemplo copiado al portapapeles.' : 'No se pudo copiar automaticamente. Ya lo deje seleccionado.', ok ? 'teal darken-2' : 'orange darken-2');
        } catch (err) {
            console.error('No se pudo copiar el ejemplo:', err);
            notify('No se pudo copiar automaticamente. Usa el boton Usar para autocompletar.', 'orange darken-2');
        }
    }

    actionButtons.forEach(function(button) {
        button.addEventListener('click', function() {
            const action = button.getAttribute('data-action') || '';
            const example = button.getAttribute('data-example') || '';
            if (!example) {
                return;
            }

            if (action === 'use') {
                useExample(example);
                return;
            }

            if (action === 'copy') {
                copyExample(example);
            }
        });
    });
});
</script>

<style>.w-100 { width: 100%; }</style>
<style>
.discount-help-icon {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    background: #ffeb3b;
    color: #6d4c41;
    text-decoration: none;
}

.discount-help-icon:hover {
    background: #fdd835;
}

.discount-help-icon:hover::after {
    content: attr(data-tip);
    position: absolute;
    top: 26px;
    right: 0;
    width: 250px;
    padding: 7px 9px;
    border-radius: 6px;
    background: #263238;
    color: #fff;
    font-size: 0.75rem;
    line-height: 1.3;
    z-index: 50;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
}

.discount-help-details summary {
    cursor: pointer;
    color: #0d47a1;
    font-weight: 600;
    user-select: none;
}

.discount-help-details[open] summary {
    color: #1565c0;
}

.discount-help-panel {
    background: #fff8e1;
    border: 1px solid #ffe082;
    border-radius: 6px;
    padding: 10px 12px;
}

.discount-example-item {
    display: grid;
    grid-template-columns: 1fr;
    gap: 4px;
    padding: 8px 0;
    border-bottom: 1px dashed #ffe082;
}

.discount-example-item:last-child {
    border-bottom: 0;
}

.discount-example-text {
    font-size: 0.82rem;
    color: #5d4037;
}

.discount-example-action {
    justify-self: start;
    min-height: auto;
    line-height: 1.4;
    padding: 2px 6px;
}

@media (min-width: 768px) {
    .discount-example-item {
        grid-template-columns: 1fr auto auto;
        align-items: center;
        column-gap: 10px;
        row-gap: 2px;
    }

    .discount-example-item code,
    .discount-example-item .discount-example-text {
        grid-column: 1 / 2;
    }

    .discount-example-item .discount-example-action[data-action='use'] {
        grid-column: 2 / 3;
        grid-row: 1 / 3;
    }

    .discount-example-item .discount-example-action[data-action='copy'] {
        grid-column: 3 / 4;
        grid-row: 1 / 3;
    }
}
</style>
<?php include __DIR__ . '/includes/footer.php'; ?>