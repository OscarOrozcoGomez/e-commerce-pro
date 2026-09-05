<?php
declare(strict_types=1);

/**
 * Limite de caracteres para el alias de una direccion de cliente. Es una etiqueta
 * corta ("Casa", "Oficina", "Mama"), no un texto libre.
 *
 * Ademas de la regla de negocio, mantener el alias corto protege el almacenamiento:
 * cliente_direcciones.alias guarda el valor CIFRADO con piiEncryptValue()
 * (core/pii_crypto.php) y el texto cifrado (ENCv1: + base64 de nonce+tag+ciphertext)
 * mide bastante mas que el plano -- un alias de ~4 caracteres ya llega a 50 cifrado.
 */
const DIRECCION_ALIAS_MAX_CARACTERES = 50;

/**
 * True si el alias supera el limite permitido. Cuenta caracteres (no bytes) para que
 * acentos y emojis valgan 1, igual que lo ve el usuario en el input.
 */
function direccionAliasExcedeLimite(string $alias): bool
{
    return mb_strlen(trim($alias)) > DIRECCION_ALIAS_MAX_CARACTERES;
}

/**
 * Mensaje estandar de error cuando el alias excede el limite. Centralizado para que
 * manage_customers.php y mis_direcciones.php muestren exactamente el mismo texto.
 */
function direccionAliasErrorLimite(): string
{
    return 'El alias de la direccion no puede exceder ' . DIRECCION_ALIAS_MAX_CARACTERES . ' caracteres.';
}

/**
 * Marca una direccion de cliente como confirmada por el cliente (p.ej. via WhatsApp),
 * dejando registro de cuando y quien del staff la confirmo. Se usa junto con el boton
 * "Enviar por WhatsApp para confirmar" en views/manage_customers.php: el cliente responde
 * fuera del sistema y el staff registra la confirmacion con esta funcion.
 *
 * @return array{success: bool, message: string}
 */
function dbConfirmarDireccionCliente(PDO $pdo, int $idCliente, int $idDireccion, ?int $idUsuario = null): array
{
    if ($idCliente <= 0 || $idDireccion <= 0) {
        return ['success' => false, 'message' => 'No se pudo confirmar la direccion.'];
    }

    // La hora se calcula en PHP (en vez de NOW() en SQL) para que sea portable entre
    // MySQL y SQLite (usado en pruebas).
    $ahora = date('Y-m-d H:i:s');
    $idUsuarioValido = ($idUsuario !== null && $idUsuario > 0) ? $idUsuario : null;

    $stmt = $pdo->prepare('UPDATE cliente_direcciones SET confirmada_cliente = 1, confirmada_en = ?, confirmada_por = ? WHERE id_direccion = ? AND id_cliente = ?');
    $stmt->execute([$ahora, $idUsuarioValido, $idDireccion, $idCliente]);

    if ($stmt->rowCount() === 0) {
        return ['success' => false, 'message' => 'No se encontro la direccion para confirmar.'];
    }

    return ['success' => true, 'message' => 'Direccion marcada como confirmada por el cliente.'];
}

/**
 * Solo consideramos "link de mapa valido" a una URL http(s). Asi evitamos meter en un
 * href esquemas peligrosos (javascript:, data:) si quedara guardado un valor raro en
 * cliente_direcciones.maps_link.
 */
function direccionMapsLinkEsValido(?string $mapsLink): bool
{
    return (bool) preg_match('#^https?://#i', trim((string) $mapsLink));
}

/**
 * Devuelve una URL para abrir la direccion del cliente en Google Maps:
 *  - si hay un maps_link http(s) guardado (pin exacto), se usa ese;
 *  - si no, se arma una busqueda de Google Maps con el texto de la direccion, para que el
 *    boton "Abrir en Google Maps" siempre funcione aunque nunca se haya fijado el pin.
 */
function direccionGoogleMapsHref(?string $mapsLink, ?string $direccion): string
{
    if (direccionMapsLinkEsValido($mapsLink)) {
        return trim((string) $mapsLink);
    }

    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode(trim((string) $direccion));
}
