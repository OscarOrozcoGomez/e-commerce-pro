-- Migracion: simplificar sucursal_incentivos a esquema JSON
-- Se conservan: id_regla, activo, descuento_por_piezas_json, fecha_actualizacion.

ALTER TABLE sucursal_incentivos
  ADD COLUMN IF NOT EXISTS descuento_por_piezas_json JSON DEFAULT NULL AFTER activo;

UPDATE sucursal_incentivos
SET descuento_por_piezas_json = COALESCE(descuento_por_piezas_json, JSON_OBJECT())
WHERE id_regla = 1;

ALTER TABLE sucursal_incentivos
  DROP COLUMN IF EXISTS descuento_porcentaje,
  DROP COLUMN IF EXISTS descuento_fijo,
  DROP COLUMN IF EXISTS subtotal_minimo,
  DROP COLUMN IF EXISTS piezas_minimas,
  DROP COLUMN IF EXISTS tope_descuento,
  DROP COLUMN IF EXISTS mensaje_publico;
