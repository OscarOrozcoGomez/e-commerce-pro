-- Permite marcar un pedido como "no afecta inventario" (ventas de muestra, cortesía, etc.
-- capturadas por admin/encargado usando la palabra clave en notas). Idempotente para
-- despliegues repetidos.

SET @db := DATABASE();

SELECT COUNT(*) INTO @has_pedidos
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'pedidos';

SELECT COUNT(*) INTO @has_afecta_inventario
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'pedidos'
  AND COLUMN_NAME = 'afecta_inventario';

SET @sql := IF(
    @has_pedidos = 1 AND @has_afecta_inventario = 0,
    "ALTER TABLE pedidos ADD COLUMN afecta_inventario TINYINT(1) NOT NULL DEFAULT 1 AFTER estado",
    'SELECT "skip: pedidos.afecta_inventario ya existe o tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
