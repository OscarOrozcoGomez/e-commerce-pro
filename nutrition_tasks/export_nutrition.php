<?php
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

if (!isAdmin()) die("Acceso denegado.");

$pdo = getPDO();
$stmt = $pdo->query("SELECT sku, nombre, ingredientes, modo_uso, tabla_nutrimental FROM productos WHERE estado = 'activo'");
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=plantilla_analisis_nutricional.csv');

$output = fopen('php://output', 'w');
// Bom para Excel (compatibilidad con acentos)
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Cabeceras del CSV
fputcsv($output, ['sku', 'nombre', 'ingredientes', 'modo_uso', 'tabla_nutrimental_json']);

foreach ($productos as $p) {
    // Si la tabla_nutrimental es un objeto/array, lo convertimos a string JSON para el CSV
    $jsonValue = $p['tabla_nutrimental'];
    if (is_array($jsonValue) || is_object($jsonValue)) {
        $jsonValue = json_encode($jsonValue, JSON_UNESCAPED_UNICODE);
    }

    fputcsv($output, [
        $p['sku'],
        $p['nombre'],
        $p['ingredientes'] ?? '',
        $p['modo_uso'] ?? '',
        $jsonValue ?? ''
    ]);
}

fclose($output);
exit;