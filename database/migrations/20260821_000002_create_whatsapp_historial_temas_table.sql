-- Migracion: temas/productos detectados por coincidencia de texto (no por IA) en los
-- mensajes de clientes, para nutrir a Alex con contexto por cliente sin gastar tokens.
-- Ver core/ai_assistant.php :: aiDetectTopicsInMessage() / aiUpsertHistorialTema().
CREATE TABLE IF NOT EXISTS `whatsapp_historial_temas` (
  `id_tema` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `wa_id` VARCHAR(20) NOT NULL,
  `tipo` ENUM('producto','tema_general') NOT NULL,
  `valor` VARCHAR(150) NOT NULL,
  `veces_mencionado` INT UNSIGNED NOT NULL DEFAULT 1,
  `primera_mencion` DATETIME NOT NULL,
  `ultima_mencion` DATETIME NOT NULL,
  PRIMARY KEY (`id_tema`),
  UNIQUE KEY `uq_wa_historial_temas_wa_tipo_valor` (`wa_id`, `tipo`, `valor`),
  KEY `idx_wa_historial_temas_valor` (`valor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
