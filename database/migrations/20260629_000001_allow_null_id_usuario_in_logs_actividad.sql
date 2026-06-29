-- Migracion: Permitir logs de invitados en logs_actividad (id_usuario nullable)
-- Objetivo:
-- 1) Permitir id_usuario NULL para registrar actividad sin sesion.
-- 2) Ajustar FK hacia usuarios con ON DELETE SET NULL.
-- 3) Ser idempotente (se puede ejecutar mas de una vez sin romper).

SET @db := DATABASE();

-- Verificar existencia de tabla y columna.
SELECT COUNT(*) INTO @has_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'logs_actividad';

SELECT COUNT(*) INTO @has_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'logs_actividad'
  AND COLUMN_NAME = 'id_usuario';

-- 1) Hacer nullable la columna id_usuario (si existe).
SET @sql := IF(
    @has_table = 1 AND @has_column = 1,
    'ALTER TABLE logs_actividad MODIFY COLUMN id_usuario INT UNSIGNED NULL',
    'SELECT "skip: logs_actividad o id_usuario no existen"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2) Detectar FK actual sobre logs_actividad.id_usuario -> usuarios.
SET @fk_name := NULL;
SELECT kcu.CONSTRAINT_NAME INTO @fk_name
FROM information_schema.KEY_COLUMN_USAGE kcu
WHERE kcu.TABLE_SCHEMA = @db
  AND kcu.TABLE_NAME = 'logs_actividad'
  AND kcu.COLUMN_NAME = 'id_usuario'
  AND kcu.REFERENCED_TABLE_NAME = 'usuarios'
LIMIT 1;

-- Drop FK actual (si existe).
SET @sql := IF(
    @fk_name IS NOT NULL,
    CONCAT('ALTER TABLE logs_actividad DROP FOREIGN KEY `', REPLACE(@fk_name, '`', '``'), '`'),
    'SELECT "skip: sin FK previa en logs_actividad.id_usuario"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3) Asegurar indice para id_usuario (si no existe).
SELECT COUNT(*) INTO @has_idx
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'logs_actividad'
  AND INDEX_NAME = 'idx_logs_actividad_usuario';

SET @sql := IF(
    @has_table = 1 AND @has_column = 1 AND @has_idx = 0,
    'ALTER TABLE logs_actividad ADD INDEX idx_logs_actividad_usuario (id_usuario)',
    'SELECT "skip: indice ya existe o tabla/columna no existen"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 4) Agregar FK estandar con ON DELETE SET NULL (si no existe ya).
SELECT COUNT(*) INTO @has_target_fk
FROM information_schema.REFERENTIAL_CONSTRAINTS rc
WHERE rc.CONSTRAINT_SCHEMA = @db
  AND rc.TABLE_NAME = 'logs_actividad'
  AND rc.CONSTRAINT_NAME = 'fk_logs_actividad_usuario';

SET @sql := IF(
    @has_table = 1 AND @has_column = 1 AND @has_target_fk = 0,
    'ALTER TABLE logs_actividad ADD CONSTRAINT fk_logs_actividad_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT "skip: FK objetivo ya existe o tabla/columna no existen"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
