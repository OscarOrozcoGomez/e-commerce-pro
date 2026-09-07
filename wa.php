<?php
declare(strict_types=1);

/**
 * Redirección a WhatsApp forzando WhatsApp Business en Android.
 *
 * Los correos (y cualquier enlace externo) no pueden usar directamente el esquema
 * `intent://` ni `whatsapp://` porque Gmail y otros clientes los descartan: solo
 * dejan pasar http(s). Este endpoint recibe un enlace http normal y, ya en el
 * navegador del teléfono, decide a dónde mandar:
 *
 *   - Android: `intent://…;package=com.whatsapp.w4b;…` para abrir WhatsApp Business
 *     (com.whatsapp.w4b) en vez del WhatsApp personal cuando ambas apps existen.
 *     Es la misma construcción que usa views/includes/footer.php (waApplyBusinessLinks).
 *   - iOS / escritorio: no hay forma confiable de elegir la app, así que va al
 *     enlace normal https://wa.me/… (Apple no permite escoger entre las dos apps).
 *
 * Parámetros:
 *   p = teléfono en dígitos con lada de país (ej. 523312345678). Obligatorio.
 *   t = texto opcional para prellenar el mensaje.
 */

$digits = preg_replace('/\D+/', '', (string) ($_GET['p'] ?? '')) ?? '';
$text = trim((string) ($_GET['t'] ?? ''));

// Aceptamos 11 a 15 dígitos (lada país + número nacional). Si no cuadra, al catálogo.
if (strlen($digits) < 11 || strlen($digits) > 15) {
    header('Location: /views/catalogo.php', true, 302);
    exit;
}

$waMe = 'https://wa.me/' . $digits . ($text !== '' ? '?text=' . rawurlencode($text) : '');

header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$isAndroid = stripos((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 'android') !== false;

if (!$isAndroid) {
    header('Location: ' . $waMe, true, 302);
    exit;
}

// Android: construir el intent hacia WhatsApp Business, con wa.me como fallback.
if ($text !== '') {
    $intent = 'intent://send?phone=' . rawurlencode($digits)
        . '&text=' . rawurlencode($text)
        . '#Intent;scheme=whatsapp;package=com.whatsapp.w4b;S.browser_fallback_url='
        . rawurlencode($waMe) . ';end';
} else {
    $intent = 'intent://send/?phone=' . rawurlencode($digits)
        . '#Intent;scheme=smsto;package=com.whatsapp.w4b;S.browser_fallback_url='
        . rawurlencode($waMe) . ';end';
}

$intentAttr = htmlspecialchars($intent, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$waMeAttr = htmlspecialchars($waMe, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$intentJs = json_encode($intent, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

header('Content-Type: text/html; charset=UTF-8');
?><!doctype html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Abriendo WhatsApp Business…</title>
<script>
  // Intento de abrir WhatsApp Business; si el intent no resuelve, el propio
  // Android usa browser_fallback_url (wa.me). Igual dejamos un enlace visible.
  try { window.location.replace(<?php echo $intentJs; ?>); } catch (e) {}
  setTimeout(function () { window.location.href = <?php echo json_encode($waMe, JSON_UNESCAPED_SLASHES); ?>; }, 1500);
</script>
</head>
<body style="font-family:Arial,Helvetica,sans-serif;padding:24px;color:#263238;">
  <p>Abriendo <strong>WhatsApp Business</strong>…</p>
  <p><a href="<?php echo $intentAttr; ?>">Abrir en WhatsApp Business</a></p>
  <p><a href="<?php echo $waMeAttr; ?>">Abrir en WhatsApp</a></p>
</body>
</html>
