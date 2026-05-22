<?php
$fh = fopen('Exportaciones/Variante del producto (product.product).csv', 'r');
$header = fgetcsv($fh);
$count = 0;
while($row = fgetcsv($fh)) {
    $sku = $row[2] ?? '';
    if (strpos($sku, 'CREATINA') !== false) {
        var_dump($sku, empty($row[8]) ? 'NO IMAGE' : 'HAS IMAGE');
    }
    $count++;
}
echo "Total rows: $count\n";
fclose($fh);
