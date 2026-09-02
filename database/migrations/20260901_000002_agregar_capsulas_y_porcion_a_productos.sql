-- Migracion: agregar capsulas_por_envase y porcion_capsulas a productos
--
-- Datos de REFERENCIA opcionales para el control de caducidades:
--   * capsulas_por_envase: permite capturar un lote "en capsulas" y convertir a
--     botes/piezas (cantidad_botes = capsulas_totales / capsulas_por_envase).
--   * porcion_capsulas: capsulas por toma; junto con lo anterior da el estimado
--     "rinde ~ capsulas_por_envase / porcion_capsulas dias por envase".
-- Ninguna es obligatoria para la alerta de caducidad (que trabaja en botes).
--
-- ADD COLUMN IF NOT EXISTS: nativo en MariaDB y MySQL 8.0.29+. El job e2e de CI
-- corre sobre mariadb:10.4, asi que es seguro. Idempotente.
ALTER TABLE productos
    ADD COLUMN IF NOT EXISTS capsulas_por_envase INT UNSIGNED DEFAULT NULL AFTER unidad,
    ADD COLUMN IF NOT EXISTS porcion_capsulas INT UNSIGNED DEFAULT NULL AFTER capsulas_por_envase;
