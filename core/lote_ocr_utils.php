<?php
declare(strict_types=1);

// Lectura de código de lote y fecha de caducidad desde la foto de una etiqueta,
// usando Google Cloud Vision (DOCUMENT_TEXT_DETECTION) + parseo por regex en PHP.
// Sin IA de por medio: el usuario siempre puede corregir a mano lo que se detecte
// (ver views/products.php, sección "Lotes de este producto").

/**
 * Llama a Google Cloud Vision con el contenido de una imagen (base64, sin el
 * prefijo "data:image/...;base64,") y regresa el texto detectado + la estructura
 * completa (con confianza por símbolo, usada para marcar caracteres dudosos del
 * código de lote — ver loteOcrCodigoLoteConHuecos()).
 *
 * @return array{ok: bool, texto: string, fullTextAnnotation: ?array, error: string}
 */
function loteOcrDetectarTexto(string $imagenBase64, string $apiKey): array
{
    $imagenBase64 = trim($imagenBase64);
    $apiKey = trim($apiKey);

    if ($apiKey === '') {
        return ['ok' => false, 'texto' => '', 'fullTextAnnotation' => null, 'error' => 'La lectura de etiquetas no está configurada (falta VISION_KEY).'];
    }
    if ($imagenBase64 === '') {
        return ['ok' => false, 'texto' => '', 'fullTextAnnotation' => null, 'error' => 'No se recibió ninguna imagen.'];
    }
    if (!function_exists('gsmHttpRequest')) {
        return ['ok' => false, 'texto' => '', 'fullTextAnnotation' => null, 'error' => 'Cliente HTTP no disponible en este entorno.'];
    }

    $body = json_encode([
        'requests' => [[
            'image' => ['content' => $imagenBase64],
            'features' => [['type' => 'DOCUMENT_TEXT_DETECTION']],
            'imageContext' => ['languageHints' => ['es']],
        ]],
    ]);

    $url = 'https://vision.googleapis.com/v1/images:annotate?key=' . rawurlencode($apiKey);
    $response = gsmHttpRequest('POST', $url, (string) $body, ['Content-Type' => 'application/json'], 20);

    $data = json_decode((string) ($response['body'] ?? ''), true);

    if (!$response['ok']) {
        $mensajeApi = is_array($data) ? ($data['error']['message'] ?? null) : null;
        $detalle = is_string($mensajeApi) && $mensajeApi !== ''
            ? $mensajeApi
            : ('HTTP ' . $response['code'] . ($response['error'] !== '' ? ' - ' . $response['error'] : ''));
        return ['ok' => false, 'texto' => '', 'fullTextAnnotation' => null, 'error' => 'Google Vision: ' . $detalle];
    }

    if (!is_array($data)) {
        return ['ok' => false, 'texto' => '', 'fullTextAnnotation' => null, 'error' => 'Respuesta inválida de Google Vision.'];
    }

    $apiError = $data['responses'][0]['error']['message'] ?? null;
    if (is_string($apiError) && $apiError !== '') {
        return ['ok' => false, 'texto' => '', 'fullTextAnnotation' => null, 'error' => $apiError];
    }

    $fullTextAnnotation = $data['responses'][0]['fullTextAnnotation'] ?? null;
    $texto = $fullTextAnnotation['text'] ?? ($data['responses'][0]['textAnnotations'][0]['description'] ?? '');
    if (!is_string($texto) || trim($texto) === '') {
        return ['ok' => false, 'texto' => '', 'fullTextAnnotation' => null, 'error' => 'No se detectó texto en la foto.'];
    }

    return [
        'ok' => true,
        'texto' => $texto,
        'fullTextAnnotation' => is_array($fullTextAnnotation) ? $fullTextAnnotation : null,
        'error' => '',
    ];
}

/**
 * Aplana fullTextAnnotation.pages[].blocks[].paragraphs[].words[] a una lista simple,
 * conservando por cada palabra su texto y la confianza (0-1) de cada símbolo/carácter.
 *
 * @return list<array{texto: string, confianzas: float[]}>
 */
function loteOcrPalabrasConConfianza(?array $fullTextAnnotation): array
{
    $palabras = [];
    foreach (($fullTextAnnotation['pages'] ?? []) as $pagina) {
        foreach (($pagina['blocks'] ?? []) as $bloque) {
            foreach (($bloque['paragraphs'] ?? []) as $parrafo) {
                foreach (($parrafo['words'] ?? []) as $palabra) {
                    $texto = '';
                    $confianzas = [];
                    foreach (($palabra['symbols'] ?? []) as $simbolo) {
                        $texto .= (string) ($simbolo['text'] ?? '');
                        $confianzas[] = isset($simbolo['confidence']) ? (float) $simbolo['confidence'] : 1.0;
                    }
                    if ($texto !== '') {
                        $palabras[] = ['texto' => $texto, 'confianzas' => $confianzas];
                    }
                }
            }
        }
    }
    return $palabras;
}

/** Reemplaza por "_" los caracteres de una palabra cuya confianza esté por debajo del umbral. */
function loteOcrEnmascararPalabra(array $palabra, float $umbral): string
{
    $resultado = '';
    foreach (str_split(strtoupper((string) $palabra['texto'])) as $i => $char) {
        $confianza = $palabra['confianzas'][$i] ?? 1.0;
        $resultado .= ($confianza < $umbral) ? '_' : $char;
    }
    return $resultado;
}

/**
 * Busca el código de lote a nivel de palabra (junto a "LOTE"/"LOT"/"L") y marca con "_"
 * los caracteres que Vision no pudo leer con confianza — típico cuando el texto queda
 * partido por la costura/unión de un bote. Ninguno se "adivina": el usuario completa a
 * mano lo que quede marcado, viendo el bote de cerca.
 */
function loteOcrCodigoLoteConHuecos(array $palabras, float $umbral = 0.65): ?string
{
    $total = count($palabras);
    for ($i = 0; $i < $total; $i++) {
        $clave = rtrim(strtoupper((string) $palabras[$i]['texto']), ':.#');
        if ($clave !== 'LOTE' && $clave !== 'LOT' && $clave !== 'L') {
            continue;
        }
        // La palabra clave puede venir seguida de un ":" suelto antes del código real.
        for ($j = $i + 1; $j < $total && $j <= $i + 2; $j++) {
            $candidata = strtoupper((string) $palabras[$j]['texto']);
            if (preg_match('/^[A-Z0-9][A-Z0-9\-]{2,14}$/', $candidata)) {
                return loteOcrEnmascararPalabra($palabras[$j], $umbral);
            }
        }
    }
    return null;
}

/** Último día del mes (28-31), sin depender de la extensión "calendar". */
function loteOcrUltimoDiaDeMes(int $mes, int $anio): int
{
    return (int) (new DateTimeImmutable(sprintf('%04d-%02d-01', $anio, $mes)))
        ->modify('last day of this month')
        ->format('d');
}

/** "29" -> 2029, "2029" -> 2029. Años de 2 dígitos se asumen 20XX. */
function loteOcrNormalizarAnio(string $anioTexto): int
{
    $anio = (int) $anioTexto;
    return strlen($anioTexto) <= 2 ? 2000 + $anio : $anio;
}

/**
 * Arma una fecha YYYY-MM-DD validando rangos de día/mes/año; null si es inválida.
 */
function loteOcrArmarFecha(int $dia, int $mes, int $anio): ?string
{
    if ($mes < 1 || $mes > 12 || $anio < 2000 || $anio > 2099) {
        return null;
    }
    $ultimoDia = loteOcrUltimoDiaDeMes($mes, $anio);
    if ($dia < 1 || $dia > $ultimoDia) {
        return null;
    }
    return sprintf('%04d-%02d-%02d', $anio, $mes, $dia);
}

/**
 * "Jul" / "JULIO" / "July" -> 7. Compara la palabra COMPLETA (no solo el prefijo) contra
 * abreviaturas y nombres de mes en español e inglés, para no confundir palabras comunes
 * que empiezan igual (p.ej. "marca"/"mayor" no son "mar"/"may").
 */
function loteOcrMesDesdeNombre(string $palabra): ?int
{
    static $meses = [
        'ene' => 1, 'enero' => 1, 'jan' => 1, 'january' => 1,
        'feb' => 2, 'febrero' => 2, 'february' => 2,
        'mar' => 3, 'marzo' => 3, 'march' => 3,
        'abr' => 4, 'abril' => 4, 'apr' => 4, 'april' => 4,
        'may' => 5, 'mayo' => 5,
        'jun' => 6, 'junio' => 6, 'june' => 6,
        'jul' => 7, 'julio' => 7, 'july' => 7,
        'ago' => 8, 'agosto' => 8, 'aug' => 8, 'august' => 8,
        'sep' => 9, 'sept' => 9, 'septiembre' => 9, 'setiembre' => 9, 'september' => 9,
        'oct' => 10, 'octubre' => 10, 'october' => 10,
        'nov' => 11, 'noviembre' => 11, 'november' => 11,
        'dic' => 12, 'diciembre' => 12, 'dec' => 12, 'december' => 12,
    ];
    return $meses[strtolower($palabra)] ?? null;
}

/**
 * Busca una fecha de caducidad en el texto detectado por OCR. Cubre los formatos
 * que traen las etiquetas de proveedores:
 *   - "CAD022029" / "EXP022029"        -> mes+año pegados (6 dígitos), sin día ->
 *                                          se usa el último día de ese mes.
 *   - "CAD27042030" / "VENCE27042030"  -> día+mes+año pegados (8 dígitos).
 *   - "27-04-2030" / "27/04/2030"      -> día-mes-año con separador (día completo).
 *   - "04-2030" / "04/2030"            -> mes-año con separador, sin día -> último
 *                                          día de ese mes.
 *   - "15-Jul-2028" / "15/Jul/2028"    -> día-mes(letras)-año (día completo).
 *   - "Jul/2028" / "JUL 2028"          -> mes(letras)-año, sin día -> fin de mes.
 *
 * @return array{fecha: string, aproximada: bool}|null
 */
function loteOcrParsearCaducidad(string $texto): ?array
{
    $palabraClave = '(?:CAD(?:UCIDAD)?|EXP(?:IRA|IRY|IRACION)?|VENCE?(?:IMIENTO)?)';

    // 1) Palabra clave + 8 dígitos pegados = DDMMYYYY.
    if (preg_match('/' . $palabraClave . '\s*[:\.#]?\s*(\d{2})(\d{2})(\d{4})\b/i', $texto, $m)) {
        $fecha = loteOcrArmarFecha((int) $m[1], (int) $m[2], (int) $m[3]);
        if ($fecha !== null) {
            return ['fecha' => $fecha, 'aproximada' => false];
        }
    }

    // 2) Palabra clave + 6 dígitos pegados = MMYYYY (sin día -> fin de mes).
    if (preg_match('/' . $palabraClave . '\s*[:\.#]?\s*(\d{2})(\d{4})\b/i', $texto, $m)) {
        $mes = (int) $m[1];
        $anio = (int) $m[2];
        if ($mes >= 1 && $mes <= 12 && $anio >= 2000 && $anio <= 2099) {
            return ['fecha' => sprintf('%04d-%02d-%02d', $anio, $mes, loteOcrUltimoDiaDeMes($mes, $anio)), 'aproximada' => true];
        }
    }

    // 3) Fecha completa con separador: DD-MM-YYYY (o /, ., año de 2 o 4 dígitos).
    if (preg_match('/\b(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{2,4})\b/', $texto, $m)) {
        $dia = (int) $m[1];
        $mes = (int) $m[2];
        // Si el primer número no puede ser día pero el segundo sí, viene como MM-DD-YYYY.
        if ($dia > 31 || ($dia > 12 && $mes > 12)) {
            $fecha = null;
        } elseif ($mes > 12 && $dia <= 12) {
            [$dia, $mes] = [$mes, $dia];
            $fecha = loteOcrArmarFecha($dia, $mes, loteOcrNormalizarAnio($m[3]));
        } else {
            $fecha = loteOcrArmarFecha($dia, $mes, loteOcrNormalizarAnio($m[3]));
        }
        if ($fecha !== null) {
            return ['fecha' => $fecha, 'aproximada' => false];
        }
    }

    // 4) Mes-año con separador, sin día: MM-YYYY (fin de mes).
    if (preg_match('/\b(0[1-9]|1[0-2])[\/\-](20\d{2})\b/', $texto, $m)) {
        $mes = (int) $m[1];
        $anio = (int) $m[2];
        return ['fecha' => sprintf('%04d-%02d-%02d', $anio, $mes, loteOcrUltimoDiaDeMes($mes, $anio)), 'aproximada' => true];
    }

    // 5) Día + mes en letras + año: "15-Jul-2028", "15/Jul/2028", "15 Jul 2028".
    if (preg_match('/\b(\d{1,2})[\/\-\.\s]+([A-Za-zñÑ]{3,10})\.?[\/\-\.\s]+(\d{2,4})\b/u', $texto, $m)) {
        $mes = loteOcrMesDesdeNombre($m[2]);
        if ($mes !== null) {
            $fecha = loteOcrArmarFecha((int) $m[1], $mes, loteOcrNormalizarAnio($m[3]));
            if ($fecha !== null) {
                return ['fecha' => $fecha, 'aproximada' => false];
            }
        }
    }

    // 6) Mes en letras + año, sin día: "Jul/2028", "JUL 2028", "Julio-2028" (fin de mes).
    if (preg_match('/\b([A-Za-zñÑ]{3,10})\.?[\/\-\.\s]+(\d{2,4})\b/u', $texto, $m)) {
        $mes = loteOcrMesDesdeNombre($m[1]);
        $anio = $mes !== null ? loteOcrNormalizarAnio($m[2]) : null;
        if ($mes !== null && $anio >= 2000 && $anio <= 2099) {
            return ['fecha' => sprintf('%04d-%02d-%02d', $anio, $mes, loteOcrUltimoDiaDeMes($mes, $anio)), 'aproximada' => true];
        }
    }

    return null;
}

/**
 * Busca un código de lote en el texto detectado por OCR (etiqueta "LOTE"/"LOT"/"L:").
 */
function loteOcrParsearCodigoLote(string $texto): ?string
{
    if (preg_match('/\bLOTE?\s*[:\.#]?\s*([A-Z0-9][A-Z0-9\-]{2,14})\b/i', $texto, $m)) {
        return strtoupper($m[1]);
    }
    if (preg_match('/\bL\s*[:#]\s*([A-Z0-9][A-Z0-9\-]{2,14})\b/i', $texto, $m)) {
        return strtoupper($m[1]);
    }
    return null;
}

/**
 * Orquesta: llama a Vision y parsea lote/caducidad del texto detectado.
 *
 * @return array{success: bool, message: string, codigo_lote: ?string, fecha_caducidad: ?string, caducidad_aproximada: int}
 */
function loteOcrProcesarImagen(string $imagenBase64, string $apiKey): array
{
    $deteccion = loteOcrDetectarTexto($imagenBase64, $apiKey);
    if (!$deteccion['ok']) {
        return [
            'success' => false,
            'message' => $deteccion['error'],
            'codigo_lote' => null,
            'fecha_caducidad' => null,
            'caducidad_aproximada' => 0,
        ];
    }

    $texto = $deteccion['texto'];
    $palabras = loteOcrPalabrasConConfianza($deteccion['fullTextAnnotation']);
    // Primero se intenta con confianza por carácter (marca con "_" lo dudoso, p.ej. texto
    // partido por la costura del bote); si Vision no entregó esa estructura, se cae al
    // regex simple sobre el texto plano (sin marcar huecos).
    $codigoLote = loteOcrCodigoLoteConHuecos($palabras) ?? loteOcrParsearCodigoLote($texto);
    $caducidad = loteOcrParsearCaducidad($texto);

    return [
        'success' => true,
        'message' => ($codigoLote !== null && $caducidad !== null)
            ? 'Lote y caducidad detectados.'
            : 'Detección parcial; revisa/completa los campos.',
        'codigo_lote' => $codigoLote,
        'fecha_caducidad' => $caducidad['fecha'] ?? null,
        'caducidad_aproximada' => ($caducidad['aproximada'] ?? false) ? 1 : 0,
    ];
}
