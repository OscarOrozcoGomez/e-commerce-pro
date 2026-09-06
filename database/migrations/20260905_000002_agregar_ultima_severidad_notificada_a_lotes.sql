-- Migracion: recuerda la ultima severidad de caducidad que ya se notifico por
-- correo para cada lote, para poder detectar CAMBIOS de severidad (y no reenviar
-- el mismo aviso cada vez que corre el cron). Idempotente.
SET @db := DATABASE();

SELECT COUNT(*) INTO @has_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'lotes_inventario';

SELECT COUNT(*) INTO @has_col
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'lotes_inventario'
  AND COLUMN_NAME = 'ultima_severidad_notificada';

SET @sql := IF(
    @has_table = 1 AND @has_col = 0,
    'ALTER TABLE lotes_inventario ADD COLUMN ultima_severidad_notificada VARCHAR(20) NULL AFTER alerta_atendida',
    'SELECT "skip: lotes_inventario.ultima_severidad_notificada ya existe o la tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
