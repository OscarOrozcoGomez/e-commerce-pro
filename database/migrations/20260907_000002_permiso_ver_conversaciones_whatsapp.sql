-- Migracion: clave de permiso para la vista "Contactos de WhatsApp" (lectura de hilos
-- de conversacion de Alex). Idempotente.
--
-- views/whatsapp_contactos.php (PR #144) ya comprueba
--   hasPermission('ver_conversaciones_whatsapp') || hasPermission('gestionar_asistente_ia') || isAdmin()
-- y la clave esta en PERMISOS_EN_USO (core/auth.php), pero faltaba la fila en `permisos`:
-- sin ella el panel de Roles y Permisos no la lista y ningun admin puede concederla por
-- rol o por persona. El acceso hoy es neutral (admin por short-circuit, quien tenga
-- 'gestionar_asistente_ia' por el fallback), asi que nadie gana ni pierde nada al aplicarla.
-- Sin filas en rol_permisos.

INSERT INTO permisos (clave, nombre, descripcion, categoria, estado)
SELECT 'ver_conversaciones_whatsapp', 'Ver conversaciones de WhatsApp',
       'Abrir la vista de contactos por dia y leer el hilo completo de cada conversacion del asistente (solo lectura)', 'Administracion', 'activo'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave = 'ver_conversaciones_whatsapp');
