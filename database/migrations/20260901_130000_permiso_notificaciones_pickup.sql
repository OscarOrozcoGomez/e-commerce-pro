-- Migracion: clave de permiso para la vista de Notificaciones Pickup. Idempotente.
--
-- El guarda pasa a "hasPermission('ver_notificaciones_pickup') || helperDeRol()", asi
-- que nadie pierde acceso (hoy la ven encargado/admin/vendedor). No se crean filas en
-- rol_permisos: si se quiere concederla a otro rol, se hace desde el panel.
--
-- Los 6 endpoints nuevos del flujo de ordenes de compra se enganchan a la clave YA
-- existente 'inventario' -- sin clave nueva para esos.

INSERT INTO permisos (clave, nombre, descripcion, categoria, estado)
SELECT 'ver_notificaciones_pickup', 'Ver notificaciones de pickup',
       'Avisar al cliente que su pedido para recoger en sucursal ya esta listo', 'Entregas', 'activo'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave = 'ver_notificaciones_pickup');
