<?php
declare(strict_types=1);

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
