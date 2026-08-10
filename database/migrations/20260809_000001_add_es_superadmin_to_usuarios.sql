-- Migracion: agregar flag de super admin a usuarios
-- Objetivo:
-- 1) Distinguir administradores normales de super administradores.
-- 2) Permitir bloquear/desbloquear altas sensibles solo desde super admin.
-- 3) Ser idempotente.

SET @db := DATABASE();

SELECT COUNT(*) INTO @has_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'usuarios';

SELECT COUNT(*) INTO @has_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'usuarios'
  AND COLUMN_NAME = 'es_superadmin';

SET @sql := IF(
    @has_table = 1 AND @has_column = 0,
    'ALTER TABLE usuarios ADD COLUMN es_superadmin TINYINT(1) NOT NULL DEFAULT 0 AFTER estado',
    'SELECT "skip: usuarios.es_superadmin ya existe o la tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @has_idx
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'usuarios'
  AND INDEX_NAME = 'idx_usuarios_superadmin';

SET @sql := IF(
    @has_table = 1 AND @has_column = 0 AND @has_idx = 0,
    'ALTER TABLE usuarios ADD INDEX idx_usuarios_superadmin (es_superadmin)',
    'SELECT "skip: indice superadmin ya existe o no aplica"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;