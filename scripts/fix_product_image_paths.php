<?php
/**
 * Persiste la ruta REAL de la imagen de cada producto en la base de datos,
 * para que el catalogo / detalle dejen de depender del rescate difuso en runtime
 * (findProductImageById) y las imagenes carguen igual en local y en produccion.
 *
 * Que hace:
 *   1. productos.imagen  -> lo reescribe con la ruta relativa que realmente
 *      resuelve en disco (misma logica que usa la web). Si no resuelve nada,
 *      solo lo REPORTA como "SIN IMAGEN" (no lo toca; hay que subir el archivo).
 *   2. producto_imagenes.ruta_archivo -> si apunta a un archivo que no existe
 *      y no se puede recuperar, propone BORRAR la fila. Si se puede recuperar
 *      (mismo stem en la carpeta correcta), propone corregir la ruta.
 *
 * Uso:
 *   C:\xampp\php\php.exe scripts\fix_product_image_paths.php            (dry-run, no escribe)
 *   C:\xampp\php\php.exe scripts\fix_product_image_paths.php --apply    (aplica en una transaccion)
 *   ... --apply --blank-broken   (ademas pone '' en productos.imagen cuando la ruta guardada
 *                                 es basura y no resuelve, para forzar el placeholder limpio)
 *
 * Correr primero en local. Para produccion: subir este archivo + assets/img/products/
 * sincronizado y correrlo alli con --apply.
 */

require __DIR__ . '/../core/config.php';
require __DIR__ . '/../core/auth.php';

$APPLY       = in_array('--apply', $argv, true);
$BLANK_BROKEN = in_array('--blank-broken', $argv, true);

// --base=<ruta> permite apuntar al assets/img/products/ real cuando el script se
// corre desde un worktree (donde esa carpeta esta gitignoreada / vacia).
$baseOverride = null;
foreach ($argv as $a) {
    if (strpos($a, '--base=') === 0) $baseOverride = substr($a, 7);
}

$pdo = getPDO();
$baseDir = realpath($baseOverride ?? (__DIR__ . '/../assets/img/products'));
if ($baseDir === false || !is_dir($baseDir)) {
    fwrite(STDERR, "No se encontro assets/img/products (base='" . ($baseOverride ?? '(default)') . "')\n");
    exit(1);
}
fwrite(STDERR, "base = $baseDir\n");

/* ---------- indice real del disco (case-sensitive, como Linux) ---------- */
$foldersByProdId = [];              // id => [carpetas reales]
$filesByFolder   = [];              // carpeta real => [ archivos reales ]
foreach (scandir($baseDir) as $entry) {
    if ($entry === '.' || $entry === '..') continue;
    $full = $baseDir . DIRECTORY_SEPARATOR . $entry;
    if (!is_dir($full)) continue;
    if (preg_match('/-(\d+)$/', $entry, $m)) {
        $foldersByProdId[(int)$m[1]][] = $entry;
    }
    $files = [];
    foreach (scandir($full) as $f) {
        if ($f === '.' || $f === '..') continue;
        if (is_file($full . DIRECTORY_SEPARATOR . $f)) $files[] = $f;
    }
    // preferencia: principal.* / img_principal.* / gal_1* primero
    usort($files, static function ($a, $b) {
        $rank = static function ($n) {
            $l = strtolower($n);
            if (strpos($l, 'principal.') === 0)     return 0;
            if (strpos($l, 'img_principal.') === 0) return 1;
            if (strpos($l, 'principal') === 0)      return 2;
            if (strpos($l, 'gal_1') === 0)          return 3;
            if (strpos($l, 'upd_0') === 0)          return 4;
            return 9;
        };
        return [$rank($a), $a] <=> [$rank($b), $b];
    });
    $filesByFolder[$entry] = $files;
}

$IMG_EXT = '/\.(jpg|jpeg|png|webp|gif|svg|avif)$/i';

/** ¿Es un valor que NO es una ruta local (base64 / data-uri / url http)? */
$isNonLocal = static function (string $v): bool {
    return $v !== ''
        && (strpos($v, 'http') === 0
            || preg_match('/^(data:image|iVBORw|\/9j\/|UklGR)/', $v));
};

/** Normaliza un valor de BD a ruta relativa dentro de assets/img/products/ */
$toRel = static function (string $v): string {
    $v = str_replace('\\', '/', trim($v));
    $v = strtok($v, "?#");
    $v = ltrim((string)$v, '/');
    if (stripos($v, 'assets/img/products/') === 0) $v = substr($v, strlen('assets/img/products/'));
    return ltrim($v, '/');
};

/**
 * Devuelve la ruta relativa real (carpeta/archivo) que corresponde a un producto,
 * o null si no hay nada en disco.
 */
$resolveCanonical = function (string $raw, int $id) use ($baseDir, $foldersByProdId, $filesByFolder, $toRel): ?string {
    $raw = trim($raw);

    // 1. si la ruta guardada existe tal cual, respetarla
    if ($raw !== '') {
        $rel = $toRel($raw);
        if ($rel !== '' && is_file($baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel))) {
            return $rel;
        }
        // 2. mismo stem en la carpeta indicada (si esa carpeta existe)
        $slash = strpos($rel, '/');
        if ($slash !== false) {
            $folder = substr($rel, 0, $slash);
            $file   = substr($rel, $slash + 1);
            $stem   = strtolower(pathinfo($file, PATHINFO_FILENAME));
            if (isset($filesByFolder[$folder]) && $stem !== '') {
                foreach ($filesByFolder[$folder] as $real) {
                    if (strpos(strtolower($real), $stem . '.') === 0) return $folder . '/' . $real;
                }
            }
        }
    }

    // 3. fallback por id: primera carpeta que termine en -<id> con algun archivo
    foreach ($foldersByProdId[$id] ?? [] as $folder) {
        if (!empty($filesByFolder[$folder])) {
            return $folder . '/' . $filesByFolder[$folder][0];
        }
    }
    return null;
};

/* ======================= 1. productos.imagen ======================= */
$rows = $pdo->query(
    "SELECT id_producto, id_padre, estado, nombre, nombre_variante, imagen
       FROM productos ORDER BY id_producto"
)->fetchAll(PDO::FETCH_ASSOC);

$updImagen = [];   // [id => nuevaRel]
$blankImagen = []; // [id => valorViejo]
$sinImagen = [];   // reporte
$yaOk = 0;

foreach ($rows as $r) {
    $id  = (int)$r['id_producto'];
    $cur = trim((string)$r['imagen']);

    if ($isNonLocal($cur)) { $yaOk++; continue; }

    $canon = $resolveCanonical($cur, $id);
    $curRel = $cur === '' ? '' : $toRel($cur);

    if ($canon === null) {
        $sinImagen[] = sprintf(
            "  ID=%d [%s] %s%s  imagen='%s'",
            $id, $r['estado'], $r['nombre'],
            $r['nombre_variante'] ? " / {$r['nombre_variante']}" : '',
            $cur
        );
        if ($cur !== '' && stripos($cur, 'default-product') === false) {
            $blankImagen[$id] = $cur;
        }
        continue;
    }

    if ($canon === $curRel) { $yaOk++; continue; }

    $updImagen[$id] = $canon;
}

/* =================== 2. producto_imagenes.ruta_archivo =================== */
$galRows = $pdo->query(
    "SELECT pi.id_imagen, pi.id_producto, pi.orden, pi.ruta_archivo,
            p.nombre, p.id_padre
       FROM producto_imagenes pi
       JOIN productos p ON p.id_producto = pi.id_producto
      ORDER BY pi.id_producto, pi.orden"
)->fetchAll(PDO::FETCH_ASSOC);

$galFix = [];   // [id_imagen => nuevaRel]
$galDel = [];   // [id_imagen => descripcion]
$galOk = 0;

foreach ($galRows as $g) {
    $ruta = trim((string)$g['ruta_archivo']);
    if ($isNonLocal($ruta) || $ruta === '') { $galOk++; continue; }

    $rel = $toRel($ruta);
    if ($rel !== '' && is_file($baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel))) {
        $galOk++;
        continue;
    }

    // intentar recuperar: mismo stem en carpeta correcta / fallback por id
    $canon = $resolveCanonical($ruta, (int)$g['id_producto']);
    if ($canon !== null) {
        $galFix[(int)$g['id_imagen']] = $canon;
    } else {
        $galDel[(int)$g['id_imagen']] = sprintf(
            "  id_imagen=%d id_producto=%d orden=%s  ruta='%s'  (%s)",
            $g['id_imagen'], $g['id_producto'], $g['orden'], $ruta, $g['nombre']
        );
    }
}

/* ======================= reporte ======================= */
$line = str_repeat('=', 70);
echo "$line\n";
echo " FIX RUTAS DE IMAGEN   -   " . ($APPLY ? "MODO APLICAR" : "DRY-RUN (no escribe nada)") . "\n";
echo "$line\n\n";

echo "productos.imagen:\n";
echo "  ya correctas / externas ....... $yaOk\n";
echo "  a corregir .................... " . count($updImagen) . "\n";
echo "  sin imagen en disco .......... " . count($sinImagen) . "\n";
if ($BLANK_BROKEN) echo "  a vaciar (ruta basura) ....... " . count($blankImagen) . "\n";
echo "\nproducto_imagenes.ruta_archivo:\n";
echo "  ya correctas ................. $galOk\n";
echo "  a corregir ruta ............. " . count($galFix) . "\n";
echo "  a BORRAR (irrecuperable) .... " . count($galDel) . "\n";

echo "\n------ productos.imagen A CORREGIR ------\n";
foreach ($updImagen as $id => $rel) echo "  ID=$id  ->  $rel\n";

echo "\n------ producto_imagenes A CORREGIR ------\n";
foreach ($galFix as $iid => $rel) echo "  id_imagen=$iid  ->  $rel\n";

echo "\n------ producto_imagenes A BORRAR ------\n";
foreach ($galDel as $txt) echo $txt . "\n";

echo "\n------ PRODUCTOS SIN IMAGEN (subir archivo a assets/img/products/<slug>-<id>/) ------\n";
foreach ($sinImagen as $txt) echo $txt . "\n";

if (!$APPLY) {
    echo "\n(dry-run) Nada fue modificado. Reejecuta con --apply para escribir.\n";
    exit(0);
}

/* ======================= aplicar ======================= */
$pdo->beginTransaction();
try {
    $u1 = $pdo->prepare("UPDATE productos SET imagen = :img WHERE id_producto = :id");
    foreach ($updImagen as $id => $rel) $u1->execute([':img' => $rel, ':id' => $id]);

    if ($BLANK_BROKEN) {
        foreach (array_keys($blankImagen) as $id) $u1->execute([':img' => '', ':id' => $id]);
    }

    $u2 = $pdo->prepare("UPDATE producto_imagenes SET ruta_archivo = :r WHERE id_imagen = :id");
    foreach ($galFix as $iid => $rel) $u2->execute([':r' => $rel, ':id' => $iid]);

    if (!empty($galDel)) {
        $ids = implode(',', array_map('intval', array_keys($galDel)));
        $pdo->exec("DELETE FROM producto_imagenes WHERE id_imagen IN ($ids)");
    }

    $pdo->commit();
    echo "\nOK. Aplicado:\n";
    echo "  productos.imagen corregidas ... " . count($updImagen) . "\n";
    if ($BLANK_BROKEN) echo "  productos.imagen vaciadas ..... " . count($blankImagen) . "\n";
    echo "  producto_imagenes corregidas .. " . count($galFix) . "\n";
    echo "  producto_imagenes borradas .... " . count($galDel) . "\n";
    echo "\nRecuerda invalidar el cache: core/cache/product_image_folder_index.json\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "ERROR, rollback: " . $e->getMessage() . "\n");
    exit(1);
}
