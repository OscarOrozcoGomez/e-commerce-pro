ALTER TABLE sucursal_incentivos
  ADD COLUMN IF NOT EXISTS descuento_por_piezas_json JSON DEFAULT NULL AFTER descuento_fijo;

UPDATE sucursal_incentivos
SET descuento_por_piezas_json = COALESCE(descuento_por_piezas_json, JSON_OBJECT())
WHERE id_regla = 1;