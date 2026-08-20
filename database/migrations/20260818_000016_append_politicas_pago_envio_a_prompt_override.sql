-- El prompt_sistema_override activo en ai_asistente_config reemplaza por completo el
-- bloque compuesto (persona/promocion/politicas/tono) de aiBuildSystemPrompt() -- ver
-- comentario en esa funcion: solo las reglas de seguridad no negociables se agregan
-- siempre, sin importar el override. Por eso politica_envio_texto/politica_pago_texto
-- (sembrados en 20260818_000015) nunca se leian mientras hubiera un override activo.
-- Se agregan aqui directo dentro del override para que Alex deje de improvisar
-- metodos de pago/envio que no son reales.

UPDATE `ai_asistente_config`
SET `prompt_sistema_override` = CONCAT(
    `prompt_sistema_override`,
    '\n\nPolitica de pago: Solo pago contra entrega -- efectivo o transferencia/deposito a la CLABE, que el repartidor proporciona el dia de la entrega. Nunca pidas ni menciones pago por adelantado.',
    '\n\nPolitica de envio: Al confirmar el pedido se revisa el inventario; si el producto esta disponible se aparta y se agenda la entrega para el siguiente dia habil.'
)
WHERE `id_config` = 1
  AND `prompt_sistema_override` IS NOT NULL
  AND `prompt_sistema_override` <> ''
  AND `prompt_sistema_override` NOT LIKE '%CLABE%';
