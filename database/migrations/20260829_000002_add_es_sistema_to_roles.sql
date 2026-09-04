-- Migracion: marca de "rol del sistema" en la tabla roles
-- Objetivo:
-- 1) Distinguir los roles base (admin, encargado, vendedor, repartidor, cliente) de
--    los roles personalizados que el admin cree desde el panel.
-- 2) La UI usa esta marca para impedir renombrar/eliminar esos roles.
-- 3) Ser idempotente.

SET @db := DATABASE();

SELECT COUNT(*) INTO @has_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'roles';

SELECT COUNT(*) INTO @has_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'roles'
  AND COLUMN_NAME = 'es_sistema';

SET @sql := IF(
    @has_table = 1 AND @has_column = 0,
    'ALTER TABLE roles ADD COLUMN es_sistema TINYINT(1) NOT NULL DEFAULT 0 AFTER estado',
    'SELECT "skip: roles.es_sistema ya existe o la tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Marcar los roles base como del sistema (idempotente: solo pone el flag).
UPDATE roles
SET es_sistema = 1
WHERE nombre IN ('admin', 'encargado', 'vendedor', 'repartidor', 'cliente');
