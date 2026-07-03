-- Migracion: agregar telefono de contacto para sucursales (almacenes)
ALTER TABLE almacenes
  ADD COLUMN IF NOT EXISTS telefono VARCHAR(60) DEFAULT NULL AFTER ubicacion;
