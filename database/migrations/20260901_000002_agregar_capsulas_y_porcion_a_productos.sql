-- Migracion: agregar capsulas_por_envase y porcion_capsulas a productos
--
-- Datos de REFERENCIA opcionales para el control de caducidades:
--   * capsulas_por_envase: permite capturar un lote "en capsulas" y convertir a
--     botes/piezas (cantidad_botes = capsulas_totales / capsulas_por_envase).
--   * porcion_capsulas: capsulas por toma; junto con lo anterior da el estimado
--     "rinde ~ capsulas_por_envase / porcion_capsulas dias por envase".
-- Ninguna es obligatoria para la alerta de caducidad (que trabaja en botes).
--
-- NOTA: la version anterior de esta migracion usaba "ADD COLUMN IF NOT EXISTS",
-- que es sintaxis de MariaDB -- Percona Server 8.4 / MySQL 8 (el VPS de produccion)
-- no la soporta y tira "You have an error in your SQL syntax". Se reescribe con el
-- patron idempotente ya usado en 20260905_120000_ampliar_alias_direccion_a_text_para_pii_cifrada.sql
-- (INFORMATION_SCHEMA + PREPARE/EXECUTE), portable entre MySQL y MariaDB.

SET @db := DATABASE();

SELECT COUNT(*) INTO @capsulas_existe
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'productos'
  AND COLUMN_NAME = 'capsulas_por_envase';

SET @sql := IF(
    @capsulas_existe > 0,
    'SELECT "skip: productos.capsulas_por_envase ya existe"',
    'ALTER TABLE `productos` ADD COLUMN `capsulas_por_envase` INT UNSIGNED DEFAULT NULL AFTER `unidad`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @porcion_existe
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'productos'
  AND COLUMN_NAME = 'porcion_capsulas';

SET @sql := IF(
    @porcion_existe > 0,
    'SELECT "skip: productos.porcion_capsulas ya existe"',
    'ALTER TABLE `productos` ADD COLUMN `porcion_capsulas` INT UNSIGNED DEFAULT NULL AFTER `capsulas_por_envase`'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
