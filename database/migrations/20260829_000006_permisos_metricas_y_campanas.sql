-- Migracion: claves de permiso para las vistas nuevas de metricas y campanas
-- (trafico/Facebook Ads, comportamiento del sitio, calendario de campanas,
-- iniciativas de ventas, inteligencia de negocio, auditoria). Idempotente.
--
-- Todas esas vistas hoy son solo-admin por codigo; los guardas pasan a
-- "hasPermission('clave') || isAdmin()", asi que el admin sigue entrando por
-- short-circuit y nadie mas gana acceso hasta que se conceda desde el panel.
-- Por eso NO se crean filas en rol_permisos.

INSERT INTO permisos (clave, nombre, descripcion, categoria, estado)
SELECT * FROM (
    SELECT 'ver_analitica_negocio'          AS clave, 'Ver inteligencia de negocio'      AS nombre, 'Panel de analitica: ventas por mes, prediccion de stock, tendencias' AS descripcion, 'Metricas'       AS categoria, 'activo' AS estado
    UNION ALL SELECT 'ver_trafico_campanas',        'Ver trafico y campanas',           'De donde llegan las visitas: Facebook Ads, Google Ads, organico, directo, referido', 'Metricas',       'activo'
    UNION ALL SELECT 'ver_comportamiento_sitio',    'Ver comportamiento en el sitio',   'Que productos y paginas se ven mas, cuanto tiempo y que tanto convierte a carrito', 'Metricas',       'activo'
    UNION ALL SELECT 'gestionar_campanas',          'Gestionar calendario de campanas', 'Registrar y borrar campanas y ver el riesgo de agotar stock durante ellas', 'Metricas',       'activo'
    UNION ALL SELECT 'configurar_iniciativas_ventas','Configurar iniciativas de ventas', 'Prender/apagar atribucion de ventas, feed de catalogo, referidos y stock vs. campanas', 'Administracion', 'activo'
    UNION ALL SELECT 'ver_auditoria',               'Ver logs de auditoria',            'Monitorear clics, visitas y acciones de cada usuario en el sistema', 'Administracion', 'activo'
) AS nuevos
WHERE NOT EXISTS (SELECT 1 FROM permisos p WHERE p.clave = nuevos.clave);
