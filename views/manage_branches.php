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

// Esquema confirmado de almacenes en este proyecto:
// id_almacen, nombre, ubicacion, estado, fecha_creacion.
$hasDireccion = false;
$hasUbicacion = true;
$hasTelefono = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF inválido.';
    } else {
        $accion = $_POST['accion'];
        $nombre = trim((string)($_POST['nombre'] ?? ''));
        $direccion = trim((string)($_POST['direccion'] ?? ''));
        $telefono = trim((string)($_POST['telefono'] ?? ''));
        $idAlmacen = (int)($_POST['id_almacen'] ?? 0);
        $telefonoDigits = preg_replace('/\D+/', '', $telefono);
        $telefonoDigits = is_string($telefonoDigits) ? substr($telefonoDigits, 0, 10) : '';

        if ($telefonoDigits !== '' && strlen($telefonoDigits) !== 10) {
            $error = 'El teléfono debe tener 10 dígitos.';
        }

        if ($telefonoDigits !== '' && strlen($telefonoDigits) === 10) {
            $telefono = sprintf(
                '(%s) - %s - %s',
                substr($telefonoDigits, 0, 3),
                substr($telefonoDigits, 3, 3),
                substr($telefonoDigits, 6, 4)
            );
        } else {
            $telefono = '';
        }

        if ($nombre === '' && in_array($accion, ['agregar', 'actualizar'], true)) {
            $error = 'El nombre de sucursal es obligatorio.';
        }

        if ($error === '' && $accion === 'agregar') {
            try {
                $insertColumns = ['nombre'];
                $insertParams = [':nombre' => $nombre];
                if ($hasDireccion) {
                    $insertColumns[] = 'direccion';
                    $insertParams[':direccion'] = $direccion !== '' ? $direccion : null;
                } elseif ($hasUbicacion) {
                    $insertColumns[] = 'ubicacion';
                    $insertParams[':ubicacion'] = $direccion !== '' ? $direccion : null;
                }
                if ($hasTelefono) {
                    $insertColumns[] = 'telefono';
                    $insertParams[':telefono'] = $telefono !== '' ? $telefono : null;
                }

                $placeholders = array_map(static fn(string $col): string => ':' . $col, $insertColumns);
                $sqlInsert = 'INSERT INTO almacenes (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $placeholders) . ')';
                $stmt = $pdo->prepare($sqlInsert);
                $stmt->execute($insertParams);
                $success = "Sucursal '$nombre' creada correctamente.";
            } catch (PDOException $e) {
                $error = "Error al crear sucursal: " . $e->getMessage();
            }
        } elseif ($error === '' && $accion === 'actualizar') {
            if ($idAlmacen <= 0) {
                $error = 'Sucursal invalida para actualizar.';
            } else {
                try {
                    $setParts = ['nombre = :nombre'];
                    $params = [':nombre' => $nombre, ':id_almacen' => $idAlmacen];

                    if ($hasDireccion) {
                        $setParts[] = 'direccion = :direccion';
                        $params[':direccion'] = $direccion !== '' ? $direccion : null;
                    } elseif ($hasUbicacion) {
                        $setParts[] = 'ubicacion = :ubicacion';
                        $params[':ubicacion'] = $direccion !== '' ? $direccion : null;
                    }

                    if ($hasTelefono) {
                        $setParts[] = 'telefono = :telefono';
                        $params[':telefono'] = $telefono !== '' ? $telefono : null;
                    }

                    $stmt = $pdo->prepare('UPDATE almacenes SET ' . implode(', ', $setParts) . ' WHERE id_almacen = :id_almacen');
                    $stmt->execute($params);
                    $success = 'Sucursal actualizada correctamente.';
                } catch (PDOException $e) {
                    $error = 'Error al actualizar sucursal: ' . $e->getMessage();
                }
            }
        } elseif ($error === '' && $accion === 'cambiar_estado') {
            if ($idAlmacen <= 0) {
                $error = 'Sucursal invalida para actualizar estado.';
            } else {
                $nuevoEstado = ($_POST['nuevo_estado'] ?? '') === 'inactivo' ? 'inactivo' : 'activo';
                try {
                    $stmt = $pdo->prepare("UPDATE almacenes SET estado = ? WHERE id_almacen = ?");
                    $stmt->execute([$nuevoEstado, $idAlmacen]);
                    $success = 'Estado de sucursal actualizado.';
                } catch (PDOException $e) {
                    $error = 'Error al cambiar estado: ' . $e->getMessage();
                }
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
$locationSelect = $hasDireccion
    ? 'a.direccion'
    : ($hasUbicacion ? 'a.ubicacion' : 'NULL');
$phoneSelect = $hasTelefono ? 'a.telefono' : 'NULL';
$sqlSucursales = "SELECT a.*, {$locationSelect} AS direccion_visible, {$phoneSelect} AS telefono_visible FROM almacenes a ORDER BY a.nombre ASC";
$sucursales = $pdo->query($sqlSucursales)->fetchAll();

$editSucursal = null;
$editId = (int)($_GET['editar'] ?? 0);
if ($editId > 0) {
    $sqlEdit = "SELECT a.*, {$locationSelect} AS direccion_visible, {$phoneSelect} AS telefono_visible FROM almacenes a WHERE a.id_almacen = :id LIMIT 1";
    $stmtEdit = $pdo->prepare($sqlEdit);
    try {
        $stmtEdit->execute([':id' => $editId]);
        $editSucursal = $stmtEdit->fetch() ?: null;
        if (!$editSucursal) {
            $error = 'No se encontro la sucursal seleccionada para editar.';
        }
    } catch (Throwable $e) {
        $error = 'No se pudo cargar la sucursal para editar: ' . $e->getMessage();
    }
}

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
                    <span class="card-title"><?php echo $editSucursal ? 'Editar Sucursal' : 'Nueva Sucursal'; ?></span>
                    <form method="POST">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="accion" value="<?php echo $editSucursal ? 'actualizar' : 'agregar'; ?>">
                        <input type="hidden" name="id_almacen" value="<?php echo (int)($editSucursal['id_almacen'] ?? 0); ?>">
                        <div class="input-field">
                            <input type="text" name="nombre" id="nombre" required value="<?php echo esc((string)($editSucursal['nombre'] ?? '')); ?>">
                            <label for="nombre">Nombre de la Sucursal</label>
                        </div>
                        <div class="input-field">
                            <input type="text" name="direccion" id="direccion" value="<?php echo esc((string)($editSucursal['direccion_visible'] ?? '')); ?>">
                            <label for="direccion">Dirección</label>
                        </div>
                        <div class="input-field">
                            <input type="tel" name="telefono" id="telefono" value="<?php echo esc((string)($editSucursal['telefono_visible'] ?? '')); ?>" placeholder="Ej: (331) - 863 - 5185" maxlength="19" inputmode="numeric" autocomplete="tel-national">
                            <label for="telefono">Teléfono</label>
                            <span class="helper-text">Formato: 10 dígitos.</span>
                        </div>
                        <button type="submit" class="btn blue darken-4 w-100"><?php echo $editSucursal ? 'Guardar Cambios' : 'Crear Sucursal'; ?></button>
                        <?php if ($editSucursal): ?>
                            <a href="manage_branches.php" class="btn-flat w-100 center" style="margin-top: 6px;">Cancelar Edicion</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-content">
                    <span class="card-title"><i class="material-icons left">local_offer</i> Incentivo Sucursal</span>
                    <p class="grey-text" style="margin-top: 0;">Define incentivo por piezas con un JSON de tramos. El valor configurado se aplica como descuento por pieza.</p>
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
                            <label for="descuento_por_piezas" class="active">Descuento por pieza segun tramo (JSON)</label>
                            <div class="helper-text" style="display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom: 6px;">
                                <span>Configura cuanto se descuenta por cada pieza segun la cantidad total del ticket.</span>
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
                                            <span class="discount-example-text">Escalado lineal por pieza (2 piezas = 30 x 2 = 60).</span>
                                            <button type="button" class="btn-flat blue-text text-darken-3 discount-example-action" data-action="use" data-example='{"1":15,"2":30,"3":45}'>Usar</button>
                                            <button type="button" class="btn-flat teal-text text-darken-3 discount-example-action" data-action="copy" data-example='{"1":15,"2":30,"3":45}'>Copiar</button>
                                        </li>
                                        <li class="discount-example-item">
                                            <code>{"1":10,"3":35,"5":70}</code>
                                            <span class="discount-example-text">Escalado por tramos por pieza (2 piezas usa 10 c/u, 4 usa 35 c/u).</span>
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
                                    <p style="margin:8px 0 0 0; font-size:0.85rem; color:#555;">Tip: si no existe tramo exacto, se usa el tramo menor mas cercano. Total incentivo = descuento por pieza x piezas.</p>
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
                                <th>Dirección</th>
                                <th>Teléfono</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($sucursales as $s): ?>
                                <tr>
                                    <td><strong><?php echo esc($s['nombre']); ?></strong></td>
                                    <td><?php echo esc((string)($s['direccion_visible'] ?? '')); ?></td>
                                    <td><?php echo esc((string)($s['telefono_visible'] ?? '')); ?></td>
                                    <td>
                                        <span class="badge <?php echo $s['estado'] === 'activo' ? 'green' : 'red'; ?> white-text" style="float:none;">
                                            <?php echo strtoupper($s['estado']); ?>
                                        </span>
                                    </td>
                                    <td style="white-space: nowrap;">
                                        <a href="manage_branches.php?editar=<?php echo (int)$s['id_almacen']; ?>" class="btn-small blue waves-effect waves-light" title="Editar sucursal">
                                            <i class="material-icons">edit</i>
                                        </a>
                                        <form method="POST" style="display:inline; margin-left:4px;">
                                            <?php echo csrfInput(); ?>
                                            <input type="hidden" name="accion" value="cambiar_estado">
                                            <input type="hidden" name="id_almacen" value="<?php echo (int)$s['id_almacen']; ?>">
                                            <input type="hidden" name="nuevo_estado" value="<?php echo $s['estado'] === 'activo' ? 'inactivo' : 'activo'; ?>">
                                            <button type="submit" class="btn-small <?php echo $s['estado'] === 'activo' ? 'orange darken-3' : 'green darken-2'; ?> waves-effect waves-light" title="Cambiar estado">
                                                <i class="material-icons"><?php echo $s['estado'] === 'activo' ? 'pause' : 'check'; ?></i>
                                            </button>
                                        </form>
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
    const phoneInput = document.getElementById('telefono');

    function formatPhoneMx(digits) {
        if (!digits) return '';
        if (digits.length <= 3) return `(${digits}`;
        if (digits.length <= 6) return `(${digits.slice(0, 3)}) - ${digits.slice(3)}`;
        return `(${digits.slice(0, 3)}) - ${digits.slice(3, 6)} - ${digits.slice(6, 10)}`;
    }

    function validatePhone() {
        if (!phoneInput) return true;
        const digits = (phoneInput.value || '').replace(/\D/g, '').slice(0, 10);
        if (digits.length > 0 && digits.length !== 10) {
            phoneInput.setCustomValidity('El teléfono debe tener 10 dígitos.');
            return false;
        }
        phoneInput.setCustomValidity('');
        return true;
    }

    if (phoneInput) {
        phoneInput.addEventListener('input', function() {
            const digits = (phoneInput.value || '').replace(/\D/g, '').slice(0, 10);
            phoneInput.value = formatPhoneMx(digits);
            validatePhone();
        });

        phoneInput.addEventListener('blur', function() {
            const digits = (phoneInput.value || '').replace(/\D/g, '').slice(0, 10);
            phoneInput.value = formatPhoneMx(digits);
            validatePhone();
        });

        const initialDigits = (phoneInput.value || '').replace(/\D/g, '').slice(0, 10);
        phoneInput.value = formatPhoneMx(initialDigits);
        validatePhone();
    }

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