-- Migracion: conversaciones de WhatsApp atendidas por el asistente de IA "Alex"
CREATE TABLE IF NOT EXISTS `whatsapp_conversaciones` (
  `id_conversacion` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `wa_id` VARCHAR(20) NOT NULL COMMENT 'Numero de WhatsApp del cliente (identidad de la conversacion, no hay login)',
  `id_cliente` INT UNSIGNED DEFAULT NULL,
  `nombre_perfil` VARCHAR(120) DEFAULT NULL,
  `estado_bot` ENUM('activo','pausado','cerrado') NOT NULL DEFAULT 'activo',
  `asignado_a` INT UNSIGNED DEFAULT NULL,
  `motivo_transferencia` VARCHAR(255) DEFAULT NULL,
  `ultimo_mensaje_en` TIMESTAMP NULL DEFAULT NULL,
  `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_conversacion`),
  UNIQUE KEY `uq_whatsapp_conv_wa_id` (`wa_id`),
  KEY `idx_whatsapp_conv_estado` (`estado_bot`),
  CONSTRAINT `fk_whatsapp_conv_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_whatsapp_conv_asignado` FOREIGN KEY (`asignado_a`) REFERENCES `usuarios` (`id_usuario`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
