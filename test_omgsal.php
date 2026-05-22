<?php
$fh = fopen('Exportaciones/Variante del producto (product.product).csv', 'r');
$header = fgetcsv($fh);
$columns = array_map('trim', $header);

$skuIndex = array_search('Referencia interna', $columns, true);
$imagenIndex = array_search('Imagen 1024', $columns, true);
$plantillaImagenIndex = array_search('Productos/Imagen 1024', $columns, true);

var_dump($plantillaImagenIndex);

while($row = fgetcsv($fh)) {
    if(count($row) === 0) continue;
    $sku = trim($row[$skuIndex] ?? '');
    if(strpos($sku, 'OMGSAL') !== false) {
        $img1 = trim($row[$imagenIndex] ?? '');
        $img2 = trim($row[$plantillaImagenIndex] ?? '');
        echo "SKU: $sku | img1: " . strlen($img1) . " | img2: " . strlen($img2) . "\n";
    }
}
fclose($fh);
