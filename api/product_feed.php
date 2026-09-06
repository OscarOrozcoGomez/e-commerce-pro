<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/ventas_features.php';

$pdo = getPDO();

if (!ventasFeatureIsActive($pdo, 'catalogo_feed')) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Feed no disponible.';
    exit;
}

header('Content-Type: application/xml; charset=UTF-8');

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string) $_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');
$scheme = $isHttps ? 'https' : 'http';
$host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
$siteUrl = $scheme . '://' . $host . BASE_URL;

/**
 * Solo el producto "padre" o un producto sin variantes por fila -- no tiene
 * caso publicar cada variante interna como un producto de catalogo separado.
 */
$sql = "SELECT p.id_producto, p.nombre, p.descripcion, p.precio_venta, p.codigo_barras,
               COALESCE(
                   NULLIF((SELECT pi.ruta_archivo FROM producto_imagenes pi WHERE pi.id_producto = p.id_producto ORDER BY pi.orden ASC, pi.id_imagen ASC LIMIT 1), ''),
                   NULLIF(TRIM(p.imagen), ''),
                   NULLIF(TRIM(p.imagen_url), '')
               ) AS imagen,
               COALESCE((SELECT SUM(ia.cantidad_actual) FROM inventario_almacen ia WHERE ia.id_producto = p.id_producto), 0) AS stock_total
        FROM productos p
        WHERE p.estado = 'activo' AND p.id_padre IS NULL AND p.precio_venta > 0
        ORDER BY p.nombre ASC";

$productos = $pdo->query($sql)->fetchAll();

function feedXmlEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">
<channel>
    <title><?php echo feedXmlEscape('Catálogo de productos'); ?></title>
    <link><?php echo feedXmlEscape($siteUrl); ?></link>
    <description><?php echo feedXmlEscape('Feed de productos para remarketing dinámico'); ?></description>
    <?php foreach ($productos as $p): ?>
        <?php
        $link = $siteUrl . 'product_detail.php?id=' . (int) $p['id_producto'];
        $imagen = trim((string) ($p['imagen'] ?? ''));
        $imageLink = $imagen !== '' ? $siteUrl . 'assets/img/products/' . rawurlencode($imagen) : '';
        $descripcion = trim((string) ($p['descripcion'] ?? '')) !== '' ? (string) $p['descripcion'] : (string) $p['nombre'];
        $disponibilidad = ((int) $p['stock_total']) > 0 ? 'in stock' : 'out of stock';
        ?>
        <item>
            <g:id><?php echo feedXmlEscape((string) $p['id_producto']); ?></g:id>
            <title><?php echo feedXmlEscape((string) $p['nombre']); ?></title>
            <description><?php echo feedXmlEscape($descripcion); ?></description>
            <link><?php echo feedXmlEscape($link); ?></link>
            <?php if ($imageLink !== ''): ?>
                <g:image_link><?php echo feedXmlEscape($imageLink); ?></g:image_link>
            <?php endif; ?>
            <g:availability><?php echo feedXmlEscape($disponibilidad); ?></g:availability>
            <g:price><?php echo feedXmlEscape(number_format((float) $p['precio_venta'], 2, '.', '') . ' MXN'); ?></g:price>
            <g:condition>new</g:condition>
            <?php if (!empty($p['codigo_barras'])): ?>
                <g:gtin><?php echo feedXmlEscape((string) $p['codigo_barras']); ?></g:gtin>
            <?php endif; ?>
        </item>
    <?php endforeach; ?>
</channel>
</rss>
