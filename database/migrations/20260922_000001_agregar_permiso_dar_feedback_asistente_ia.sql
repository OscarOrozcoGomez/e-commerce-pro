-- Migracion: clave de permiso propia para "Dar feedback" a Alex desde el hilo de una
-- conversacion de WhatsApp (crear regla de aprendizaje), separada de 'gestionar_asistente_ia'.
-- Quien solo lee conversaciones ('ver_conversaciones_whatsapp') no necesariamente debe poder
-- reconfigurar todo el asistente; con esta clave el admin puede dar ese acceso mas angosto
-- sin soltar el resto. Idempotente. Sin fallback de rol: nadie la tiene hasta que el admin
-- la conceda desde el panel (mismo criterio que 'ver_conversaciones_whatsapp').

INSERT INTO permisos (clave, nombre, descripcion, categoria, estado)
SELECT * FROM (
    SELECT 'dar_feedback_asistente_ia' AS clave, 'Dar feedback a Alex' AS nombre, 'Convertir una respuesta de Alex en regla de aprendizaje desde el hilo de una conversacion de WhatsApp' AS descripcion, 'Administracion' AS categoria, 'activo' AS estado
) AS nuevos
WHERE NOT EXISTS (SELECT 1 FROM permisos p WHERE p.clave = nuevos.clave);
