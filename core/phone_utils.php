<?php
declare(strict_types=1);

require_once __DIR__ . '/pii_crypto.php';

function normalizePhoneDigitsMx(?string $value, int $expectedDigits = 10): ?string
{
    if ($value === null) {
        return null;
    }

    $digits = preg_replace('/\D+/', '', $value);
    if (!is_string($digits)) {
        return null;
    }

    if ($digits === '') {
        return '';
    }

    if (strlen($digits) !== $expectedDigits) {
        return null;
    }

    return $digits;
}

function formatPhoneMxDigits(string $digits): string
{
    $digits = substr(preg_replace('/\D+/', '', $digits) ?? '', 0, 10);
    if ($digits === '') {
        return '';
    }

    if (strlen($digits) <= 3) {
        return '(' . $digits;
    }

    if (strlen($digits) <= 6) {
        return '(' . substr($digits, 0, 3) . ') - ' . substr($digits, 3);
    }

    return '(' . substr($digits, 0, 3) . ') - ' . substr($digits, 3, 3) . ' - ' . substr($digits, 6, 4);
}

function normalizePhoneMx(?string $value): ?string
{
    $digits = normalizePhoneDigitsMx($value);
    if ($digits === null) {
        return null;
    }

    if ($digits === '') {
        return '';
    }

    return formatPhoneMxDigits($digits);
}

function phoneStoredValueToDigits(?string $value): ?string
{
    if ($value === null) {
        return null;
    }

    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }

    if (function_exists('piiIsEncryptedValue') && piiIsEncryptedValue($trimmed)) {
        if (function_exists('piiGetEncryptionKeyOrNull') && piiGetEncryptionKeyOrNull() === null) {
            return null;
        }

        $decrypted = function_exists('piiDecryptValue') ? piiDecryptValue($trimmed) : $trimmed;
        if (!is_string($decrypted) || $decrypted === '') {
            return null;
        }

        return normalizePhoneDigitsMx($decrypted);
    }

    return normalizePhoneDigitsMx($trimmed);
}

function findClienteByPhone(PDO $pdo, string $phoneValue, ?int $excludeClientId = null): ?array
{
    $inputDigits = normalizePhoneDigitsMx($phoneValue);
    if ($inputDigits === null || $inputDigits === '') {
        return null;
    }

    // nombre se incluye aqui porque views/complete_account.php precarga el campo
    // "Nombre Completo" con $clientePendiente['nombre'] -- sin esta columna en el SELECT
    // ese prefill siempre salia vacio (bug real, encontrado via E2E), forzando a cualquier
    // usuario real a volver a escribir su nombre en ese flujo.
    $stmt = $pdo->query('SELECT id_cliente, id_usuario, telefono, nombre FROM clientes');
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $idCliente = isset($row['id_cliente']) ? (int) $row['id_cliente'] : 0;
        if ($excludeClientId !== null && $idCliente === $excludeClientId) {
            continue;
        }

        $storedDigits = phoneStoredValueToDigits(isset($row['telefono']) ? (string) $row['telefono'] : null);
        if ($storedDigits === null || $storedDigits === '') {
            continue;
        }

        if ($storedDigits === $inputDigits) {
            $row['telefono_digitos'] = $storedDigits;
            return $row;
        }
    }

    return null;
}
