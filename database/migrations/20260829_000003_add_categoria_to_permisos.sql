-- Migracion: columna `categoria` en permisos para agrupar la matriz en el panel
-- Objetivo:
-- 1) La pantalla Roles y Permisos agrupa los switches por categoria (Ventas,
--    Inventario, Entregas, Catalogo, Administracion).
-- 2) Backfill de las claves conocidas; las nuevas se crearan ya con categoria.
-- 3) Ser idempotente (solo rellena las filas sin categoria).

SET @db := DATABASE();

SELECT COUNT(*) INTO @has_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'permisos';

SELECT COUNT(*) INTO @has_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'permisos'
  AND COLUMN_NAME = 'categoria';

SET @sql := IF(
    @has_table = 1 AND @has_column = 0,
    'ALTER TABLE permisos ADD COLUMN categoria VARCHAR(50) NULL AFTER descripcion',
    'SELECT "skip: permisos.categoria ya existe o la tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

UPDATE permisos SET categoria = 'Ventas'
WHERE (categoria IS NULL OR categoria = '') AND clave IN ('venta', 'realizar_ventas');

UPDATE permisos SET categoria = 'Inventario'
WHERE (categoria IS NULL OR categoria = '') AND clave IN ('inventario', 'transferir_stock', 'gestionar_productos');

UPDATE permisos SET categoria = 'Entregas'
WHERE (categoria IS NULL OR categoria = '') AND clave IN ('ver_entregas');

UPDATE permisos SET categoria = 'Catalogo'
WHERE (categoria IS NULL OR categoria = '') AND clave IN ('ver_catalogo', 'agregar_carrito', 'apartar_productos');

UPDATE permisos SET categoria = 'Administracion'
WHERE (categoria IS NULL OR categoria = '') AND clave IN ('configurar_usuarios', 'gestionar_usuarios', 'gestionar_clientes', 'gestionar_blogs', 'ver_reportes', 'ver_dashboard');

-- Cualquier clave no contemplada arriba queda en "Otros" para que aparezca en la UI.
UPDATE permisos SET categoria = 'Otros'
WHERE categoria IS NULL OR categoria = '';
