<?php
declare(strict_types=1);

/**
 * Llena productos.nombre_corto (la etiqueta del frasco: "Omega 3 Platinum", "4 Mag Element")
 * de los productos activos que no lo tienen, sacandolo de la tienda de B-Life igual que SINC
 * en views/products.php (blifeShortName() sobre el body_html). Alex reconoce productos por
 * foto solo contra nombre_corto (core/ai_foto_producto_utils.php): sin el, no los identifica.
 *
 * Empareja cada producto con B-Life por SKU exacto, luego por titulo exacto y, al final, por el
 * arranque del nombre contra el arranque del handle (productos con SKU viejo). Solo escribe
 * donde nombre_corto sigue vacio (nunca pisa uno capturado a mano). Por defecto es simulacion.
 *
 * Uso:
 *   C:\xampp\php\php.exe scripts/blife_llenar_nombre_corto.php            # solo reporte
 *   C:\xampp\php\php.exe scripts/blife_llenar_nombre_corto.php --aplicar  # escribe
 *   ... --omitir=201,216  # ids que no se tocan (el reporte los marca OMITIDO)
 */

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/blife_sync_utils.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, 'Este script solo se puede ejecutar por CLI.' . PHP_EOL);
    exit(1);
}

$opciones = getopt('', ['aplicar', 'omitir:']);
$aplicar = array_key_exists('aplicar', $opciones);
$omitir = array_map('intval', array_filter(explode(',', (string) ($opciones['omitir'] ?? ''))));
$pdo = getPDO();

$productos = $pdo->query(
    "SELECT id_producto, sku, nombre FROM productos
     WHERE estado = 'activo' AND (nombre_corto IS NULL OR TRIM(nombre_corto) = '')
     ORDER BY id_producto"
)->fetchAll(PDO::FETCH_ASSOC);

$catalogo = blifeCatalog(true);
if ($catalogo === []) {
    fwrite(STDERR, 'No se pudo descargar el catalogo de B-Life.' . PHP_EOL);
    exit(1);
}
$porHandle = [];
$porTitulo = [];
foreach ($catalogo as $p) {
    $porHandle[(string) ($p['handle'] ?? '')] = $p;
    $porTitulo[blifeNormalizeText((string) ($p['title'] ?? ''))] = $p;
}

$update = $pdo->prepare(
    "UPDATE productos SET nombre_corto = ? WHERE id_producto = ? AND (nombre_corto IS NULL OR TRIM(nombre_corto) = '')"
);
$conteo = ['llenado' => 0, 'sin_nombre_en_blife' => 0, 'no_esta_en_blife' => 0];

foreach ($productos as $prod) {
    $id = (int) $prod['id_producto'];
    if (in_array($id, $omitir, true)) {
        printf("%-4d OMITIDO                  %s%s", $id, $prod['nombre'], PHP_EOL);
        continue;
    }
    $encontrado = blifeHandleFromSku((string) ($prod['sku'] ?? ''), $catalogo);
    $blife = $encontrado !== null
        ? ($porHandle[$encontrado[0]] ?? null)
        : ($porTitulo[blifeNormalizeText((string) $prod['nombre'])] ?? null);

    // SKU viejo y titulo distinto: el arranque del nombre ("Pros Platinum", "Reishi Blend B Life. ...")
    // contra el arranque del handle ("pros-platinum-suplemento-..."). Solo si hay UN candidato.
    if ($blife === null) {
        $cabeza = preg_split('~\s*\|\s*|\s+B\s*Life\b~u', (string) $prod['nombre'])[0] ?? '';
        $slugCabeza = trim((string) preg_replace('~[^a-z0-9]+~', '-', blifeNormalizeText($cabeza)), '-');
        $candidatos = strlen($slugCabeza) >= 6
            ? array_filter($porHandle, static fn($p, $h) => $h === $slugCabeza || str_starts_with((string) $h, $slugCabeza . '-'), ARRAY_FILTER_USE_BOTH)
            : [];
        if (count($candidatos) === 1) {
            $blife = reset($candidatos);
        }
    }

    if ($blife === null) {
        $conteo['no_esta_en_blife']++;
        printf("%-4d NO ESTA EN B-LIFE        %s%s", $id, $prod['nombre'], PHP_EOL);
        continue;
    }

    $corto = blifeShortName((string) ($blife['body_html'] ?? ''), $blife['tags'] ?? [], (string) ($blife['handle'] ?? ''));
    if ($corto === '') {
        $conteo['sin_nombre_en_blife']++;
        printf("%-4d SIN NOMBRE (capturar)  %s  [%s]%s", $id, $prod['nombre'], $blife['handle'] ?? '', PHP_EOL);
        continue;
    }

    $conteo['llenado']++;
    printf("%-4d %-24s %s%s", $id, $corto, $prod['nombre'], PHP_EOL);
    if ($aplicar) {
        $update->execute([$corto, $id]);
        if ($update->rowCount() > 0) {
            logAuditCambios('PRODUCTO_EDITADO', 'productos', $id, ['nombre_corto' => null], ['nombre_corto' => $corto], [], [
                'contexto' => (string) $prod['nombre'] . ' (script blife_llenar_nombre_corto)',
            ]);
        }
    }
}

printf(
    "%s%s: %d con nombre, %d sin nombre en B-Life, %d no estan en B-Life (de %d).%s",
    PHP_EOL,
    $aplicar ? 'APLICADO' : 'SIMULACION (usa --aplicar para escribir)',
    $conteo['llenado'],
    $conteo['sin_nombre_en_blife'],
    $conteo['no_esta_en_blife'],
    count($productos),
    PHP_EOL
);
