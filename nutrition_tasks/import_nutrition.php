<?php
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

if (!isAdmin()) die("Acceso denegado.");

$message = "";
$updated = 0;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file']['tmp_name'];
    $handle = fopen($file, 'r');
    
    // Saltar BOM si existe
    if (fgets($handle, 4) !== "\xEF\xBB\xBF") rewind($handle);
    
    $headers = fgetcsv($handle); // Leer cabeceras
    $pdo = getPDO();

    $sql = "UPDATE productos SET 
            ingredientes = :ing, 
            modo_uso = :modo, 
            tabla_nutrimental = :tabla 
            WHERE sku = :sku";
    $stmt = $pdo->prepare($sql);

    while (($row = fgetcsv($handle)) !== false) {
        if (count($row) < 5) continue;

        $sku = trim($row[0]);
        $ingredientes = trim($row[2]);
        $modoUso = trim($row[3]);
        $tablaJson = trim($row[4]);

        // Validar que sea un JSON válido antes de intentar guardar
        if (!empty($tablaJson)) {
            json_decode($tablaJson);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors[] = "Error JSON en SKU $sku: " . json_last_error_msg();
                $tablaJson = null;
            }
        }

        try {
            $stmt->execute([
                ':ing' => $ingredientes ?: null,
                ':modo' => $modoUso ?: null,
                ':tabla' => $tablaJson ?: null,
                ':sku' => $sku
            ]);
            if ($stmt->rowCount() > 0) $updated++;
        } catch (Exception $e) {
            $errors[] = "Error en SKU $sku: " . $e->getMessage();
        }
    }
    fclose($handle);
    $message = "Proceso completado. Productos actualizados: $updated";
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Importar Nutrición</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css">
</head>
<body class="grey lighten-4">
    <div class="container" style="margin-top: 50px;">
        <div class="card-panel">
            <h4>Herramienta de Importación Nutricional</h4>
            <?php if ($message): ?><p class="green-text"><?php echo $message; ?></p><?php endif; ?>
            <?php if ($errors): ?><ul class="red-text"><?php foreach($errors as $e) echo "<li>$e</li>"; ?></ul><?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data">
                <div class="file-field input-field">
                    <div class="btn blue darken-4">
                        <span>Seleccionar CSV</span>
                        <input type="file" name="csv_file" accept=".csv" required>
                    </div>
                    <div class="file-path-wrapper">
                        <input class="file-path validate" type="text" placeholder="Sube el archivo final aquí">
                    </div>
                </div>
                <button type="submit" class="btn green">ACTUALIZAR BASE DE DATOS</button>
                <a href="../../views/products.php" class="btn-flat">Volver</a>
            </form>
        </div>
    </div>
</body>
</html>