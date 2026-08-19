<?php
declare(strict_types=1);

/**
 * Normaliza un telefono de cliente al formato de digitos con lada (52 + numero nacional) que
 * usan los links "https://wa.me/..." y el atributo data-wa-phone que footer.php lee para forzar
 * la apertura de WhatsApp Business (en vez del WhatsApp personal) en Android.
 */
function waBuildBusinessLinkPhone(?string $telefono): string
{
    $digits = preg_replace('/\D/', '', (string)$telefono) ?? '';
    if ($digits === '') {
        return '';
    }

    return '52' . $digits;
}
