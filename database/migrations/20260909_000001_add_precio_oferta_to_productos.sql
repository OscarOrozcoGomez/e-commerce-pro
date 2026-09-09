-- Migracion: add precio_oferta to productos
--
-- Precio de venta rebajado para los productos que estan en la categoria "oferta"
-- (proximos a caducar, ver views/caducidades.php). Regla general del negocio:
-- precio_oferta = precio_costo + 50. La columna guarda un override manual; si queda
-- NULL/0, el catalogo calcula costo+50 al vuelo (ver core/oferta_pricing.php).
--
-- Mismo patron idempotente que 20260629_000004: check a information_schema + ALTER
-- dinamico via PREPARE/EXECUTE. "ADD COLUMN IF NOT EXISTS" es MariaDB-only y revienta
-- el runner en MySQL/Percona 8.4 (prod), bloqueando todas las migraciones siguientes.

SET @db := DATABASE();

-- productos.precio_oferta
SET @needs := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'precio_oferta');
SET @sql := IF(@needs,
    'ALTER TABLE productos ADD COLUMN precio_oferta DECIMAL(12,2) DEFAULT NULL AFTER precio_comparacion',
    'SELECT "skip: productos.precio_oferta ya existe"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
