-- Migracion: "Articulo libre" -- vender en el POS productos que NO estan en el catalogo
-- (p. ej. de otras marcas que no se quieren exhibir) solo para llevar contabilidad.
-- Ver core/articulo_libre_utils.php. Idempotente.
--
-- 1) detalle_pedidos.marca_libre / descripcion_libre: cada linea guarda su propia marca y
--    descripcion (el costo y precio ya se guardan por linea en costo_unitario / precio_unitario).
-- 2) Un producto interno de sistema (codigo_barras 'SYS-ARTICULO-LIBRE', estado 'archivado',
--    sin lote) que solo cumple la llave foranea de detalle_pedidos.id_producto. Archivado =
--    no sale en catalogo, POS, Alex, feed ni recomendaciones.
-- 3) Permiso 'vender_articulo_libre'. Sin semilla por rol: el admin lo tiene siempre
--    (hasPermission) y a otros se les concede desde Roles y Permisos.

SET @db := DATABASE();

-- 1a) detalle_pedidos.marca_libre
SELECT COUNT(*) INTO @has_table FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'detalle_pedidos';
SELECT COUNT(*) INTO @has_column FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'detalle_pedidos' AND COLUMN_NAME = 'marca_libre';
SET @sql := IF(
    @has_table = 1 AND @has_column = 0,
    'ALTER TABLE detalle_pedidos ADD COLUMN marca_libre VARCHAR(120) NULL AFTER subtotal',
    'SELECT "skip: detalle_pedidos.marca_libre ya existe o tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 1b) detalle_pedidos.descripcion_libre
SELECT COUNT(*) INTO @has_column FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'detalle_pedidos' AND COLUMN_NAME = 'descripcion_libre';
SET @sql := IF(
    @has_table = 1 AND @has_column = 0,
    'ALTER TABLE detalle_pedidos ADD COLUMN descripcion_libre VARCHAR(255) NULL AFTER marca_libre',
    'SELECT "skip: detalle_pedidos.descripcion_libre ya existe o tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2) Producto interno de sistema
INSERT INTO productos (nombre, codigo_barras, descripcion, precio_costo, precio_venta, estado)
SELECT * FROM (
    SELECT 'Artículo libre (otras marcas)' AS nombre,
           'SYS-ARTICULO-LIBRE' AS codigo_barras,
           'Producto interno del sistema para vender en el POS artículos que no están en el catálogo. No editar ni activar.' AS descripcion,
           0.00 AS precio_costo,
           0.00 AS precio_venta,
           'archivado' AS estado
) AS nuevo
WHERE NOT EXISTS (SELECT 1 FROM productos p WHERE p.codigo_barras = 'SYS-ARTICULO-LIBRE');

-- Sin lote: nunca se le pide lote/caducidad.
SELECT COUNT(*) INTO @has_column FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'requiere_lote';
SET @sql := IF(
    @has_column = 1,
    'UPDATE productos SET requiere_lote = 0 WHERE codigo_barras = ''SYS-ARTICULO-LIBRE''',
    'SELECT "skip: productos.requiere_lote no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3) Permiso
INSERT INTO permisos (clave, nombre, descripcion, categoria, estado)
SELECT * FROM (
    SELECT 'vender_articulo_libre' AS clave,
           'Vender artículo libre' AS nombre,
           'Agregar en el POS artículos fuera del catálogo (otras marcas) capturando marca, descripción, costo y precio, y ver su reporte de ganancia' AS descripcion,
           'Ventas' AS categoria,
           'activo' AS estado
) AS nuevos
WHERE NOT EXISTS (SELECT 1 FROM permisos p WHERE p.clave = nuevos.clave);
