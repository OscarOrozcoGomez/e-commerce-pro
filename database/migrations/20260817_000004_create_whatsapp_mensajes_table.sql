-- Migracion: historial de mensajes de WhatsApp (transcripcion + log de function calling con el LLM)
CREATE TABLE IF NOT EXISTS `whatsapp_mensajes` (
  `id_mensaje` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_conversacion` INT UNSIGNED NOT NULL,
  `wa_message_id` VARCHAR(100) DEFAULT NULL COMMENT 'ID de Meta para mensajes entrantes; usado para deduplicar reintentos del webhook',
  `rol` ENUM('user','assistant','tool','system') NOT NULL,
  `contenido` MEDIUMTEXT DEFAULT NULL,
  `tool_calls_json` MEDIUMTEXT DEFAULT NULL COMMENT 'tool_calls solicitados por el asistente, en el turno rol=assistant',
  `tool_call_id` VARCHAR(100) DEFAULT NULL COMMENT 'Referencia al tool_calls[].id que responde, en el turno rol=tool',
  `tool_name` VARCHAR(60) DEFAULT NULL,
  `enviado_whatsapp` TINYINT(1) NOT NULL DEFAULT 0,
  `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_mensaje`),
  UNIQUE KEY `uq_whatsapp_msg_wa_message_id` (`wa_message_id`),
  KEY `idx_whatsapp_msg_conv_fecha` (`id_conversacion`, `creado_en`),
  CONSTRAINT `fk_whatsapp_msg_conversacion` FOREIGN KEY (`id_conversacion`) REFERENCES `whatsapp_conversaciones` (`id_conversacion`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
