<?php
declare(strict_types=1);

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

$host = $_SERVER['HTTP_HOST'] ?? '';
$isLocal = strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false;
$baseUrl = $isLocal ? '/e-commerce-pro/' : '/';

$orderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1],
]);
$token = isset($_GET['t']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $_GET['t']) : '';

$hasValidOrder = $orderId !== false && $orderId !== null;
$sessionKey = $hasValidOrder ? ('thanks_seen_' . $orderId . '_' . ($token !== '' ? $token : 'no_token')) : 'thanks_invalid';
$isDuplicateView = false;
$shouldTriggerConversion = false;

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

$detailUrl = $hasValidOrder
    ? $baseUrl . 'views/detalle_compra.php?id=' . urlencode((string) $orderId)
    : $baseUrl . 'views/login.php';

$googleAdsSendTo = thankYouEnv('GOOGLE_ADS_SEND_TO', '');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Gracias por tu compra</title>
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
            width: min(760px, 100%);
            background: var(--card);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 28px;
            border: 1px solid #e8edf6;
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
    <main class="card" role="main" aria-live="polite">
        <?php if ($hasValidOrder && !$isDuplicateView): ?>
            <div class="badge ok">Compra registrada</div>
            <h1>Gracias por tu compra</h1>
            <p>
                Tu pedido esta siendo procesado. Te hemos enviado un correo con los detalles.
            </p>
            <p>
                Por seguridad, los detalles completos solo estan disponibles dentro de tu cuenta.
            </p>
        <?php elseif ($hasValidOrder && $isDuplicateView): ?>
            <div class="badge warn">Vista repetida detectada</div>
            <h1>Gracias, tu pedido ya fue confirmado</h1>
            <p>
                Esta pantalla ya fue visitada anteriormente en esta sesion. Si necesitas revisar informacion,
                ingresa a tu cuenta para ver el detalle de la orden.
            </p>
        <?php else: ?>
            <div class="badge warn">Sesion de confirmacion expirada</div>
            <h1>Gracias por tu compra</h1>
            <p>
                No fue posible validar la referencia del pedido desde esta URL. Si deseas consultar el detalle,
                inicia sesion y revisa tu historial de compras.
            </p>
        <?php endif; ?>

        <div class="actions">
            <a class="btn primary" href="<?php echo htmlspecialchars($detailUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                Ver detalle de mi orden
            </a>
            <a class="btn secondary" href="<?php echo htmlspecialchars($baseUrl . 'index.php', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>">
                Volver al inicio
            </a>
        </div>

        <p class="small-note">
            Esta pagina no muestra datos sensibles de compra para proteger la privacidad.
        </p>
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

            if (typeof window.gtag === 'function' && '<?php echo htmlspecialchars($googleAdsSendTo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>' !== '') {
                window.gtag('event', 'conversion', {
                    send_to: '<?php echo htmlspecialchars($googleAdsSendTo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); ?>',
                    transaction_id: orderReference
                });
            }

            localStorage.setItem(conversionKey, '1');
        }
    </script>
    <?php endif; ?>
</body>
</html>
