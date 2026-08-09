-- Agrega columnas de coordenadas para cachear lat/lng por pedido en rutas de entrega.
-- Idempotente para despliegues repetidos.

SET @db := DATABASE();

SELECT COUNT(*) INTO @has_pedidos
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'pedidos';

SELECT COUNT(*) INTO @has_latitud
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'pedidos'
  AND COLUMN_NAME = 'latitud';

SET @sql := IF(
    @has_pedidos = 1 AND @has_latitud = 0,
    "ALTER TABLE pedidos ADD COLUMN latitud DECIMAL(10,8) DEFAULT NULL AFTER maps_link_entrega",
    'SELECT "skip: pedidos.latitud ya existe o tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @has_longitud
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'pedidos'
  AND COLUMN_NAME = 'longitud';

SET @sql := IF(
    @has_pedidos = 1 AND @has_longitud = 0,
    "ALTER TABLE pedidos ADD COLUMN longitud DECIMAL(11,8) DEFAULT NULL AFTER latitud",
    'SELECT "skip: pedidos.longitud ya existe o tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
