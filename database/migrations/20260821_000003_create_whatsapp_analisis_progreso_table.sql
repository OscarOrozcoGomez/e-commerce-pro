-- Migracion: marcador de progreso (singleton, mismo patron que ai_asistente_config) para
-- que scripts/whatsapp_analizar_historial_cron.php procese solo mensajes nuevos en cada
-- corrida, en vez de reanalizar todo el historial cada vez.
CREATE TABLE IF NOT EXISTS `whatsapp_analisis_progreso` (
  `id_progreso` TINYINT UNSIGNED NOT NULL,
  `ultimo_id_historial_procesado` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `ultimo_id_mensaje_procesado` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `actualizado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_progreso`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

INSERT INTO `whatsapp_analisis_progreso` (`id_progreso`, `ultimo_id_historial_procesado`, `ultimo_id_mensaje_procesado`)
VALUES (1, 0, 0)
ON DUPLICATE KEY UPDATE `id_progreso` = `id_progreso`;
