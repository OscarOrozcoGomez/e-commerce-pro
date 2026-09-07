-- Cargo de envio foraneo (fuera de la periferia de Guadalajara) que ahora calculan y
-- persisten los tres flujos de alta de pedido (checkout web, panel de vendedor y bot).
-- Antes solo el bot lo aplicaba, sumandolo directo a pedidos.total sin dejar rastro del
-- desglose. total = subtotal - descuento_total + costo_envio. Idempotente.

SET @db := DATABASE();

SELECT COUNT(*) INTO @has_pedidos
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'pedidos';

SELECT COUNT(*) INTO @has_costo_envio
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'pedidos'
  AND COLUMN_NAME = 'costo_envio';

SET @sql := IF(
    @has_pedidos = 1 AND @has_costo_envio = 0,
    "ALTER TABLE pedidos ADD COLUMN costo_envio DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER descuento_total",
    'SELECT "skip: pedidos.costo_envio ya existe o tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
