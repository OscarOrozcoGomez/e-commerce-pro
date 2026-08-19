<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/ai_assistant.php';

requireAuth();
if (!isAdmin()) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$pageTitle = 'Diagnostico del Asistente de IA';
$pdo = getPDO();

$verTodos = ($_GET['ver'] ?? '') === 'todos';

$errores = [];
$pendientesCount = 0;
$reglas = [];
$etiquetas = [];
try {
    $errores = aiGetDiagnosticErrors($pdo, !$verTodos, 200);
    $pendientesCount = aiCountUnresolvedDiagnosticErrors($pdo);
    $reglas = aiGetAllLearningRules($pdo);
    $etiquetas = aiGetAllTags($pdo);
} catch (Throwable $e) {
    // Las tablas pueden no existir todavia si las migraciones no se han aplicado en este entorno.
}

include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="col s12">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 20px; flex-wrap: wrap; gap: 10px;">
                <h4 style="margin: 0;">
                    <i class="material-icons left">bug_report</i> Diagnostico del Asistente de IA
                    <?php if ($pendientesCount > 0): ?>
                        <span class="new badge red" data-badge-caption="sin revisar" style="position: static; margin-left: 8px;"><?php echo (int) $pendientesCount; ?></span>
                    <?php endif; ?>
                </h4>
                <div>
                    <a href="ai_assistant_settings.php" class="btn-flat waves-effect">Configuracion del asistente</a>
                    <a href="dashboard.php" class="btn blue darken-4 waves-effect waves-light"><i class="material-icons left">dashboard</i> Volver al Dashboard</a>
                </div>
            </div>
            <p class="grey-text">Aqui quedan registrados los problemas que Alex tuvo al atender a un cliente: fallas de herramientas, datos incompletos, caidas de DeepSeek o transferencias por incertidumbre.</p>
        </div>
    </div>

    <div class="row">
        <div class="col s12">
            <a href="?ver=pendientes" class="btn-small <?php echo !$verTodos ? 'blue darken-4' : 'grey lighten-1'; ?> waves-effect">Sin revisar</a>
            <a href="?ver=todos" class="btn-small <?php echo $verTodos ? 'blue darken-4' : 'grey lighten-1'; ?> waves-effect">Todos</a>
        </div>
    </div>

    <div class="row">
        <div class="col s12">
            <div class="card">
                <div class="card-content">
                    <table class="responsive-table">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Cliente</th>
                                <th>Tipo</th>
                                <th>Mensaje del cliente</th>
                                <th>Contexto</th>
                                <th>Estado</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($errores)): ?>
                                <tr><td colspan="7">Sin incidentes registrados.</td></tr>
                            <?php else: ?>
                                <?php foreach ($errores as $err): ?>
                                    <tr>
                                        <td style="white-space: nowrap;"><?php echo esc((string)$err['fecha_creacion']); ?></td>
                                        <td>
                                            <?php echo esc((string)($err['nombre_perfil'] ?: ($err['wa_id'] ?? 'N/D'))); ?><br>
                                            <span class="grey-text text-darken-1" style="font-size: 12px;"><?php echo esc((string)($err['wa_id'] ?? '')); ?></span>
                                        </td>
                                        <td><span class="chip"><?php echo esc((string)$err['tipo_error']); ?></span></td>
                                        <td style="max-width: 220px; white-space: normal;"><?php echo esc((string)($err['mensaje_usuario'] ?? '')); ?></td>
                                        <td style="max-width: 260px;">
                                            <details>
                                                <summary>Ver detalle</summary>
                                                <pre style="white-space: pre-wrap; font-size: 11px; margin: 6px 0 0;"><?php echo esc((string)($err['contexto_error'] ?? '')); ?></pre>
                                            </details>
                                        </td>
                                        <td>
                                            <?php if ((int)$err['resuelto'] === 1): ?>
                                                <span class="green-text text-darken-2">Revisado</span>
                                            <?php else: ?>
                                                <span class="red-text text-darken-2">Sin revisar</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="white-space: nowrap;">
                                            <?php if ((int)$err['resuelto'] === 0): ?>
                                                <button type="button" class="btn-small green darken-1 btn-resolver-error" data-id="<?php echo (int)$err['id_error']; ?>">Marcar revisado</button>
                                            <?php endif; ?>
                                            <button
                                                type="button"
                                                class="btn-small orange darken-1 btn-convertir-regla"
                                                data-id-error="<?php echo (int)$err['id_error']; ?>"
                                                data-contexto="<?php echo esc((string)($err['mensaje_usuario'] ?? '')); ?>"
                                            >Convertir en regla</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col s12">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Reglas de aprendizaje (few-shot)</span>
                    <p class="grey-text" style="margin-top: 0;">Ejemplos que se inyectan en el prompt de Alex para que no repita el mismo error. Se generan desde la columna "Convertir en regla" de arriba, o quedan aqui para activar/desactivar.</p>
                    <table class="responsive-table">
                        <thead>
                            <tr>
                                <th>Situacion</th>
                                <th>Respuesta/accion esperada</th>
                                <th>Etiqueta sugerida</th>
                                <th>Activa</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($reglas)): ?>
                                <tr><td colspan="4">Sin reglas registradas todavia.</td></tr>
                            <?php else: ?>
                                <?php foreach ($reglas as $regla): ?>
                                    <tr>
                                        <td style="max-width: 260px; white-space: normal;"><?php echo esc((string)$regla['contexto_o_pregunta']); ?></td>
                                        <td style="max-width: 260px; white-space: normal;"><?php echo esc((string)$regla['respuesta_o_accion_esperada']); ?></td>
                                        <td><?php echo esc((string)($regla['etiqueta_sugerida'] ?? '')); ?></td>
                                        <td>
                                            <label>
                                                <input
                                                    type="checkbox"
                                                    class="chk-toggle-regla"
                                                    data-id="<?php echo (int)$regla['id_regla']; ?>"
                                                    <?php echo (int)$regla['activa'] === 1 ? 'checked' : ''; ?>
                                                >
                                                <span></span>
                                            </label>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="modal-convertir-regla" class="modal" style="max-width: 560px;">
    <div class="modal-content">
        <h5>Convertir correccion en regla de aprendizaje</h5>
        <input type="hidden" id="regla-id-error">
        <div class="input-field">
            <textarea class="materialize-textarea" id="regla-contexto" rows="2"></textarea>
            <label for="regla-contexto" class="active">Situacion / pregunta del cliente</label>
        </div>
        <div class="input-field">
            <textarea class="materialize-textarea" id="regla-respuesta" rows="3"></textarea>
            <label for="regla-respuesta" class="active">Como debio responder/actuar Alex</label>
        </div>
        <div class="input-field">
            <select id="regla-etiqueta">
                <option value="">(sin etiqueta sugerida)</option>
                <?php foreach ($etiquetas as $tag): ?>
                    <option value="<?php echo esc((string)$tag['nombre']); ?>"><?php echo esc((string)$tag['nombre']); ?></option>
                <?php endforeach; ?>
            </select>
            <label>Etiqueta sugerida (opcional)</label>
        </div>
    </div>
    <div class="modal-footer">
        <a href="#!" class="modal-close btn-flat">Cancelar</a>
        <button type="button" id="btn-guardar-regla" class="btn orange darken-1">Guardar regla</button>
    </div>
</div>

<script>
(function () {
    var csrfToken = <?php echo json_encode(getCsrfToken()); ?>;

    document.querySelectorAll('.btn-resolver-error').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var idError = btn.getAttribute('data-id');
            btn.disabled = true;

            fetch('<?php echo BASE_URL; ?>api/ai_assistant_admin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'resolve_diagnostic_error', id_error: idError, csrf_token: csrfToken })
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert(data.message || 'No se pudo marcar como revisado.');
                        btn.disabled = false;
                    }
                })
                .catch(function () {
                    alert('Error de conexion al marcar el incidente.');
                    btn.disabled = false;
                });
        });
    });

    var modalConvertirElem = document.getElementById('modal-convertir-regla');
    var modalConvertirInstance = M.Modal.init(modalConvertirElem);

    document.querySelectorAll('.btn-convertir-regla').forEach(function (btn) {
        btn.addEventListener('click', function () {
            document.getElementById('regla-id-error').value = btn.getAttribute('data-id-error') || '';
            document.getElementById('regla-contexto').value = btn.getAttribute('data-contexto') || '';
            document.getElementById('regla-respuesta').value = '';
            document.getElementById('regla-etiqueta').value = '';
            M.textareaAutoResize(document.getElementById('regla-contexto'));
            M.updateTextFields();
            var etiquetaSelect = document.getElementById('regla-etiqueta');
            if (window.M && M.FormSelect) {
                M.FormSelect.init(etiquetaSelect);
            }
            modalConvertirInstance.open();
        });
    });

    document.getElementById('btn-guardar-regla').addEventListener('click', function () {
        var idError = document.getElementById('regla-id-error').value;
        var contexto = document.getElementById('regla-contexto').value.trim();
        var respuesta = document.getElementById('regla-respuesta').value.trim();
        var etiqueta = document.getElementById('regla-etiqueta').value;

        if (!contexto || !respuesta) {
            alert('Completa la situacion y la respuesta esperada.');
            return;
        }

        var btn = this;
        btn.disabled = true;

        fetch('<?php echo BASE_URL; ?>api/ai_assistant_admin.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                action: 'create_learning_rule',
                id_error: idError,
                contexto_o_pregunta: contexto,
                respuesta_o_accion_esperada: respuesta,
                etiqueta_sugerida: etiqueta,
                csrf_token: csrfToken
            })
        })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data.success) {
                    window.location.reload();
                } else {
                    alert(data.message || 'No se pudo guardar la regla.');
                    btn.disabled = false;
                }
            })
            .catch(function () {
                alert('Error de conexion al guardar la regla.');
                btn.disabled = false;
            });
    });

    document.querySelectorAll('.chk-toggle-regla').forEach(function (chk) {
        chk.addEventListener('change', function () {
            var idRegla = chk.getAttribute('data-id');
            var activa = chk.checked;
            chk.disabled = true;

            fetch('<?php echo BASE_URL; ?>api/ai_assistant_admin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'toggle_learning_rule', id_regla: idRegla, activa: activa, csrf_token: csrfToken })
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data.success) {
                        alert(data.message || 'No se pudo actualizar la regla.');
                        chk.checked = !activa;
                    }
                    chk.disabled = false;
                })
                .catch(function () {
                    alert('Error de conexion al actualizar la regla.');
                    chk.checked = !activa;
                    chk.disabled = false;
                });
        });
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
