-- Migracion: agrega ultimo envio proactivo a ai asistente config
--
-- Timestamp del ultimo mensaje PROACTIVO real que mando Alex (seguimiento de 24h o
-- catch-up de horario, ver aiPuedeEnviarProactivoAhora()/aiRegistrarEnvioProactivo() en
-- core/ai_assistant.php). Nunca se manda mas de uno combinado por hora -- ver el comentario
-- junto a AI_PROACTIVO_INTERVALO_MIN_MINUTOS (incidente 2026-09-13: sin este tope, un
-- backlog grande podia sostener su maximo por corrida hora tras hora todo el dia).
SET @db := DATABASE();
SELECT COUNT(*) INTO @existe
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ai_asistente_config' AND COLUMN_NAME = 'ultimo_envio_proactivo_en';

SET @sql := IF(@existe > 0,
    'SELECT "skip: ai_asistente_config.ultimo_envio_proactivo_en ya existe"',
    'ALTER TABLE `ai_asistente_config` ADD COLUMN `ultimo_envio_proactivo_en` DATETIME NULL DEFAULT NULL');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
