-- Migracion: corrige el nombre de la marca en textos guardados por el admin/seeds antiguos.
--
-- Nuestra unica marca es "Blife" -- varios seeds anteriores (y el prompt override por
-- defecto) escribieron "Be Life" (dos palabras) por error. Esto hacia que Alex se
-- presentara a si mismo con el nombre equivocado y, peor, que rechazara a clientes que
-- preguntaban por "Be Life"/"By Life" (typo comun de "Blife") diciendo que esa marca no
-- esta en el catalogo -- cuando en realidad es la unica marca que vendemos.
--
-- REPLACE() en MySQL/MariaDB compara segun la collation de la columna; ai_asistente_config
-- y whatsapp_templates usan utf8mb4_spanish_ci (case-insensitive), asi que un solo REPLACE
-- por variante ya cubre "Be Life", "be life", "BE LIFE", etc. Es idempotente: si ya no
-- queda ningun "Be Life"/"By Life" en el texto, REPLACE no cambia nada.

UPDATE `ai_asistente_config`
SET
    `prompt_sistema_override` = REPLACE(REPLACE(`prompt_sistema_override`, 'Be Life', 'Blife'), 'By Life', 'Blife'),
    `promocion_vigente_texto` = REPLACE(REPLACE(`promocion_vigente_texto`, 'Be Life', 'Blife'), 'By Life', 'Blife'),
    `politica_envio_texto`    = REPLACE(REPLACE(`politica_envio_texto`, 'Be Life', 'Blife'), 'By Life', 'Blife'),
    `politica_pago_texto`     = REPLACE(REPLACE(`politica_pago_texto`, 'Be Life', 'Blife'), 'By Life', 'Blife'),
    `mensaje_bienvenida`      = REPLACE(REPLACE(`mensaje_bienvenida`, 'Be Life', 'Blife'), 'By Life', 'Blife'),
    `tono_instrucciones`      = REPLACE(REPLACE(`tono_instrucciones`, 'Be Life', 'Blife'), 'By Life', 'Blife'),
    `ubicacion_texto`         = REPLACE(REPLACE(`ubicacion_texto`, 'Be Life', 'Blife'), 'By Life', 'Blife')
WHERE `id_config` = 1;

UPDATE `whatsapp_templates`
SET `texto` = REPLACE(REPLACE(`texto`, 'Be Life', 'Blife'), 'By Life', 'Blife')
WHERE `texto` LIKE '%Be Life%' OR `texto` LIKE '%By Life%';
