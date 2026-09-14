-- Migracion: URL del producto en mayoreo.blife.mx, para el llenado de carrito.
-- Idempotente (patron information_schema + PREPARE, compatible MariaDB y Percona 8.4).
--
-- scripts/mayoreo_llenar_carrito.mjs la usa como destino exacto; si esta vacia,
-- busca por nombre en el sitio y guarda aqui la URL que encontro (cache).

SET @col_existe := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'mayoreo_url'
);
SET @sql := IF(@col_existe = 0,
    'ALTER TABLE productos ADD COLUMN mayoreo_url VARCHAR(500) NULL AFTER sku',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
