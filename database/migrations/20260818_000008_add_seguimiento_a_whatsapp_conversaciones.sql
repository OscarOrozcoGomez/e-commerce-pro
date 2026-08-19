-- Migracion: marca de cuando se envio el seguimiento automatico de 24h por inactividad.
-- NULL = sin seguimiento enviado todavia; con fecha = esperando respuesta del cliente.
ALTER TABLE `whatsapp_conversaciones`
  ADD COLUMN `seguimiento_enviado_en` TIMESTAMP NULL DEFAULT NULL AFTER `ultimo_mensaje_en`;
