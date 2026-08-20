<?php
declare(strict_types=1);

/**
 * Intenta extraer el nombre de la colonia/fraccionamiento de una direccion en texto libre.
 * Primero busca las palabras clave usuales ("Colonia", "Col.", "Fraccionamiento", "Fracc.",
 * "Barrio"); si no aparecen, usa el segundo segmento separado por comas como aproximacion
 * razonable (patron comun: "Calle y numero, Colonia, Ciudad, ..."). Devuelve '' cuando no hay
 * nada usable, ya que el repartidor puede escribirla a mano antes de publicar.
 */
function extractColoniaFromAddress(?string $direccion): string
{
    $direccion = trim((string)$direccion);
    if ($direccion === '') {
        return '';
    }

    if (preg_match('/\b(?:colonia|col\.?|fraccionamiento|fracc\.?|barrio)\s+([^,;]+)/iu', $direccion, $m)) {
        $candidate = trim($m[1]);
    } else {
        $segments = array_map('trim', explode(',', $direccion));
        $segments = array_values(array_filter($segments, static fn (string $s): bool => $s !== ''));
        $candidate = $segments[1] ?? '';
    }

    return sanitizeColoniaCandidate($candidate);
}

function sanitizeColoniaCandidate(string $candidate): string
{
    // Corta lo que venga despues de un codigo postal o de otra palabra clave de direccion.
    $candidate = preg_split('/\b(?:c\.?p\.?|codigo postal|municipio|delegacion)\b/iu', $candidate)[0] ?? $candidate;
    $candidate = trim($candidate, " \t\n\r\0\x0B.-");

    if ($candidate === '' || preg_match('/^\d+$/', $candidate)) {
        return '';
    }

    if (mb_strlen($candidate) < 3) {
        return '';
    }

    return mb_convert_case(mb_strtolower($candidate), MB_CASE_TITLE, 'UTF-8');
}

/**
 * Convierte un nombre de colonia en un hashtag valido (sin acentos, espacios ni signos).
 */
function slugifyHashtag(string $texto): string
{
    $map = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N',
    ];
    $texto = strtr($texto, $map);
    $texto = preg_replace('/[^A-Za-z0-9\s]/', '', $texto) ?? '';
    $words = array_filter(explode(' ', $texto), static fn (string $w): bool => $w !== '');

    return implode('', array_map(static fn (string $w): string => ucfirst(strtolower($w)), $words));
}

/**
 * Convierte un numero de entrega (1, 2, 3...) a su ordinal femenino en espanol, para frases
 * como "Primera entrega", "Segunda entrega". Cubre 1-30 (rango realista de paradas por ruta);
 * fuera de ese rango regresa null y el llamador usa un formato numerico simple como respaldo.
 */
function ordinalFemeninoEntrega(int $numero): ?string
{
    static $ordinales = [
        1 => 'Primera', 2 => 'Segunda', 3 => 'Tercera', 4 => 'Cuarta', 5 => 'Quinta',
        6 => 'Sexta', 7 => 'Septima', 8 => 'Octava', 9 => 'Novena', 10 => 'Decima',
        11 => 'Undecima', 12 => 'Duodecima', 13 => 'Decimotercera', 14 => 'Decimocuarta',
        15 => 'Decimoquinta', 16 => 'Decimosexta', 17 => 'Decimoseptima', 18 => 'Decimoctava',
        19 => 'Decimonovena', 20 => 'Vigesima', 21 => 'Vigesima primera', 22 => 'Vigesima segunda',
        23 => 'Vigesima tercera', 24 => 'Vigesima cuarta', 25 => 'Vigesima quinta',
        26 => 'Vigesima sexta', 27 => 'Vigesima septima', 28 => 'Vigesima octava',
        29 => 'Vigesima novena', 30 => 'Trigesima',
    ];

    return $ordinales[$numero] ?? null;
}

/**
 * Arma el texto sugerido para la publicacion de entrega. Si no hay colonia detectable,
 * regresa la plantilla sin ese hashtag (el repartidor la completa a mano si quiere).
 * $numeroEntrega (si se da y es > 0) antepone "Primera/Segunda/... entrega del dia" en base
 * al orden de la ruta generada (o, si no hay ruta, al conteo de entregas del dia).
 */
function buildDeliveryPostText(string $colonia, ?int $numeroEntrega = null): string
{
    $prefijo = '';
    if ($numeroEntrega !== null && $numeroEntrega > 0) {
        $ordinal = ordinalFemeninoEntrega($numeroEntrega);
        $prefijo = $ordinal !== null
            ? '¡' . $ordinal . ' entrega del dia completada! '
            : '¡Entrega #' . $numeroEntrega . ' del dia completada! ';
    }

    $base = $prefijo !== ''
        ? '📦🚚 ' . $prefijo . '#EntregaExpress'
        : '¡Pedido entregado! 📦🚚 #EntregaExpress';

    $hashtagColonia = slugifyHashtag($colonia);

    if ($hashtagColonia === '') {
        return $base;
    }

    return $base . ' #' . $hashtagColonia;
}
