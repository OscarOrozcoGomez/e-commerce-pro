-- Migracion: atribucion de marketing "last-touch" congelada en el pedido al
-- momento de crearse (no se recalcula despues), para poder reportar ventas
-- reales por plataforma/campana en vez de solo trafico. Se llenan copiando el
-- ultimo valor conocido de logs_actividad para el mismo visitor_id.
ALTER TABLE `pedidos`
  ADD COLUMN `visitor_id` CHAR(32) DEFAULT NULL,
  ADD COLUMN `plataforma` VARCHAR(30) DEFAULT NULL,
  ADD COLUMN `utm_source` VARCHAR(100) DEFAULT NULL,
  ADD COLUMN `utm_campaign` VARCHAR(150) DEFAULT NULL,
  ADD KEY `idx_pedidos_plataforma` (`plataforma`);
