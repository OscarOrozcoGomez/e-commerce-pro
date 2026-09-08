-- Sucursal "dueña" de cada cliente. Hasta ahora `clientes` no tenía ninguna
-- referencia a sucursal, así que cualquier encargado veía (y editaba) los datos
-- PII de TODOS los clientes de TODAS las sucursales. Con esta columna:
--   - admin: sigue viendo todos, incluidos los NULL (registros del sitio web).
--   - encargado/vendedor: solo los de su propia sucursal.
-- Backfill: se toma la sucursal del primer pedido de cada cliente (misma heurística
-- que ya usaba manage_customers.php para mostrar "sucursal_origen"). Los clientes sin
-- pedidos quedan en NULL -> visibles solo para admin hasta que se les asigne una.
-- Idempotente.

SET @db := DATABASE();

SELECT COUNT(*) INTO @has_clientes
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clientes';

SELECT COUNT(*) INTO @has_id_almacen
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clientes' AND COLUMN_NAME = 'id_almacen';

SET @sql := IF(
    @has_clientes = 1 AND @has_id_almacen = 0,
    "ALTER TABLE clientes ADD COLUMN id_almacen INT UNSIGNED DEFAULT NULL AFTER telefono",
    'SELECT "skip: clientes.id_almacen ya existe o tabla no existe"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Índice para el filtro por sucursal en cada carga de lista.
SELECT COUNT(*) INTO @has_idx
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clientes' AND INDEX_NAME = 'idx_clientes_almacen';

SET @sql := IF(
    @has_idx = 0,
    "ALTER TABLE clientes ADD INDEX idx_clientes_almacen (id_almacen)",
    'SELECT "skip: idx_clientes_almacen ya existe"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Llave foránea a almacenes (si la tabla existe y aún no está puesta).
SELECT COUNT(*) INTO @has_almacenes
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'almacenes';

SELECT COUNT(*) INTO @has_fk
FROM information_schema.TABLE_CONSTRAINTS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'clientes' AND CONSTRAINT_NAME = 'fk_clientes_almacen';

SET @sql := IF(
    @has_almacenes = 1 AND @has_fk = 0,
    "ALTER TABLE clientes ADD CONSTRAINT fk_clientes_almacen FOREIGN KEY (id_almacen) REFERENCES almacenes (id_almacen) ON DELETE SET NULL ON UPDATE CASCADE",
    'SELECT "skip: fk_clientes_almacen ya existe o falta tabla almacenes"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill: sucursal del primer pedido de cada cliente que aún no tenga sucursal.
SELECT COUNT(*) INTO @has_pedidos
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'pedidos';

SET @sql := IF(
    @has_pedidos = 1,
    "UPDATE clientes c
        SET c.id_almacen = (
            SELECT p.id_almacen
            FROM pedidos p
            WHERE p.id_cliente = c.id_cliente AND p.id_almacen IS NOT NULL
            ORDER BY p.id_pedido ASC
            LIMIT 1
        )
     WHERE c.id_almacen IS NULL",
    'SELECT "skip: no hay tabla pedidos para backfill"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
