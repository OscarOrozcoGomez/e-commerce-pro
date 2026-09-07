-- Migracion: clave de permiso para la vista "Contactos de WhatsApp" (lectura de hilos
-- de conversacion de Alex, con PII de clientes). Idempotente.
--
-- views/whatsapp_contactos.php (PR #144) comprueba hasPermission('ver_conversaciones_whatsapp')
-- y la clave esta en PERMISOS_EN_USO (core/auth.php), pero faltaba la fila en `permisos`:
-- sin ella el panel de Roles y Permisos no la lista y ningun admin puede concederla por
-- rol o por persona.
--
-- Sin filas en rol_permisos: la vista queda admin-only por defecto (el admin entra por
-- el short-circuit de hasPermission()) y desde ahi el admin decide a quien concedersela.

INSERT INTO permisos (clave, nombre, descripcion, categoria, estado)
SELECT 'ver_conversaciones_whatsapp', 'Ver conversaciones de WhatsApp',
       'Abrir la vista de contactos por dia y leer el hilo completo de cada conversacion del asistente (solo lectura)', 'Administracion', 'activo'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave = 'ver_conversaciones_whatsapp');
