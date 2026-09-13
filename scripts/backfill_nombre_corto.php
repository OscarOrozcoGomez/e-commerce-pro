<?php
declare(strict_types=1);

/**
 * Llena productos.nombre_corto para el catalogo YA existente, cruzando cada
 * producto (por SKU) contra el catalogo de B-Life y derivando el nombre corto
 * de su body_html con blifeShortName() (misma funcion que usa el SINC al
 * traer un producto individual). Nunca se corrio un backfill masivo desde que
 * se agrego la columna (PR #170): solo se llena a mano en la ficha o al usar
 * "Buscar producto en B-Life" producto por producto.
 *
 * Solo toca productos con nombre_corto vacio (NULL o ''); nunca pisa un valor
 * ya capturado a mano.
 *
 * Uso:
 *   C:\xampp\php\php.exe scripts\backfill_nombre_corto.php            (dry-run, no escribe)
 *   C:\xampp\php\php.exe scripts\backfill_nombre_corto.php --apply    (aplica en una transaccion)
 *
 * Correr primero en local. Para produccion: subir este archivo y correrlo alli
 * con --apply (el catalogo de B-Life se descarga fresco, no depende de datos locales).
 */

require __DIR__ . '/../core/config.php';
require __DIR__ . '/../core/blife_sync_utils.php';

$APPLY = in_array('--apply', $argv, true);

/**
 * Normaliza un titulo para comparar: sin acentos/mayusculas (blifeNormalizeText),
 * puntuacion colapsada a espacios (conserva "|" como separador de segmento) y sin
 * guion/espacio colgante al final (las fichas locales suelen traer un "-" residual).
 */
function bnc_normTitle(string $s): string
{
    $s = blifeNormalizeText($s);
    $s = (string) preg_replace('~[^\p{L}\p{N}|]+~u', ' ', $s);
    $s = trim((string) preg_replace('~\s+~u', ' ', $s));
    return rtrim($s, '| ');
}

/**
 * ¿El nombre local y el titulo de B-Life son "el mismo producto" con confianza
 * alta? Exige que uno sea PREFIJO del otro (no basta con aparecer en cualquier
 * parte: "Ajo negro" aparece dentro de la lista de ingredientes de un blend que
 * NO es de ajo negro) y que tengan longitud comparable (evita que un nombre
 * generico corto quede pegado al titulo larguisimo de una variante distinta,
 * p. ej. "Citrato de magnesio" contra la version infantil saborizada).
 */
function bnc_matchConfiable(string $normLocal, string $normBlife): bool
{
    if ($normLocal === $normBlife) return true;
    $lenA = mb_strlen($normLocal);
    $lenB = mb_strlen($normBlife);
    if ($lenA < 8 || $lenB < 8) return false;
    $ratio = min($lenA, $lenB) / max($lenA, $lenB);
    if ($ratio < 0.4) return false;
    return mb_strpos($normLocal, $normBlife) === 0 || mb_strpos($normBlife, $normLocal) === 0;
}

$pdo = getPDO();

$rows = $pdo->query(
    "SELECT id_producto, nombre, sku FROM productos
     WHERE (nombre_corto IS NULL OR nombre_corto = '')
     ORDER BY id_producto"
)->fetchAll(PDO::FETCH_ASSOC);

fwrite(STDERR, "Descargando catalogo de B-Life...\n");
$catalogo = blifeCatalog();
fwrite(STDERR, "Catalogo: " . count($catalogo) . " productos.\n\n");

// SKUs locales (BLIFE-9MA-007...) son codigos internos secuenciales, NO el SKU
// real de Shopify (BLIFE9MAPLATINUM007RE...) salvo excepciones puntuales donde
// se copio tal cual: el match por SKU solo sirve como primer intento exacto,
// no como via principal.
$byHandle = [];
$blifeEntries = [];
foreach ($catalogo as $p) {
    $body = (string) ($p['body_html'] ?? '');
    $corto = blifeShortName($body);
    $byHandle[(string) ($p['handle'] ?? '')] = $corto;
    if ($corto !== '') {
        $blifeEntries[] = ['norm' => bnc_normTitle((string) ($p['title'] ?? '')), 'title' => (string) ($p['title'] ?? ''), 'short' => $corto];
    }
}

$sinNombreConfiable = [];   // [id => [nombre, sku]]  -> hay que capturarlo a mano
$aActualizar = [];          // [id => [corto, via, refTitulo]]

foreach ($rows as $r) {
    $id  = (int) $r['id_producto'];
    $sku = trim((string) $r['sku']);

    // 1) SKU exacto (rapido y 100% confiable cuando aplica).
    if ($sku !== '') {
        $match = blifeHandleFromSku($sku, $catalogo);
        if ($match !== null) {
            $corto = $byHandle[$match[0]] ?? '';
            if ($corto !== '') {
                $aActualizar[$id] = [$corto, 'sku', $match[0]];
                continue;
            }
        }
    }

    // 2) Titulo: uno debe ser prefijo del otro y de longitud comparable.
    $normLocal = bnc_normTitle((string) $r['nombre']);
    $encontrado = false;
    foreach ($blifeEntries as $be) {
        if (bnc_matchConfiable($normLocal, $be['norm'])) {
            $aActualizar[$id] = [$be['short'], 'titulo', $be['title']];
            $encontrado = true;
            break;
        }
    }
    if (!$encontrado) {
        $sinNombreConfiable[$id] = [$r['nombre'], $sku];
    }
}

$line = str_repeat('=', 70);
echo "$line\n";
echo " BACKFILL nombre_corto   -   " . ($APPLY ? "MODO APLICAR" : "DRY-RUN (no escribe nada)") . "\n";
echo "$line\n\n";

echo "Productos con nombre_corto vacio ...... " . count($rows) . "\n";
echo "  a llenar (match confiable) ........... " . count($aActualizar) . "\n";
echo "  sin match confiable (capturar a mano). " . count($sinNombreConfiable) . "\n";

echo "\n------ A LLENAR ------\n";
foreach ($aActualizar as $id => [$corto, $via, $ref]) {
    echo "  ID=$id  ->  \"$corto\"  (via=$via, blife=\"$ref\")\n";
}

if (!$APPLY) {
    echo "\n(dry-run) Nada fue modificado. Reejecuta con --apply para escribir.\n";
    exit(0);
}

$pdo->beginTransaction();
try {
    $upd = $pdo->prepare("UPDATE productos SET nombre_corto = :corto WHERE id_producto = :id");
    foreach ($aActualizar as $id => [$corto, $via, $ref]) {
        $upd->execute([':corto' => $corto, ':id' => $id]);
    }
    $pdo->commit();
    echo "\nOK. nombre_corto llenado en " . count($aActualizar) . " productos.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "ERROR, rollback: " . $e->getMessage() . "\n");
    exit(1);
}
