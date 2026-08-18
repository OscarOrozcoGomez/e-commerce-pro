-- Catalogo de motivos de cancelacion + registro de cancelaciones de pedidos hechas por el cliente,
-- para dar seguimiento a por que los clientes deciden cancelar.
CREATE TABLE IF NOT EXISTS motivos_cancelacion_pedido (
  id_motivo INT UNSIGNED NOT NULL AUTO_INCREMENT,
  motivo VARCHAR(150) NOT NULL,
  requiere_detalle TINYINT(1) NOT NULL DEFAULT 0,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  orden SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_motivo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

INSERT INTO motivos_cancelacion_pedido (motivo, requiere_detalle, orden) VALUES
  ('Cambie de opinion / ya no lo quiero', 0, 10),
  ('Encontre un mejor precio en otro lugar', 0, 20),
  ('El tiempo de entrega es muy largo', 0, 30),
  ('Pedido duplicado o hecho por error', 0, 40),
  ('Quiero cambiar productos o cantidades', 0, 50),
  ('Problemas con el metodo de pago', 0, 60),
  ('Mis datos de entrega son incorrectos', 0, 70),
  ('Otro motivo', 1, 80);

CREATE TABLE IF NOT EXISTS pedido_cancelaciones (
  id_cancelacion INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_pedido INT UNSIGNED NOT NULL,
  id_motivo INT UNSIGNED DEFAULT NULL,
  motivo_detalle TEXT DEFAULT NULL,
  cancelado_por INT UNSIGNED NOT NULL,
  fecha_cancelacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_cancelacion),
  UNIQUE KEY uq_pedido_cancelacion_pedido (id_pedido),
  INDEX idx_pedido_cancelacion_motivo (id_motivo),
  CONSTRAINT fk_pedido_cancelacion_pedido FOREIGN KEY (id_pedido) REFERENCES pedidos (id_pedido) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_pedido_cancelacion_motivo FOREIGN KEY (id_motivo) REFERENCES motivos_cancelacion_pedido (id_motivo) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_pedido_cancelacion_usuario FOREIGN KEY (cancelado_por) REFERENCES usuarios (id_usuario) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
