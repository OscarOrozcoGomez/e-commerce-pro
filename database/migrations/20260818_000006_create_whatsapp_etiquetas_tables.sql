-- Migracion: sistema de etiquetas para conversaciones/clientes de WhatsApp.
CREATE TABLE IF NOT EXISTS `whatsapp_etiquetas` (
  `id_etiqueta` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(60) NOT NULL,
  `color` VARCHAR(20) NOT NULL DEFAULT 'grey',
  `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_etiqueta`),
  UNIQUE KEY `uq_whatsapp_etiquetas_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE IF NOT EXISTS `whatsapp_conversacion_etiquetas` (
  `id_conversacion` INT UNSIGNED NOT NULL,
  `id_etiqueta` INT UNSIGNED NOT NULL,
  `asignado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_conversacion`, `id_etiqueta`),
  CONSTRAINT `fk_wa_conv_etiquetas_conversacion` FOREIGN KEY (`id_conversacion`) REFERENCES `whatsapp_conversaciones` (`id_conversacion`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_wa_conv_etiquetas_etiqueta` FOREIGN KEY (`id_etiqueta`) REFERENCES `whatsapp_etiquetas` (`id_etiqueta`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

INSERT INTO `whatsapp_etiquetas` (`nombre`, `color`) VALUES
  ('Cliente Nuevo', 'blue'),
  ('Entrega Pendiente', 'orange'),
  ('Cliente Frecuente', 'green'),
  ('Mayoreo', 'purple')
ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`);
