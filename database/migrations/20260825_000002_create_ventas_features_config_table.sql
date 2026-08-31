-- Migracion: interruptores on/off (y config asociada en JSON) para las nuevas
-- iniciativas de ventas/marketing: atribucion de ventas, feed de catalogo,
-- programa de referidos, y cruce de stock con calendario de campanas.
-- Todas arrancan apagadas salvo las que son puro reporte interno sin riesgo
-- (atribucion_ventas y stock_calendario_campanas), siguiendo el mismo patron
-- de ai_asistente_config: una tabla chica, editable desde un panel admin,
-- sin necesitar deploy para prender/apagar.
CREATE TABLE IF NOT EXISTS `ventas_features_config` (
  `feature_key` VARCHAR(50) NOT NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 0,
  `config_json` TEXT DEFAULT NULL,
  `actualizado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`feature_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

INSERT INTO `ventas_features_config` (`feature_key`, `activo`, `config_json`) VALUES
  ('atribucion_ventas', 1, NULL),
  ('catalogo_feed', 0, NULL),
  ('programa_referidos', 0, '{"descuento_porcentaje": 10, "monto_minimo_pedido": 0}'),
  ('stock_calendario_campanas', 1, NULL)
ON DUPLICATE KEY UPDATE `feature_key` = `feature_key`;
