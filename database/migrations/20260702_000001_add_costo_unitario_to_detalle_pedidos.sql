-- Agrega snapshot de costo por unidad para calcular utilidad historica.
-- Idempotente para despliegues repetidos.

SET @db := DATABASE();

SELECT COUNT(*) INTO @has_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'detalle_pedidos';

SELECT COUNT(*) INTO @has_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'detalle_pedidos'
  AND COLUMN_NAME = 'costo_unitario';

SET @sql := IF(
    @has_table = 1 AND @has_column = 0,
    'ALTER TABLE detalle_pedidos ADD COLUMN costo_unitario DECIMAL(12,2) NULL AFTER precio_unitario',
    'SELECT "skip: detalle_pedidos.costo_unitario ya existe o tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;