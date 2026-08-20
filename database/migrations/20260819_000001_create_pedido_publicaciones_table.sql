-- Migracion: registro de publicaciones (Facebook/WhatsApp) generadas al entregar un pedido
CREATE TABLE IF NOT EXISTS pedido_publicaciones (
  id_publicacion INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_pedido INT NOT NULL,
  id_repartidor INT NOT NULL,
  colonia_detectada VARCHAR(150) NULL,
  texto TEXT NOT NULL,
  ruta_foto VARCHAR(255) NOT NULL,
  publicado_facebook TINYINT(1) NOT NULL DEFAULT 0,
  facebook_post_id VARCHAR(100) NULL,
  facebook_error TEXT NULL,
  compartido_manual TINYINT(1) NOT NULL DEFAULT 0,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_publicacion),
  KEY idx_pedido_publicaciones_pedido (id_pedido)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
