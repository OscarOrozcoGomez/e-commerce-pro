<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

// Pagina publica (sin requireAuth) para que el boton "Compartir" de products.php tenga una
// URL real con etiquetas Open Graph -- sin esto, el dialogo de compartir de Facebook no
// puede armar preview (no lee texto por URL, solo og:title/og:image/og:description de la
// pagina publica que se comparte). WhatsApp y correo tambien usan este link como referencia
// para que el cliente pueda ver el producto sin tener que buscarlo en el catalogo completo.

$pdo = getPDO();
$idProducto = (int)($_GET['id'] ?? 0);

$producto = null;
if ($idProducto > 0) {
    $stmt = $pdo->prepare(
        "SELECT id_producto, nombre, nombre_variante, precio_venta, texto_compartir, imagen, imagen_url
         FROM productos
         WHERE id_producto = :id AND estado = 'activo'
         LIMIT 1"
    );
    $stmt->execute([':id' => $idProducto]);
    $producto = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$scheme = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https'))
    ? 'https' : 'http';
$baseAbsoluta = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . BASE_URL;

if ($producto === null) {
    $pageTitle = 'Producto no disponible';
    include __DIR__ . '/includes/header.php';
    ?>
    <div class="container" style="padding: 60px 0; text-align: center;">
        <i class="material-icons" style="font-size: 4rem; color: #cfd8dc;">inventory_2</i>
        <h5>Este producto ya no está disponible</h5>
        <p class="grey-text">Puede que se haya agotado o dado de baja del catálogo.</p>
        <a href="<?php echo BASE_URL; ?>views/catalogo.php" class="btn waves-effect waves-light blue darken-2">Ver catálogo completo</a>
    </div>
    <?php
    include __DIR__ . '/includes/footer.php';
    exit;
}

$nombreCompleto = trim((string)$producto['nombre']) . (trim((string)($producto['nombre_variante'] ?? '')) !== '' ? ' - ' . trim((string)$producto['nombre_variante']) : '');
$precio = (float)$producto['precio_venta'];
$textoCompartir = trim((string)($producto['texto_compartir'] ?? ''));
$imagenUrl = getProductImageUrl((string)($producto['imagen'] ?? $producto['imagen_url'] ?? ''), (int)$producto['id_producto']);

// og:image debe ser una URL absoluta (con dominio) -- getProductImageUrl() regresa una ruta
// relativa a BASE_URL para imagenes locales, que Facebook/WhatsApp no pueden resolver solos.
$imagenUrlAbsoluta = (strpos($imagenUrl, 'http://') === 0 || strpos($imagenUrl, 'https://') === 0)
    ? $imagenUrl
    : $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $imagenUrl;

$pageTitle = $nombreCompleto;
$ogTitle = $nombreCompleto . ' | Belleza y Bienestar';
$ogDescription = $textoCompartir !== '' ? $textoCompartir : 'Conoce este producto en nuestro catálogo.';
$ogImage = $imagenUrlAbsoluta;
$ogUrl = $baseAbsoluta . 'views/producto_publico.php?id=' . $idProducto;

include __DIR__ . '/includes/header.php';
?>

<div class="container" style="padding: 30px 0;">
    <div class="row">
        <div class="col s12 m6 center-align">
            <img src="<?php echo esc($imagenUrl); ?>" alt="<?php echo esc($nombreCompleto); ?>" class="responsive-img z-depth-1" style="max-height: 380px; border-radius: 8px;">
        </div>
        <div class="col s12 m6">
            <h4 style="margin-top: 0;"><?php echo esc($nombreCompleto); ?></h4>
            <p style="font-size: 1.6rem; font-weight: 700; color: #2e7d32;">$<?php echo number_format($precio, 2); ?></p>
            <?php if ($textoCompartir !== ''): ?>
                <p class="grey-text text-darken-2" style="font-size: 1.05rem; line-height: 1.5;"><?php echo esc($textoCompartir); ?></p>
            <?php endif; ?>
            <a href="https://api.whatsapp.com/send?phone=5213344420747&text=<?php echo rawurlencode('Hola, me interesa este producto: ' . $nombreCompleto); ?>" target="_blank" rel="noopener noreferrer" class="btn waves-effect waves-light green darken-1" style="margin-top: 10px;">
                <i class="fa-brands fa-whatsapp left"></i>Preguntar por WhatsApp
            </a>
            <a href="<?php echo BASE_URL; ?>views/catalogo.php" class="btn-flat waves-effect" style="margin-top: 10px; display: block;">Ver catálogo completo</a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
