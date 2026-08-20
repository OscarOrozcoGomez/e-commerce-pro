-- Migracion: configuracion editable desde el panel admin para el asistente de IA "Alex"
CREATE TABLE IF NOT EXISTS `ai_asistente_config` (
  `id_config` TINYINT UNSIGNED NOT NULL,
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `nombre_persona` VARCHAR(60) NOT NULL DEFAULT 'Alex',
  `tono_instrucciones` TEXT DEFAULT NULL,
  `promocion_vigente_texto` TEXT DEFAULT NULL,
  `politica_envio_texto` TEXT DEFAULT NULL,
  `politica_pago_texto` TEXT DEFAULT NULL,
  `mensaje_bienvenida` TEXT DEFAULT NULL,
  `modelo_llm` VARCHAR(40) NOT NULL DEFAULT 'deepseek-chat',
  `actualizado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_config`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

INSERT INTO `ai_asistente_config` (`id_config`, `activo`, `nombre_persona`)
VALUES (1, 1, 'Alex')
ON DUPLICATE KEY UPDATE `id_config` = `id_config`;
