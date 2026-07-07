<?php
declare(strict_types=1);

/**
 * Normaliza imagenes del catalogo a un estandar uniforme de calidad y dimension.
 *
 * Uso (Windows/XAMPP):
 *   C:\xampp\php\php.exe scripts\normalize_product_images.php --max=1600 --quality=82
 *   C:\xampp\php\php.exe scripts\normalize_product_images.php --dry-run
 *
 * Notas:
 * - Trabaja en sitio (sobrescribe archivos) para no romper rutas en BD.
 * - Conserva extension original (jpg/jpeg/png/webp).
 * - Requiere extension GD habilitada.
 */

$defaultBaseDir = realpath(__DIR__ . '/../assets/img/products');
if ($defaultBaseDir === false) {
    fwrite(STDERR, "No se encontro assets/img/products\n");
    exit(1);
}

$options = getopt('', ['dir::', 'max::', 'quality::', 'dry-run']);
$baseDir = isset($options['dir']) ? (string)$options['dir'] : $defaultBaseDir;
$baseDir = rtrim(str_replace('\\', '/', $baseDir), '/');
$maxDimension = isset($options['max']) ? max(300, (int)$options['max']) : 1600;
$quality = isset($options['quality']) ? max(50, min(95, (int)$options['quality'])) : 82;
$dryRun = array_key_exists('dry-run', $options);

if (!extension_loaded('gd')) {
    fwrite(STDERR, "GD no esta habilitada en este PHP CLI.\n");
    exit(1);
}

if (!is_dir($baseDir)) {
    fwrite(STDERR, "Directorio invalido: {$baseDir}\n");
    exit(1);
}

$allowed = ['jpg', 'jpeg', 'png', 'webp'];

$files = [];
$it = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS)
);

foreach ($it as $fileInfo) {
    if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
        continue;
    }

    $ext = strtolower(pathinfo($fileInfo->getFilename(), PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        continue;
    }

    $files[] = $fileInfo->getPathname();
}

sort($files, SORT_NATURAL | SORT_FLAG_CASE);

$totalBefore = 0;
$totalAfter = 0;
$processed = 0;
$skipped = 0;
$errors = 0;
$reduced = 0;
$increased = 0;

$startedAt = microtime(true);

echo "Normalizando imagenes en: {$baseDir}\n";
echo "Archivos detectados: " . count($files) . "\n";
echo "Config: max={$maxDimension}px, quality={$quality}, dryRun=" . ($dryRun ? 'si' : 'no') . "\n\n";

foreach ($files as $path) {
    $before = filesize($path) ?: 0;
    $totalBefore += $before;

    $imageData = @file_get_contents($path);
    if ($imageData === false || $imageData === '') {
        $errors++;
        echo "[ERROR] No se pudo leer: {$path}\n";
        continue;
    }

    $img = @imagecreatefromstring($imageData);
    if ($img === false) {
        $errors++;
        echo "[ERROR] No se pudo abrir imagen: {$path}\n";
        continue;
    }

    $width = imagesx($img);
    $height = imagesy($img);

    if ($width <= 0 || $height <= 0) {
        imagedestroy($img);
        $errors++;
        echo "[ERROR] Dimensiones invalidas: {$path}\n";
        continue;
    }

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

    // Corregir orientacion EXIF solo en JPEG.
    if (in_array($ext, ['jpg', 'jpeg'], true) && function_exists('exif_read_data')) {
        $exif = @exif_read_data($path);
        $orientation = isset($exif['Orientation']) ? (int)$exif['Orientation'] : 1;

        if ($orientation === 3) {
            $rotated = imagerotate($img, 180, 0);
            if ($rotated !== false) {
                imagedestroy($img);
                $img = $rotated;
            }
        } elseif ($orientation === 6) {
            $rotated = imagerotate($img, -90, 0);
            if ($rotated !== false) {
                imagedestroy($img);
                $img = $rotated;
            }
        } elseif ($orientation === 8) {
            $rotated = imagerotate($img, 90, 0);
            if ($rotated !== false) {
                imagedestroy($img);
                $img = $rotated;
            }
        }

        $width = imagesx($img);
        $height = imagesy($img);
    }

    $target = $img;
    $newW = $width;
    $newH = $height;

    $maxSide = max($width, $height);
    if ($maxSide > $maxDimension) {
        $scale = $maxDimension / $maxSide;
        $newW = max(1, (int)round($width * $scale));
        $newH = max(1, (int)round($height * $scale));

        $resized = imagecreatetruecolor($newW, $newH);
        if ($resized === false) {
            imagedestroy($img);
            $errors++;
            echo "[ERROR] No se pudo crear lienzo: {$path}\n";
            continue;
        }

        if (in_array($ext, ['png', 'webp'], true)) {
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            $transparent = imagecolorallocatealpha($resized, 0, 0, 0, 127);
            imagefilledrectangle($resized, 0, 0, $newW, $newH, $transparent);
        }

        imagecopyresampled($resized, $img, 0, 0, 0, 0, $newW, $newH, $width, $height);
        imagedestroy($img);
        $target = $resized;
    }

    if (!$dryRun) {
        $saved = false;

        if ($ext === 'webp') {
            $saved = imagewebp($target, $path, $quality);
        } elseif ($ext === 'png') {
            // PNG usa nivel de compresion 0-9 (mas alto, mas chico, mas lento)
            $saved = imagepng($target, $path, 8);
        } else {
            imageinterlace($target, true);
            $saved = imagejpeg($target, $path, $quality);
        }

        if ($saved === false) {
            imagedestroy($target);
            $errors++;
            echo "[ERROR] No se pudo guardar: {$path}\n";
            continue;
        }
    }

    imagedestroy($target);

    $after = $dryRun ? $before : (filesize($path) ?: 0);
    $totalAfter += $after;
    $processed++;

    if ($after < $before) {
        $reduced++;
    } elseif ($after > $before) {
        $increased++;
    } else {
        $skipped++;
    }

    $delta = $after - $before;
    $deltaKb = number_format($delta / 1024, 1);
    $status = $delta < 0 ? 'OK' : ($delta > 0 ? 'WARN' : 'IGUAL');

    echo "[{$status}] {$path} | "
        . number_format($before / 1024, 1) . "KB -> "
        . number_format($after / 1024, 1) . "KB ({$deltaKb}KB)"
        . ($newW !== $width || $newH !== $height ? " | {$width}x{$height} -> {$newW}x{$newH}" : '')
        . "\n";
}

$elapsed = microtime(true) - $startedAt;

$diff = $totalAfter - $totalBefore;
$diffLabel = number_format($diff / 1024 / 1024, 2);

echo "\n===== RESUMEN =====\n";
echo "Procesadas: {$processed}\n";
echo "Reducidas: {$reduced}\n";
echo "Aumentadas: {$increased}\n";
echo "Sin cambio: {$skipped}\n";
echo "Errores: {$errors}\n";
echo "Total antes: " . number_format($totalBefore / 1024 / 1024, 2) . " MB\n";
echo "Total despues: " . number_format($totalAfter / 1024 / 1024, 2) . " MB\n";
echo "Diferencia: {$diffLabel} MB\n";
echo "Tiempo: " . number_format($elapsed, 2) . " s\n";

exit($errors > 0 ? 2 : 0);
