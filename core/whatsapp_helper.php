<?php
declare(strict_types=1);

/**
 * Puente de transporte de WhatsApp: servidor propio en DigitalOcean (Node.js + Baileys).
 *
 * Para respuestas dentro de un turno de conversacion, el flujo es sincrono: el puente hace
 * POST del mensaje entrante a api/whatsapp_webhook.php y esa MISMA respuesta HTTP (JSON) es
 * lo que reenvia al cliente. Pero para mensajes PROACTIVOS (sin que el cliente haya escrito
 * nada, ej. el seguimiento automatico de 24h en scripts/whatsapp_followup_cron.php) no hay
 * a que peticion responder, asi que se necesita un endpoint de envio en el puente
 * (WA_BRIDGE_SEND_URL) al que este archivo si le hace cURL.
 */

// Fallback igual al de core/ai_assistant.php: permite cargar este archivo sin config.php
// (tests), asumiendo que en produccion config.php ya define la version real primero.
if (!function_exists('getEnvVar')) {
    function getEnvVar(string $name, ?string $default = null): ?string
    {
        $value = getenv($name);
        if ($value === false) {
            $value = $_SERVER[$name] ?? $_ENV[$name] ?? null;
        }
        if ($value !== null) {
            $value = trim((string)$value);
            if ($value === '') {
                $value = null;
            }
        }

        return $value ?? $default;
    }
}

function waIsTestMode(): bool
{
    $raw = strtolower((string)(getEnvVar('AI_ASSISTANT_TEST_MODE', '0') ?? '0'));

    return in_array($raw, ['1', 'true', 'yes', 'on'], true);
}

/**
 * Extrae un campo esperado como texto de un payload ya decodificado, rechazando tipos que
 * no sean escalares (arrays/objetos) en vez de dejar que (string) los convierta a "Array"
 * silenciosamente -- eso es justo el vector de "parameter pollution"/confusion de tipos:
 * un atacante mandando "sender_phone": ["x"] no debe colarse como texto valido.
 */
function waExtractScalarString(array $payload, string $key): ?string
{
    if (!array_key_exists($key, $payload)) {
        return null;
    }

    $value = $payload[$key];
    if (!is_scalar($value)) {
        return null;
    }

    return trim((string)$value);
}

/**
 * Limite defensivo de tamano para el body crudo de un webhook, antes de intentar
 * json_decode. php.ini (post_max_size) ya suele cortar peticiones enormes, pero esto es
 * una segunda barrera explicita para no depender solo de la configuracion del servidor.
 */
function waPayloadTooLarge(string $rawBody, int $maxBytes = 262144): bool
{
    return strlen($rawBody) > $maxBytes;
}

/**
 * El puente es un servidor propio (no un proveedor externo como Meta/Green API), por lo
 * que no hay firma criptografica del lado del proveedor. El control de acceso es un token
 * compartido que el puente manda en el header X-Webhook-Token, comparado con WA_WEBHOOK_TOKEN.
 */
function waVerifyWebhookToken(?string $providedToken, ?string $expectedToken): bool
{
    if ($expectedToken === null || trim($expectedToken) === '') {
        return false;
    }
    if ($providedToken === null || trim($providedToken) === '') {
        return false;
    }

    return hash_equals($expectedToken, $providedToken);
}

/**
 * Parsea el payload que manda el puente de DigitalOcean:
 * { "sender_phone": "52133XXXXXXX", "message": "texto", "message_id": "opcional", "from_me": false }
 * Pura y testeable. Regresa null cuando falta el telefono o el mensaje viene vacio, cuando
 * algun campo llega con un tipo no escalar (array/objeto -- confusion de tipos/parameter
 * pollution), o cuando el mensaje excede un largo razonable para un chat de WhatsApp.
 *
 * from_me=true significa que el mensaje lo mando un asesor/repartidor directamente desde
 * la app de WhatsApp del celular (evento fromMe de Baileys), no un cliente. El webhook usa
 * este dato para NO llamar a la IA en ese caso y en cambio pausar el bot automaticamente.
 */
function waParseBridgePayload(array $payload): ?array
{
    $senderPhone = waExtractScalarString($payload, 'sender_phone');
    $message = waExtractScalarString($payload, 'message');

    if ($senderPhone === null || $message === null || $senderPhone === '' || $message === '') {
        return null;
    }

    // WhatsApp limita mensajes de texto a 65536 caracteres; un valor mucho mas grande no es
    // un mensaje legitimo de un cliente, es abuso o un bug del puente.
    if (strlen($senderPhone) > 40 || strlen($message) > 65536) {
        return null;
    }

    $waIdDigits = preg_replace('/\D+/', '', $senderPhone) ?? '';
    // Un numero real de WhatsApp (con codigo de pais) nunca tiene menos de 10 digitos --
    // mismo minimo que usa aiWaIdToMxDigits(). Sin este piso, un telefono corrupto como
    // "abc-123-!!*" se reduce a "123" y se acepta como si fuera un cliente real.
    if (strlen($waIdDigits) < 10) {
        return null;
    }

    // message_id es opcional: Baileys expone msg.key.id, pero si el puente no lo manda
    // todavia se procesa el mensaje, solo que sin proteccion de deduplicado ante reintentos.
    $messageId = waExtractScalarString($payload, 'message_id') ?? '';

    return [
        'wa_id' => $waIdDigits,
        'wa_message_id' => $messageId !== '' ? $messageId : null,
        'texto' => $message,
        'from_me' => !empty($payload['from_me']),
    ];
}

/**
 * Envia un mensaje PROACTIVO (sin peticion entrante que responder), llamando al endpoint
 * de envio que el puente Node.js debe exponer. $replyParts usa el mismo formato que la
 * respuesta sincrona del webhook: [{"type":"text","text":"..."}, {"type":"image"|"document","url":"...","caption":"..."}].
 * El puente debe validar el mismo X-Webhook-Token y mandar cada parte por sock.sendMessage().
 */
function waSendOutboundMessage(string $waId, array $replyParts): array
{
    if (waIsTestMode()) {
        return ['ok' => true, 'test_mode' => true];
    }

    $sendUrl = getEnvVar('WA_BRIDGE_SEND_URL');
    $token = getEnvVar('WA_WEBHOOK_TOKEN');
    if ($sendUrl === null || $token === null) {
        error_log('WARNING: WA_BRIDGE_SEND_URL/WA_WEBHOOK_TOKEN no configurados; no se pudo enviar mensaje saliente.');
        return ['ok' => false];
    }

    $payload = ['to' => $waId, 'reply' => $replyParts];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $sendUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Webhook-Token: ' . $token,
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        error_log('WARNING: fallo al enviar mensaje saliente de WhatsApp. HTTP=' . $httpCode . ' cURL=' . $curlError . ' body=' . substr((string)$response, 0, 300));
        return ['ok' => false];
    }

    return ['ok' => true];
}

/**
 * Parsea el payload que manda el puente al hacer POST a api/whatsapp/sync_labels.php con
 * las etiquetas nativas de WhatsApp Business leidas de getLabels()/App State Sync:
 * { "labels": [{"id":"1","name":"Nuevo cliente","color":"..."}] }
 * Pura y testeable. Regresa null si "labels" no es un arreglo; entradas sin id o nombre
 * validos se descartan individualmente en vez de invalidar todo el lote.
 */
function waParseLabelsSyncPayload(array $payload): ?array
{
    if (!isset($payload['labels']) || !is_array($payload['labels'])) {
        return null;
    }

    $out = [];
    foreach ($payload['labels'] as $label) {
        if (!is_array($label)) {
            continue;
        }

        $idEtiquetaWa = waExtractScalarString($label, 'id');
        $nombre = waExtractScalarString($label, 'name');
        if ($idEtiquetaWa === null || $nombre === null || $idEtiquetaWa === '' || $nombre === '') {
            continue;
        }

        // whatsapp_etiquetas.nombre/color son VARCHAR(60)/VARCHAR(20); se recorta aqui en
        // vez de dejar que una entrada fuera de rango rompa el INSERT/UPDATE mas adelante.
        $nombre = substr($nombre, 0, 60);
        $color = substr(waExtractScalarString($label, 'color') ?? '', 0, 20);

        $out[] = [
            'id_etiqueta_wa' => substr($idEtiquetaWa, 0, 50),
            'nombre' => $nombre,
            'color' => $color,
        ];
    }

    return $out;
}

/**
 * Parsea el lote que manda el puente al hacer POST a api/whatsapp/import_history.php con
 * el historial completo (evento messaging-history.set de Baileys, disparado UNA VEZ al
 * conectar): { "lote": "2026-08-21T10:00:00Z", "messages": [{"wa_id","nombre_perfil",
 * "message","from_me","timestamp"}, ...] }
 * Pura y testeable. Regresa null si "messages" no es un arreglo; entradas individuales
 * invalidas (sin wa_id/message/timestamp, tipos no escalares, texto fuera de rango) se
 * descartan sin invalidar el lote completo -- un historial real puede traer miles de
 * mensajes y no tiene sentido tirar todo el lote por una fila corrupta.
 */
function waParseHistoryImportPayload(array $payload): ?array
{
    if (!isset($payload['messages']) || !is_array($payload['messages'])) {
        return null;
    }

    $out = [];
    foreach ($payload['messages'] as $mensaje) {
        if (!is_array($mensaje)) {
            continue;
        }

        $waId = waExtractScalarString($mensaje, 'wa_id');
        $texto = waExtractScalarString($mensaje, 'message');
        $timestamp = waExtractScalarString($mensaje, 'timestamp');
        if ($waId === null || $texto === null || $timestamp === null || $waId === '' || $texto === '') {
            continue;
        }
        if (strlen($waId) > 20 || strlen($texto) > 65536) {
            continue;
        }

        $fechaMensaje = strtotime($timestamp);
        if ($fechaMensaje === false) {
            continue;
        }

        $waIdDigits = preg_replace('/\D+/', '', $waId) ?? '';
        if ($waIdDigits === '') {
            continue;
        }

        $nombrePerfil = waExtractScalarString($mensaje, 'nombre_perfil') ?? '';

        $out[] = [
            'wa_id' => $waIdDigits,
            'nombre_perfil' => substr($nombrePerfil, 0, 150),
            'mensaje' => $texto,
            'from_me' => !empty($mensaje['from_me']),
            'fecha_mensaje' => date('Y-m-d H:i:s', $fechaMensaje),
        ];
    }

    return $out;
}

/**
 * Pide al puente que aplique/quite una etiqueta NATIVA de WhatsApp Business en el chat
 * ($idEtiquetaWa es el id que WhatsApp le dio a esa etiqueta, sincronizado previamente por
 * sync_labels.php). $accion es 'add' o 'remove'. Solo tiene sentido llamarla para etiquetas
 * que ya existen del lado de WhatsApp -- la API no permite crear etiquetas nuevas.
 */
function waSyncChatLabel(string $waId, string $idEtiquetaWa, string $accion): array
{
    if (waIsTestMode()) {
        return ['ok' => true, 'test_mode' => true];
    }

    $labelUrl = getEnvVar('WA_BRIDGE_LABEL_URL');
    $token = getEnvVar('WA_WEBHOOK_TOKEN');
    if ($labelUrl === null || $token === null) {
        error_log('WARNING: WA_BRIDGE_LABEL_URL/WA_WEBHOOK_TOKEN no configurados; no se pudo sincronizar etiqueta con WhatsApp.');
        return ['ok' => false];
    }

    $payload = ['to' => $waId, 'label_id' => $idEtiquetaWa, 'action' => $accion];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $labelUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-Webhook-Token: ' . $token,
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false || $httpCode < 200 || $httpCode >= 300) {
        error_log('WARNING: fallo al sincronizar etiqueta de WhatsApp. HTTP=' . $httpCode . ' cURL=' . $curlError . ' body=' . substr((string)$response, 0, 300));
        return ['ok' => false];
    }

    return ['ok' => true];
}
