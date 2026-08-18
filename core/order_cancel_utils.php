<?php
declare(strict_types=1);

/**
 * Estados de pedido desde los cuales el cliente puede cancelar.
 * Una vez en reparto, entregado o ya cancelado, no aplica.
 */
const PEDIDO_CANCELABLE_ESTADOS = ['pendiente_pago', 'pagado'];

/**
 * Lista los motivos de cancelacion activos, para mostrar al cliente al cancelar.
 */
function dbGetActiveCancellationReasons(): array
{
    $pdo = getPDO();
    $stmt = $pdo->query(
        "SELECT id_motivo, motivo, requiere_detalle
         FROM motivos_cancelacion_pedido
         WHERE activo = 1
         ORDER BY orden ASC, motivo ASC"
    );
    return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

/**
 * Cancela un pedido a peticion del cliente dueño del pedido: valida pertenencia y estado,
 * regresa el inventario reservado al almacen del pedido y deja registro del motivo de
 * cancelacion para poder dar seguimiento a por que los clientes cancelan.
 */
function dbCancelOrderByCustomer(PDO $pdo, int $idPedido, int $idUsuario, int $idMotivo, string $motivoDetalle): array
{
    if ($idPedido <= 0 || $idUsuario <= 0) {
        return ['success' => false, 'message' => 'Datos invalidos.'];
    }

    $motivoDetalle = trim($motivoDetalle);
    $isMysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';

    try {
        $pdo->beginTransaction();

        // Chequeo de existencia agnostico de motor (funciona igual en MySQL/SQLite):
        // intenta un SELECT y, si la tabla no existe, cae al catch en vez de depender
        // de information_schema, que es sintaxis exclusiva de MySQL.
        $hasPickupTable = false;
        try {
            $pdo->query('SELECT 1 FROM pickup_notificaciones LIMIT 1');
            $hasPickupTable = true;
        } catch (Throwable $e) {
            $hasPickupTable = false;
        }

        $pickupSelect = $hasPickupTable ? ', pn.estado AS pickup_estado' : ', NULL AS pickup_estado';
        $pickupJoin = $hasPickupTable ? ' LEFT JOIN pickup_notificaciones pn ON pn.id_pedido = p.id_pedido' : '';
        // FOR UPDATE serializa cancelaciones concurrentes del mismo pedido en MySQL;
        // SQLite no soporta esta clausula (solo se usa en tests con PDO sqlite).
        $lockClause = $isMysql ? ' FOR UPDATE' : '';

        $sql = "SELECT p.id_pedido, p.estado, p.id_almacen, p.numero_pedido, p.observaciones {$pickupSelect}
                FROM pedidos p
                JOIN clientes c ON p.id_cliente = c.id_cliente
                {$pickupJoin}
                WHERE p.id_pedido = :id_pedido AND c.id_usuario = :id_usuario{$lockClause}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id_pedido' => $idPedido, ':id_usuario' => $idUsuario]);
        $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$pedido) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'No encontramos ese pedido en tu cuenta.'];
        }

        if (!in_array((string)$pedido['estado'], PEDIDO_CANCELABLE_ESTADOS, true)) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Este pedido ya no se puede cancelar por su estado actual.'];
        }

        if ((string)($pedido['pickup_estado'] ?? '') === 'atendida') {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Este pedido ya fue entregado/recogido y no se puede cancelar.'];
        }

        $stmtItems = $pdo->prepare("SELECT id_producto, cantidad FROM detalle_pedidos WHERE id_pedido = ? AND cantidad > 0");
        $stmtItems->execute([$idPedido]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stmtRestock = $pdo->prepare(
            "UPDATE inventario_almacen SET cantidad_actual = cantidad_actual + ? WHERE id_producto = ? AND id_almacen = ?"
        );
        foreach ($items as $item) {
            $stmtRestock->execute([(int)$item['cantidad'], (int)$item['id_producto'], (int)$pedido['id_almacen']]);
        }

        // Se arma el texto en PHP (en vez de CONCAT en SQL) para no depender de una
        // funcion de concatenacion especifica de motor.
        $nuevasObservaciones = trim((string)($pedido['observaciones'] ?? '')) . ' | Cancelado por el cliente.';
        $stmtCancel = $pdo->prepare('UPDATE pedidos SET estado = ?, observaciones = ? WHERE id_pedido = ?');
        $stmtCancel->execute(['cancelado', $nuevasObservaciones, $idPedido]);

        // Insert simple: el FOR UPDATE de arriba ya serializa cancelaciones concurrentes
        // del mismo pedido, asi que en operacion normal nunca deberia chocar con la
        // llave unica de id_pedido (evita depender de ON DUPLICATE KEY, exclusivo MySQL).
        $stmtLog = $pdo->prepare(
            'INSERT INTO pedido_cancelaciones (id_pedido, id_motivo, motivo_detalle, cancelado_por)
             VALUES (:id_pedido, :id_motivo, :motivo_detalle, :cancelado_por)'
        );
        $stmtLog->execute([
            ':id_pedido' => $idPedido,
            ':id_motivo' => $idMotivo > 0 ? $idMotivo : null,
            ':motivo_detalle' => $motivoDetalle !== '' ? $motivoDetalle : null,
            ':cancelado_por' => $idUsuario,
        ]);

        logAudit('PEDIDO_CANCELADO_CLIENTE', 'pedidos', $idPedido, 'Cliente cancelo el pedido #' . (string)$pedido['numero_pedido']);

        $pdo->commit();

        return ['success' => true, 'message' => 'Tu pedido fue cancelado correctamente.'];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('Error en dbCancelOrderByCustomer: ' . $e->getMessage());
        return ['success' => false, 'message' => 'No fue posible cancelar el pedido. Intenta de nuevo.'];
    }
}
