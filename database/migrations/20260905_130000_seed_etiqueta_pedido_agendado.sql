-- Etiqueta "Pedido Agendado".
--
-- La aplica el SISTEMA automaticamente cuando Alex agenda una venta con exito
-- (aiToolAgendarVenta, tras un dbCreatePublicOrder correcto). Alex NO puede
-- asignarla por su cuenta -- de hecho ni la ve en su lista de etiquetas
-- disponibles. Sirve como marcador confiable en el dashboard de que
-- conversaciones terminaron en un pedido real, independiente de las etiquetas
-- nativas de WhatsApp Business ("New order"/"New customer"), que las pone la
-- app de WhatsApp en el celular y no este sistema.
--
-- Idempotente: whatsapp_etiquetas.nombre tiene UNIQUE KEY.

INSERT INTO `whatsapp_etiquetas` (`nombre`, `color`)
VALUES ('Pedido Agendado', 'teal')
ON DUPLICATE KEY UPDATE `nombre` = `nombre`;
