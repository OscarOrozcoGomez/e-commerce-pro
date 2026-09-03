<?php
/**
 * Auditoria exhaustiva de imagenes de producto para detectar las que
 * fallaran al cargar en produccion (Linux, filesystem case-sensitive).
 *
 * Uso:  C:\xampp\php\php.exe scripts\_img_audit.php
 */
require __DIR__ . '/../core/config.php';
require __DIR__ . '/../core/auth.php';

$pdo = getPDO();
$baseDir = realpath(__DIR__ . '/../assets/img/products');

/* ---------- 1. Indice REAL del disco (case-sensitive) ---------- */
$folderExactByLower = [];          // lower(folder) => real folder name
$foldersByProdId    = [];          // id => [real folder names]
$filesByFolderLower = [];          // real folder => [ lower(file) => real file ]
foreach (scandir($baseDir) as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $full = $baseDir . DIRECTORY_SEPARATOR . $entry;
    if (!is_dir($full)) continue;
    $folderExactByLower[mb_strtolower($entry)] = $entry;
    if (preg_match('/-(\d+)$/', $entry, $m)) {
        $foldersByProdId[(int)$m[1]][] = $entry;
    }
    $filesByFolderLower[$entry] = [];
    foreach (scandir($full) as $f) {
        if ($f === '.' || $f === '..') continue;
        if (is_file($full . DIRECTORY_SEPARATOR . $f)) {
            $filesByFolderLower[$entry][mb_strtolower($f)] = $f;
        }
    }
}

/* ---------- 2. Todos los productos + su referencia de imagen ---------- */
$sql = "SELECT p.id_producto, p.id_padre, p.estado, p.nombre, p.nombre_variante, p.sku,
        COALESCE(
          (SELECT pi.ruta_archivo FROM producto_imagenes pi
             INNER JOIN productos pm ON pi.id_producto = pm.id_producto
           WHERE (pm.id_producto = p.id_producto OR pm.id_padre = p.id_producto)
           ORDER BY (pm.id_producto = p.id_producto) DESC, pi.orden ASC LIMIT 1),
          NULLIF(TRIM(p.imagen), ''), NULLIF(TRIM(p.imagen_url), '')
        ) AS ref,
        NULLIF(TRIM(p.imagen),'')      AS raw_imagen,
        NULLIF(TRIM(p.imagen_url),'')  AS raw_url,
        (SELECT COUNT(*) FROM producto_imagenes pi2
           INNER JOIN productos pm2 ON pi2.id_producto = pm2.id_producto
         WHERE (pm2.id_producto = p.id_producto OR pm2.id_padre = p.id_producto)) AS n_galeria
        FROM productos p
        ORDER BY p.id_producto";
$rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

/* ---------- 3. Clasificacion ---------- */
$buckets = [
    'OK'                 => [],
    'CASE_CARPETA'       => [],   // carpeta existe con otra capitalizacion -> rompe en Linux
    'CASE_ARCHIVO'       => [],   // archivo existe con otra capitalizacion -> rompe en Linux
    'EXT_MAYUS'          => [],   // extension en mayuscula (.JPG) -> fragil
    'SIN_CARPETA'        => [],   // no hay carpeta para ese id -> imagen inexistente
    'ARCHIVO_NO_EXISTE'  => [],   // carpeta ok, archivo no esta (ni por case) -> roto en todos lados
    'SOLO_FALLBACK'      => [],   // el path guardado NO existe; el fallback difuso encontro otro archivo
    'CHARS_RAROS'        => [],   // espacios, %20, no-ascii, parentesis en la ruta guardada
    'EXTERNA_HTTP'       => [],
    'BASE64'             => [],
    'SIN_IMAGEN'         => [],
];
$folderCaseAffected = [];

foreach ($rows as $r) {
    $id   = (int)$r['id_producto'];
    $ref  = trim((string)($r['ref'] ?? ''));
    $tag  = "ID=$id" . ($r['id_padre'] ? " (variante de {$r['id_padre']})" : '')
          . " [{$r['estado']}] " . $r['nombre']
          . ($r['nombre_variante'] ? " / {$r['nombre_variante']}" : '')
          . "  ref='" . $ref . "'";

    if ($ref === '') { $buckets['SIN_IMAGEN'][] = $tag; continue; }
    if (stripos($ref, 'http') === 0) { $buckets['EXTERNA_HTTP'][] = $tag; continue; }
    if (preg_match('/^(data:image|iVBORw|\/9j\/|UklGR)/', $ref)) { $buckets['BASE64'][] = $tag; continue; }

    // normaliza a ruta relativa dentro de assets/img/products/
    $rel = ltrim(str_replace('\\', '/', $ref), '/');
    $rel = strtok($rel, "?#");
    if (stripos($rel, 'assets/img/products/') === 0) $rel = substr($rel, strlen('assets/img/products/'));
    $rel = ltrim($rel, '/');

    $hasWeird = preg_match('/[ %()]|[^\x00-\x7F]/', $rel);

    $slash = strpos($rel, '/');
    $folder = $slash === false ? '' : substr($rel, 0, $slash);
    $file   = $slash === false ? $rel : substr($rel, $slash + 1);

    // ¿existe la carpeta tal cual?
    $folderReal = null; $folderCaseBad = false;
    if ($folder !== '') {
        if (isset($filesByFolderLower[$folder])) {
            $folderReal = $folder;
        } elseif (isset($folderExactByLower[mb_strtolower($folder)])) {
            $folderReal = $folderExactByLower[mb_strtolower($folder)];
            $folderCaseBad = true;
        }
    }

    // fallback por id (como hace la app)
    $fallbackFolders = $foldersByProdId[$id] ?? [];

    if ($folderReal === null) {
        // el path guardado apunta a una carpeta que no existe con ningun case
        if (!empty($fallbackFolders)) {
            $found = null;
            foreach ($fallbackFolders as $ff) {
                foreach ($filesByFolderLower[$ff] as $lf => $realf) {
                    $found = "$ff/$realf"; break 2;
                }
            }
            $buckets['SOLO_FALLBACK'][] = $tag . ($found ? "  -> usa '$found'" : "  -> carpeta id sin archivos");
        } else {
            $buckets['SIN_CARPETA'][] = $tag;
        }
        if ($hasWeird) $buckets['CHARS_RAROS'][] = $tag;
        continue;
    }

    // carpeta resuelta; ahora el archivo
    $lf = mb_strtolower($file);
    if (isset($filesByFolderLower[$folderReal][$lf])) {
        $realFile = $filesByFolderLower[$folderReal][$lf];
        if ($folderCaseBad) {
            $buckets['CASE_CARPETA'][] = $tag . "  disco='$folderReal/'";
            $folderCaseAffected[$folder] = $folderReal;
        } elseif ($realFile !== $file) {
            $buckets['CASE_ARCHIVO'][] = $tag . "  disco='$folderReal/$realFile'";
        } elseif (preg_match('/\.[A-Z]+$/', $file)) {
            $buckets['EXT_MAYUS'][] = $tag;
        } else {
            $buckets['OK'][] = $tag;
        }
    } else {
        // archivo no esta en esa carpeta; ¿fallback difuso?
        $stem = pathinfo($file, PATHINFO_FILENAME);
        $found = null;
        foreach ($filesByFolderLower[$folderReal] as $lfx => $realf) {
            if (stripos($lfx, mb_strtolower($stem) . '.') === 0) { $found = "$folderReal/$realf"; break; }
        }
        if (!$found) {
            foreach (['principal', 'img_principal', 'gal_1'] as $cand) {
                foreach ($filesByFolderLower[$folderReal] as $lfx => $realf) {
                    if (stripos($lfx, $cand . '.') === 0 || stripos($lfx, $cand . '_') === 0) { $found = "$folderReal/$realf"; break 2; }
                }
            }
        }
        if ($found) {
            $buckets['SOLO_FALLBACK'][] = $tag . "  -> usa '$found'";
        } else {
            $buckets['ARCHIVO_NO_EXISTE'][] = $tag . "  (carpeta '$folderReal' existe, sin match)";
        }
    }
    if ($hasWeird) $buckets['CHARS_RAROS'][] = $tag;
}

/* ---------- 4. Archivos en disco con nombres fragiles ---------- */
$riskyFiles = [];
foreach ($filesByFolderLower as $folder => $files) {
    foreach ($files as $realf) {
        if (preg_match('/[ %()]|[^\x00-\x7F]/', $realf) || preg_match('/\.[A-Z]+$/', $realf) || preg_match('/[A-Z]/', $realf)) {
            $riskyFiles[] = "$folder/$realf";
        }
    }
}
$riskyFolders = [];
foreach ($folderExactByLower as $low => $real) {
    if (preg_match('/[ %()]|[^\x00-\x7F]/', $real) || preg_match('/[A-Z]/', $real)) $riskyFolders[] = $real;
}

/* ---------- 5. Reporte ---------- */
$total = count($rows);
echo "==================================================================\n";
echo " AUDITORIA DE IMAGENES DE PRODUCTO   (productos: $total)\n";
echo "==================================================================\n\n";
$order = ['OK','SIN_IMAGEN','EXTERNA_HTTP','BASE64','SOLO_FALLBACK','CASE_CARPETA','CASE_ARCHIVO','EXT_MAYUS','SIN_CARPETA','ARCHIVO_NO_EXISTE','CHARS_RAROS'];
foreach ($order as $k) echo str_pad($k, 20) . count($buckets[$k]) . "\n";
echo "\n";

$detail = ['SIN_CARPETA','ARCHIVO_NO_EXISTE','CASE_CARPETA','CASE_ARCHIVO','EXT_MAYUS','SOLO_FALLBACK','SIN_IMAGEN','EXTERNA_HTTP'];
foreach ($detail as $k) {
    if (!$buckets[$k]) continue;
    echo "------ $k (" . count($buckets[$k]) . ") ------\n";
    foreach ($buckets[$k] as $line) echo "  $line\n";
    echo "\n";
}

echo "------ ARCHIVOS EN DISCO CON NOMBRE FRAGIL (" . count($riskyFiles) . ") ------\n";
foreach (array_slice($riskyFiles, 0, 60) as $f) echo "  $f\n";
if (count($riskyFiles) > 60) echo "  ... (" . (count($riskyFiles) - 60) . " mas)\n";
echo "\n";
echo "------ CARPETAS EN DISCO CON NOMBRE FRAGIL (" . count($riskyFolders) . ") ------\n";
foreach ($riskyFolders as $f) echo "  $f\n";
