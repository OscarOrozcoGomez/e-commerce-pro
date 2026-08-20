-- Migracion: al desplegar Alex a un ambiente nuevo, debe quedar APAGADO por default (el
-- interruptor del dashboard, ai_asistente_config.activo) hasta que un admin lo encienda
-- a proposito -- para no contestarle a clientes reales antes de terminar de conectar y
-- validar el puente de WhatsApp. No se toca la migracion original que sembro activo=1
-- (20260817_000005) porque ya esta aplicada en otros ambientes; esta es la correccion
-- aditiva de siempre.
--
-- Esto NO afecta nada mas: las migraciones siguen corriendo igual, el endpoint de
-- respaldo (api/whatsapp/import_history.php) y el cron de analisis del historial
-- (scripts/whatsapp_analizar_historial_cron.php) son independientes de este interruptor
-- a proposito -- solo el webhook en vivo y el cron de seguimiento de 24h lo respetan.
UPDATE `ai_asistente_config`
SET `activo` = 0
WHERE `id_config` = 1;
