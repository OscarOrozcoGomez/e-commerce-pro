-- Migracion: claves de permiso para vistas admin/staff que seguian decidiendo por rol
-- (sucursales, cancelaciones, asistente de IA / Alex Insights). Idempotente.
--
-- Los guardas de esas vistas pasan a "hasPermission('clave') || helperDeRol()", asi que
-- nadie pierde acceso; el admin sigue entrando por short-circuit y nadie mas gana acceso
-- hasta que se conceda desde el panel. Por eso NO se crean filas en rol_permisos.
--
-- (manage_customers.php se engancha a la clave YA existente 'gestionar_clientes' y las
--  vistas/endpoints de inventario a 'inventario' -- sin claves nuevas para esos.)

INSERT INTO permisos (clave, nombre, descripcion, categoria, estado)
SELECT * FROM (
    SELECT 'gestionar_sucursales'   AS clave, 'Gestionar sucursales'          AS nombre, 'Crear, editar y configurar sucursales, horarios y ofertas de pickup' AS descripcion, 'Administracion' AS categoria, 'activo' AS estado
    UNION ALL SELECT 'gestionar_cancelaciones', 'Gestionar cancelaciones de pedidos', 'Revisar y resolver solicitudes de cancelacion de pedidos', 'Ventas',        'activo'
    UNION ALL SELECT 'gestionar_asistente_ia',  'Configurar el asistente de IA',     'Ajustes y diagnostico del asistente de WhatsApp (Alex)', 'Administracion', 'activo'
    UNION ALL SELECT 'ver_insights_ia',         'Ver Alex Insights',                 'Panel de hallazgos que genera el asistente de WhatsApp a partir de las conversaciones', 'Metricas', 'activo'
) AS nuevos
WHERE NOT EXISTS (SELECT 1 FROM permisos p WHERE p.clave = nuevos.clave);
