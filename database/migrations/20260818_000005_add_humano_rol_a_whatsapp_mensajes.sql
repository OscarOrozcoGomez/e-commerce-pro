-- Migracion: agrega el rol "humano" al historial de whatsapp_mensajes, para registrar
-- mensajes que un asesor/repartidor mando manualmente desde la app de WhatsApp (fromMe),
-- sin pasar por el asistente de IA.
ALTER TABLE `whatsapp_mensajes`
  MODIFY COLUMN `rol` ENUM('user','assistant','tool','system','humano') NOT NULL;
