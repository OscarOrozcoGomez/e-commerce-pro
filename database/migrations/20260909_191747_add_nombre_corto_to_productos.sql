-- Migracion: add nombre_corto to productos
--
-- "Nombre corto" / nombre de la etiqueta del pomo (ej. "3 Mag Blend", "Clarity
-- Platinum"). El equipo conoce los productos por ese nombre, no por el nombre largo
-- de marketing que trae B-Life ("Mezcla de Magnesios | Citrato, Oxido y Gluconato...").
-- Se usa para buscar el producto en el listado de Gestionar Productos y en el
-- buscador de Ventas. Lo autollena la sincronizacion con B-Life (prefijo del
-- body_html antes de "B Life(R)") y se puede editar a mano en la ficha.
--
-- Patron idempotente (information_schema + ALTER dinamico via PREPARE/EXECUTE):
-- "ADD COLUMN IF NOT EXISTS" es MariaDB-only y revienta el runner en Percona 8.4.

SET @db := DATABASE();

SET @needs := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'nombre_corto');
SET @sql := IF(@needs,
    'ALTER TABLE productos ADD COLUMN nombre_corto VARCHAR(120) DEFAULT NULL AFTER nombre_variante',
    'SELECT "skip: productos.nombre_corto ya existe"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
