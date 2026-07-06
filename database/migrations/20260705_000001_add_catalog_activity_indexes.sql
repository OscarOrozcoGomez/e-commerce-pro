-- Migracion: Indices para reducir latencia/contencion en catalogo + logging.
-- Objetivo:
-- 1) Mejorar filtros y ordenamientos usados por views/catalogo.php.
-- 2) Acelerar subconsultas de imagenes/categorias para evitar timeouts.
-- 3) Mantener script idempotente para ejecuciones repetidas.

SET @db := DATABASE();

-- ---------- productos ----------
SELECT COUNT(*) INTO @has_productos
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'productos';

SELECT COUNT(*) INTO @has_idx_productos_estado_padre_nombre
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'productos'
  AND INDEX_NAME = 'idx_productos_estado_padre_nombre';

SET @sql := IF(
  @has_productos = 1 AND @has_idx_productos_estado_padre_nombre = 0,
  'ALTER TABLE productos ADD INDEX idx_productos_estado_padre_nombre (estado, id_padre, nombre, id_producto)',
  'SELECT "skip: idx_productos_estado_padre_nombre"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @has_idx_productos_padre_estado
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'productos'
  AND INDEX_NAME = 'idx_productos_padre_estado';

SET @sql := IF(
  @has_productos = 1 AND @has_idx_productos_padre_estado = 0,
  'ALTER TABLE productos ADD INDEX idx_productos_padre_estado (id_padre, estado)',
  'SELECT "skip: idx_productos_padre_estado"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- producto_imagenes ----------
SELECT COUNT(*) INTO @has_producto_imagenes
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'producto_imagenes';

SELECT COUNT(*) INTO @has_idx_producto_imagenes_producto_orden
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'producto_imagenes'
  AND INDEX_NAME = 'idx_producto_imagenes_producto_orden';

SET @sql := IF(
  @has_producto_imagenes = 1 AND @has_idx_producto_imagenes_producto_orden = 0,
  'ALTER TABLE producto_imagenes ADD INDEX idx_producto_imagenes_producto_orden (id_producto, orden)',
  'SELECT "skip: idx_producto_imagenes_producto_orden"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- producto_categorias ----------
SELECT COUNT(*) INTO @has_producto_categorias
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'producto_categorias';

SELECT COUNT(*) INTO @has_idx_pc_categoria_producto
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'producto_categorias'
  AND INDEX_NAME = 'idx_pc_categoria_producto';

SET @sql := IF(
  @has_producto_categorias = 1 AND @has_idx_pc_categoria_producto = 0,
  'ALTER TABLE producto_categorias ADD INDEX idx_pc_categoria_producto (id_categoria, id_producto)',
  'SELECT "skip: idx_pc_categoria_producto"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @has_idx_pc_producto_categoria
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'producto_categorias'
  AND INDEX_NAME = 'idx_pc_producto_categoria';

SET @sql := IF(
  @has_producto_categorias = 1 AND @has_idx_pc_producto_categoria = 0,
  'ALTER TABLE producto_categorias ADD INDEX idx_pc_producto_categoria (id_producto, id_categoria)',
  'SELECT "skip: idx_pc_producto_categoria"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- categorias ----------
SELECT COUNT(*) INTO @has_categorias
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'categorias';

SELECT COUNT(*) INTO @has_idx_categorias_nombre
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'categorias'
  AND INDEX_NAME = 'idx_categorias_nombre';

SET @sql := IF(
  @has_categorias = 1 AND @has_idx_categorias_nombre = 0,
  'ALTER TABLE categorias ADD INDEX idx_categorias_nombre (nombre)',
  'SELECT "skip: idx_categorias_nombre"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- logs_actividad ----------
SELECT COUNT(*) INTO @has_logs_actividad
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'logs_actividad';

SELECT COUNT(*) INTO @has_idx_logs_actividad_tipo_fecha
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'logs_actividad'
  AND INDEX_NAME = 'idx_logs_actividad_tipo_fecha';

SET @sql := IF(
  @has_logs_actividad = 1 AND @has_idx_logs_actividad_tipo_fecha = 0,
  'ALTER TABLE logs_actividad ADD INDEX idx_logs_actividad_tipo_fecha (tipo_accion, fecha_creacion)',
  'SELECT "skip: idx_logs_actividad_tipo_fecha"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
