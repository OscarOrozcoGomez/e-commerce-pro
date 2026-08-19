-- Migracion: reglas de aprendizaje (few-shot) que el admin crea al corregir a Alex desde
-- el panel de diagnostico. Se inyectan como ejemplos en el prompt de sistema.
CREATE TABLE IF NOT EXISTS `ai_reglas_aprendizaje` (
  `id_regla` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `contexto_o_pregunta` TEXT NOT NULL,
  `respuesta_o_accion_esperada` TEXT NOT NULL,
  `etiqueta_sugerida` VARCHAR(60) DEFAULT NULL,
  `activa` TINYINT(1) NOT NULL DEFAULT 1,
  `fecha_creacion` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_regla`),
  KEY `idx_ai_reglas_activa` (`activa`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
