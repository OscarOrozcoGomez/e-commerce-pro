-- Migracion: crear tabla detalle_pedido_lotes
--
-- Hasta ahora una venta descuenta inventario_almacen pero NUNCA lotes_inventario:
-- los lotes se acumulan a mano y solo se ajustan manualmente (ver comentario en
-- 20260901_000001_crear_tabla_lotes_inventario.sql). Esta tabla registra, por cada
-- linea de pedido (detalle_pedidos), de que lote(s) salio la mercancia y cuanto,
-- para poder:
--   1) descontar lotes en FEFO en el momento de la venta (core/lote_caducidad_utils.php,
--      loteDescontarVentaFEFO) y marcarlos 'agotado' solos cuando cantidad_restante llega
--      a 0 -- asi salen de la vista de Caducidades sin intervencion manual,
--   2) regresar exactamente esas unidades a sus lotes de origen cuando una venta se
--      cancela o un producto no se entrega (loteRegresarDetalleALotes), reactivando el
--      lote si hace falta,
--   3) calcular costo real por lote vendido (COGS) en vez del precio_costo generico.
--
-- Es "best effort": si un producto no tiene lotes registrados, o no alcanzan, la venta
-- no se bloquea por esto -- simplemente no queda registro aqui para esas unidades.
-- Idempotente para despliegues repetidos.
CREATE TABLE IF NOT EXISTS detalle_pedido_lotes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_detalle INT UNSIGNED NOT NULL,
  id_lote INT UNSIGNED NOT NULL,
  cantidad INT NOT NULL,
  costo_unitario DECIMAL(12,2) DEFAULT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_detalle_pedido_lotes_detalle (id_detalle),
  KEY idx_detalle_pedido_lotes_lote (id_lote),
  CONSTRAINT fk_detalle_pedido_lotes_detalle FOREIGN KEY (id_detalle) REFERENCES detalle_pedidos(id_detalle) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_detalle_pedido_lotes_lote FOREIGN KEY (id_lote) REFERENCES lotes_inventario(id_lote) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
