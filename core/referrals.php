<?php
declare(strict_types=1);

/**
 * Programa de referidos: codigo unico por cliente (generado bajo demanda),
 * validacion en checkout, y registro de uso para limitar abuso -- un mismo
 * telefono solo puede redimir un codigo de referido una vez en su vida,
 * y nadie puede usar su propio codigo.
 */

function referralGenerateCode(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sin 0/O/1/I para evitar confusiones al dictarlo
    $code = '';
    for ($i = 0; $i < 6; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $code;
}

function referralGetOrCreateCode(PDO $pdo, int $idCliente): string
{
    $stmt = $pdo->prepare('SELECT codigo FROM codigos_referido WHERE id_cliente = ?');
    $stmt->execute([$idCliente]);
    $existing = $stmt->fetchColumn();
    if (is_string($existing) && $existing !== '') {
        return $existing;
    }

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $code = referralGenerateCode();
        try {
            $insert = $pdo->prepare('INSERT INTO codigos_referido (id_cliente, codigo) VALUES (?, ?)');
            $insert->execute([$idCliente, $code]);
            return $code;
        } catch (PDOException $e) {
            if ((int) $e->getCode() !== 23000) {
                throw $e;
            }
            // Codigo duplicado (raro): reintentar con otro codigo aleatorio.
        }
    }

    throw new RuntimeException('No se pudo generar un codigo de referido unico.');
}

/**
 * Valida un codigo de referido ingresado en checkout y calcula el descuento
 * a aplicar. No modifica nada -- solo informa si es valido y cuanto
 * descontar; registrar el uso es responsabilidad de referralRecordUsage().
 *
 * @return array{valido: bool, motivo: ?string, id_cliente_referidor: ?int, descuento: float}
 */
function referralValidate(PDO $pdo, string $codigoInput, ?int $idClienteComprador, string $telefonoDigits, float $subtotal): array
{
    $invalido = ['valido' => false, 'motivo' => null, 'id_cliente_referidor' => null, 'descuento' => 0.0];

    $codigo = strtoupper(trim($codigoInput));
    if ($codigo === '') {
        return $invalido;
    }

    $config = ventasFeatureConfig($pdo, 'programa_referidos');
    $descuentoPorcentaje = (float) ($config['descuento_porcentaje'] ?? 10);
    $montoMinimo = (float) ($config['monto_minimo_pedido'] ?? 0);

    if ($subtotal < $montoMinimo) {
        return array_merge($invalido, ['motivo' => 'monto_minimo_no_alcanzado']);
    }

    $stmt = $pdo->prepare('SELECT id_cliente FROM codigos_referido WHERE codigo = ?');
    $stmt->execute([$codigo]);
    $idClienteReferidor = $stmt->fetchColumn();
    if ($idClienteReferidor === false) {
        return array_merge($invalido, ['motivo' => 'codigo_no_existe']);
    }
    $idClienteReferidor = (int) $idClienteReferidor;

    if ($idClienteComprador !== null && $idClienteComprador === $idClienteReferidor) {
        return array_merge($invalido, ['motivo' => 'no_puedes_usar_tu_propio_codigo']);
    }

    // Un telefono vacio/invalido no se puede usar para el control anti-abuso (un mismo
    // codigo por telefono en toda su historia), asi que se rechaza en vez de dejarlo pasar:
    // de lo contrario, mandar el pedido sin telefono seria una forma trivial de redimir el
    // mismo codigo un numero ilimitado de veces.
    if ($telefonoDigits === '') {
        return array_merge($invalido, ['motivo' => 'telefono_requerido']);
    }

    $usoStmt = $pdo->prepare('SELECT COUNT(*) FROM referidos_usos WHERE telefono_referido_digits = ?');
    $usoStmt->execute([$telefonoDigits]);
    if ((int) $usoStmt->fetchColumn() > 0) {
        return array_merge($invalido, ['motivo' => 'telefono_ya_redimio_un_codigo']);
    }

    $descuento = round($subtotal * max(0.0, min(100.0, $descuentoPorcentaje)) / 100, 2);

    return [
        'valido' => true,
        'motivo' => null,
        'id_cliente_referidor' => $idClienteReferidor,
        'descuento' => $descuento,
    ];
}

function referralRecordUsage(PDO $pdo, int $idPedido, string $codigo, int $idClienteReferidor, ?int $idClienteReferido, string $telefonoDigits, float $descuento): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO referidos_usos (id_pedido, codigo, id_cliente_referidor, id_cliente_referido, telefono_referido_digits, descuento_aplicado)
         VALUES (:id_pedido, :codigo, :id_cliente_referidor, :id_cliente_referido, :telefono, :descuento)'
    );
    $stmt->execute([
        ':id_pedido' => $idPedido,
        ':codigo' => strtoupper($codigo),
        ':id_cliente_referidor' => $idClienteReferidor,
        ':id_cliente_referido' => $idClienteReferido,
        ':telefono' => $telefonoDigits !== '' ? $telefonoDigits : null,
        ':descuento' => $descuento,
    ]);
}
