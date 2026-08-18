-- Migracion: lista editable de correos que reciben aviso al entrar un pedido web nuevo
CREATE TABLE IF NOT EXISTS pedido_notificacion_correos (
  id_correo INT UNSIGNED NOT NULL AUTO_INCREMENT,
  correo VARCHAR(190) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_correo),
  UNIQUE KEY uq_pedido_notificacion_correo (correo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
