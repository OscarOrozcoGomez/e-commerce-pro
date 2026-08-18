-- Agrega columnas de coordenadas para cachear lat/lng por domicilio guardado del cliente.
-- Permite reutilizar coordenadas resueltas desde maps_link sin recalcularlas en cada
-- pedido (a diferencia de pedidos.latitud/longitud, que solo cachean por pedido).
-- Idempotente para despliegues repetidos.

SET @db := DATABASE();

SELECT COUNT(*) INTO @has_tabla
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'cliente_direcciones';

SELECT COUNT(*) INTO @has_latitud
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'cliente_direcciones'
  AND COLUMN_NAME = 'latitud';

SET @sql := IF(
    @has_tabla = 1 AND @has_latitud = 0,
    "ALTER TABLE cliente_direcciones ADD COLUMN latitud DECIMAL(10,8) DEFAULT NULL AFTER maps_link",
    'SELECT "skip: cliente_direcciones.latitud ya existe o tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @has_longitud
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'cliente_direcciones'
  AND COLUMN_NAME = 'longitud';

SET @sql := IF(
    @has_tabla = 1 AND @has_longitud = 0,
    "ALTER TABLE cliente_direcciones ADD COLUMN longitud DECIMAL(11,8) DEFAULT NULL AFTER latitud",
    'SELECT "skip: cliente_direcciones.longitud ya existe o tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
