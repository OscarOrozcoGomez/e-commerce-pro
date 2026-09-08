-- Migracion: corrige politica_envio_texto/politica_pago_texto -- solo si siguen en uno
-- de los valores conocidos sembrados por 20260818_000017 (o su version ya corregida de
-- "Be Life" a "Blife" por 20260907_000001), para NO pisar un texto que el admin ya haya
-- personalizado a mano desde el panel.
--
-- Dos problemas reales encontrados:
--   1. politica_envio_texto decia "Entregamos UNICAMENTE dentro de la Zona Metropolitana
--      de Guadalajara" -- ya no es cierto desde que existe el cargo de envio foraneo
--      ($40 MXN fuera de la ZMG, ver aiCalcularCargoEnvio()/delivery_zone_utils.php):
--      SI se entrega fuera, con costo. Tambien decia "si necesitas otro dia, con gusto
--      revisamos disponibilidad" para los dias de entrega, lo cual contradice la
--      politica real: solo se entrega miercoles y sabado, cualquier otro dia se
--      consulta con el equipo (no se le promete disponibilidad al cliente).
--   2. politica_pago_texto solo mencionaba efectivo, mientras el negocio SI acepta
--      efectivo o transferencia (nunca tarjeta) -- confirmado por el usuario.

UPDATE `ai_asistente_config`
SET `politica_envio_texto` = 'Entregamos a domicilio contra entrega. Dentro de la Zona Metropolitana de Guadalajara el envio es gratis; fuera de la ZMG el costo es $40 MXN, o gratis si tu pedido incluye 2 o mas productos Blife distintos. Hacemos entregas los dias miercoles y sabado; si necesitas otro dia, lo consultamos con el equipo.'
WHERE `id_config` = 1
  AND `politica_envio_texto` IN (
    'Entregamos unicamente dentro de la Zona Metropolitana de Guadalajara, contra entrega. Costo de envio: $40 MXN; si tu pedido incluye 2 o mas productos Be Life, el envio es gratis. Entregamos los dias miercoles y sabado despues del mediodia; si necesitas otro dia, con gusto revisamos disponibilidad.',
    'Entregamos unicamente dentro de la Zona Metropolitana de Guadalajara, contra entrega. Costo de envio: $40 MXN; si tu pedido incluye 2 o mas productos Blife, el envio es gratis. Entregamos los dias miercoles y sabado despues del mediodia; si necesitas otro dia, con gusto revisamos disponibilidad.'
  );

UPDATE `ai_asistente_config`
SET `politica_pago_texto` = 'Pago contra entrega: efectivo o transferencia/deposito. No manejamos pago con tarjeta ni pago por adelantado.'
WHERE `id_config` = 1
  AND `politica_pago_texto` = 'Pago estrictamente en efectivo al momento de la entrega. No manejamos pagos por adelantado ni otros metodos.';
