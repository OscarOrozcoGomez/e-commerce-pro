-- Migracion: permitir sku nulo en productos
-- Objetivo:
-- 1) Permitir dar de alta productos sin SKU (el campo ya no debe ser obligatorio).
-- 2) codigo_barras ya admite NULL; el backend se ajusta para enviar NULL en vez de '' cuando esta vacio.
-- 3) Ser idempotente.

SET @db := DATABASE();

SELECT COUNT(*) INTO @has_column_not_null
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'productos'
  AND COLUMN_NAME = 'sku'
  AND IS_NULLABLE = 'NO';

SET @sql := IF(
    @has_column_not_null = 1,
    'ALTER TABLE productos MODIFY COLUMN `sku` VARCHAR(120) NULL DEFAULT NULL',
    'SELECT "skip: productos.sku ya admite NULL o la columna no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
