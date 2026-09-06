-- Migracion: Fase 4 - preparar el control por permiso en modulos que hoy deciden por rol.
-- Los guardas de esas vistas pasan a "hasPermission('clave') || helperDeRol()", asi que
-- nadie pierde acceso. Esta migracion solo ajusta el catalogo para que el cambio sea
-- neutro en el despliegue (nadie GANA acceso tampoco); a partir de aqui se gestiona
-- todo desde el panel de Roles y Permisos. Idempotente.

SET @db := DATABASE();

-- 1) Nueva clave "asignar_entregas": agendar a domicilio y asignar repartidores.
--    Es distinta de "ver_entregas" (que tiene el repartidor para ver SUS entregas).
INSERT INTO permisos (clave, nombre, descripcion, categoria, estado)
SELECT 'asignar_entregas', 'Asignar entregas',
       'Agendar pedidos a domicilio y asignar repartidores', 'Entregas', 'activo'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave = 'asignar_entregas');

-- 2) Otorgar "asignar_entregas" al encargado (el admin pasa por el short-circuit de isAdmin()).
INSERT INTO rol_permisos (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso
FROM roles r
JOIN permisos p ON p.clave = 'asignar_entregas'
WHERE r.nombre = 'encargado'
  AND NOT EXISTS (
    SELECT 1 FROM rol_permisos rp WHERE rp.id_rol = r.id_rol AND rp.id_permiso = p.id_permiso
  );

-- 3) transfer_stock.php y su API son solo-admin hoy (!isAdmin()). Quitar la fila muerta
--    encargado -> transferir_stock para no ampliarle el acceso al activar el check.
--    Si se quiere dar a un encargado, se concede desde el panel.
DELETE rp FROM rol_permisos rp
JOIN roles r ON r.id_rol = rp.id_rol
JOIN permisos p ON p.id_permiso = rp.id_permiso
WHERE r.nombre = 'encargado' AND p.clave = 'transferir_stock';
