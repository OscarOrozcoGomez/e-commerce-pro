-- Migracion: registro de fallas/incidentes del asistente de IA para diagnostico y
-- seguimiento desde el panel admin (tools que fallan, DeepSeek caido, datos incompletos,
-- pases a humano por incertidumbre).
CREATE TABLE IF NOT EXISTS `ai_errores_diagnostico` (
  `id_error` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_conversacion` INT UNSIGNED DEFAULT NULL,
  `tipo_error` VARCHAR(60) NOT NULL,
  `mensaje_usuario` TEXT DEFAULT NULL COMMENT 'Lo que el cliente escribio cuando ocurrio el problema',
  `contexto_error` MEDIUMTEXT DEFAULT NULL COMMENT 'JSON con detalles tecnicos (tool, argumentos, excepcion, etc.)',
  `resuelto` TINYINT(1) NOT NULL DEFAULT 0,
  `fecha_creacion` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_error`),
  KEY `idx_ai_errores_conversacion` (`id_conversacion`),
  KEY `idx_ai_errores_resuelto` (`resuelto`),
  CONSTRAINT `fk_ai_errores_conversacion` FOREIGN KEY (`id_conversacion`) REFERENCES `whatsapp_conversaciones` (`id_conversacion`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
