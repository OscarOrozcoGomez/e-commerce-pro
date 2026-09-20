<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/ai_assistant.php';

// Solo existe en local/QA -- nunca en produccion (ver api/alex_playground.php, mismo candado).
if (IS_PRODUCTION) {
    http_response_code(404);
    exit;
}

requireAuth();
if (!hasPermission('gestionar_asistente_ia') && !isAdmin()) {
    header('Location: ' . BASE_URL . 'views/dashboard.php');
    exit;
}

$csrfToken = getCsrfToken();
$waIdSugerido = AI_PLAYGROUND_WA_PREFIX . str_pad((string)random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Alex Playground (local)</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    :root { color-scheme: light; }
    body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; margin: 0; background: #f0f2f5; color: #111; }
    header { background: #075e54; color: #fff; padding: 14px 20px; }
    header h1 { font-size: 16px; margin: 0; }
    header p { font-size: 12px; margin: 4px 0 0; opacity: .85; }
    .layout { display: flex; gap: 16px; padding: 16px; max-width: 1100px; margin: 0 auto; }
    .panel { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.15); padding: 14px; }
    .chat-col { flex: 2; display: flex; flex-direction: column; min-height: 70vh; }
    .side-col { flex: 1; }
    .chat-window { flex: 1; overflow-y: auto; padding: 10px; background: #e5ddd5; border-radius: 6px; margin-bottom: 10px; min-height: 400px; }
    .bubble { max-width: 75%; margin: 6px 0; padding: 8px 12px; border-radius: 8px; font-size: 14px; white-space: pre-wrap; line-height: 1.35; }
    .bubble.user { background: #fff; margin-right: auto; }
    .bubble.assistant { background: #dcf8c6; margin-left: auto; }
    .bubble.humano { background: #cfe8ff; margin-left: auto; border: 1px dashed #5b9bd5; }
    .bubble.bloqueado { background: #ffe0e0; margin-left: auto; border: 1px dashed #c0392b; color: #7a1f1f; }
    .bubble .tag { display: block; font-size: 10px; text-transform: uppercase; opacity: .6; margin-bottom: 3px; }
    .row { display: flex; gap: 8px; margin-bottom: 8px; }
    input[type=text], textarea { flex: 1; padding: 8px; border: 1px solid #ccc; border-radius: 6px; font-size: 14px; }
    button { padding: 8px 14px; border: none; border-radius: 6px; background: #128c7e; color: #fff; cursor: pointer; font-size: 13px; }
    button.secundario { background: #5b9bd5; }
    button.peligro { background: #c0392b; }
    button:disabled { opacity: .5; cursor: default; }
    label { font-size: 12px; color: #555; display: block; margin: 10px 0 4px; }
    #estadoBadge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 600; }
    #estadoBadge.activo { background: #d4edda; color: #155724; }
    #estadoBadge.pausado { background: #f8d7da; color: #721c24; }
    #log { font-size: 11px; color: #666; max-height: 200px; overflow-y: auto; white-space: pre-wrap; }
</style>
</head>
<body>
<header>
    <h1>🧪 Alex Playground -- solo local, nunca en producción</h1>
    <p>Recrea literal las peticiones que hace el puente real: POST a whatsapp_webhook.php, espera un delay, POST a whatsapp_confirmar_envio.php. Usa inventario/DeepSeek reales; jamás manda nada a WhatsApp de verdad ni alertas reales de Telegram.</p>
</header>
<div class="layout">
    <div class="panel chat-col">
        <div>
            wa_id de prueba: <code id="waIdLabel"></code>
            &nbsp; Estado: <span id="estadoBadge" class="activo">activo</span>
        </div>
        <div class="chat-window" id="chatWindow"></div>

        <div class="row">
            <input type="text" id="inputCliente" placeholder="Mensaje como CLIENTE...">
            <button id="btnCliente">Enviar</button>
        </div>
        <div class="row">
            <input type="text" id="inputAsesor" placeholder="Mensaje como ASESOR (desde el celular)...">
            <button id="btnAsesor" class="secundario">Simular asesor</button>
        </div>
    </div>

    <div class="panel side-col">
        <label for="delaySegundos">Delay de envío simulado (segundos, real = 60-120s)</label>
        <input type="text" id="delaySegundos" value="3" style="width:60px">

        <label for="messageKind">message_kind del mensaje de cliente</label>
        <select id="messageKind">
            <option value="text">text</option>
            <option value="other">other (mensaje de sistema/no interpretable)</option>
            <option value="sticker">sticker</option>
            <option value="location">location</option>
            <option value="image">image</option>
        </select>

        <p style="font-size:12px;color:#555;margin-top:16px;">
            Para reproducir la condición de carrera: manda un mensaje de cliente con delay alto (ej. 15s),
            y ANTES de que termine, usa "Simular asesor" en este mismo chat -- la respuesta de Alex debe
            aparecer marcada como bloqueada (no enviada).
        </p>

        <button id="btnReiniciar" class="peligro" style="width:100%;margin-top:10px;">Reiniciar esta conversación</button>
        <button id="btnNueva" style="width:100%;margin-top:8px;background:#888;">Nueva conversación (wa_id nuevo)</button>

        <label>Log</label>
        <div id="log"></div>
    </div>
</div>

<script>
const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
const WA_PREFIX = <?php echo json_encode(AI_PLAYGROUND_WA_PREFIX); ?>;
let waId = localStorage.getItem('alex_playground_wa_id') || <?php echo json_encode($waIdSugerido); ?>;
localStorage.setItem('alex_playground_wa_id', waId);
document.getElementById('waIdLabel').textContent = waId;

function nuevoWaId() {
    const rnd = String(Math.floor(Math.random() * 10000000)).padStart(7, '0');
    waId = WA_PREFIX + rnd;
    localStorage.setItem('alex_playground_wa_id', waId);
    document.getElementById('waIdLabel').textContent = waId;
    document.getElementById('chatWindow').innerHTML = '';
    cargarEstado();
}

function log(msg) {
    const el = document.getElementById('log');
    const linea = document.createElement('div');
    linea.textContent = '[' + new Date().toLocaleTimeString() + '] ' + msg;
    el.prepend(linea);
}

function pintarBurbuja(rol, texto, bloqueado) {
    const div = document.createElement('div');
    let clase = rol;
    let etiqueta = rol === 'user' ? 'Cliente' : (rol === 'humano' ? 'Asesor (celular)' : 'Alex');
    if (bloqueado) {
        clase = 'bloqueado';
        etiqueta = 'Alex (NO ENVIADO -- bloqueado por intervención humana)';
    }
    div.className = 'bubble ' + clase;
    div.innerHTML = '<span class="tag">' + etiqueta + '</span>' + texto.replace(/</g, '&lt;');
    document.getElementById('chatWindow').appendChild(div);
    document.getElementById('chatWindow').scrollTop = document.getElementById('chatWindow').scrollHeight;
}

async function llamar(accion, extra) {
    const body = Object.assign({ accion, wa_id: waId, csrf_token: CSRF_TOKEN }, extra || {});
    const res = await fetch('<?php echo BASE_URL; ?>api/alex_playground.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
    });
    const data = await res.json();
    if (!data.success) {
        log('ERROR: ' + (data.message || 'desconocido'));
    }
    return data;
}

async function cargarEstado() {
    const data = await llamar('estado');
    if (!data.success) return;
    const badge = document.getElementById('estadoBadge');
    badge.textContent = data.estado_bot;
    badge.className = data.estado_bot === 'activo' ? 'activo' : 'pausado';
    document.getElementById('chatWindow').innerHTML = '';
    (data.mensajes || []).forEach((m) => {
        pintarBurbuja(m.rol, m.contenido, m.rol === 'assistant' && Number(m.enviado_whatsapp) === 0);
    });
}

document.getElementById('btnCliente').addEventListener('click', async () => {
    const texto = document.getElementById('inputCliente').value.trim();
    if (!texto) return;
    const delay = parseInt(document.getElementById('delaySegundos').value, 10) || 0;
    const kind = document.getElementById('messageKind').value;
    document.getElementById('inputCliente').value = '';
    pintarBurbuja('user', texto, false);
    log('Cliente manda "' + texto + '" (delay=' + delay + 's, kind=' + kind + ')...');
    const data = await llamar('mensaje_cliente', { texto, delay_segundos: delay, message_kind: kind });
    if (!data.success) return;
    if (!data.reply || data.reply.length === 0) {
        log('Alex no contestó nada (revisa: horario, bot pausado, rate limit).');
    } else {
        data.reply.forEach((parte) => {
            if (parte.type === 'text') {
                pintarBurbuja('assistant', parte.text, data.enviado === false);
            } else {
                pintarBurbuja('assistant', '[' + parte.type + '] ' + (parte.caption || parte.url || ''), data.enviado === false);
            }
        });
        log(data.enviado === false ? 'BLOQUEADO: alguien más ya estaba atendiendo cuando terminó el delay.' : 'Enviado.');
    }
    cargarEstado();
});

document.getElementById('btnAsesor').addEventListener('click', async () => {
    const texto = document.getElementById('inputAsesor').value.trim();
    if (!texto) return;
    document.getElementById('inputAsesor').value = '';
    pintarBurbuja('humano', texto, false);
    log('Asesor (celular) manda "' + texto + '"...');
    const data = await llamar('mensaje_asesor', { texto });
    if (data.success) log('Conversación ahora: ' + data.estado_bot);
    cargarEstado();
});

document.getElementById('btnReiniciar').addEventListener('click', async () => {
    if (!confirm('¿Borrar esta conversación de prueba?')) return;
    await llamar('reiniciar');
    document.getElementById('chatWindow').innerHTML = '';
    log('Conversación reiniciada.');
    cargarEstado();
});

document.getElementById('btnNueva').addEventListener('click', nuevoWaId);

cargarEstado();
</script>
</body>
</html>
