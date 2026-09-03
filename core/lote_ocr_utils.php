<?php
declare(strict_types=1);

/**
 * Lectura por camara para el control de caducidades.
 *
 * Pipeline (el mas barato que reutiliza el stack existente):
 *   1. La foto del bote / tabla nutrimental se sube a api/lote_ocr.php.
 *   2. loteOcrExtraerTexto() la manda a Google Cloud Vision
 *      (DOCUMENT_TEXT_DETECTION) usando el mismo service account que ya usa
 *      core/google_secret_manager.php. Devuelve el texto crudo.
 *   3. loteOcrInterpretar() manda ese texto a DeepSeek (ya integrado en
 *      core/ai_assistant.php) pidiendo SOLO un JSON con
 *      {codigo_lote, fecha_caducidad, caducidad_aproximada,
 *       capsulas_por_envase, porcion_capsulas, confianza}.
 *   4. Las funciones deterministas loteOcrNormalizarFecha() /
 *      loteOcrExtraerCodigoLote() sirven de respaldo y de validador.
 *
 * El usuario SIEMPRE revisa y confirma los valores antes de guardar el lote.
 *
 * Las funciones puras (normalizar fecha, extraer codigo, interpretar con un LLM
 * inyectable) se prueban en tests/Unit/LoteOcrUtilsTest.php sin red.
 */

/* -------------------------------------------------------------------------- *
 *  Disponibilidad / credenciales                                            *
 * -------------------------------------------------------------------------- */

function loteOcrHabilitado(): bool
{
    if (!function_exists('getEnvVar')) {
        return false;
    }
    $flag = strtolower(trim((string) (getEnvVar('LOTE_OCR_ENABLED', '1') ?? '1')));

    return !in_array($flag, ['0', 'false', 'off', 'no', ''], true);
}

/**
 * Access token de GCP reutilizando los helpers de core/google_secret_manager.php.
 */
function loteOcrGcpAccessToken(?string &$reason = null): ?string
{
    $reason = null;
    if (!function_exists('gsmGetServiceAccountPath') || !function_exists('gsmGetAccessTokenFromServiceAccount')) {
        $reason = 'google_secret_manager no esta cargado.';
        return null;
    }

    $path = gsmGetServiceAccountPath();
    if ($path === null) {
        $reason = 'No se encontro el archivo de service account de GCP.';
        return null;
    }

    $sa = gsmLoadServiceAccount($path);
    if ($sa === null) {
        $reason = 'El service account de GCP no es valido.';
        return null;
    }

    return gsmGetAccessTokenFromServiceAccount($sa, $reason);
}

/* -------------------------------------------------------------------------- *
 *  Google Cloud Vision                                                      *
 * -------------------------------------------------------------------------- */

/**
 * OCR de una imagen local con Google Cloud Vision. Lanza RuntimeException.
 */
function loteOcrExtraerTexto(string $rutaImagen): string
{
    if (!is_readable($rutaImagen)) {
        throw new RuntimeException('No se puede leer la imagen para OCR.');
    }

    $bytes = file_get_contents($rutaImagen);
    if ($bytes === false || $bytes === '') {
        throw new RuntimeException('La imagen esta vacia.');
    }

    $token = loteOcrGcpAccessToken($reason);
    if ($token === null) {
        throw new RuntimeException('OCR no disponible: ' . (string) $reason);
    }

    $relay = function_exists('getEnvVar') ? trim((string) (getEnvVar('LOTE_OCR_VISION_URL', '') ?? '')) : '';
    $url = $relay !== '' ? $relay : 'https://vision.googleapis.com/v1/images:annotate';

    $payload = json_encode([
        'requests' => [[
            'image' => ['content' => base64_encode($bytes)],
            'features' => [['type' => 'DOCUMENT_TEXT_DETECTION']],
            'imageContext' => ['languageHints' => ['es', 'en']],
        ]],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json; charset=utf-8',
        'Authorization: Bearer ' . $token,
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Fallo al llamar a Vision: ' . ($curlError !== '' ? $curlError : 'error desconocido'));
    }

    return loteOcrParsearRespuestaVision($httpCode, (string) $response);
}

/**
 * Extrae fullTextAnnotation.text de la respuesta de Vision. Pura y testeable.
 */
function loteOcrParsearRespuestaVision(int $httpCode, string $rawResponse): string
{
    $decoded = json_decode($rawResponse, true);
    if ($httpCode < 200 || $httpCode >= 300 || !is_array($decoded)) {
        throw new RuntimeException('Vision respondio HTTP ' . $httpCode . '.');
    }

    $resp = $decoded['responses'][0] ?? null;
    if (!is_array($resp)) {
        throw new RuntimeException('Respuesta de Vision sin "responses".');
    }
    if (isset($resp['error']['message'])) {
        throw new RuntimeException('Vision: ' . (string) $resp['error']['message']);
    }

    $texto = $resp['fullTextAnnotation']['text']
        ?? ($resp['textAnnotations'][0]['description'] ?? '');

    return trim((string) $texto);
}

/* -------------------------------------------------------------------------- *
 *  Funciones deterministas (respaldo + validador)                           *
 * -------------------------------------------------------------------------- */

/**
 * Mapa de nombres de mes (es / en, abreviados y completos) a numero.
 *
 * @return array<string,int>
 */
function loteOcrMesesMapa(): array
{
    return [
        'ene' => 1, 'enero' => 1, 'jan' => 1, 'january' => 1,
        'feb' => 2, 'febrero' => 2, 'february' => 2,
        'mar' => 3, 'marzo' => 3, 'march' => 3,
        'abr' => 4, 'abril' => 4, 'apr' => 4, 'april' => 4,
        'may' => 5, 'mayo' => 5,
        'jun' => 6, 'junio' => 6, 'june' => 6,
        'jul' => 7, 'julio' => 7, 'july' => 7,
        'ago' => 8, 'agosto' => 8, 'aug' => 8, 'august' => 8,
        'sep' => 9, 'sept' => 9, 'septiembre' => 9, 'september' => 9,
        'oct' => 10, 'octubre' => 10, 'october' => 10,
        'nov' => 11, 'noviembre' => 11, 'november' => 11,
        'dic' => 12, 'diciembre' => 12, 'dec' => 12, 'december' => 12,
    ];
}

/**
 * Intenta deducir una fecha de caducidad de un texto libre impreso en un bote.
 *
 * @return array{fecha: ?string, aproximada: bool}  fecha en 'Y-m-d' o null
 */
function loteOcrNormalizarFecha(string $texto): array
{
    $t = ' ' . strtolower($texto) . ' ';
    // normaliza separadores raros
    $t = str_replace(['.', '\\'], ['/', '/'], $t);
    $t = preg_replace('/\s+/', ' ', $t) ?? $t;

    $meses = loteOcrMesesMapa();
    $mesesAlt = implode('|', array_map('preg_quote', array_keys($meses)));

    $anioPleno = static function (string $y): int {
        $y = (int) $y;
        return $y < 100 ? 2000 + $y : $y;
    };
    $armar = static function (int $y, int $m, ?int $d): array {
        if ($m < 1 || $m > 12) {
            return ['fecha' => null, 'aproximada' => false];
        }
        if ($d === null) {
            return ['fecha' => sprintf('%04d-%02d-01', $y, $m), 'aproximada' => true];
        }
        if ($d < 1 || $d > 31) {
            return ['fecha' => sprintf('%04d-%02d-01', $y, $m), 'aproximada' => true];
        }
        return ['fecha' => sprintf('%04d-%02d-%02d', $y, $m, $d), 'aproximada' => false];
    };

    // 1. ISO: 2027-07-31  o  2027/07
    if (preg_match('/(20\d{2})[\/-](\d{1,2})(?:[\/-](\d{1,2}))?/', $t, $m)) {
        return $armar((int) $m[1], (int) $m[2], isset($m[3]) && $m[3] !== '' ? (int) $m[3] : null);
    }

    // 2. dd <mes> yyyy  o  <mes> dd yyyy  o  <mes> yyyy
    if (preg_match('/\b(\d{1,2})\s*(' . $mesesAlt . ')\.?\s*(\d{2,4})\b/', $t, $m)) {
        return $armar($anioPleno($m[3]), $meses[$m[2]], (int) $m[1]);
    }
    if (preg_match('/\b(' . $mesesAlt . ')\.?\s*(\d{1,2})[\s,]+(\d{2,4})\b/', $t, $m)) {
        return $armar($anioPleno($m[3]), $meses[$m[1]], (int) $m[2]);
    }
    if (preg_match('/\b(' . $mesesAlt . ')\.?\s*[\/-]?\s*(\d{2,4})\b/', $t, $m)) {
        return $armar($anioPleno($m[2]), $meses[$m[1]], null);
    }

    // 3. dd/mm/yyyy  (formato dominante en MX)
    if (preg_match('/\b(\d{1,2})\/(\d{1,2})\/(\d{2,4})\b/', $t, $m)) {
        $d = (int) $m[1];
        $mes = (int) $m[2];
        // si el primer numero no puede ser dia pero el segundo si -> mm/dd
        if ($d > 12 && $mes <= 12) {
            return $armar($anioPleno($m[3]), $mes, $d);
        }
        if ($mes > 12 && $d <= 12) {
            return $armar($anioPleno($m[3]), $d, $mes);
        }
        return $armar($anioPleno($m[3]), $mes, $d);
    }

    // 4. mm/yyyy  o  mm/yy
    if (preg_match('/\b(\d{1,2})\/(\d{2,4})\b/', $t, $m)) {
        return $armar($anioPleno($m[2]), (int) $m[1], null);
    }

    // 5. mmyyyy pegado (ej "072027")
    if (preg_match('/\b(0[1-9]|1[0-2])(20\d{2})\b/', $t, $m)) {
        return $armar((int) $m[2], (int) $m[1], null);
    }

    return ['fecha' => null, 'aproximada' => false];
}

/**
 * Heuristica para aislar el codigo de lote de un texto impreso.
 */
function loteOcrExtraerCodigoLote(string $texto): ?string
{
    $lineas = preg_split('/[\r\n]+/', $texto) ?: [];

    // 1. "LOTE: XXXX" / "LOT XXXX" / "L: XXXX" / "BATCH XXXX"
    foreach ($lineas as $linea) {
        if (preg_match('/\b(?:lote|lot|batch|l)\s*[:.#-]?\s*([A-Z0-9][A-Z0-9\-\/]{2,19})\b/i', $linea, $m)) {
            return strtoupper(trim($m[1], '-/'));
        }
    }

    // 2. Token alfanumerico con letras y digitos mezclados (tipico de lote).
    if (preg_match_all('/\b([A-Z]{1,4}\d{2,}[A-Z0-9\-]*|\d{2,}[A-Z]{1,4}[A-Z0-9\-]*)\b/', strtoupper($texto), $m)) {
        foreach ($m[1] as $cand) {
            $cand = trim($cand, '-/');
            if (strlen($cand) >= 4 && strlen($cand) <= 20) {
                return $cand;
            }
        }
    }

    return null;
}

/**
 * Extrae de una tabla nutrimental: capsulas por envase, capsulas por porcion,
 * porciones por envase y forma farmaceutica.
 *
 * @return array{
 *   capsulas_por_envase: ?int, porcion_capsulas: ?int,
 *   servings_por_envase: ?int, forma: ?string
 * }
 */
function loteOcrExtraerCapsulas(string $texto): array
{
    $t = strtolower($texto);
    $porEnvase = null;
    $porcion = null;
    $servings = null;
    $forma = null;

    $u = '(?:c[aá]psulas?|c[aá]ps\.?|tabletas?|softgels?|comprimidos?|perlas?|gomitas?)';

    if (preg_match('/(\d{2,4})\s*' . $u . '\b/u', $t, $m)) {
        $porEnvase = (int) $m[1];
    }
    if (preg_match('/(?:contenido neto|net\s*wt|contiene)\D{0,15}(\d{2,4})\s*' . $u . '/u', $t, $m)) {
        $porEnvase = (int) $m[1];
    }
    if (preg_match('/(?:porciones? por envase|servings? per container)\D{0,15}(\d{1,3})/u', $t, $m)) {
        $servings = (int) $m[1];
    }
    if (preg_match('/(?:porci[oó]n|tama[nñ]o de la porci[oó]n|serving size)\D{0,15}(\d{1,2})\s*' . $u . '/u', $t, $m)) {
        $porcion = (int) $m[1];
    }
    if ($porcion === null && preg_match('/(?:tomar|ingerir|consumir)\D{0,10}(\d{1,2})\s*' . $u . '/u', $t, $m)) {
        $porcion = (int) $m[1];
    }

    // forma farmaceutica dominante (needles sin acento; el OCR suele perderlos)
    foreach ([
        'softgel' => 'Softgels', 'perla' => 'Softgels',
        'capsula' => 'Cápsulas', 'cápsula' => 'Cápsulas',
        'tableta' => 'Tabletas', 'comprimido' => 'Tabletas', 'gomita' => 'Gomitas',
        'polvo' => 'Polvo', 'liquido' => 'Líquido', 'líquido' => 'Líquido',
    ] as $needle => $label) {
        if (mb_strpos($t, $needle) !== false) {
            $forma = $label;
            break;
        }
    }

    // Si tenemos porciones-por-envase y capsulas-por-porcion pero no total, derivarlo.
    if ($porEnvase === null && $servings !== null && $porcion !== null) {
        $porEnvase = $servings * $porcion;
    }

    return [
        'capsulas_por_envase' => $porEnvase,
        'porcion_capsulas' => $porcion,
        'servings_por_envase' => $servings,
        'forma' => $forma,
    ];
}

/**
 * Extrae el contenido neto declarado (ej "90 capsulas", "500 mg", "250 ml").
 */
function loteOcrExtraerContenidoNeto(string $texto): ?string
{
    $t = strtolower($texto);
    if (preg_match('/(?:contenido neto|cont\.?\h*neto|net\h*wt\.?|contenido)\h*[:.]?\h*([0-9][0-9.,]*\h*(?:c[aá]psulas?|c[aá]ps\.?|tabletas?|softgels?|g|gr|mg|ml|kg|l|oz|piezas?)\b)/u', $t, $m)) {
        return trim($m[1]);
    }
    if (preg_match('/\b(\d{2,4}\h*(?:c[aá]psulas?|softgels?|tabletas?))\b/u', $t, $m)) {
        return trim($m[1]);
    }
    return null;
}

/* -------------------------------------------------------------------------- *
 *  Interpretacion con LLM                                                    *
 * -------------------------------------------------------------------------- */

/**
 * Construye el prompt de extraccion para el LLM.
 */
function loteOcrConstruirPrompt(string $textoLote, ?string $textoTabla): string
{
    $hoy = date('Y-m-d');
    $prompt = "Eres un asistente que extrae datos de etiquetas de suplementos. "
        . "Hoy es {$hoy}. A partir del TEXTO OCR de la foto de un bote, devuelve EXCLUSIVAMENTE "
        . "un objeto JSON valido, sin texto adicional, con estas claves:\n"
        . "- codigo_lote: string o null. El codigo de lote / batch impreso (ej 'LOT6758', 'L2507A').\n"
        . "- fecha_caducidad: string 'YYYY-MM-DD' o null. Fecha de caducidad / expiracion / 'consumir antes de'. "
        . "Si solo hay mes y anio usa el dia 01.\n"
        . "- caducidad_aproximada: true si solo se conocia mes/anio, false si habia dia exacto.\n"
        . "- capsulas_por_envase: entero o null (capsulas/tabletas por bote).\n"
        . "- porcion_capsulas: entero o null (capsulas por toma/porcion).\n"
        . "- servings_por_envase: entero o null (porciones por envase / servings per container).\n"
        . "- forma: string o null (Cápsulas, Softgels, Tabletas, Gomitas, Polvo, Líquido).\n"
        . "- nombre_detectado: string o null (nombre comercial del producto).\n"
        . "- marca: string o null (marca/fabricante).\n"
        . "- contenido_neto: string o null (ej '90 cápsulas', '500 mg', '250 ml').\n"
        . "- fecha_fabricacion: string 'YYYY-MM-DD' o null (fecha de elaboracion / MFG / FAB / LOTE fabricado).\n"
        . "- confianza: numero 0..1 con tu confianza global.\n\n"
        . "TEXTO OCR DEL BOTE:\n\"\"\"\n" . mb_substr($textoLote, 0, 4000) . "\n\"\"\"\n";

    if ($textoTabla !== null && trim($textoTabla) !== '') {
        $prompt .= "\nTEXTO OCR DE LA TABLA NUTRIMENTAL:\n\"\"\"\n" . mb_substr($textoTabla, 0, 4000) . "\n\"\"\"\n";
    }

    return $prompt;
}

/**
 * Llama a DeepSeek en modo texto simple (sin tools). Lanza RuntimeException.
 */
function loteOcrLlamarDeepSeek(string $prompt): string
{
    if (function_exists('aiIsTestMode') && aiIsTestMode()) {
        return '{}';
    }
    if (!function_exists('aiResolveDeepSeekEndpoint')) {
        throw new RuntimeException('ai_assistant no esta cargado.');
    }

    $endpoint = aiResolveDeepSeekEndpoint(getEnvVar('WA_BRIDGE_DEEPSEEK_URL'));
    $headers = ['Content-Type: application/json'];

    if (!empty($endpoint['use_relay'])) {
        $webhookToken = getEnvVar('WA_WEBHOOK_TOKEN');
        if ($webhookToken === null || trim($webhookToken) === '') {
            throw new RuntimeException('WA_WEBHOOK_TOKEN no configurado; no se puede usar el relay.');
        }
        $headers[] = 'X-Webhook-Token: ' . $webhookToken;
    } else {
        $apiKey = getEnvVar('DEEPSEEK_AI_ASSISTANT');
        if ($apiKey === null || trim($apiKey) === '') {
            throw new RuntimeException('DEEPSEEK_AI_ASSISTANT no configurado.');
        }
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    $payload = json_encode([
        'model' => 'deepseek-chat',
        'messages' => [
            ['role' => 'system', 'content' => 'Devuelve solo JSON valido.'],
            ['role' => 'user', 'content' => $prompt],
        ],
        'temperature' => 0.0,
        'response_format' => ['type' => 'json_object'],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $endpoint['url']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Fallo al llamar a DeepSeek: ' . ($curlError !== '' ? $curlError : 'desconocido'));
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        throw new RuntimeException('DeepSeek respondio HTTP ' . $httpCode . '.');
    }

    $decoded = json_decode((string) $response, true);
    $content = $decoded['choices'][0]['message']['content'] ?? '';

    return is_string($content) ? $content : '';
}

/**
 * Interpreta el/los texto(s) OCR y devuelve los campos del lote.
 *
 * @param callable|null $llm  fn(string $prompt): string  (inyectable para tests)
 * @return array{
 *   codigo_lote: ?string, fecha_caducidad: ?string, caducidad_aproximada: bool,
 *   capsulas_por_envase: ?int, porcion_capsulas: ?int, confianza: float, fuente: string
 * }
 */
function loteOcrInterpretar(string $textoLote, ?string $textoTabla = null, ?callable $llm = null): array
{
    // Respaldo determinista primero.
    $fechaDet = loteOcrNormalizarFecha($textoLote);
    $codigoDet = loteOcrExtraerCodigoLote($textoLote);
    $textoFull = ($textoTabla ?? '') . "\n" . $textoLote;
    $capsDet = loteOcrExtraerCapsulas($textoFull);

    $out = [
        'codigo_lote' => $codigoDet,
        'fecha_caducidad' => $fechaDet['fecha'],
        'caducidad_aproximada' => $fechaDet['aproximada'],
        'fecha_fabricacion' => null,
        'capsulas_por_envase' => $capsDet['capsulas_por_envase'],
        'porcion_capsulas' => $capsDet['porcion_capsulas'],
        'servings_por_envase' => $capsDet['servings_por_envase'],
        'forma' => $capsDet['forma'],
        'contenido_neto' => loteOcrExtraerContenidoNeto($textoFull),
        'nombre_detectado' => null,
        'marca' => null,
        'confianza' => $fechaDet['fecha'] !== null ? 0.4 : 0.15,
        'fuente' => 'heuristica',
    ];

    $llm = $llm ?? (loteOcrHabilitado() ? 'loteOcrLlamarDeepSeek' : null);
    if ($llm === null) {
        return $out;
    }

    try {
        $raw = (string) $llm(loteOcrConstruirPrompt($textoLote, $textoTabla));
    } catch (Throwable $e) {
        error_log('lote_ocr: fallo LLM, uso heuristica: ' . $e->getMessage());
        return $out;
    }

    $json = loteOcrExtraerJson($raw);
    if ($json === null) {
        return $out;
    }

    if (!empty($json['codigo_lote']) && is_string($json['codigo_lote'])) {
        $out['codigo_lote'] = strtoupper(trim($json['codigo_lote']));
    }
    foreach (['fecha_caducidad', 'fecha_fabricacion'] as $fk) {
        if (!empty($json[$fk]) && is_string($json[$fk])) {
            $ts = strtotime($json[$fk]);
            if ($ts !== false) {
                $out[$fk] = date('Y-m-d', $ts);
                if ($fk === 'fecha_caducidad') {
                    $out['caducidad_aproximada'] = !empty($json['caducidad_aproximada']);
                }
            }
        }
    }
    foreach (['capsulas_por_envase', 'porcion_capsulas', 'servings_por_envase'] as $k) {
        if (isset($json[$k]) && is_numeric($json[$k]) && (int) $json[$k] > 0) {
            $out[$k] = (int) $json[$k];
        }
    }
    foreach (['forma', 'nombre_detectado', 'marca', 'contenido_neto'] as $k) {
        if (!empty($json[$k]) && is_string($json[$k])) {
            $out[$k] = trim($json[$k]);
        }
    }
    // Si el LLM no dio total pero si porciones y capsulas/porcion, derivarlo.
    if (($out['capsulas_por_envase'] === null) && $out['servings_por_envase'] && $out['porcion_capsulas']) {
        $out['capsulas_por_envase'] = $out['servings_por_envase'] * $out['porcion_capsulas'];
    }
    if (isset($json['confianza']) && is_numeric($json['confianza'])) {
        $out['confianza'] = max(0.0, min(1.0, (float) $json['confianza']));
    }
    $out['fuente'] = 'llm';

    return $out;
}

/**
 * Extrae el primer objeto JSON de una cadena (tolera fences ```json).
 *
 * @return array<string,mixed>|null
 */
function loteOcrExtraerJson(string $raw): ?array
{
    $raw = trim($raw);
    $raw = preg_replace('/^```(?:json)?|```$/m', '', $raw) ?? $raw;

    $decoded = json_decode(trim($raw), true);
    if (is_array($decoded)) {
        return $decoded;
    }

    if (preg_match('/\{.*\}/s', $raw, $m)) {
        $decoded = json_decode($m[0], true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return null;
}

/**
 * Orquesta OCR + interpretacion para 1 o 2 imagenes ya guardadas en disco.
 *
 * @param array{lote?:string, tabla?:string} $rutas  rutas locales de las imagenes
 * @param callable|null $ocr  fn(string $ruta): string  (inyectable para tests)
 * @param callable|null $llm
 * @return array<string,mixed>  campos interpretados + 'texto_ocr'
 */
function loteOcrProcesar(array $rutas, ?callable $ocr = null, ?callable $llm = null): array
{
    $ocr = $ocr ?? 'loteOcrExtraerTexto';

    $textoLote = '';
    $textoTabla = null;

    if (!empty($rutas['lote'])) {
        $textoLote = (string) $ocr($rutas['lote']);
    }
    if (!empty($rutas['tabla'])) {
        $textoTabla = (string) $ocr($rutas['tabla']);
    }

    if (trim($textoLote) === '' && ($textoTabla === null || trim($textoTabla) === '')) {
        throw new RuntimeException('No se detecto texto en la(s) imagen(es).');
    }

    $datos = loteOcrInterpretar($textoLote, $textoTabla, $llm);
    $datos['texto_ocr'] = trim($textoLote . "\n" . (string) $textoTabla);
    $datos['codigo_barras'] = isset($rutas['codigo_barras']) && trim((string) $rutas['codigo_barras']) !== ''
        ? trim((string) $rutas['codigo_barras'])
        : null;

    return $datos;
}
