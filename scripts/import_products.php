<?php
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

function runImport(int $almacenId = 1): void
{
    $csvFile = CSV_IMPORT_PATH;
    if (!is_file($csvFile) || !is_readable($csvFile)) {
        echo "ERROR: No se puede leer el archivo CSV en: {$csvFile}";
        return;
    }

    $pdo = getPDO();
    $pdo->beginTransaction();

    $handle = fopen($csvFile, 'r');
    if ($handle === false) {
        echo "ERROR: No se pudo abrir el archivo CSV.";
        return;
    }

    $header = fgetcsv($handle);
    if ($header === false) {
        echo "ERROR: CSV vacío o con formato incorrecto.";
        fclose($handle);
        return;
    }

    $columns = array_map('trim', $header);
    $summaries = [
        'inserted' => 0,
        'updated' => 0,
        'inventory_updated' => 0,
        'skipped' => 0,
        'errors' => 0,
    ];

    $sqlProducto = "INSERT INTO productos (nombre, nombre_variante, sku, codigo_barras, unidad, precio_costo, precio_venta, categoria, estado, id_padre, imagen) VALUES (:nombre, :nombre_variante, :sku, :codigo_barras, :unidad, :precio_costo, :precio_venta, :categoria, 'activo', :id_padre, :imagen)";
    $stmtInsert = $pdo->prepare($sqlProducto);

    $sqlProductoUpdate = "UPDATE productos SET nombre = :nombre, nombre_variante = :nombre_variante, codigo_barras = :codigo_barras, unidad = :unidad, precio_costo = :precio_costo, precio_venta = :precio_venta, categoria = :categoria, estado = 'activo', imagen = :imagen WHERE sku = :sku";
    $stmtUpdate = $pdo->prepare($sqlProductoUpdate);

    $sqlSelect = "SELECT id_producto FROM productos WHERE sku = :sku";
    $stmtSelect = $pdo->prepare($sqlSelect);

    $sqlInventory = "INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual, cantidad_reservada) VALUES (:id_producto, :id_almacen, :cantidad_actual, 0)
        ON DUPLICATE KEY UPDATE cantidad_actual = VALUES(cantidad_actual)";
    $stmtInventory = $pdo->prepare($sqlInventory);

    $skuIndex = array_search('Referencia interna', $columns, true);
    $nombreIndex = array_search('Nombre', $columns, true); 
    $displayNameIndex = array_search('Nombre en pantalla', $columns, true); 
    $codigoIndex = array_search('Código de barras', $columns, true);
    // En Odoo el Costo a veces se llama "Costo" o "Costo promedio"
    $costoIndex = array_search('Costo promedio', $columns, true) !== false ? array_search('Costo promedio', $columns, true) : array_search('Costo', $columns, true);
    $precioIndex = array_search('Precio de venta', $columns, true);
    $cantidadIndex = array_search('Cantidad a la mano', $columns, true);
    $unidadIndex = array_search('Unidad', $columns, true);
    $categoriaIndex = array_search('Categoría del producto', $columns, true);
    $imagenIndex = array_search('Imagen 1024', $columns, true); // Buscar la imagen de mayor resolución

    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) === 0) {
            continue;
        }

        $sku = trim($row[$skuIndex] ?? '');
        if ($sku === '') {
            $summaries['skipped']++;
            continue;
        }

        $nombreFull = trim($row[$displayNameIndex] ?? '');
        $nombreBase = trim($row[$nombreIndex] ?? '');
        
        $nombre_variante = null;
        $nombre = $nombreBase ?: $nombreFull;

        if (preg_match('/^(.*)\s\((.*)\)$/', $nombreFull, $matches)) {
            $nombre = trim($matches[1]);
            $nombre_variante = trim($matches[2]);
        }

        $codigo = trim($row[$codigoIndex] ?? '');
        $unidad = trim($row[$unidadIndex] ?? '');
        $categoria = trim($row[$categoriaIndex] ?? '');
        $precioCosto = floatval(str_replace(',', '.', trim($row[$costoIndex] ?? '0')));
        $precioVenta = floatval(str_replace(',', '.', trim($row[$precioIndex] ?? '0')));
        $cantidad = intval(trim($row[$cantidadIndex] ?? '0'));
        
        // Extraer imagen y limpiar espacios u otros caracteres si los hay
        $imagenBase64 = null;
        if ($imagenIndex !== false && !empty($row[$imagenIndex])) {
            $imagenBase64 = trim($row[$imagenIndex]);
        }

        try {
            $stmtSelect->execute([':sku' => $sku]);
            $producto = $stmtSelect->fetch(PDO::FETCH_ASSOC);
            $id_padre = null; 

            if ($producto === false) {
                $stmtInsert->execute([
                    ':nombre' => $nombre,
                    ':nombre_variante' => $nombre_variante,
                    ':sku' => $sku,
                    ':codigo_barras' => $codigo ?: null,
                    ':unidad' => $unidad ?: null,
                    ':precio_costo' => $precioCosto,
                    ':precio_venta' => $precioVenta,
                    ':categoria' => $categoria ?: null,
                    ':id_padre' => $id_padre,
                    ':imagen' => $imagenBase64
                ]);
                $productoId = intval($pdo->lastInsertId());
                $summaries['inserted']++;
            } else {
                $productoId = intval($producto['id_producto']);
                $stmtUpdate->execute([
                    ':nombre' => $nombre,
                    ':nombre_variante' => $nombre_variante,
                    ':codigo_barras' => $codigo ?: null,
                    ':unidad' => $unidad ?: null,
                    ':precio_costo' => $precioCosto,
                    ':precio_venta' => $precioVenta,
                    ':categoria' => $categoria ?: null,
                    ':sku' => $sku,
                    ':imagen' => $imagenBase64
                ]);
                $summaries['updated']++;
            }

            if ($cantidad >= 0) {
                $stmtInventory->execute([
                    ':id_producto' => $productoId,
                    ':id_almacen' => $almacenId,
                    ':cantidad_actual' => $cantidad,
                ]);
                $summaries['inventory_updated']++;
            }
        } catch (Throwable $e) {
            $summaries['errors']++;
        }
    }

    fclose($handle);
    $pdo->commit();

    echo "Importación finalizada.\n";
    echo "Productos insertados: {$summaries['inserted']}\n";
    echo "Productos actualizados: {$summaries['updated']}\n";
    echo "Registros de inventario actualizados: {$summaries['inventory_updated']}\n";
    echo "Filas omitidas: {$summaries['skipped']}\n";
    echo "Errores: {$summaries['errors']}\n";
}

$almacenId = 1;
if (PHP_SAPI === 'cli') {
    $options = getopt('', ['almacen::']);
    if (isset($options['almacen'])) {
        $almacenId = intval($options['almacen']);
    }
    runImport($almacenId);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo 'Error: Token CSRF inválido. Por favor recarga la página e inténtalo de nuevo.';
        exit;
    }
    $almacenId = isset($_POST['almacen_id']) ? intval($_POST['almacen_id']) : 1;
    runImport($almacenId);
    exit;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Importar productos</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
</head>
<body class="grey lighten-4">
<div class="container">
    <h4>Importación masiva de productos</h4>
    <p>Este script importa el CSV de productos desde:</p>
    <pre><?php echo esc(CSV_IMPORT_PATH); ?></pre>
    <p>Usa el almacén destino para stock inicial.</p>
    <form method="post">
        <?php echo csrfInput(); ?>
        <div class="input-field">
            <input id="almacen_id" name="almacen_id" type="number" value="<?php echo esc((string)$almacenId); ?>">
            <label for="almacen_id">ID de almacén destino</label>
        </div>
        <button class="btn waves-effect waves-light" type="submit">Iniciar importación</button>
    </form>
</div>
</body>
</html>
