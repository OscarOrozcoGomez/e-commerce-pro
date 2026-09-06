-- Migracion: lista editable de correos que reciben aviso cuando un lote cambia
-- de severidad de caducidad (ok -> planificar -> urgente -> critico -> caducado,
-- o vuelve a ok). Mismo patron que pedido_notificacion_correos.
CREATE TABLE IF NOT EXISTS caducidad_notificacion_correos (
  id_correo INT UNSIGNED NOT NULL AUTO_INCREMENT,
  correo VARCHAR(190) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_correo),
  UNIQUE KEY uq_caducidad_notificacion_correo (correo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
