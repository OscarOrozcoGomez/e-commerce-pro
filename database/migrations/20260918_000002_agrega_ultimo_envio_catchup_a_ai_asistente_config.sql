-- Migracion: agrega ultimo envio catchup a ai asistente config
--
-- Timestamp del ultimo catch-up de horario real que mando Alex (contestar con retraso algo
-- que el cliente YA escribio mientras Alex estaba callado por politica, ver
-- aiPuedeResponderCatchupAhora()/aiRegistrarEnvioCatchup() en core/ai_assistant.php).
-- Cupo INDEPENDIENTE del seguimiento de 24h (ultimo_envio_proactivo_en) desde 2026-09-18:
-- antes ambos compartian el mismo tope de 1/hora, lo que dejaba a un segundo cliente nuevo
-- de madrugada esperando hasta 2 horas su primera respuesta. Ver el comentario junto a
-- AI_CATCHUP_INTERVALO_MIN_MINUTOS.
SET @db := DATABASE();
SELECT COUNT(*) INTO @existe
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'ai_asistente_config' AND COLUMN_NAME = 'ultimo_envio_catchup_en';

SET @sql := IF(@existe > 0,
    'SELECT "skip: ai_asistente_config.ultimo_envio_catchup_en ya existe"',
    'ALTER TABLE `ai_asistente_config` ADD COLUMN `ultimo_envio_catchup_en` DATETIME NULL DEFAULT NULL');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
