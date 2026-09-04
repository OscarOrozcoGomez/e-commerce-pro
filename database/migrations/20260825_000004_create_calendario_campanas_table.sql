-- Migracion: calendario simple de campanas de marketing (fecha de inicio/fin,
-- canal, productos destacados), para cruzarlo contra la prediccion de
-- inventario y avisar si un producto se va a agotar antes de que termine la
-- campana que lo esta anunciando.
CREATE TABLE IF NOT EXISTS `calendario_campanas` (
  `id_campana` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(150) NOT NULL,
  `canal` VARCHAR(30) NOT NULL DEFAULT 'otro',
  `fecha_inicio` DATE NOT NULL,
  `fecha_fin` DATE NOT NULL,
  `productos_destacados` TEXT DEFAULT NULL COMMENT 'IDs de productos separados por coma',
  `notas` TEXT DEFAULT NULL,
  `id_usuario_creador` INT UNSIGNED DEFAULT NULL,
  `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_campana`),
  KEY `idx_calendario_campanas_fechas` (`fecha_inicio`, `fecha_fin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
