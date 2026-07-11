-- Agrega link de Google Maps por pedido para priorizar la ruta/captura exacta de entrega.
-- Idempotente para despliegues repetidos.

SET @db := DATABASE();

SELECT COUNT(*) INTO @has_pedidos
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'pedidos';

SELECT COUNT(*) INTO @has_maps_link_entrega
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'pedidos'
  AND COLUMN_NAME = 'maps_link_entrega';

SET @sql := IF(
    @has_pedidos = 1 AND @has_maps_link_entrega = 0,
    "ALTER TABLE pedidos ADD COLUMN maps_link_entrega TEXT DEFAULT NULL AFTER telefono_entrega",
    'SELECT "skip: pedidos.maps_link_entrega ya existe o tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;