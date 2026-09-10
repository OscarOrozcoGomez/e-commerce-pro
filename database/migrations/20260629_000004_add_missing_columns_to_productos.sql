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
-- IMPORTANTE: "ALTER TABLE ... ADD COLUMN IF NOT EXISTS" es una extension EXCLUSIVA de MariaDB.
-- MySQL/Percona (cualquier version, incluida 8.4) NO la soporta: es error de sintaxis directo,
-- aborta el runner de migraciones (core/migrations.php) y bloquea TODAS las migraciones
-- posteriores en produccion (Percona Server 8.4). La version anterior de este archivo lo usaba
-- y por eso el deploy fallo en cadena desde el PR #153.
--
-- Reescrito con checks a information_schema + ALTER dinamico via PREPARE/EXECUTE: idempotente y
-- valido tanto en MariaDB (local/CI) como en MySQL/Percona (prod). Cada columna se agrega solo
-- si falta; si ya existe (caso de prod), es un no-op. Se mantiene el orden original por las
-- dependencias de la clausula AFTER en instalaciones desde cero.

SET @db := DATABASE();

-- productos.sku
SET @needs := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'sku');
SET @sql := IF(@needs,
    'ALTER TABLE productos ADD COLUMN sku VARCHAR(120) DEFAULT NULL AFTER nombre_variante',
    'SELECT "skip: productos.sku ya existe"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- productos.beneficios
SET @needs := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'beneficios');
SET @sql := IF(@needs,
    'ALTER TABLE productos ADD COLUMN beneficios TEXT DEFAULT NULL AFTER descripcion',
    'SELECT "skip: productos.beneficios ya existe"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- productos.ingredientes
SET @needs := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'ingredientes');
SET @sql := IF(@needs,
    'ALTER TABLE productos ADD COLUMN ingredientes TEXT DEFAULT NULL AFTER beneficios',
    'SELECT "skip: productos.ingredientes ya existe"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- productos.modo_uso
SET @needs := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'modo_uso');
SET @sql := IF(@needs,
    'ALTER TABLE productos ADD COLUMN modo_uso TEXT DEFAULT NULL AFTER ingredientes',
    'SELECT "skip: productos.modo_uso ya existe"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- productos.tabla_nutrimental
SET @needs := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'tabla_nutrimental');
SET @sql := IF(@needs,
    'ALTER TABLE productos ADD COLUMN tabla_nutrimental TEXT DEFAULT NULL AFTER modo_uso',
    'SELECT "skip: productos.tabla_nutrimental ya existe"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- productos.mostrar_tabla
SET @needs := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'mostrar_tabla');
SET @sql := IF(@needs,
    'ALTER TABLE productos ADD COLUMN mostrar_tabla TINYINT(1) DEFAULT 1 AFTER tabla_nutrimental',
    'SELECT "skip: productos.mostrar_tabla ya existe"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- productos.precio_comparacion
SET @needs := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'precio_comparacion');
SET @sql := IF(@needs,
    'ALTER TABLE productos ADD COLUMN precio_comparacion DECIMAL(12,2) DEFAULT 0.00 AFTER precio_venta',
    'SELECT "skip: productos.precio_comparacion ya existe"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- productos.imagen
SET @needs := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'imagen');
SET @sql := IF(@needs,
    'ALTER TABLE productos ADD COLUMN imagen VARCHAR(255) DEFAULT NULL AFTER categoria',
    'SELECT "skip: productos.imagen ya existe"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- productos.imagen_url
SET @needs := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'imagen_url');
SET @sql := IF(@needs,
    'ALTER TABLE productos ADD COLUMN imagen_url VARCHAR(255) DEFAULT ''default-product.png'' AFTER imagen',
    'SELECT "skip: productos.imagen_url ya existe"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- uq_productos_sku: sku es nullable (multiples NULL son validos bajo una UNIQUE KEY en
-- MySQL/MariaDB), asi que agregar el indice es seguro aunque ningun producto lo tenga
-- todavia poblado. Guardado con information_schema porque, a diferencia de ADD COLUMN, MariaDB
-- no soporta "ADD UNIQUE KEY IF NOT EXISTS".
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
