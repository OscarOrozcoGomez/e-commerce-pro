-- Mismo patron que clientes.id_usuario (20260629_000002) y logs_actividad (20260628_000006):
-- estas columnas de `productos` nunca tuvieron migracion ni estan en database.sql -- se
-- agregaron en algun momento directo contra las BDs reales, fuera de control de versiones.
-- El codigo de la app (views/catalogo.php, views/products.php, product_detail.php, etc.) las
-- da por existentes desde siempre.
--
-- Descubierto en el mismo barrido que las anteriores: al reconstruir el schema completo desde
-- cero (job e2e-tests de CI), views/catalogo.php tronaba en silencio (atrapa PDOException y
-- deja $productos = [], solo error_log()) con "Unknown column 'precio_comparacion' in 'field
-- list'" -- el catalogo salia vacio en vez de mostrar un error, lo que hizo mucho mas dificil
-- encontrar esto que los casos anteriores (tabla/columna faltante con error directo).
--
-- ADD COLUMN IF NOT EXISTS: sintaxis nativa de MariaDB (soportada hace anios) y de MySQL
-- 8.0.29+; el job de CI ya corre sobre mariadb:10.4 (ver ci.yml), asi que es segura aqui.
ALTER TABLE productos
    ADD COLUMN IF NOT EXISTS sku VARCHAR(120) DEFAULT NULL AFTER nombre_variante,
    ADD COLUMN IF NOT EXISTS beneficios TEXT DEFAULT NULL AFTER descripcion,
    ADD COLUMN IF NOT EXISTS ingredientes TEXT DEFAULT NULL AFTER beneficios,
    ADD COLUMN IF NOT EXISTS modo_uso TEXT DEFAULT NULL AFTER ingredientes,
    ADD COLUMN IF NOT EXISTS tabla_nutrimental TEXT DEFAULT NULL AFTER modo_uso,
    ADD COLUMN IF NOT EXISTS mostrar_tabla TINYINT(1) DEFAULT 1 AFTER tabla_nutrimental,
    ADD COLUMN IF NOT EXISTS precio_comparacion DECIMAL(12,2) DEFAULT 0.00 AFTER precio_venta,
    ADD COLUMN IF NOT EXISTS imagen VARCHAR(255) DEFAULT NULL AFTER categoria,
    ADD COLUMN IF NOT EXISTS imagen_url VARCHAR(255) DEFAULT 'default-product.png' AFTER imagen;

-- uq_productos_sku: sku es nullable (multiples NULL son validos bajo una UNIQUE KEY en
-- MySQL/MariaDB), asi que agregar el indice es seguro aunque ningun producto lo tenga
-- todavia poblado. Guardado con informacion_schema porque, a diferencia de ADD COLUMN, MariaDB
-- no soporta "ADD UNIQUE KEY IF NOT EXISTS".
SET @db := DATABASE();

SELECT COUNT(*) INTO @has_idx
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'productos'
  AND INDEX_NAME = 'uq_productos_sku';

SET @sql := IF(
    @has_idx = 0,
    'ALTER TABLE productos ADD UNIQUE KEY uq_productos_sku (sku)',
    'SELECT "skip: uq_productos_sku ya existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
