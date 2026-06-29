-- Agrega alias de perfil para clientes (no confundir con alias de direcciones).
ALTER TABLE clientes
    ADD COLUMN alias_perfil VARCHAR(80) NULL AFTER nombre;
