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

$pageTitle = 'Asistente de IA (WhatsApp)';
$pdo = getPDO();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar_config') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF invalido.';
    } else {
        $activo = !empty($_POST['activo']) ? 1 : 0;
        $nombrePersona = trim((string)($_POST['nombre_persona'] ?? 'Alex'));
        $tono = trim((string)($_POST['tono_instrucciones'] ?? ''));
        $promo = trim((string)($_POST['promocion_vigente_texto'] ?? ''));
        $envio = trim((string)($_POST['politica_envio_texto'] ?? ''));
        $pago = trim((string)($_POST['politica_pago_texto'] ?? ''));
        $ubicacion = trim((string)($_POST['ubicacion_texto'] ?? ''));
        $bienvenida = trim((string)($_POST['mensaje_bienvenida'] ?? ''));
        $modelo = trim((string)($_POST['modelo_llm'] ?? 'deepseek-chat'));
        $temperatura = (float)($_POST['temperatura'] ?? 0.30);
        $promptOverride = trim((string)($_POST['prompt_sistema_override'] ?? ''));
        $apiKeyVariable = trim((string)($_POST['api_key_variable'] ?? 'DEEPSEEK_AI_ASSISTANT'));

        if ($nombrePersona === '') {
            $nombrePersona = 'Alex';
        }
        if (!in_array($modelo, ['deepseek-chat', 'deepseek-reasoner'], true)) {
            $modelo = 'deepseek-chat';
        }
        $temperatura = max(0.0, min(2.0, $temperatura));
        if ($apiKeyVariable === '' || !preg_match('/^[A-Z0-9_]+$/', $apiKeyVariable)) {
            $apiKeyVariable = 'DEEPSEEK_AI_ASSISTANT';
        }

        try {
            $stmt = $pdo->prepare(
                'INSERT INTO ai_asistente_config
                    (id_config, activo, nombre_persona, tono_instrucciones, promocion_vigente_texto, politica_envio_texto, politica_pago_texto, ubicacion_texto, mensaje_bienvenida, modelo_llm, temperatura, prompt_sistema_override, api_key_variable)
                 VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                    activo = VALUES(activo),
                    nombre_persona = VALUES(nombre_persona),
                    tono_instrucciones = VALUES(tono_instrucciones),
                    promocion_vigente_texto = VALUES(promocion_vigente_texto),
                    politica_envio_texto = VALUES(politica_envio_texto),
                    politica_pago_texto = VALUES(politica_pago_texto),
                    ubicacion_texto = VALUES(ubicacion_texto),
                    mensaje_bienvenida = VALUES(mensaje_bienvenida),
                    modelo_llm = VALUES(modelo_llm),
                    temperatura = VALUES(temperatura),
                    prompt_sistema_override = VALUES(prompt_sistema_override),
                    api_key_variable = VALUES(api_key_variable)'
            );
            $stmt->execute([$activo, $nombrePersona, $tono, $promo, $envio, $pago, $ubicacion, $bienvenida, $modelo, $temperatura, $promptOverride, $apiKeyVariable]);
            $success = 'Configuracion del asistente actualizada.';
        } catch (Throwable $e) {
            $error = 'Error al guardar la configuracion: ' . $e->getMessage();
        }
    }
}

$config = aiGetConfig($pdo);

$conversaciones = [];
$pendientesCount = 0;
$allTags = [];
try {
    $conversaciones = $pdo->query(
        'SELECT id_conversacion, wa_id, nombre_perfil, estado_bot, motivo_transferencia, ultimo_mensaje_en
         FROM whatsapp_conversaciones
         ORDER BY ultimo_mensaje_en DESC
         LIMIT 50'
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($conversaciones as &$conv) {
        $conv['tags'] = aiGetConversationTags($pdo, (int)$conv['id_conversacion']);
        if ($conv['estado_bot'] !== 'activo') {
            $pendientesCount++;
        }
    }
    unset($conv);

    $allTags = aiGetAllTags($pdo);
} catch (Throwable $e) {
    // La tabla puede no existir todavia si la migracion no se ha aplicado en este entorno.
}

include __DIR__ . '/includes/header.php';
?>

<div class="container">
    <div class="row">
        <div class="col s12">
            <div style="display: flex; align-items: center; justify-content: space-between; margin-top: 20px; flex-wrap: wrap; gap: 10px;">
                <h4 style="margin: 0;">
                    <i class="material-icons left">smart_toy</i> Asistente de IA (WhatsApp)
                    <?php if ($pendientesCount > 0): ?>
                        <span class="new badge red" data-badge-caption="esperando atencion" style="position: static; margin-left: 8px;"><?php echo (int)$pendientesCount; ?></span>
                    <?php endif; ?>
                </h4>
                <div>
                    <a href="ai_diagnostics.php" class="btn-flat waves-effect">Diagnostico de errores</a>
                    <a href="dashboard.php" class="btn blue darken-4 waves-effect waves-light"><i class="material-icons left">dashboard</i> Volver al Dashboard</a>
                </div>
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
        <div class="col s12 m6">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Configuracion del asistente</span>
                    <p class="grey-text" style="margin-top: 0;">
                        La promocion vigente que aparece en la tienda se administra en
                        <a href="manage_branches.php">Gestionar Sucursales</a>; el texto de aqui es lo que Alex dice por WhatsApp y puede redactarse distinto.
                    </p>
                    <form method="POST">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="accion" value="guardar_config">

                        <p>
                            <label>
                                <input type="checkbox" name="activo" value="1" <?php echo !empty($config['activo']) ? 'checked' : ''; ?>>
                                <span>Asistente activo (interruptor general)</span>
                            </label>
                        </p>

                        <div class="input-field">
                            <input type="text" name="nombre_persona" id="nombre_persona" value="<?php echo esc((string)$config['nombre_persona']); ?>" maxlength="60">
                            <label for="nombre_persona" class="active">Nombre de la persona (ej. Alex)</label>
                        </div>

                        <div class="input-field">
                            <select name="modelo_llm" id="modelo_llm">
                                <option value="deepseek-chat" <?php echo $config['modelo_llm'] === 'deepseek-chat' ? 'selected' : ''; ?>>deepseek-chat (V3, recomendado)</option>
                                <option value="deepseek-reasoner" <?php echo $config['modelo_llm'] === 'deepseek-reasoner' ? 'selected' : ''; ?>>deepseek-reasoner (R1, experimental)</option>
                            </select>
                            <label>Modelo DeepSeek</label>
                        </div>

                        <div class="input-field">
                            <input type="number" name="temperatura" id="temperatura" value="<?php echo esc((string)$config['temperatura']); ?>" min="0" max="2" step="0.05">
                            <label for="temperatura" class="active">Temperatura (0 = mas literal, 2 = mas creativo)</label>
                        </div>

                        <div class="input-field">
                            <input type="text" name="api_key_variable" id="api_key_variable" value="<?php echo esc((string)$config['api_key_variable']); ?>" maxlength="80" pattern="[A-Z0-9_]+" style="text-transform: uppercase;">
                            <label for="api_key_variable" class="active">Variable de entorno con la API key de DeepSeek</label>
                            <span class="helper-text">Nombre de la variable de entorno/secreto (ej. DEEPSEEK_AI_ASSISTANT) que contiene la API key. Debe existir con ese mismo nombre en core/app_secrets.*.php o Google Secret Manager.</span>
                        </div>

                        <div class="input-field">
                            <textarea class="materialize-textarea" name="promocion_vigente_texto" id="promocion_vigente_texto" rows="2"><?php echo esc((string)$config['promocion_vigente_texto']); ?></textarea>
                            <label for="promocion_vigente_texto" class="active">Promocion vigente (texto libre)</label>
                        </div>

                        <div class="input-field">
                            <textarea class="materialize-textarea" name="politica_envio_texto" id="politica_envio_texto" rows="2"><?php echo esc((string)$config['politica_envio_texto']); ?></textarea>
                            <label for="politica_envio_texto" class="active">Politica de envio</label>
                        </div>

                        <div class="input-field">
                            <textarea class="materialize-textarea" name="politica_pago_texto" id="politica_pago_texto" rows="2"><?php echo esc((string)$config['politica_pago_texto']); ?></textarea>
                            <label for="politica_pago_texto" class="active">Politica de pago</label>
                        </div>

                        <div class="input-field">
                            <textarea class="materialize-textarea" name="ubicacion_texto" id="ubicacion_texto" rows="2"><?php echo esc((string)$config['ubicacion_texto']); ?></textarea>
                            <label for="ubicacion_texto" class="active">Ubicacion del negocio (direccion + link de Maps)</label>
                            <span class="helper-text">Lo que Alex responde cuando preguntan por sucursales o donde se encuentran.</span>
                        </div>

                        <div class="input-field">
                            <textarea class="materialize-textarea" name="mensaje_bienvenida" id="mensaje_bienvenida" rows="2"><?php echo esc((string)$config['mensaje_bienvenida']); ?></textarea>
                            <label for="mensaje_bienvenida" class="active">Mensaje de bienvenida (opcional)</label>
                        </div>

                        <div class="input-field">
                            <textarea class="materialize-textarea" name="tono_instrucciones" id="tono_instrucciones" rows="3"><?php echo esc((string)$config['tono_instrucciones']); ?></textarea>
                            <label for="tono_instrucciones" class="active">Instrucciones adicionales de tono/negocio</label>
                        </div>

                        <div class="input-field">
                            <textarea class="materialize-textarea" name="prompt_sistema_override" id="prompt_sistema_override" rows="5"><?php echo esc((string)$config['prompt_sistema_override']); ?></textarea>
                            <label for="prompt_sistema_override" class="active">Prompt de sistema completo (modo experto, opcional)</label>
                            <span class="helper-text">Si se llena, reemplaza TODO el prompt compuesto de arriba (persona/tono/flujo/promocion/politicas). Las reglas de seguridad y privacidad se siguen aplicando siempre. Dejalo vacio para usar el prompt compuesto normal.</span>
                        </div>

                        <button type="submit" class="btn blue darken-4 w-100">Guardar Configuracion</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col s12 m6">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Conversaciones recientes de WhatsApp</span>
                    <p class="grey-text" style="margin-top: 0;">Cuando Alex transfiere a un cliente, la conversacion queda pausada hasta que un asesor la reactiva aqui.</p>
                    <table class="responsive-table">
                        <thead>
                            <tr>
                                <th>WhatsApp</th>
                                <th>Estado</th>
                                <th>Motivo</th>
                                <th>Etiquetas</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($conversaciones)): ?>
                                <tr><td colspan="5">Sin conversaciones todavia.</td></tr>
                            <?php else: ?>
                                <?php foreach ($conversaciones as $conv): ?>
                                    <tr>
                                        <td>
                                            <?php echo esc((string)($conv['nombre_perfil'] ?: $conv['wa_id'])); ?><br>
                                            <span class="grey-text text-darken-1" style="font-size: 12px;"><?php echo esc((string)$conv['wa_id']); ?></span>
                                        </td>
                                        <td><?php echo esc((string)$conv['estado_bot']); ?></td>
                                        <td><?php echo esc((string)($conv['motivo_transferencia'] ?? '')); ?></td>
                                        <td>
                                            <div class="conv-tags" data-id="<?php echo (int)$conv['id_conversacion']; ?>" style="display:flex; flex-wrap:wrap; gap:4px; align-items:center;">
                                                <?php foreach ($conv['tags'] as $tag): ?>
                                                    <div class="chip <?php echo esc((string)$tag['color']); ?> lighten-4" style="margin: 2px;">
                                                        <?php echo esc((string)$tag['nombre']); ?>
                                                        <i class="close material-icons btn-quitar-etiqueta" data-id-etiqueta="<?php echo (int)$tag['id_etiqueta']; ?>">close</i>
                                                    </div>
                                                <?php endforeach; ?>
                                                <select class="select-agregar-etiqueta browser-default" style="width: auto; height: 26px; font-size: 12px; display:inline-block;">
                                                    <option value="">+ etiqueta</option>
                                                    <?php foreach ($allTags as $tag): ?>
                                                        <option value="<?php echo esc((string)$tag['nombre']); ?>"><?php echo esc((string)$tag['nombre']); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </td>
                                        <td>
                                            <?php if ($conv['estado_bot'] !== 'activo'): ?>
                                                <button type="button" class="btn-small green darken-1 btn-reactivar-bot" data-id="<?php echo (int)$conv['id_conversacion']; ?>">Reactivar bot</button>
                                            <?php endif; ?>
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

<script>
(function () {
    var csrfToken = <?php echo json_encode(getCsrfToken()); ?>;

    document.querySelectorAll('.btn-reactivar-bot').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var idConversacion = btn.getAttribute('data-id');
            btn.disabled = true;

            fetch('<?php echo BASE_URL; ?>api/ai_assistant_admin.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'reactivate_bot',
                    id_conversacion: idConversacion,
                    csrf_token: csrfToken
                })
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert(data.message || 'No se pudo reactivar el bot.');
                        btn.disabled = false;
                    }
                })
                .catch(function () {
                    alert('Error de conexion al reactivar el bot.');
                    btn.disabled = false;
                });
        });
    });

    function callAdmin(payload) {
        return fetch('<?php echo BASE_URL; ?>api/ai_assistant_admin.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ csrf_token: csrfToken }, payload))
        }).then(function (res) { return res.json(); });
    }

    document.querySelectorAll('.select-agregar-etiqueta').forEach(function (select) {
        select.addEventListener('change', function () {
            var nombreEtiqueta = select.value;
            if (!nombreEtiqueta) {
                return;
            }
            var idConversacion = select.closest('.conv-tags').getAttribute('data-id');

            callAdmin({ action: 'add_tag', id_conversacion: idConversacion, nombre_etiqueta: nombreEtiqueta })
                .then(function (data) {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert(data.message || 'No se pudo agregar la etiqueta.');
                    }
                })
                .catch(function () { alert('Error de conexion al agregar la etiqueta.'); });
        });
    });

    document.querySelectorAll('.btn-quitar-etiqueta').forEach(function (icon) {
        icon.addEventListener('click', function () {
            var idConversacion = icon.closest('.conv-tags').getAttribute('data-id');
            var idEtiqueta = icon.getAttribute('data-id-etiqueta');

            callAdmin({ action: 'remove_tag', id_conversacion: idConversacion, id_etiqueta: idEtiqueta })
                .then(function (data) {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert(data.message || 'No se pudo quitar la etiqueta.');
                    }
                })
                .catch(function () { alert('Error de conexion al quitar la etiqueta.'); });
        });
    });
})();
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
