<?php
declare(strict_types=1);
/**
 * Aplica scripts/.mayoreo/mayoreo_urls.json ({ "SKU": "https://mayoreo.blife.mx/producto/..." })
 * a la columna productos.mayoreo_url, para que la proxima corrida del llenado de
 * carrito sea exacta (sin buscar por nombre).
 *
 *   php scripts/mayoreo_guardar_urls.php            # muestra el diff
 *   php scripts/mayoreo_guardar_urls.php --aplicar  # lo escribe
 */

putenv('APP_ENV=' . (getenv('APP_ENV') ?: 'qa'));
if (getenv('DISABLE_GSM') === false) {
    putenv('DISABLE_GSM=1');
}
require __DIR__ . '/../core/config.php';

$file = __DIR__ . '/.mayoreo/mayoreo_urls.json';
if (!is_file($file)) {
    fwrite(STDERR, "No existe {$file}. Corre primero node scripts/mayoreo_llenar_carrito.mjs\n");
    exit(1);
}
$map = json_decode((string) file_get_contents($file), true);
if (!is_array($map) || $map === []) {
    fwrite(STDERR, "El archivo no tiene URLs.\n");
    exit(1);
}

$aplicar = in_array('--aplicar', $argv, true);
$pdo = getPDO();
$sel = $pdo->prepare('SELECT id_producto, nombre, mayoreo_url FROM productos WHERE sku = ? LIMIT 1');
$upd = $pdo->prepare('UPDATE productos SET mayoreo_url = ? WHERE sku = ?');

$cambios = 0;
$iguales = 0;
$sinSku = 0;
foreach ($map as $sku => $url) {
    $url = trim((string) $url);
    if ($url === '' || !preg_match('#^https://mayoreo\.blife\.mx/producto/#', $url)) {
        continue;
    }
    $sel->execute([$sku]);
    $prod = $sel->fetch(PDO::FETCH_ASSOC);
    if (!$prod) {
        $sinSku++;
        echo "  ?  {$sku}: no esta en el catalogo\n";
        continue;
    }
    if ((string) ($prod['mayoreo_url'] ?? '') === $url) {
        $iguales++;
        continue;
    }
    $cambios++;
    echo "  " . ($aplicar ? '✓' : '·') . "  {$sku}  {$prod['nombre']}\n     {$url}\n";
    if ($aplicar) {
        $upd->execute([$url, $sku]);
    }
}

echo "\n" . ($aplicar ? "APLICADO. " : "Simulacion (usa --aplicar para escribir). ")
    . "{$cambios} por cambiar, {$iguales} ya iguales, {$sinSku} sin producto.\n";
