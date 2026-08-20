-- Migracion: permite ajustar la temperatura de DeepSeek y, opcionalmente, reemplazar
-- por completo el prompt de sistema compuesto (persona/tono/flujo) por uno redactado
-- a mano desde el panel admin. Si prompt_sistema_override esta vacio, se sigue usando
-- el prompt compuesto de siempre.
ALTER TABLE `ai_asistente_config`
  ADD COLUMN `temperatura` DECIMAL(3,2) NOT NULL DEFAULT 0.30 AFTER `modelo_llm`,
  ADD COLUMN `prompt_sistema_override` MEDIUMTEXT DEFAULT NULL AFTER `temperatura`;
