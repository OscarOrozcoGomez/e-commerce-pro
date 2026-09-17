-- Migracion: agrega columna perfil_recomendado a productos, para que el vendedor/encargado
-- y Alex tengan una referencia rapida de a que tipo de cliente se le puede sugerir cada
-- producto (ej. "mujeres, embarazo y lactancia: no recomendado, deportistas"). Es un campo
-- interno de apoyo, igual que beneficios -- no se muestra tal cual al cliente. Idempotente
-- para despliegues repetidos.

SET @db := DATABASE();

SELECT COUNT(*) INTO @has_productos
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'productos';

SELECT COUNT(*) INTO @has_perfil
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'productos'
  AND COLUMN_NAME = 'perfil_recomendado';

SET @sql := IF(
    @has_productos = 1 AND @has_perfil = 0,
    "ALTER TABLE productos ADD COLUMN perfil_recomendado TEXT DEFAULT NULL AFTER beneficios",
    'SELECT "skip: productos.perfil_recomendado ya existe o tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
