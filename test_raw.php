<?php
$fh = fopen('Exportaciones/Variante del producto (product.product).csv', 'r');
$header = fgetcsv($fh);
$columns = array_map('trim', $header);

echo "COLUMNAS DEL CSV:\n";
foreach ($columns as $i => $col) {
    echo "  [$i] $col\n";
}
echo "\n";

$skuIndex = array_search('Referencia interna', $columns, true);
$imagenIndex = array_search('Imagen 1024', $columns, true);
$plantillaImagenIndex = array_search('Productos/Imagen 1024', $columns, true);

echo "skuIndex=$skuIndex, imagenIndex=$imagenIndex, plantillaImagenIndex=$plantillaImagenIndex\n\n";

$count = 0;
$conImagen = 0;
$sinImagen = [];
while($row = fgetcsv($fh)) {
    if(count($row) <= 1 || empty(trim($row[$skuIndex] ?? ''))) continue;
    $sku = trim($row[$skuIndex]);
    $count++;
    $img1 = trim($row[$imagenIndex] ?? '');
    $img2 = trim($row[$plantillaImagenIndex] ?? '');
    if (!empty($img1) || !empty($img2)) {
        $conImagen++;
    } else {
        $sinImagen[] = $sku;
    }
}
fclose($fh);

echo "Total filas validas: $count\n";
echo "Con imagen (variante o plantilla): $conImagen\n";
echo "Sin imagen (" . count($sinImagen) . " productos):\n";
foreach(array_slice($sinImagen, 0, 20) as $sku) {
    echo "  - $sku\n";
}
if (count($sinImagen) > 20) echo "  ... y " . (count($sinImagen) - 20) . " más\n";
