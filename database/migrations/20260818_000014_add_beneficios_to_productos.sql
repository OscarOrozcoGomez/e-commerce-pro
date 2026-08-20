-- Agrega columna beneficios a productos, para listar de forma breve las
-- necesidades/afecciones que atiende cada producto (poblada luego por
-- scripts/populate_product_benefits.php). Idempotente para despliegues repetidos.

SET @db := DATABASE();

SELECT COUNT(*) INTO @has_productos
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'productos';

SELECT COUNT(*) INTO @has_beneficios
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'productos'
  AND COLUMN_NAME = 'beneficios';

SET @sql := IF(
    @has_productos = 1 AND @has_beneficios = 0,
    "ALTER TABLE productos ADD COLUMN beneficios TEXT DEFAULT NULL AFTER descripcion",
    'SELECT "skip: productos.beneficios ya existe o tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
