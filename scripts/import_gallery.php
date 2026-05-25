<?php
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

function runGalleryImport(): void
{
    $csvFile = __DIR__ . '/../Exportaciones/Medios_del_producto.csv';
    
    // También buscamos sin guiones bajos por si acaso
    if (!is_file($csvFile)) {
        $csvFile = __DIR__ . '/../Exportaciones/Medios del producto.csv';
    }

    if (!is_file($csvFile) || !is_readable($csvFile)) {
        echo "ERROR: No se encuentra el archivo CSV.\n";
        echo "Asegúrate de exportar la galería desde Odoo y guardar el archivo en la carpeta 'Exportaciones' con el nombre 'Medios del producto.csv'.";
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
        'skipped' => 0,
        'errors' => 0,
    ];

    $sqlSelect = "SELECT id_producto FROM productos WHERE sku = :sku";
    $stmtSelect = $pdo->prepare($sqlSelect);

    $sqlInsert = "INSERT INTO producto_imagenes (id_producto, ruta_archivo) VALUES (:id_producto, :imagen)";
    $stmtInsert = $pdo->prepare($sqlInsert);

    // En la exportación de medios, el SKU puede llamarse así dependiendo de qué se seleccionó
    $skuIndex = array_search('Producto / Referencia interna', $columns, true);
    if ($skuIndex === false) $skuIndex = array_search('Referencia interna', $columns, true);
    
    $imagenIndex = array_search('Imagen 1024', $columns, true);

    if ($skuIndex === false || $imagenIndex === false) {
        echo "ERROR: El archivo CSV no tiene las columnas requeridas.\n";
        echo "Asegúrate de incluir 'Producto / Referencia interna' y 'Imagen 1024' en tu exportación.";
        fclose($handle);
        return;
    }

    // Opcional: limpiar la tabla antes de importar para evitar duplicados si se corre varias veces
    // $pdo->exec("TRUNCATE TABLE producto_imagenes"); // Descomentar si se desea reemplazo total

    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) === 0) continue;

        $sku = trim($row[$skuIndex] ?? '');
        $imagenBase64 = trim($row[$imagenIndex] ?? '');

        if ($sku === '' || $imagenBase64 === '') {
            $summaries['skipped']++;
            continue;
        }

        try {
            $stmtSelect->execute([':sku' => $sku]);
            $producto = $stmtSelect->fetch(PDO::FETCH_ASSOC);

            if ($producto) {
                $stmtInsert->execute([
                    ':id_producto' => $producto['id_producto'],
                    ':imagen' => $imagenBase64
                ]);
                $summaries['inserted']++;
            } else {
                $summaries['skipped']++; // El producto padre no existe en la BD
            }
        } catch (Throwable $e) {
            $summaries['errors']++;
        }
    }

    fclose($handle);
    $pdo->commit();

    echo "Importación de galería finalizada.\n";
    echo "Imágenes insertadas: {$summaries['inserted']}\n";
    echo "Filas omitidas (sin SKU, sin imagen o producto no encontrado): {$summaries['skipped']}\n";
    echo "Errores: {$summaries['errors']}\n";
}

if (PHP_SAPI === 'cli') {
    runGalleryImport();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        echo 'Error: Token CSRF inválido.';
        exit;
    }
    runGalleryImport();
    exit;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Importar Galería</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
</head>
<body class="grey lighten-4">
<div class="container">
    <h4>Importación de Galería de Imágenes</h4>
    <p>Este script lee el archivo de fotos extra de Odoo ubicado en:</p>
    <pre>Exportaciones/Medios del producto.csv</pre>
    <form method="post">
        <?php echo csrfInput(); ?>
        <button class="btn waves-effect waves-light pink lighten-2" type="submit">Iniciar importación de Galería</button>
    </form>
</div>
</body>
</html>
