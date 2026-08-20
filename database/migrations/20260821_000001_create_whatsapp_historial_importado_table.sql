-- Migracion: respaldo del historial completo de WhatsApp, importado UNA VEZ cuando el
-- puente Node.js/Baileys se conecta por primera vez (evento messaging-history.set).
-- Separada a proposito de whatsapp_mensajes: esa tabla la usa aiLoadConversationHistory()
-- para reconstruir el contexto real que se manda al LLM en cada turno; llenarla con miles
-- de mensajes historicos rompería ese armado. Esta tabla es solo respaldo/insumo de analisis.
CREATE TABLE IF NOT EXISTS `whatsapp_historial_importado` (
  `id_historial` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `wa_id` VARCHAR(20) NOT NULL,
  `nombre_perfil` VARCHAR(150) DEFAULT NULL,
  `mensaje` MEDIUMTEXT NOT NULL,
  `from_me` TINYINT(1) NOT NULL DEFAULT 0,
  `fecha_mensaje` DATETIME NOT NULL COMMENT 'Timestamp original del mensaje en WhatsApp',
  `lote_importacion` VARCHAR(40) DEFAULT NULL,
  `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_historial`),
  KEY `idx_wa_historial_wa_id_fecha` (`wa_id`, `fecha_mensaje`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
