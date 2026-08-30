<?php
declare(strict_types=1);

/**
 * Redimensiona/recomprime una imagen de producto recien guardada en disco si excede
 * un tamano razonable para como se muestra en el sitio (tarjetas de ~200px de alto,
 * galeria de detalle bastante mas grande, nunca a resolucion de foto de camara/DSLR).
 * Se usa despues de guardar el archivo (move_uploaded_file/file_put_contents), nunca
 * en el flujo critico de subida antes de eso -- si esto falla o GD no esta disponible,
 * la imagen original sencillamente se queda tal cual (nunca se bloquea la subida por
 * esto).
 */

const IMAGE_OPTIMIZER_MAX_DIMENSION = 1200;
const IMAGE_OPTIMIZER_JPEG_QUALITY = 82;
const IMAGE_OPTIMIZER_WEBP_QUALITY = 82;
const IMAGE_OPTIMIZER_PNG_COMPRESSION = 6;
// Si las dimensiones ya son razonables pero el archivo sigue pesando mas que esto (una
// foto de camara/DSLR poco comprimida, aun a 1080x1080), igual se re-encoda a la
// calidad objetivo sin cambiar dimensiones -- el peso importa tanto como el tamano.
const IMAGE_OPTIMIZER_MAX_BYTES_WITHOUT_REENCODE = 400 * 1024;

function imageOptimizerAvailable(): bool
{
    return extension_loaded('gd') && function_exists('imagecreatefromjpeg');
}

/**
 * @return array{resized: bool, reason: ?string}
 */
function optimizeUploadedProductImage(string $filePath): array
{
    if (!imageOptimizerAvailable()) {
        return ['resized' => false, 'reason' => 'gd_no_disponible'];
    }

    if (!is_file($filePath) || !is_readable($filePath) || !is_writable($filePath)) {
        return ['resized' => false, 'reason' => 'archivo_no_accesible'];
    }

    try {
        $info = @getimagesize($filePath);
        if ($info === false) {
            return ['resized' => false, 'reason' => 'no_es_imagen_valida'];
        }

        [$width, $height, $type] = $info;
        if ($width <= 0 || $height <= 0) {
            return ['resized' => false, 'reason' => 'dimensiones_invalidas'];
        }

        $maxSide = max($width, $height);
        $needsResize = $maxSide > IMAGE_OPTIMIZER_MAX_DIMENSION;
        $needsReencode = !$needsResize && (int) (@filesize($filePath) ?: 0) > IMAGE_OPTIMIZER_MAX_BYTES_WITHOUT_REENCODE;

        if (!$needsResize && !$needsReencode) {
            // Ya es razonable en dimension y peso: no reprocesar (evita perdida de
            // calidad innecesaria en imagenes que ya vienen bien optimizadas).
            return ['resized' => false, 'reason' => 'ya_dentro_del_limite'];
        }

        if ($needsResize) {
            $scale = IMAGE_OPTIMIZER_MAX_DIMENSION / $maxSide;
            $newWidth = max(1, (int) round($width * $scale));
            $newHeight = max(1, (int) round($height * $scale));
        } else {
            // Re-encode a la misma resolucion, solo baja la calidad/compresion.
            $newWidth = $width;
            $newHeight = $height;
        }

        $source = imageOptimizerCreateFrom($filePath, $type);
        if ($source === null) {
            return ['resized' => false, 'reason' => 'tipo_no_soportado'];
        }

        $resized = imagecreatetruecolor($newWidth, $newHeight);
        if ($resized === false) {
            return ['resized' => false, 'reason' => 'no_se_pudo_crear_lienzo'];
        }

        // Preservar transparencia en PNG/WebP con canal alfa.
        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_WEBP) {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
            imagefilledrectangle($resized, 0, 0, $newWidth, $newHeight, $transparent);
        }

        imagecopyresampled($resized, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        imagedestroy($source);

        $saved = imageOptimizerSaveTo($resized, $filePath, $type);
        imagedestroy($resized);

        if (!$saved) {
            return ['resized' => false, 'reason' => 'no_se_pudo_guardar'];
        }

        return ['resized' => true, 'reason' => null];
    } catch (Throwable $e) {
        error_log('optimizeUploadedProductImage: error inesperado con ' . $filePath . ': ' . $e->getMessage());
        return ['resized' => false, 'reason' => 'excepcion'];
    }
}

/**
 * @return resource|\GdImage|null
 */
function imageOptimizerCreateFrom(string $filePath, int $type)
{
    switch ($type) {
        case IMAGETYPE_JPEG:
            return function_exists('imagecreatefromjpeg') ? @imagecreatefromjpeg($filePath) ?: null : null;
        case IMAGETYPE_PNG:
            return function_exists('imagecreatefrompng') ? @imagecreatefrompng($filePath) ?: null : null;
        case IMAGETYPE_WEBP:
            return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($filePath) ?: null : null;
        case IMAGETYPE_GIF:
            return function_exists('imagecreatefromgif') ? @imagecreatefromgif($filePath) ?: null : null;
        default:
            return null;
    }
}

/**
 * @param resource|\GdImage $image
 */
function imageOptimizerSaveTo($image, string $filePath, int $type): bool
{
    switch ($type) {
        case IMAGETYPE_JPEG:
            return @imagejpeg($image, $filePath, IMAGE_OPTIMIZER_JPEG_QUALITY);
        case IMAGETYPE_PNG:
            return @imagepng($image, $filePath, IMAGE_OPTIMIZER_PNG_COMPRESSION);
        case IMAGETYPE_WEBP:
            return function_exists('imagewebp') ? @imagewebp($image, $filePath, IMAGE_OPTIMIZER_WEBP_QUALITY) : false;
        case IMAGETYPE_GIF:
            return @imagegif($image, $filePath);
        default:
            return false;
    }
}
