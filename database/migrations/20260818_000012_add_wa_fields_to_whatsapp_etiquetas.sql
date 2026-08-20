-- Migracion: soporte para etiquetas nativas de WhatsApp Business sincronizadas desde el
-- telefono (App State Sync via el puente Node.js). id_etiqueta_wa queda NULL para las
-- etiquetas puramente internas (Cliente Nuevo, Preguntón, etc.) que no existen del lado
-- de WhatsApp -- WhatsApp Business NO permite crear etiquetas por API, solo asignar/quitar
-- las que ya existen en la app; por eso solo las etiquetas con id_etiqueta_wa se pueden
-- empujar de vuelta a WhatsApp.
ALTER TABLE `whatsapp_etiquetas`
  ADD COLUMN `id_etiqueta_wa` VARCHAR(50) DEFAULT NULL AFTER `id_etiqueta`,
  ADD UNIQUE KEY `uq_whatsapp_etiquetas_wa_id` (`id_etiqueta_wa`);
