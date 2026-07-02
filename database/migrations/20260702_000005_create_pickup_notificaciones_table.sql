-- Migracion: crear modulo de notificaciones de pickup en sucursal
CREATE TABLE IF NOT EXISTS pickup_notificaciones (
  id_notificacion INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_pedido INT UNSIGNED NOT NULL,
  id_almacen INT UNSIGNED NOT NULL,
  id_cliente INT UNSIGNED DEFAULT NULL,
  estado ENUM('nueva','vista','atendida') NOT NULL DEFAULT 'nueva',
  mensaje VARCHAR(255) NOT NULL,
  fecha_estimacion_reabasto DATETIME DEFAULT NULL,
  fecha_vista DATETIME DEFAULT NULL,
  fecha_atendida DATETIME DEFAULT NULL,
  id_usuario_seguimiento INT UNSIGNED DEFAULT NULL,
  notas_seguimiento TEXT DEFAULT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_notificacion),
  UNIQUE KEY uq_pickup_notificacion_pedido (id_pedido),
  KEY idx_pickup_estado_almacen (estado, id_almacen),
  KEY idx_pickup_almacen_creado (id_almacen, creado_en),
  CONSTRAINT fk_pickup_notif_pedido FOREIGN KEY (id_pedido) REFERENCES pedidos(id_pedido) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_pickup_notif_almacen FOREIGN KEY (id_almacen) REFERENCES almacenes(id_almacen) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT fk_pickup_notif_cliente FOREIGN KEY (id_cliente) REFERENCES clientes(id_cliente) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_pickup_notif_usuario FOREIGN KEY (id_usuario_seguimiento) REFERENCES usuarios(id_usuario) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
