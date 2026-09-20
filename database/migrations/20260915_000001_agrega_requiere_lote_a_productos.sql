-- Migracion: agrega requiere_lote a productos
--
-- Algunos productos no caducan (pastilleros, accesorios, etc.): no tiene
-- sentido pedirles lote/caducidad ni marcarlos como "faltante" en
-- Caducidades > Inconsistencias solo porque nadie les registro un lote.
-- Default 1 (todo producto existente sigue requiriendo lote como hasta hoy);
-- se desmarca a mano desde la ficha para los que no aplica.
--
-- Patron idempotente (information_schema + ALTER dinamico via PREPARE/EXECUTE):
-- "ADD COLUMN IF NOT EXISTS" es MariaDB-only y revienta el runner en Percona 8.4.

SET @db := DATABASE();

SET @needs := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'productos' AND COLUMN_NAME = 'requiere_lote');
SET @sql := IF(@needs,
    'ALTER TABLE productos ADD COLUMN requiere_lote TINYINT(1) NOT NULL DEFAULT 1 AFTER unidad',
    'SELECT "skip: productos.requiere_lote ya existe"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
