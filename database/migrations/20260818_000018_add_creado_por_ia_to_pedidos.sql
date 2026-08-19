-- Distintivo para saber que un pedido lo agendo Alex (asistente de WhatsApp) y no el
-- checkout web ni un vendedor desde el panel. Idempotente para despliegues repetidos.

SET @db := DATABASE();

SELECT COUNT(*) INTO @has_pedidos
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'pedidos';

SELECT COUNT(*) INTO @has_creado_por_ia
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'pedidos'
  AND COLUMN_NAME = 'creado_por_ia';

SET @sql := IF(
    @has_pedidos = 1 AND @has_creado_por_ia = 0,
    "ALTER TABLE pedidos ADD COLUMN creado_por_ia TINYINT(1) NOT NULL DEFAULT 0 AFTER id_usuario",
    'SELECT "skip: pedidos.creado_por_ia ya existe o tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
