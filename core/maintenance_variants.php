<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';

// Solo el administrador puede ejecutar esto
if (!isAdmin()) {
    die("Acceso denegado. Se requieren permisos de administrador.");
}

$pdo = getPDO();

// 1. Obtener todos los productos activos
$stmt = $pdo->query("SELECT id_producto, nombre, unidad, nombre_variante FROM productos WHERE estado = 'activo'");
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

/**
 * Regex para detectar presentaciones comunes al final de los nombres
 * Detecta: "90 caps", "180 capsules", "500mg", "1.5kg", "30 porciones", etc.
 */
$pattern = '/\s+((?:\d+\.?\d*)\s*(?:Caps|Capsules|Cápsulas|mg|g|kg|ml|Porciones|Tomas|Tabletas|Softgels|Caps).*)$/i';

echo "<html><head><title>Normalización de Variantes</title>";
echo "<link rel='stylesheet' href='https://cdnjs.cloudflare.com/ajax/libs/materialize/1.0.0/css/materialize.min.css'></head>";
echo "<body class='container' style='padding-top:20px;'>";
echo "<h4><i class='material-icons'>Build</i> Normalizador de Variantes Inteligente</h4>";
echo "<p class='grey-text'>Este script limpia los nombres y separa las presentaciones para habilitar el agrupamiento estilo Odoo.</p>";
echo "<ul class='collection'>";

$updatedCount = 0;

foreach ($productos as $p) {
    $oldName = $p['nombre'];
    $newName = $oldName;
    $newVariant = $p['nombre_variante'] ?? '';

    // Intentar extraer del nombre usando el patrón
    if (preg_match($pattern, $oldName, $matches)) {
        $newName = trim(preg_replace($pattern, '', $oldName));
        $newVariant = trim($matches[1]);
    } 
    // Si el nombre no tiene el patrón pero el campo 'unidad' sí tiene datos, usarlo
    elseif (empty($newVariant) && !empty($p['unidad']) && $p['unidad'] !== 'Unidades') {
        $newVariant = $p['unidad'];
    }

    // Solo actualizar si hubo un cambio real
    if ($oldName !== $newName || $p['nombre_variante'] !== $newVariant) {
        try {
            $upd = $pdo->prepare("UPDATE productos SET nombre = ?, nombre_variante = ? WHERE id_producto = ?");
            $upd->execute([$newName, $newVariant, $p['id_producto']]);
            
            echo "<li class='collection-item'>";
            echo "<b>Original:</b> <span class='red-text'>$oldName</span><br>";
            echo "<b>&rarr; Base:</b> <span class='blue-text'>$newName</span> | <b>Variante:</b> <span class='orange-text'>$newVariant</span>";
            echo "</li>";
            $updatedCount++;
        } catch (Exception $e) {
            echo "<li class='collection-item red lighten-4'>Error en ID {$p['id_producto']}: {$e->getMessage()}</li>";
        }
    }
}

echo "</ul>";

if ($updatedCount === 0) {
    echo "<div class='card-panel orange lighten-4'>No se encontraron productos que necesiten normalización.</div>";
} else {
    echo "<div class='card-panel green lighten-4'><b>¡Éxito!</b> Se normalizaron $updatedCount productos correctamente.</div>";
}

echo "<div style='margin-bottom:50px;'>";
echo "<a href='../views/products.php' class='btn blue darken-4'>Ir a Gestión de Productos</a> ";
echo "<a href='catalogo.php' class='btn-flat'>Ver Catálogo</a>";
echo "</div>";
echo "</body></html>";