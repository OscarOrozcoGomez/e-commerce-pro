<?php
declare(strict_types=1);

/**
 * Fotos de productos que el cliente le manda a Alex por WhatsApp.
 *
 * El puente de WhatsApp (VPS) le saca texto a la foto con OCR y lo manda a PHP dentro del
 * mensaje: `[El cliente envio una foto con el texto: "<caption>". Texto detectado en la imagen
 * (puede tener errores de OCR): <ocr>]`. El OCR lee bien el texto chico y denso de la etiqueta
 * ("SUPLEMENTO ALIMENTICIO", ingredientes) y suele perder el nombre grande y decorativo del
 * frasco ("WOMENS MULT MATUR3"). Caso real 2026-09-20: del frasco solo salio "Mento Alimentic"
 * (= "SUPLEMENTO ALIMENTICIO" mal leido); Alex lo busco como si fuera el nombre del producto,
 * no encontro nada y ofrecio productos "similares" que no eran el de la foto.
 *
 * Este archivo, todo por codigo (sin gastar tokens), hace tres cosas:
 *  1. Empareja lo que salio del OCR contra los nombres cortos del catalogo (la etiqueta del pomo),
 *     tolerando errores de lectura -- aiFotoAnalizar().
 *  2. Distingue las leyendas genericas de la etiqueta (no son un nombre de producto).
 *  3. Arma una linea de contexto para el prompt de ese turno -- aiFotoBuildContextLine() -- que
 *     le da a Alex la pista correcta o le dice que NO busque leyendas ni ofrezca "similares".
 * Ademas, aiFotoVisionDetectarTexto() lee la imagen con Google Vision (mucho mejor que
 * Tesseract con tipografias decorativas y frascos curvos) para el endpoint
 * api/alex_ocr_imagen.php que puede llamar el puente.
 *
 * Nada de esto manda mensajes a WhatsApp ni cambia la cadencia de envios: solo agrega contexto
 * al prompt del turno que YA se iba a generar.
 */

/** Frase con la que el puente marca el texto sacado por OCR (ver aiEsMensajeNoInterpretable()). */
const AI_FOTO_MARCADOR_OCR = 'Texto detectado en la imagen';

/** Nombres (normalizados) con menos caracteres que esto son demasiado ambiguos ("D3", "BPS", "Scrub"). */
const AI_FOTO_MIN_LARGO_NOMBRE = 8;
/** Similitud minima (0-1) para dar por leido un token con errores de OCR. */
const AI_FOTO_SIMILITUD_TOKEN = 0.8;
/** Puntaje minimo para listar un producto como posible. */
const AI_FOTO_PUNTAJE_MINIMO = 0.6;
/** Puntaje desde el que la coincidencia se considera clara. */
const AI_FOTO_PUNTAJE_CLARO = 0.85;
/** Dos candidatos con menos de esta diferencia de puntaje se consideran empatados (ambiguo). */
const AI_FOTO_MARGEN_EMPATE = 0.12;
/** Cuantos candidatos se le pasan a Alex como maximo. */
const AI_FOTO_MAX_CANDIDATOS = 3;

/**
 * Separa el mensaje que arma el puente en caption (lo que escribio el cliente junto con la
 * foto) y texto de OCR. Regresa null si el mensaje no viene de una foto con OCR.
 *
 * @return array{caption: string, ocr: string}|null
 */
function aiFotoExtraerPartes(string $textoUsuario): ?array
{
    $posMarcador = mb_stripos($textoUsuario, AI_FOTO_MARCADOR_OCR);
    if ($posMarcador === false) {
        return null;
    }

    $ocr = mb_substr($textoUsuario, $posMarcador);
    // Se quita "Texto detectado en la imagen (puede tener errores de OCR):" y el corchete final.
    $ocr = (string) preg_replace('/^' . preg_quote(AI_FOTO_MARCADOR_OCR, '/') . '[^:]*:\s*/iu', '', $ocr);
    $ocr = trim(rtrim(trim($ocr), ']'));

    $caption = '';
    if (preg_match('/foto con el texto:\s*"(.*)"\.?\s*' . preg_quote(AI_FOTO_MARCADOR_OCR, '/') . '/isu', $textoUsuario, $m)) {
        $caption = trim($m[1]);
    }

    return ['caption' => $caption, 'ocr' => $ocr];
}

/**
 * Minusculas, sin acentos y solo [a-z0-9] separados por un espacio. Sirve para comparar texto
 * de OCR contra nombres de producto sin que importen acentos, signos ni mayusculas.
 */
function aiFotoNormalizar(string $texto): string
{
    $texto = mb_strtolower($texto, 'UTF-8');
    $texto = strtr($texto, [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'ñ' => 'n',
    ]);
    $texto = (string) preg_replace('/[^a-z0-9]+/', ' ', $texto);

    return trim($texto);
}

/** @return string[] */
function aiFotoTokens(string $normalizado): array
{
    if ($normalizado === '') {
        return [];
    }

    return array_values(array_filter(explode(' ', $normalizado), static fn(string $t): bool => strlen($t) >= 2));
}

/**
 * Leyendas genericas que traen las etiquetas de todos los frascos (no identifican a ningun
 * producto) y numero de capsulas si se alcanzo a leer. Recibe el texto ya normalizado.
 *
 * @return array{leyendas: string[], capsulas: ?int}
 */
function aiFotoLeyendasGenericas(string $ocrNormalizado): array
{
    $leyendas = [];

    // "SUPLEMENTO ALIMENTICIO" suele salir mutilado ("Mento Alimentic"): basta la raiz de la 2a palabra.
    if (preg_match('/\balimentic\w*/', $ocrNormalizado) === 1 || str_contains($ocrNormalizado, 'suplemento')) {
        $leyendas[] = 'Suplemento alimenticio';
    }
    if (preg_match('/\bcapsulas? a base\b|\ba base de\b/', $ocrNormalizado) === 1) {
        $leyendas[] = 'Capsulas a base de...';
    }
    if (preg_match('/\bcontenido\b/', $ocrNormalizado) === 1) {
        $leyendas[] = 'Contenido N capsulas';
    }
    if (str_contains($ocrNormalizado, 'informacion nutrimental') || str_contains($ocrNormalizado, 'porciones por envase')) {
        $leyendas[] = 'Informacion nutrimental';
    }
    if (str_contains($ocrNormalizado, 'modo de uso')) {
        $leyendas[] = 'Modo de uso';
    }
    if (preg_match('/\bingredientes\b/', $ocrNormalizado) === 1) {
        $leyendas[] = 'Ingredientes';
    }

    $capsulas = null;
    if (preg_match('/\b(\d{2,3})\s*(?:capsulas|caps|cap)\b/', $ocrNormalizado, $m) === 1) {
        $capsulas = (int) $m[1];
    } elseif (preg_match('/\bcontenido\s+(\d{2,3})\b/', $ocrNormalizado, $m) === 1) {
        $capsulas = (int) $m[1];
    }

    return ['leyendas' => $leyendas, 'capsulas' => $capsulas];
}

/**
 * Indice de nombres para emparejar con el OCR: SOLO los nombres cortos (la etiqueta del pomo, el
 * texto grande del frente: "Womens Mult Matur3"). Los nombres largos del catalogo ("Colageno
 * Hidrolizado", "Vitamina C"...) NO entran: son justo las palabras del texto chico de la etiqueta
 * (la lista de ingredientes), y en la prueba con el caso real del 2026-09-20 hacian que un frasco
 * "a base de Colageno Hidrolizado, Minerales, Vitaminas..." se emparejara con el producto
 * "Colageno Hidrolizado". Los productos sin nombre corto capturado no se reconocen por foto (Alex
 * los sigue buscando con consultar_inventario si el cliente los nombra).
 *
 * @param array<int, array{id_producto:int|string, nombre?:?string, nombre_corto?:?string}> $filas
 * @return array<int, array{label:string, norm:string, tokens:string[], ids:int[]}>
 */
function aiFotoConstruirIndice(array $filas): array
{
    $entradas = [];

    foreach ($filas as $fila) {
        $id = (int) ($fila['id_producto'] ?? 0);
        $label = trim((string) ($fila['nombre_corto'] ?? ''));
        if ($id <= 0 || $label === '') {
            continue;
        }

        $norm = aiFotoNormalizar($label);
        if (strlen($norm) < AI_FOTO_MIN_LARGO_NOMBRE) {
            continue;
        }
        if (!isset($entradas[$norm])) {
            $entradas[$norm] = ['label' => $label, 'norm' => $norm, 'tokens' => aiFotoTokens($norm), 'ids' => []];
        }
        $entradas[$norm]['ids'][$id] = $id;
    }

    $indice = [];
    foreach ($entradas as $entrada) {
        $entrada['ids'] = array_values($entrada['ids']);
        if ($entrada['tokens'] !== []) {
            $indice[] = $entrada;
        }
    }

    return $indice;
}

/**
 * Empareja el texto de OCR (ya normalizado) con el indice de nombres, tolerando errores de
 * lectura. El puntaje es la fraccion del "peso" del nombre que se encontro en el OCR; cada token
 * pesa mas mientras menos nombres del catalogo lo comparten ("matur3" pesa mucho, "blend" poco).
 * Solo se lista un producto si su puntaje llega al minimo: una sola palabra suelta NO basta, porque
 * los ingredientes de la etiqueta ("Probioticos") se parecen a nombres cortos en ingles ("60 Billion
 * Probiotics") y darian una pista falsa.
 *
 * @param array<int, array{label:string, norm:string, tokens:string[], ids:int[]}> $indice
 * @return array<int, array{label:string, ids:int[], puntaje:float}> Mejores primero, maximo AI_FOTO_MAX_CANDIDATOS.
 */
function aiFotoCoincidencias(string $ocrNormalizado, array $indice): array
{
    $tokensOcr = aiFotoTokens($ocrNormalizado);
    if ($tokensOcr === [] || $indice === []) {
        return [];
    }

    // Cuantos nombres del catalogo contienen cada token (para ponderar).
    $df = [];
    foreach ($indice as $entrada) {
        foreach (array_unique($entrada['tokens']) as $token) {
            $df[$token] = ($df[$token] ?? 0) + 1;
        }
    }
    $totalEntradas = count($indice);
    $peso = static fn(string $token): float => log(1 + $totalEntradas / max(1, $df[$token] ?? 1));

    $resultados = [];
    foreach ($indice as $entrada) {
        $pesoTotal = 0.0;
        $pesoLeido = 0.0;

        foreach ($entrada['tokens'] as $token) {
            $w = $peso($token);
            $pesoTotal += $w;

            $mejor = 0.0;
            foreach ($tokensOcr as $tokenOcr) {
                if ($tokenOcr === $token) {
                    $mejor = 1.0;
                    break;
                }
                // Con errores de lectura solo se compara si ambos son largos (los cortos, como "d3", deben coincidir exactos).
                if (strlen($token) >= 5 && strlen($tokenOcr) >= 5) {
                    $largo = max(strlen($token), strlen($tokenOcr));
                    $similitud = 1 - (levenshtein($token, $tokenOcr) / $largo);
                    if ($similitud >= AI_FOTO_SIMILITUD_TOKEN && $similitud > $mejor) {
                        $mejor = $similitud;
                    }
                }
            }

            if ($mejor > 0.0) {
                $pesoLeido += $w * $mejor;
            }
        }

        if ($pesoTotal <= 0.0 || $pesoLeido <= 0.0) {
            continue;
        }

        $puntaje = round($pesoLeido / $pesoTotal, 3);
        if ($puntaje >= AI_FOTO_PUNTAJE_MINIMO) {
            $resultados[] = ['label' => $entrada['label'], 'ids' => $entrada['ids'], 'puntaje' => $puntaje];
        }
    }

    usort($resultados, static fn(array $a, array $b): int => $b['puntaje'] <=> $a['puntaje']);

    return array_slice($resultados, 0, AI_FOTO_MAX_CANDIDATOS);
}

/**
 * Analiza un mensaje de foto con OCR. Si el mensaje no trae OCR regresa ['es_foto_ocr' => false].
 * Empareja contra el texto del OCR Y contra el caption (el cliente pudo nombrar el producto al escribir).
 *
 * @param array<int, array{label:string, norm:string, tokens:string[], ids:int[]}> $indice
 * @return array{es_foto_ocr: bool, caption: string, ocr: string, leyendas: string[], capsulas: ?int, es_foto_de_etiqueta: bool, coincidencias: array<int, array{label:string, ids:int[], puntaje:float}>, nombre_claro: bool, ambiguo: bool}
 */
function aiFotoAnalizar(string $textoUsuario, array $indice): array
{
    $vacio = [
        'es_foto_ocr' => false, 'caption' => '', 'ocr' => '', 'leyendas' => [], 'capsulas' => null,
        'es_foto_de_etiqueta' => false, 'coincidencias' => [], 'nombre_claro' => false, 'ambiguo' => false,
    ];

    $partes = aiFotoExtraerPartes($textoUsuario);
    if ($partes === null) {
        return $vacio;
    }

    $ocrNorm = aiFotoNormalizar($partes['ocr']);
    $textoTotalNorm = trim(aiFotoNormalizar($partes['caption']) . ' ' . $ocrNorm);
    $info = aiFotoLeyendasGenericas($ocrNorm);
    $coincidencias = aiFotoCoincidencias($textoTotalNorm, $indice);

    $nombreClaro = false;
    $ambiguo = false;
    if ($coincidencias !== []) {
        $mejor = $coincidencias[0];
        $nombreClaro = $mejor['puntaje'] >= AI_FOTO_PUNTAJE_CLARO;
        if (isset($coincidencias[1]) && ($mejor['puntaje'] - $coincidencias[1]['puntaje']) < AI_FOTO_MARGEN_EMPATE) {
            $ambiguo = true;
        }
    }

    return [
        'es_foto_ocr' => true,
        'caption' => $partes['caption'],
        'ocr' => $partes['ocr'],
        'leyendas' => $info['leyendas'],
        'capsulas' => $info['capsulas'],
        'es_foto_de_etiqueta' => $info['leyendas'] !== [],
        'coincidencias' => $coincidencias,
        'nombre_claro' => $nombreClaro,
        'ambiguo' => $ambiguo,
    ];
}

/**
 * Linea de contexto (para el prompt de ESTE turno) segun lo que se pudo identificar en la foto.
 * Cadena vacia si el mensaje no es una foto con OCR, o si parece otra cosa (comprobante de pago,
 * captura de pantalla...) donde no aplica nada de esto.
 *
 * @param array<string, mixed> $analisis Resultado de aiFotoAnalizar().
 */
function aiFotoBuildContextLine(array $analisis): string
{
    if (empty($analisis['es_foto_ocr'])) {
        return '';
    }

    $coincidencias = is_array($analisis['coincidencias'] ?? null) ? $analisis['coincidencias'] : [];
    $capsulas = isset($analisis['capsulas']) && $analisis['capsulas'] !== null ? (int) $analisis['capsulas'] : null;
    $textoCapsulas = $capsulas !== null
        ? " La etiqueta parece decir {$capsulas} capsulas: si consultar_inventario regresa varias presentaciones, confirma cual es con el cliente."
        : '';
    $leyendas = is_array($analisis['leyendas'] ?? null) ? $analisis['leyendas'] : [];
    $listaLeyendas = $leyendas !== [] ? implode(', ', $leyendas) : 'leyendas genericas';

    if ($coincidencias !== []) {
        $nombres = array_map(static fn(array $c): string => '«' . $c['label'] . '»', $coincidencias);
        if (!empty($analisis['ambiguo']) && count($coincidencias) > 1) {
            return 'FOTO DE PRODUCTO (deteccion automatica por codigo, es solo una pista): por el texto que el OCR alcanzo a leer, el producto de la foto podria ser '
                . implode(' o ', $nombres) . '. No sabes cual es: llama a consultar_inventario con esos nombres, comparte las opciones reales que te regrese y pidele al cliente que confirme cual dice el frasco (viene al frente, en letras grandes) antes de dar un precio como definitivo.'
                . $textoCapsulas;
        }

        $mejor = $coincidencias[0];
        $seguridad = !empty($analisis['nombre_claro']) ? 'con bastante seguridad' : 'con poca seguridad';

        return 'FOTO DE PRODUCTO (deteccion automatica por codigo, es solo una pista): el OCR sugiere ' . $seguridad . ' que el producto de la foto es «'
            . $mejor['label'] . '». Llama a consultar_inventario con ese nombre para verificarlo y comparte precio y existencia reales; nunca des un precio sin haberlo consultado. Si el cliente escribio otro nombre en su mensaje, manda lo que el cliente dice.'
            . $textoCapsulas;
    }

    if (!empty($analisis['es_foto_de_etiqueta'])) {
        return 'FOTO DE PRODUCTO SIN NOMBRE LEGIBLE: la foto es de una etiqueta o frasco, pero el OCR NO alcanzo a leer el nombre del producto (el nombre va en letras grandes al frente y suele fallar); solo leyo texto generico de la etiqueta (' . $listaLeyendas . '). '
            . 'Reglas: (1) NO busques esas leyendas en consultar_inventario como si fueran el nombre del producto (por ejemplo "Mento Alimentic" es "SUPLEMENTO ALIMENTICIO" mal leido, viene en todos los frascos). '
            . '(2) NO ofrezcas productos "similares" o "con esas caracteristicas" como si fueran el de la foto ni des precios de productos que el cliente no nombro. '
            . '(3) Si el texto que el cliente escribio junto con la foto nombra el producto, usa ese nombre. Si no, llama a transferir_a_humano con un motivo que describa lo que si se leyo de la foto (por ejemplo la marca, el numero de capsulas y los ingredientes) y responde con calidez algo breve, como que lo confirmas con el equipo para darle el precio correcto.'
            . $textoCapsulas;
    }

    return '';
}

/**
 * Nombres y nombres cortos de los productos activos, para construir el indice de emparejamiento.
 *
 * @return array<int, array{id_producto:int, nombre:string, nombre_corto:?string}>
 */
function aiFotoCargarCatalogo(PDO $pdo): array
{
    $filas = $pdo->query("SELECT id_producto, nombre, nombre_corto FROM productos WHERE estado = 'activo'")->fetchAll(PDO::FETCH_ASSOC);

    return is_array($filas) ? $filas : [];
}

/**
 * Punto de entrada para aiGenerarRespuestaParaConversacion(): linea de contexto para el turno,
 * o cadena vacia (sin tocar la BD) si el mensaje no es una foto con OCR. Nunca lanza: si algo
 * falla, Alex sigue sin la pista en vez de fallar el turno.
 */
function aiFotoBuildContextLineParaMensaje(PDO $pdo, string $textoUsuario): string
{
    if (mb_stripos($textoUsuario, AI_FOTO_MARCADOR_OCR) === false) {
        return '';
    }

    try {
        $indice = aiFotoConstruirIndice(aiFotoCargarCatalogo($pdo));

        return aiFotoBuildContextLine(aiFotoAnalizar($textoUsuario, $indice));
    } catch (Throwable $e) {
        error_log('aiFotoBuildContextLineParaMensaje: ' . $e->getMessage());

        return '';
    }
}

/**
 * Lee el texto de una foto con Google Vision TEXT_DETECTION (texto "de escena": frascos, letreros,
 * tipografias decorativas), que rinde mucho mejor que Tesseract con letras estilizadas sobre un
 * frasco curvo y con reflejos. Es la contraparte de loteOcrDetectarTexto() (DOCUMENT_TEXT_DETECTION,
 * pensada para texto denso en hojas). Requiere gsmHttpRequest() (core/google_secret_manager.php).
 *
 * @return array{ok: bool, texto: string, error: string}
 */
function aiFotoVisionDetectarTexto(string $imagenBase64, string $apiKey): array
{
    $imagenBase64 = trim($imagenBase64);
    $apiKey = trim($apiKey);

    if ($apiKey === '') {
        return ['ok' => false, 'texto' => '', 'error' => 'Falta la llave de Google Vision (VISION_KEY).'];
    }
    if ($imagenBase64 === '') {
        return ['ok' => false, 'texto' => '', 'error' => 'No se recibio ninguna imagen.'];
    }
    if (!function_exists('gsmHttpRequest')) {
        return ['ok' => false, 'texto' => '', 'error' => 'Cliente HTTP no disponible en este entorno.'];
    }

    $body = json_encode([
        'requests' => [[
            'image' => ['content' => $imagenBase64],
            'features' => [['type' => 'TEXT_DETECTION']],
            'imageContext' => ['languageHints' => ['es', 'en']],
        ]],
    ]);

    $response = gsmHttpRequest(
        'POST',
        'https://vision.googleapis.com/v1/images:annotate?key=' . rawurlencode($apiKey),
        (string) $body,
        ['Content-Type' => 'application/json'],
        20
    );

    $data = json_decode((string) ($response['body'] ?? ''), true);

    if (empty($response['ok'])) {
        $mensajeApi = is_array($data) ? ($data['error']['message'] ?? null) : null;
        $detalle = is_string($mensajeApi) && $mensajeApi !== '' ? $mensajeApi : ('HTTP ' . ($response['code'] ?? 0));

        return ['ok' => false, 'texto' => '', 'error' => 'Google Vision: ' . $detalle];
    }
    if (!is_array($data)) {
        return ['ok' => false, 'texto' => '', 'error' => 'Respuesta invalida de Google Vision.'];
    }

    $apiError = $data['responses'][0]['error']['message'] ?? null;
    if (is_string($apiError) && $apiError !== '') {
        return ['ok' => false, 'texto' => '', 'error' => $apiError];
    }

    $texto = $data['responses'][0]['fullTextAnnotation']['text'] ?? ($data['responses'][0]['textAnnotations'][0]['description'] ?? '');
    if (!is_string($texto) || trim($texto) === '') {
        return ['ok' => false, 'texto' => '', 'error' => 'No se detecto texto en la foto.'];
    }

    return ['ok' => true, 'texto' => trim($texto), 'error' => ''];
}
