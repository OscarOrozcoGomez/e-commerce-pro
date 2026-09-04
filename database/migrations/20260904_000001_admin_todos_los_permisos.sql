-- Migracion: el rol admin debe reflejar TODOS los permisos activos en rol_permisos.
-- Objetivo:
-- 1) admin ya tiene acceso total en tiempo de ejecucion (isAdmin() hace short-circuit
--    en hasPermission(), sin consultar rol_permisos) -- pero la tabla solo traia 7 de
--    las 26 claves activas, de cuando se sembro a mano. Eso hacia que la matriz de
--    "Editar rol: admin" se viera con casillas vacias que no reflejaban la realidad,
--    y que "Quien puede...?"/el conteo de roles del catalogo no incluyeran a admin.
-- 2) Ser idempotente: solo inserta lo que falte.
--
-- No cambia ningun comportamiento (admin ya podia hacer todo); solo hace que los
-- datos del catalogo dejen de contradecir lo que el codigo ya hacia.

INSERT INTO rol_permisos (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso
FROM roles r
CROSS JOIN permisos p
WHERE r.nombre = 'admin'
  AND p.estado = 'activo'
  AND NOT EXISTS (
    SELECT 1 FROM rol_permisos rp WHERE rp.id_rol = r.id_rol AND rp.id_permiso = p.id_permiso
  );
