-- Migracion: respuestas de Alex "en vuelo" (generadas en vivo, esperando el retraso humano del
-- puente de 60-120s antes de salir a WhatsApp).
-- Idempotente (patron information_schema + PREPARE, compatible MariaDB y Percona 8.4).
--
-- esperando_confirmacion_envio = 1 mientras el puente no haya llamado a
-- api/whatsapp_confirmar_envio.php. Si en ese lapso el cliente escribe otra vez, el turno de ese
-- mensaje nuevo cancela la respuesta en vuelo (enviado_whatsapp = 0) y contesta todo junto en UN
-- solo mensaje, en vez de dos respuestas pegadas que se repiten (ver aiCancelarRespuestasEnVuelo()).

SET @col_existe := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'whatsapp_mensajes' AND COLUMN_NAME = 'esperando_confirmacion_envio'
);
SET @sql := IF(@col_existe = 0,
    'ALTER TABLE whatsapp_mensajes ADD COLUMN esperando_confirmacion_envio TINYINT(1) NOT NULL DEFAULT 0 AFTER enviado_whatsapp',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
