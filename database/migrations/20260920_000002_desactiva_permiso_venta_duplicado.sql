-- Migracion: desactiva la clave heredada 'venta', duplicada de 'realizar_ventas'. Idempotente.
--
-- 'venta' viene del seed original (database.sql: "Puede crear y gestionar pedidos"). Ningun
-- archivo de views/ ni api/ la comprueba (no esta en PERMISOS_EN_USO); todo el modulo de ventas
-- se decide con 'realizar_ventas'. El panel de Roles y Permisos la mostraba como
-- "DUPLICADO / SIN EFECTO", con un interruptor que no controlaba nada.
--
-- Se hace igual que la accion "desactivar_permiso" del panel: la clave NO se borra, solo pasa a
-- 'inactivo'. Asi deja de listarse en la matriz y en el modal de usuarios, y
-- getEffectivePermissions() ya la ignora (solo lee permisos con estado = 'activo'). Sus filas en
-- rol_permisos / usuario_permisos se conservan por si hiciera falta reactivarla:
--   UPDATE permisos SET estado = 'activo' WHERE clave = 'venta';
--
-- Es neutra para el acceso: nadie pierde nada porque ninguna guarda la usaba. Si ya estaba
-- inactiva en el entorno (p. ej. produccion), no cambia nada.

UPDATE permisos
   SET estado = 'inactivo'
 WHERE clave = 'venta'
   AND estado = 'activo';
