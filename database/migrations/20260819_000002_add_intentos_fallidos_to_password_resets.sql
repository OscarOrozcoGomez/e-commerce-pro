-- Migracion: contador de intentos fallidos para codigos de recuperacion de contraseña
-- Objetivo: permitir bloquear un codigo de 6 digitos tras varios intentos fallidos,
-- igual que ya ocurre con el login, para evitar que se adivine por fuerza bruta.

SET @db := DATABASE();

SELECT COUNT(*) INTO @has_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'password_resets';

SELECT COUNT(*) INTO @has_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'password_resets'
  AND COLUMN_NAME = 'intentos_fallidos';

SET @sql := IF(
    @has_table = 1 AND @has_column = 0,
    'ALTER TABLE password_resets ADD COLUMN intentos_fallidos TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER usado',
    'SELECT "skip: password_resets.intentos_fallidos ya existe o la tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
