-- Agrega campos para priorizar entregas con hora limite y tiempo de servicio por parada.
-- Idempotente para despliegues repetidos.

SET @db := DATABASE();

SELECT COUNT(*) INTO @has_pedidos
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'pedidos';

SELECT COUNT(*) INTO @has_fecha_limite
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'pedidos'
  AND COLUMN_NAME = 'fecha_limite_entrega';

SET @sql := IF(
    @has_pedidos = 1 AND @has_fecha_limite = 0,
    "ALTER TABLE pedidos ADD COLUMN fecha_limite_entrega DATETIME DEFAULT NULL AFTER fecha_entrega_programada",
    'SELECT "skip: pedidos.fecha_limite_entrega ya existe o tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @has_prioridad
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'pedidos'
  AND COLUMN_NAME = 'prioridad_entrega';

SET @sql := IF(
    @has_pedidos = 1 AND @has_prioridad = 0,
    "ALTER TABLE pedidos ADD COLUMN prioridad_entrega TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER fecha_limite_entrega",
    'SELECT "skip: pedidos.prioridad_entrega ya existe o tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @has_tiempo_servicio
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'pedidos'
  AND COLUMN_NAME = 'tiempo_servicio_min';

SET @sql := IF(
    @has_pedidos = 1 AND @has_tiempo_servicio = 0,
    "ALTER TABLE pedidos ADD COLUMN tiempo_servicio_min SMALLINT UNSIGNED NOT NULL DEFAULT 5 AFTER prioridad_entrega",
    'SELECT "skip: pedidos.tiempo_servicio_min ya existe o tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
