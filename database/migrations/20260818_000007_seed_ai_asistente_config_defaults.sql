-- Migracion: agrega la variable de entorno configurable para la API key de DeepSeek
-- (en vez de un nombre fijo en el codigo) y siembra los valores por defecto del
-- asistente "Alex" (marca Be Life) si el admin todavia no ha personalizado nada
-- desde el panel. No pisa una configuracion ya editada manualmente.
ALTER TABLE `ai_asistente_config`
  ADD COLUMN `api_key_variable` VARCHAR(80) NOT NULL DEFAULT 'DEEPSEEK_AI_ASSISTANT' AFTER `modelo_llm`;

UPDATE `ai_asistente_config`
SET
  `api_key_variable` = 'DEEPSEEK_AI_ASSISTANT',
  `modelo_llm` = 'deepseek-chat',
  `temperatura` = 0.30
WHERE `id_config` = 1;

UPDATE `ai_asistente_config`
SET `prompt_sistema_override` = "Eres Alex, un ejecutivo de atención al cliente amable, conciso y profesional de la marca Be Life. Tu objetivo es resolver dudas sobre catálogo, precios y tomar pedidos. Si detectas una queja grave, una solicitud compleja o no tienes información suficiente en la base de datos para responder con certeza, debes incluir la bandera '[PASE_A_HUMANO]' en tu respuesta para transferir la conversación al equipo de soporte."
WHERE `id_config` = 1 AND (`prompt_sistema_override` IS NULL OR `prompt_sistema_override` = '');
