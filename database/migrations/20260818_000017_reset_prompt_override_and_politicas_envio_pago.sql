-- El prompt_sistema_override ya solo repetia contenido que aiBuildSystemPrompt() agrega
-- SIEMPRE de todos modos (persona basica, bandera [PASE_A_HUMANO] -- ver "Manejo de
-- incertidumbre", siempre presente sin importar el override). Seguir parchando el
-- override a punta de CONCAT (20260818_000015/000016) ya no escala con una politica de
-- envio/pago mas larga y especifica. Se limpia el override para que vuelva a activarse
-- el prompt compuesto (persona + Flujo de atencion + promocion/politicas/tono desde
-- ai_asistente_config), que es el mecanismo pensado para que un admin edite esto sin
-- tocar migraciones. Las politicas de envio/pago se actualizan con los terminos reales
-- del negocio: cobertura solo Zona Metropolitana de Guadalajara, $40 de envio (gratis
-- con 2+ productos), entregas miercoles y sabado por la tarde, pago solo en efectivo
-- contra entrega.

UPDATE `ai_asistente_config`
SET `prompt_sistema_override` = NULL
WHERE `id_config` = 1;

UPDATE `ai_asistente_config`
SET `politica_envio_texto` = 'Entregamos unicamente dentro de la Zona Metropolitana de Guadalajara, contra entrega. Costo de envio: $40 MXN; si tu pedido incluye 2 o mas productos Be Life, el envio es gratis. Entregamos los dias miercoles y sabado despues del mediodia; si necesitas otro dia, con gusto revisamos disponibilidad.',
    `politica_pago_texto` = 'Pago estrictamente en efectivo al momento de la entrega. No manejamos pagos por adelantado ni otros metodos.'
WHERE `id_config` = 1;
