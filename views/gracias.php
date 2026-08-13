<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';

// Pantalla de agradecimiento publica para conversiones de marketing.
// No carga datos sensibles ni requiere login.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function thankYouEnv(string $name, ?string $default = null): ?string
{
    $value = getenv($name);
    if ($value === false) {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? $_SERVER['REDIRECT_' . $name] ?? null;
    }
    if ($value === null) {
        return $default;
    }
    $value = trim((string) $value);
    return $value === '' ? $default : $value;
}

function thankYouResolveProductImageUrl(array $product, string $baseUrl): string
{
    $candidates = [];
    foreach (['imagen_resuelta', 'ruta_archivo', 'imagen_db', 'imagen', 'imagen_url'] as $field) {
        $value = trim((string) ($product[$field] ?? ''));
        if ($value !== '') {
            $candidates[] = $value;
        }
    }

    foreach ($candidates as $candidate) {
        if ($candidate === '') {
            continue;
        }

        if (preg_match('#^(https?:)?//#i', $candidate) === 1 || preg_match('#^https?://#i', $candidate) === 1) {
            return $candidate;
        }

        $normalized = ltrim($candidate, '/\\');
        if ($normalized === '') {
            continue;
        }

        if (strpos($normalized, 'assets/') === 0 || strpos($normalized, 'views/') === 0 || strpos($normalized, 'index.php') === 0) {
            return rtrim($baseUrl, '/') . '/' . $normalized;
        }

        return rtrim($baseUrl, '/') . '/' . $normalized;
    }

    return rtrim($baseUrl, '/') . '/assets/img/logo.png';
}

$host = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false;
$baseUrl = $isLocal ? '/e-commerce-pro/' : '/';
$allowMarketingLocal = filter_var((string) thankYouEnv('ALLOW_MARKETING_LOCAL', '0'), FILTER_VALIDATE_BOOLEAN);
$gtmContainerId = trim((string) thankYouEnv('GTM_CONTAINER_ID', 'GTM-TPZG9NDT'));
$ga4MeasurementId = trim((string) thankYouEnv('GA4_MEASUREMENT_ID', 'G-R2BFK5J1BJ'));
$googleAdsId = trim((string) thankYouEnv('GOOGLE_ADS_ID', 'AW-18369681241'));
$googleAdsConversionSendTo = trim((string) thankYouEnv('GOOGLE_ADS_PURCHASE_SEND_TO', 'AW-18369681241/oT3sCIewyNscENmurLdE'));
$trackingEnabled = !$isLocal || $allowMarketingLocal;

$orderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$token = isset($_GET['t']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $_GET['t']) : '';

$hasValidOrder = $orderId !== false && $orderId !== null;
$sessionKey = $hasValidOrder ? ('thanks_seen_' . $orderId . '_' . ($token !== '' ? $token : 'no_token')) : 'thanks_invalid';
$isDuplicateView = false;
$shouldTriggerConversion = false;
$isAuthenticated = !empty($_SESSION['usuario']) && is_array($_SESSION['usuario']);

if (!isset($_SESSION['thanks_page_seen']) || !is_array($_SESSION['thanks_page_seen'])) {
    $_SESSION['thanks_page_seen'] = [];
}

// Limpiar marcadores antiguos (24 horas) para evitar crecimiento de session.
$now = time();
foreach ($_SESSION['thanks_page_seen'] as $k => $ts) {
    if (!is_int($ts) || ($now - $ts) > 86400) {
        unset($_SESSION['thanks_page_seen'][$k]);
    }
}

if ($hasValidOrder) {
    if (isset($_SESSION['thanks_page_seen'][$sessionKey])) {
        $isDuplicateView = true;
    } else {
        $_SESSION['thanks_page_seen'][$sessionKey] = $now;
        $shouldTriggerConversion = true;
    }
}

if ($isLocal && !$allowMarketingLocal) {
    $shouldTriggerConversion = false;
}

$catalogUrl = $baseUrl . 'index.php';
$detailUrl = $hasValidOrder
    ? ($isAuthenticated
        ? $baseUrl . 'views/detalle_compra.php?id=' . urlencode((string) $orderId)
        : $baseUrl . 'views/login.php')
    : $catalogUrl;
$detailLabel = $hasValidOrder && $isAuthenticated
    ? 'Ver detalle de mi pedido'
    : ($hasValidOrder ? 'Iniciar sesión para ver mi pedido' : 'Explorar catálogo');
$detailButtonClass = $hasValidOrder && $isAuthenticated ? 'primary' : 'secondary';

$recommendedProducts = [];
$offerProduct = null;
$offerDiscountPercent = 0;
try {
    if (function_exists('getPDO')) {
        $pdo = getPDO();
        $stmt = $pdo->prepare(
            "SELECT p.id_producto, p.nombre, COALESCE(p.precio_venta, 0) AS precio_venta, COALESCE(p.precio_comparacion, 0) AS precio_comparacion, COALESCE(p.descripcion_corta, '') AS descripcion_corta, " .
            "(SELECT pi.ruta_archivo FROM producto_imagenes pi WHERE pi.id_producto = p.id_producto ORDER BY pi.orden ASC, pi.id_imagen ASC LIMIT 1) AS imagen_db " .
            "FROM productos p WHERE p.estado = 'activo' AND COALESCE(p.precio_venta, 0) > 0 ORDER BY p.precio_venta ASC, p.id_producto DESC LIMIT 4"
        );
        $stmt->execute();
        $recommendedProducts = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        foreach ($recommendedProducts as $product) {
            $price = (float) ($product['precio_venta'] ?? 0);
            $comparison = (float) ($product['precio_comparacion'] ?? 0);
            if ($comparison > $price && $comparison > 0) {
                $discount = (int) round((($comparison - $price) / $comparison) * 100);
                if ($discount > $offerDiscountPercent) {
                    $offerProduct = $product;
                    $offerDiscountPercent = $discount;
                }
            }
        }

        if ($offerProduct === null && !empty($recommendedProducts)) {
            $offerProduct = $recommendedProducts[0];
        }
    }
} catch (Throwable $e) {
    $recommendedProducts = [];
    $offerProduct = null;
    $offerDiscountPercent = 0;
}
?>
<?php
// Fetch order total securely for conversion tracking
$orderTotal = 0.0;
if ($hasValidOrder) {
    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare("SELECT total FROM pedidos WHERE id_pedido = ? LIMIT 1");
        $stmt->execute([$orderId]);
        $orderTotal = (float)($stmt->fetchColumn() ?: 0.0);
    } catch (Throwable $e) {
        error_log("Error fetching order total for conversion: " . $e->getMessage());
        $orderTotal = 0.0; // Fallback to 0 if error
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Gracias por tu compra</title>
        <?php if ($trackingEnabled): ?>
                <!-- Google Tag Manager -->
                <script>
                        (function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start': new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','<?php echo htmlspecialchars($gtmContainerId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>');
                </script>
                <!-- End Google Tag Manager -->
                <!-- Global site tag (gtag.js) - Google Analytics / Google Ads -->
                <script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo htmlspecialchars($ga4MeasurementId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>"></script>
                <script>
                    window.dataLayer = window.dataLayer || [];
                    function gtag(){dataLayer.push(arguments);}
                    gtag('js', new Date());
                    gtag('config', '<?php echo htmlspecialchars($ga4MeasurementId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>');
                    gtag('config', '<?php echo htmlspecialchars($googleAdsId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>');
                </script>
        <?php endif; ?>
    <style>
        :root {
            --bg1: #f7f9fc;
            --bg2: #e9eef9;
            --card: #ffffff;
            --ink: #1c2744;
            --muted: #607090;
            --brand: #1f4ba5;
            --brand-2: #2c6bed;
            --ok: #16a34a;
            --warn: #b45309;
            --shadow: 0 10px 30px rgba(20, 35, 70, 0.12);
            --radius: 16px;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            color: var(--ink);
            background: radial-gradient(circle at 20% 20%, var(--bg2), var(--bg1) 60%);
            min-height: 100vh;
            display: grid;
            place-items: center;
            padding: 20px;
        }

        .card {
            width: min(900px, 100%);
            background: var(--card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 28px;
            border: 1px solid #e8edf6;
        }

        .hero {
            border: 1px solid #e2e8f6;
            border-radius: 18px;
            padding: 24px;
            background: linear-gradient(135deg, #f8fbff 0%, #eef4ff 100%);
            position: relative;
            overflow: hidden;
        }

        .hero::after {
            content: "";
            position: absolute;
            right: -30px;
            top: -30px;
            width: 140px;
            height: 140px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(31, 75, 165, 0.12), rgba(31, 75, 165, 0));
            pointer-events: none;
        }

        .hero h1 {
            font-size: clamp(1.7rem, 3.1vw, 2.3rem);
            margin-bottom: 12px;
        }

        .hero p {
            font-size: 1.02rem;
            margin-bottom: 10px;
        }

        .benefits {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 18px 0 0;
            padding: 0;
            list-style: none;
        }

        .benefits li {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            border-radius: 999px;
            padding: 8px 12px;
            background: #fff;
            color: var(--brand);
            border: 1px solid #dce7ff;
            font-size: 0.95rem;
            font-weight: 600;
        }

        .recommendations {
            margin-top: 24px;
            padding-top: 20px;
            border-top: 1px solid #e8edf6;
        }

        .section-head {
            margin-bottom: 14px;
        }

        .section-head h2 {
            font-size: 1.15rem;
            margin: 0 0 6px;
        }

        .section-head p {
            margin: 0;
            color: var(--muted);
        }

        .offer-card {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding: 16px;
            border-radius: 14px;
            border: 1px solid #ffe3c2;
            background: linear-gradient(135deg, #fff8ef 0%, #fff0d8 100%);
            margin-bottom: 16px;
            box-shadow: 0 8px 20px rgba(180, 83, 9, 0.08);
        }

        .delivery-card {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 14px;
            padding: 16px;
            border-radius: 14px;
            margin-bottom: 16px;
            background: linear-gradient(135deg, #f3f8ff 0%, #eaf1ff 100%);
            border: 1px solid #dce7ff;
        }

        .delivery-card .delivery-title {
            margin: 0 0 4px;
            font-size: 1rem;
            color: var(--brand);
        }

        .delivery-card .delivery-text {
            margin: 0;
            color: var(--muted);
        }

        .delivery-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 12px;
            border-radius: 999px;
            background: #fff;
            color: var(--brand);
            font-weight: 700;
            border: 1px solid #d9e6ff;
        }

        .offer-card .offer-copy {
            flex: 1 1 260px;
        }

        .offer-card .offer-title {
            margin: 0 0 6px;
            font-size: 1.05rem;
            color: #8a4b00;
        }

        .offer-card .offer-text {
            margin: 0;
            color: #8a4b00;
        }

        .offer-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            border-radius: 999px;
            padding: 8px 12px;
            background: #fffbf5;
            color: #b45309;
            font-weight: 800;
            border: 1px solid #ffd8a8;
        }

        .product-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 14px;
        }

        .product-card {
            background: #fff;
            border: 1px solid #e8edf6;
            border-radius: 14px;
            padding: 16px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .product-thumb {
            width: 100%;
            height: 160px;
            object-fit: cover;
            border-radius: 12px;
            background: #f4f7fc;
        }

        .product-card .product-title {
            font-size: 1rem;
            font-weight: 700;
            margin: 0;
        }

        .product-card .product-copy {
            font-size: 0.92rem;
            color: var(--muted);
            margin: 0;
            line-height: 1.5;
        }

        .product-card .price-row {
            margin-top: auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 8px;
        }

        .price-row .price {
            font-weight: 800;
            color: var(--brand);
        }

        .mini-btn {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            padding: 7px 10px;
            background: #eef4ff;
            color: var(--brand);
            text-decoration: none;
            font-weight: 700;
            font-size: 0.85rem;
        }

        .testimonial-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 12px;
            margin-top: 14px;
        }

        .testimonial-card {
            border: 1px solid #e8edf6;
            border-radius: 14px;
            padding: 14px;
            background: #fbfdff;
        }

        .testimonial-card p {
            margin: 0 0 8px;
            font-size: 0.95rem;
            color: var(--muted);
        }

        .testimonial-card strong {
            color: var(--ink);
        }

        @media (max-width: 760px) {
            .product-grid,
            .testimonial-grid {
                grid-template-columns: 1fr;
            }

            .actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }

        .badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 999px;
            padding: 8px 14px;
            font-size: 0.9rem;
            font-weight: 700;
            margin-bottom: 14px;
        }

        .badge.ok {
            background: #eaf8ef;
            color: var(--ok);
            border: 1px solid #caefd7;
        }

        .badge.warn {
            background: #fff4e5;
            color: var(--warn);
            border: 1px solid #ffe2bd;
        }

        h1 {
            font-size: clamp(1.5rem, 3vw, 2rem);
            margin: 0 0 10px;
            letter-spacing: 0.2px;
        }

        p {
            margin: 0 0 16px;
            color: var(--muted);
            line-height: 1.65;
            font-size: 1rem;
        }

        .actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 20px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 44px;
            text-decoration: none;
            border-radius: 12px;
            padding: 10px 18px;
            border: 1px solid transparent;
            font-weight: 700;
            transition: transform 0.12s ease, box-shadow 0.12s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn.primary {
            background: linear-gradient(135deg, var(--brand), var(--brand-2));
            color: #fff;
            box-shadow: 0 8px 18px rgba(44, 107, 237, 0.25);
        }

        .btn.secondary {
            background: #fff;
            color: var(--brand);
            border-color: #d5def0;
        }

        .small-note {
            margin-top: 16px;
            font-size: 0.88rem;
            color: #7b88a6;
        }
    </style>
</head>
<body>
    <?php if ($trackingEnabled): ?>
        <!-- Google Tag Manager (noscript) -->
        <noscript><iframe src="https://www.googletagmanager.com/ns.html?id=<?php echo htmlspecialchars($gtmContainerId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
        <!-- End Google Tag Manager (noscript) -->
    <?php endif; ?>

    <main class="card" role="main" aria-live="polite">
        <section class="hero">
            <?php if ($hasValidOrder && !$isDuplicateView): ?>
                <div class="badge ok">Pedido confirmado</div>
                <h1>¡Tu compra quedó registrada y listo para seguir!</h1>
                <p>
                    Gracias por elegirnos. Ahora puedes revisar tu pedido, seguir explorando lo mejor de nuestra colección y encontrar nuevas opciones que se adapten a tu estilo.
                </p>
            <?php elseif ($hasValidOrder && $isDuplicateView): ?>
                <div class="badge warn">Pedido ya confirmado</div>
                <h1>Tu compra ya fue registrada</h1>
                <p>
                    Esta pantalla ya fue vista antes en esta sesión. Puedes seguir consultando tu pedido desde tu cuenta o volver al catálogo para descubrir más productos.
                </p>
            <?php else: ?>
                <div class="badge warn">Confirmación general</div>
                <h1>¡Gracias por tu interés en Belleza y Bienestar!</h1>
                <p>
                    Esta página está creada para que tus campañas muestren una experiencia clara, elegante y preparada para convertir. Sigue explorando productos, descubre ofertas y encuentra tu próximo favorito.
                </p>
            <?php endif; ?>

            <div class="actions">
                <a class="btn primary" href="<?php echo htmlspecialchars($catalogUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                    Explorar catálogo
                </a>
                <a class="btn secondary" href="<?php echo htmlspecialchars($detailUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                    <?php echo htmlspecialchars($detailLabel, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>
                </a>
            </div>

            <ul class="benefits" aria-label="Beneficios destacados">
                <li>✓ Envío confiable</li>
                <li>✓ Pago seguro</li>
                <li>✓ Atención personalizada</li>
            </ul>
        </section>

        <?php if (!empty($recommendedProducts) || $offerProduct !== null): ?>
            <section class="recommendations" aria-label="Productos recomendados">
                <?php if ($offerProduct !== null): ?>
                    <?php
                    $offerPrice = (float) ($offerProduct['precio_venta'] ?? 0);
                    $offerComparison = (float) ($offerProduct['precio_comparacion'] ?? 0);
                    $offerName = trim((string) ($offerProduct['nombre'] ?? 'Oferta destacada'));
                    $offerUrl = $baseUrl . 'product_detail.php?id=' . urlencode((string) ($offerProduct['id_producto'] ?? 0));
                    ?>
                    <div class="offer-card">
                        <div class="offer-copy">
                            <div class="offer-pill">Oferta del día · -<?php echo (int) $offerDiscountPercent; ?>%</div>
                            <h3 class="offer-title"><?php echo htmlspecialchars($offerName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></h3>
                            <p class="offer-text">
                                Aprovecha el precio especial de hoy y descubre una opción que combina calidad, cuidado y valor.
                            </p>
                        </div>
                        <a class="btn primary" href="<?php echo htmlspecialchars($offerUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                            Ver oferta
                        </a>
                    </div>
                <?php endif; ?>

                <div class="delivery-card">
                    <div>
                        <h3 class="delivery-title">Entregas programadas los miércoles y sábados</h3>
                        <p class="delivery-text">Tu pedido se prepara con cuidado y se entrega en los días de ruta que tenemos disponibles.</p>
                    </div>
                    <div class="delivery-pill">📦 Entregas confirmadas</div>
                </div>

                <div class="section-head">
                    <h2>Productos que también están marcando la diferencia</h2>
                    <p>Descubre opciones que combinan con lo que estabas buscando.</p>
                </div>
                <div class="product-grid">
                    <?php foreach ($recommendedProducts as $product): ?>
                        <?php
                        $productId = (int) ($product['id_producto'] ?? 0);
                        $productName = trim((string) ($product['nombre'] ?? 'Producto destacado'));
                        $productDescription = trim((string) ($product['descripcion_corta'] ?? ''));
                        $productPrice = (float) ($product['precio_venta'] ?? 0);
                        $productUrl = $baseUrl . 'product_detail.php?id=' . urlencode((string) $productId);
                        $productImage = thankYouResolveProductImageUrl($product, $baseUrl);
                        ?>
                        <article class="product-card">
                            <img class="product-thumb" src="<?php echo htmlspecialchars($productImage, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>" alt="<?php echo htmlspecialchars($productName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                            <div class="badge ok" style="margin-bottom: 0; padding: 6px 10px; font-size: 0.8rem;">Destacado</div>
                            <h3 class="product-title"><?php echo htmlspecialchars($productName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></h3>
                            <?php if ($productDescription !== ''): ?>
                                <p class="product-copy"><?php echo htmlspecialchars($productDescription, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?></p>
                            <?php else: ?>
                                <p class="product-copy">Explora este producto y encuentra tu favorita.</p>
                            <?php endif; ?>
                            <div class="price-row">
                                <span class="price">$<?php echo number_format($productPrice, 2, '.', ','); ?></span>
                                <a class="mini-btn" href="<?php echo htmlspecialchars($productUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">Ver producto</a>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="recommendations" aria-label="Testimonios y confianza">
            <div class="section-head">
                <h2>Confianza que se nota desde el primer contacto</h2>
                <p>Más que una compra, una experiencia cuidada desde el inicio.</p>
            </div>
            <div class="testimonial-grid">
                <div class="testimonial-card">
                    <p>“La experiencia fue simple, rápida y muy profesional. Me encantó lo claro del proceso.”</p>
                    <strong>— Cliente satisfecha</strong>
                </div>
                <div class="testimonial-card">
                    <p>“Encontré opciones que se ajustaban perfecto a lo que buscaba.”</p>
                    <strong>— Cliente recurrente</strong>
                </div>
                <div class="testimonial-card">
                    <p>“Me gustó la atención y el diseño de la compra. Se siente confiable.”</p>
                    <strong>— Nuevo cliente</strong>
                </div>
            </div>
        </section>
    </main>

    <?php if ($shouldTriggerConversion): ?>
    <script>
        const orderReference = '<?php echo (int) $orderId; ?>';
        const conversionKey = `bb_conversion_fired_${orderReference}`;
        const storedAttributionRaw = localStorage.getItem('bb_marketing_attribution');
        let storedAttribution = null;

        if (storedAttributionRaw) {
            try {
                storedAttribution = JSON.parse(storedAttributionRaw);
            } catch (e) {
                storedAttribution = null;
            }
        }

        const alreadyFired = localStorage.getItem(conversionKey) === '1';

        // Hook para marketing: solo dispara en la primera visita de esta sesion para esta orden.
        if (!alreadyFired) {
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({
                event: 'purchase_thank_you_view',
                order_reference: orderReference,
                attribution: storedAttribution
            });

            if (typeof window.gtag === 'function') {
                // Google Ads Conversion Snippet
                window.gtag('event', 'conversion', {
                    'send_to': '<?php echo htmlspecialchars($googleAdsConversionSendTo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>',
                    'value': <?php echo number_format($orderTotal, 2, '.', ''); ?>,
                    'currency': 'MXN',
                    'transaction_id': orderReference
                });
            }

            localStorage.setItem(conversionKey, '1');
        }
    </script>
    <?php endif; ?>
</body>
</html>
