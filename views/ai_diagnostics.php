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
$temasGlobales = [];
try {
    $errores = aiGetDiagnosticErrors($pdo, !$verTodos, 200);
    $pendientesCount = aiCountUnresolvedDiagnosticErrors($pdo);
    $reglas = aiGetAllLearningRules($pdo);
    $etiquetas = aiGetAllTags($pdo);
    $temasGlobales = aiGetTopHistorialTemasGlobal($pdo);
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
            <ul class="collapsible z-depth-1" id="ayuda-diagnostico">
                <li>
                    <div class="collapsible-header" style="display: flex; align-items: center; gap: 8px;">
                        <i class="material-icons">help_outline</i> <strong>Que hago aqui? (guia rapida)</strong>
                    </div>
                    <div class="collapsible-body white">
                        <p><strong>Que es esta pantalla.</strong> Cada vez que Alex no pudo resolver algo solo (le faltaron datos, DeepSeek fallo, o no tuvo la certeza para contestar), queda un registro aqui abajo. Es tu forma de revisar esos casos y, si hace falta, "ensenarle" a Alex la respuesta correcta sin tocar codigo.</p>

                        <p><strong>Que significa cada tipo de incidente:</strong></p>
                        <ul class="browser-default" style="margin-bottom: 16px;">
                            <li><span class="chip">venta_sin_direccion</span> El cliente quiso comprar pero no dio su direccion completa. Alex ya lo registro como cliente/pedido pendiente; solo falta que alguien complete la direccion o confirme con el cliente.</li>
                            <li><span class="chip">tool_datos_incompletos</span> Alex intento usar una herramienta (buscar producto, agendar venta, etc.) pero le faltaron datos o la accion no se pudo completar del todo.</li>
                            <li><span class="chip">tool_excepcion</span> Ocurrio un error tecnico al ejecutar una herramienta (por ejemplo, un problema de base de datos). Vale la pena revisar el detalle por si es un bug real.</li>
                            <li><span class="chip">deepseek_conexion</span> El modelo de IA (DeepSeek) no respondio o fallo la conexion. Alex transfirio la conversacion a un humano automaticamente.</li>
                            <li><span class="chip">pase_a_humano_incertidumbre</span> Alex prefirio no arriesgarse a inventar una respuesta (precios exactos, promesas medicas, preguntas muy especificas) y paso la conversacion a una persona. Esto es un comportamiento correcto, no necesariamente un error.</li>
                        </ul>

                        <p><strong>Que hacer con cada fila:</strong></p>
                        <ol style="padding-left: 20px;">
                            <li>Da clic en "Ver detalle" para leer el contexto completo (que pregunto el cliente, que datos tenia Alex).</li>
                            <li>Si fue un caso aislado y no se puede repetir (o ya se atendio manualmente), da clic en <strong>"Marcar revisado"</strong> para sacarlo de la lista de pendientes.</li>
                            <li>Si crees que esa situacion se va a repetir y quieres que Alex la maneje mejor la proxima vez, da clic en <strong>"Convertir en regla"</strong>: describe la situacion y como debio responder o actuar Alex, y guarda. Eso marca el incidente como revisado automaticamente.</li>
                        </ol>

                        <p><strong>Reglas de aprendizaje (la tabla de abajo).</strong> Cada regla que creas se le muestra a Alex como ejemplo dentro de sus instrucciones, para que no repita el mismo error. Puedes apagar una regla con el switch de "Activa" sin necesidad de borrarla, por si quieres probar sin ella.</p>

                        <p><strong>Temas y productos mas mencionados.</strong> Esta tabla se llena sola, por codigo (sin gastar nada de IA): cada vez que corre el analisis periodico del historial de WhatsApp, cuenta cuantas veces se menciona cada producto real del catalogo y algunas palabras clave de negocio (envio, pago, garantia, etc.) en los mensajes de los clientes. Es informacion cruda para que TU decidas que vale la pena convertir en regla de aprendizaje manual -- ninguna IA genera reglas por su cuenta.</p>

                        <p class="grey-text" style="margin-bottom: 0;"><strong>Tip:</strong> usa el filtro "Sin revisar" para enfocarte solo en lo pendiente, y "Todos" cuando quieras ver el historial completo, incluido lo ya revisado.</p>
                    </div>
                </li>
            </ul>
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
                    <span class="card-title">Temas y productos mas mencionados</span>
                    <p class="grey-text" style="margin-top: 0;">Contado por codigo (sin IA) a partir del historial de WhatsApp. Util para decidir manualmente que convertir en regla de aprendizaje.</p>
                    <table class="responsive-table">
                        <thead>
                            <tr>
                                <th>Tema / producto</th>
                                <th>Tipo</th>
                                <th>Menciones</th>
                                <th>Clientes distintos</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($temasGlobales)): ?>
                                <tr><td colspan="4">Todavia no hay datos suficientes. Corre el analisis del historial para empezar a ver esta tabla.</td></tr>
                            <?php else: ?>
                                <?php foreach ($temasGlobales as $tema): ?>
                                    <tr>
                                        <td><?php echo esc((string)$tema['valor']); ?></td>
                                        <td><span class="chip"><?php echo (string)$tema['tipo'] === 'producto' ? 'Producto' : 'Tema general'; ?></span></td>
                                        <td><?php echo (int)$tema['total_menciones']; ?></td>
                                        <td><?php echo (int)$tema['total_clientes']; ?></td>
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
document.addEventListener('DOMContentLoaded', function () {
    var csrfToken = <?php echo json_encode(getCsrfToken()); ?>;

    var ayudaElem = document.getElementById('ayuda-diagnostico');
    if (ayudaElem) {
        M.Collapsible.init(ayudaElem, { accordion: false });
    }

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
});
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
