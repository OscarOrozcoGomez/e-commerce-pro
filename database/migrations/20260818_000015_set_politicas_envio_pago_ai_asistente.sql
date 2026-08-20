-- Siembra las politicas reales de envio y pago del negocio en ai_asistente_config,
-- para que Alex las use tal cual en vez de improvisar (encontrado en pruebas: sin
-- este texto, el LLM afirmaba metodos de pago no confirmados). No pisa un texto
-- que el admin ya haya personalizado desde el panel.

UPDATE `ai_asistente_config`
SET `politica_envio_texto` = "Al confirmar tu pedido revisamos el inventario; si el producto esta disponible, lo apartamos y agendamos la entrega para el siguiente dia habil."
WHERE `id_config` = 1 AND (`politica_envio_texto` IS NULL OR `politica_envio_texto` = '');

UPDATE `ai_asistente_config`
SET `politica_pago_texto` = "Solo pago contra entrega: efectivo o transferencia/deposito a la CLABE, que el repartidor proporciona el dia de la entrega. Nunca pedimos pago por adelantado."
WHERE `id_config` = 1 AND (`politica_pago_texto` IS NULL OR `politica_pago_texto` = '');
