<?php
declare(strict_types=1);

/**
 * Cifrado de PII para almacenamiento en BD.
 *
 * Formato almacenado: ENCv1:<base64(payload)>
 * payload[0] = algoritmo (1=sodium xchacha20poly1305, 2=openssl aes-256-gcm)
 */

const PII_CIPHER_PREFIX = 'ENCv1:';

function piiGetEncryptionKey(): string
{
    $key = piiGetEncryptionKeyOrNull();
    if ($key === null) {
        throw new RuntimeException('PII_ENCRYPTION_KEY no esta configurada.');
    }

    return $key;
}

function piiGetEncryptionKeyOrNull(): ?string
{
    $key = getenv('PII_ENCRYPTION_KEY');
    if ($key === false || trim((string)$key) === '') {
        $key = $_SERVER['PII_ENCRYPTION_KEY'] ?? $_ENV['PII_ENCRYPTION_KEY'] ?? '';
    }

    $key = trim((string)$key);
    if ($key === '') {
        return null;
    }

    return $key;
}

function piiEncryptValue(?string $plainText): ?string
{
    if ($plainText === null || $plainText === '') {
        return $plainText;
    }

    if (piiIsEncryptedValue($plainText)) {
        return $plainText;
    }

    $keyMaterial = piiGetEncryptionKey();

    if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')) {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $key = piiNormalizeKeyLength($keyMaterial, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $cipher = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plainText, '', $nonce, $key);
        $payload = chr(1) . $nonce . $cipher;
        return PII_CIPHER_PREFIX . base64_encode($payload);
    }

    if (function_exists('openssl_encrypt')) {
        $nonce = random_bytes(12);
        $tag = '';
        $key = piiNormalizeKeyLength($keyMaterial, 32);
        $cipherRaw = openssl_encrypt($plainText, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipherRaw === false) {
            throw new RuntimeException('No se pudo cifrar PII (openssl).');
        }
        $payload = chr(2) . $nonce . $tag . $cipherRaw;
        return PII_CIPHER_PREFIX . base64_encode($payload);
    }

    throw new RuntimeException('No hay libreria de cifrado disponible (sodium/openssl).');
}

function piiDecryptValue(?string $cipherText): ?string
{
    if ($cipherText === null || $cipherText === '') {
        return $cipherText;
    }

    $current = trim($cipherText);
    // Soporta una segunda capa accidental de cifrado sin romper compatibilidad.
    for ($i = 0; $i < 3; $i++) {
        if (!piiIsEncryptedValue($current)) {
            return $current;
        }

        $decoded = piiDecryptSingleLayer($current);
        if (!is_string($decoded) || $decoded === $current) {
            return $cipherText;
        }

        $current = trim($decoded);
    }

    return $current;
}

function piiLogMissingKeyOnce(): void
{
    static $logged = false;
    if ($logged) {
        return;
    }

    $logged = true;
    error_log('WARNING: PII_ENCRYPTION_KEY no configurada; mostrando valores cifrados sin descifrar.');
}

function piiIsEncryptedValue(?string $value): bool
{
    if (!is_string($value) || $value === '') {
        return false;
    }

    return strpos(trim($value), PII_CIPHER_PREFIX) === 0;
}

function piiDecryptSingleLayer(string $cipherText): string
{
    $cipherText = trim($cipherText);
    $encoded = substr($cipherText, strlen(PII_CIPHER_PREFIX));
    $payload = base64_decode($encoded, true);
    if (!is_string($payload) || $payload === '') {
        return $cipherText;
    }

    $algo = ord($payload[0]);
    $keyMaterial = piiGetEncryptionKeyOrNull();
    if ($keyMaterial === null) {
        piiLogMissingKeyOnce();
        return $cipherText;
    }

    if ($algo === 1 && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')) {
        $nonceLen = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if (strlen($payload) <= 1 + $nonceLen) {
            return $cipherText;
        }
        $nonce = substr($payload, 1, $nonceLen);
        $cipher = substr($payload, 1 + $nonceLen);
        $key = piiNormalizeKeyLength($keyMaterial, SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt($cipher, '', $nonce, $key);
        return is_string($plain) ? $plain : $cipherText;
    }

    if ($algo === 2 && function_exists('openssl_decrypt')) {
        if (strlen($payload) <= 1 + 12 + 16) {
            return $cipherText;
        }
        $nonce = substr($payload, 1, 12);
        $tag = substr($payload, 13, 16);
        $cipherRaw = substr($payload, 29);
        $key = piiNormalizeKeyLength($keyMaterial, 32);
        $plain = openssl_decrypt($cipherRaw, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $nonce, $tag, '');
        return is_string($plain) ? $plain : $cipherText;
    }

    return $cipherText;
}

function piiNormalizeKeyLength(string $key, int $bytes): string
{
    if (preg_match('/^[A-Fa-f0-9]{64}$/', $key) === 1) {
        $bin = hex2bin($key);
        if (is_string($bin)) {
            $key = $bin;
        }
    } elseif (preg_match('/^[A-Za-z0-9+\/]+=*$/', $key) === 1) {
        $decoded = base64_decode($key, true);
        if (is_string($decoded) && $decoded !== '') {
            $key = $decoded;
        }
    }

    if (strlen($key) === $bytes) {
        return $key;
    }

    return substr(hash('sha256', $key, true), 0, $bytes);
}
