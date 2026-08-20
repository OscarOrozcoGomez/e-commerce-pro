-- Migracion: agregar estado_entrega y motivo_rechazo a detalle_pedidos
-- Permite al repartidor marcar un producto individual como rechazado por el
-- cliente (sin cancelar todo el pedido) y registrar el motivo.
-- Idempotente para despliegues repetidos.

SET @db := DATABASE();

SELECT COUNT(*) INTO @has_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'detalle_pedidos';

SELECT COUNT(*) INTO @has_estado_entrega
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'detalle_pedidos'
  AND COLUMN_NAME = 'estado_entrega';

SET @sql := IF(
    @has_table = 1 AND @has_estado_entrega = 0,
    "ALTER TABLE detalle_pedidos ADD COLUMN estado_entrega ENUM('entregado','rechazado') NOT NULL DEFAULT 'entregado' AFTER subtotal",
    'SELECT "skip: detalle_pedidos.estado_entrega ya existe o tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @has_motivo_rechazo
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'detalle_pedidos'
  AND COLUMN_NAME = 'motivo_rechazo';

SET @sql := IF(
    @has_table = 1 AND @has_motivo_rechazo = 0,
    'ALTER TABLE detalle_pedidos ADD COLUMN motivo_rechazo VARCHAR(191) NULL AFTER estado_entrega',
    'SELECT "skip: detalle_pedidos.motivo_rechazo ya existe o tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
