-- Migracion: ampliar telefono de clientes para cifrado
-- Objetivo:
-- 1) clientes.telefono era VARCHAR(60). El telefono se formatea como
--    "(xxx) - xxx - xxxx" (19 caracteres) antes de cifrarse en
--    manage_customers.php y api/create_customer.php; el texto cifrado
--    resultante (~70 caracteres con el prefijo ENCv1:) no cabia en 60 y
--    MySQL lo truncaba en silencio, corrompiendo el cifrado para siempre
--    (el telefono quedaba en blanco al descifrarse, p.ej. en sales.php).
-- 2) Ampliar la columna evita que se siga corrompiendo al crear/editar
--    clientes con telefono. Los registros ya corrompidos se corrigen
--    volviendo a capturar el telefono desde Administrar Clientes.
-- 3) Ser idempotente.

SET @db := DATABASE();

SELECT COUNT(*) INTO @has_narrow_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'clientes'
  AND COLUMN_NAME = 'telefono'
  AND CHARACTER_MAXIMUM_LENGTH < 120;

SET @sql := IF(
    @has_narrow_column = 1,
    'ALTER TABLE clientes MODIFY COLUMN `telefono` VARCHAR(120) NULL DEFAULT NULL',
    'SELECT "skip: clientes.telefono ya admite 120 caracteres o la columna no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
