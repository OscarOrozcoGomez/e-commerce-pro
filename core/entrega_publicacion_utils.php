<?php
declare(strict_types=1);

/**
 * Helpers puros (sin BD ni red) del flujo de publicacion de entregas, extraidos de
 * api/entrega_publicacion.php para poder cubrirlos con pruebas unitarias. El repartidor
 * puede adjuntar VARIAS fotos a una misma entrega: se guardan todas como evidencia, se
 * comparten todas por WhatsApp y en Facebook se publican como un solo post de album.
 */

/**
 * Maximo de fotos por publicacion de entrega. Facebook admite hasta 10 imagenes en un post
 * con attached_media; ademas subir muchas fotos desde el celular del repartidor es lento.
 * Si el navegador manda mas, epNormalizeFotoUploads() se queda con las primeras.
 */
const ENTREGA_PUBLICACION_MAX_FOTOS = 10;

/**
 * Normaliza $_FILES['foto'] a una lista de archivos individuales, sin importar si el
 * navegador mando una sola foto (input simple) o varias (input con `multiple`, que PHP
 * entrega como arrays paralelos: name[], tmp_name[], error[], size[], type[]). Descarta las
 * ranuras vacias (UPLOAD_ERR_NO_FILE) y recorta a ENTREGA_PUBLICACION_MAX_FOTOS.
 *
 * @param array<string, mixed>|null $fotoField Valor crudo de $_FILES['foto'] (o null si no vino).
 * @return list<array{name:string, type:string, tmp_name:string, error:int, size:int}>
 */
function epNormalizeFotoUploads(?array $fotoField): array
{
    if ($fotoField === null || !isset($fotoField['tmp_name'])) {
        return [];
    }

    // Input simple (sin `multiple`): los campos son escalares.
    if (!is_array($fotoField['tmp_name'])) {
        $one = [
            'name' => (string)($fotoField['name'] ?? ''),
            'type' => (string)($fotoField['type'] ?? ''),
            'tmp_name' => (string)($fotoField['tmp_name'] ?? ''),
            'error' => (int)($fotoField['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($fotoField['size'] ?? 0),
        ];
        return $one['error'] === UPLOAD_ERR_NO_FILE ? [] : [$one];
    }

    $out = [];
    foreach (array_keys($fotoField['tmp_name']) as $i) {
        $error = (int)($fotoField['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        $out[] = [
            'name' => (string)($fotoField['name'][$i] ?? ''),
            'type' => (string)($fotoField['type'][$i] ?? ''),
            'tmp_name' => (string)($fotoField['tmp_name'][$i] ?? ''),
            'error' => $error,
            'size' => (int)($fotoField['size'][$i] ?? 0),
        ];
        if (count($out) >= ENTREGA_PUBLICACION_MAX_FOTOS) {
            break;
        }
    }

    return $out;
}

/**
 * Normaliza los id_publicacion que manda el cliente. El flujo viejo manda un `id_publicacion`
 * suelto; el nuevo (varias fotos) manda `id_publicaciones` como lista. Se aceptan ambos.
 *
 * @param mixed $lista  Valor de $payload['id_publicaciones'] (idealmente una lista).
 * @param mixed $suelto Valor de $payload['id_publicacion'] (compatibilidad hacia atras).
 * @return list<int>    IDs enteros positivos, unicos, en orden de aparicion.
 */
function epNormalizeIdPublicaciones(mixed $lista, mixed $suelto = null): array
{
    $candidatos = [];
    if (is_array($lista)) {
        foreach ($lista as $v) {
            $candidatos[] = $v;
        }
    }
    if ($suelto !== null) {
        $candidatos[] = $suelto;
    }

    $out = [];
    foreach ($candidatos as $v) {
        $n = is_numeric($v) ? (int)$v : 0;
        if ($n > 0 && !in_array($n, $out, true)) {
            $out[] = $n;
        }
    }

    return $out;
}

/**
 * Arma los campos POST attached_media[N] que espera el endpoint /{page-id}/feed de Facebook
 * para crear un solo post con varias imagenes (album). Cada id es el de una foto ya subida
 * a la pagina con published=false.
 *
 * @param list<string|int> $mediaFbids
 * @return array<string, string>
 */
function epBuildAttachedMediaFields(array $mediaFbids): array
{
    $fields = [];
    $i = 0;
    foreach ($mediaFbids as $fbid) {
        $fbid = trim((string)$fbid);
        if ($fbid === '') {
            continue;
        }
        $fields['attached_media[' . $i . ']'] = json_encode(['media_fbid' => $fbid], JSON_UNESCAPED_UNICODE);
        $i++;
    }

    return $fields;
}
