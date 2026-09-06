-- Migracion: crear tabla lotes_inventario (control de caducidades por lote)
--
-- Hasta ahora el sistema no modela lotes ni fechas de caducidad: el stock es un
-- escalar (inventario_almacen.cantidad_actual) por producto/almacen. Esta tabla
-- registra cada lote fisico que entra a bodega con su codigo, su fecha de
-- caducidad y su cantidad, para poder proyectar -- comparando contra la
-- velocidad de venta de los ultimos 90 dias -- si alcanzara a venderse antes de
-- caducar y alertar con anticipacion para ponerlo en oferta.
--
-- En esta fase los lotes NO se descuentan en la venta (eso toca api/ventas.php y
-- core/auth.php); cantidad_restante se ajusta a mano y la UI muestra el
-- descuadre contra inventario_almacen. Idempotente para despliegues repetidos.
CREATE TABLE IF NOT EXISTS lotes_inventario (
  id_lote INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_producto INT UNSIGNED NOT NULL,
  id_almacen INT UNSIGNED DEFAULT NULL,
  codigo_lote VARCHAR(120) NOT NULL,
  fecha_caducidad DATE NOT NULL,
  caducidad_aproximada TINYINT(1) NOT NULL DEFAULT 0,
  fecha_ingreso DATE NOT NULL,
  cantidad_inicial INT NOT NULL,
  cantidad_restante INT NOT NULL,
  costo_unitario DECIMAL(12,2) DEFAULT NULL,
  estado ENUM('activo','agotado','caducado','retirado') NOT NULL DEFAULT 'activo',
  en_oferta TINYINT(1) NOT NULL DEFAULT 0,
  alerta_atendida TINYINT(1) NOT NULL DEFAULT 0,
  foto_evidencia VARCHAR(255) DEFAULT NULL,
  id_usuario_seguimiento INT UNSIGNED DEFAULT NULL,
  notas_seguimiento VARCHAR(500) DEFAULT NULL,
  creado_por INT UNSIGNED DEFAULT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_lote),
  UNIQUE KEY uq_lote_producto_codigo (id_producto, codigo_lote),
  KEY idx_lote_caducidad (fecha_caducidad),
  KEY idx_lote_producto_estado (id_producto, estado),
  KEY idx_lote_almacen (id_almacen),
  CONSTRAINT fk_lote_producto FOREIGN KEY (id_producto) REFERENCES productos(id_producto) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_lote_almacen FOREIGN KEY (id_almacen) REFERENCES almacenes(id_almacen) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT fk_lote_usuario FOREIGN KEY (id_usuario_seguimiento) REFERENCES usuarios(id_usuario) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
