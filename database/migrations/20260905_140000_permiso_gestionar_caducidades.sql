-- Migracion: clave de permiso para el Control de Caducidades por Lote. Idempotente.
--
-- views/caducidades.php y api/lotes_manager.php hoy son admin/encargado por codigo;
-- pasan a "hasPermission('gestionar_caducidades') || isAdmin() || isEncargado()", asi
-- que nadie pierde acceso (admin por short-circuit, encargado por el fallback de rol) y
-- desde el panel se puede concederle a otro rol o a una persona concreta.
-- Sin filas en rol_permisos.
--
-- views/notificaciones_caducidades.php (config de correos de alertas) se deja admin-only,
-- igual que views/notificaciones_pedidos.php -- es configuracion de sistema, no operacion.

INSERT INTO permisos (clave, nombre, descripcion, categoria, estado)
SELECT 'gestionar_caducidades', 'Control de caducidades por lote',
       'Ver lotes con proyeccion FEFO, excedentes y riesgo de merma; capturar caducidad al recibir', 'Inventario', 'activo'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave = 'gestionar_caducidades');
