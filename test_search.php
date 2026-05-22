<?php
$fh = fopen('Exportaciones/Variante del producto (product.product).csv', 'r');
$header = fgetcsv($fh);
$columns = array_map('trim', $header);

$desc1Idx = array_search('Descripción', $columns, true);
$desc2Idx = array_search('Descripción de ventas', $columns, true);

echo "Columnas encontradas:\n";
foreach ($columns as $i => $c) echo "  [$i] $c\n";
echo "\n";

$con1 = 0; $con2 = 0; $total = 0;
while ($row = fgetcsv($fh)) {
    $sku = trim($row[1] ?? '');
    if ($sku === '') continue;
    $total++;
    $d1 = trim(strip_tags($row[$desc1Idx] ?? ''));
    $d2 = trim(strip_tags($row[$desc2Idx] ?? ''));
    if ($d1 !== '') { $con1++; echo "DESC1 [$sku]: " . substr($d1, 0, 60) . "\n"; }
    if ($d2 !== '') { $con2++; echo "DESC_VENTAS [$sku]: " . substr($d2, 0, 60) . "\n"; }
}
fclose($fh);
echo "\nTotal: $total | Descripción: $con1 | Descripción de ventas: $con2\n";
