-- Migracion: asegura la clave de permiso 'transferir_stock' en el catalogo. Idempotente.
--
-- La clave existe en database.sql (seed base) pero nunca tuvo migracion propia: en
-- entornos cuyo `permisos` se poblo antes de que se agregara esa fila, la clave falta.
-- Sin ella, views/transfer_stock.php y api/transfer_stock.php solo funcionan para admin
-- (short-circuit de isAdmin()) y el permiso no aparece en el panel de Roles y Permisos.
--
-- No agrega filas en rol_permisos: el admin ya pasa por isAdmin(); a cualquier otro rol
-- o persona se le concede desde el panel. (La migracion 20260829_000005 ya quito la fila
-- muerta encargado -> transferir_stock a proposito.)

INSERT INTO permisos (clave, nombre, descripcion, categoria, estado)
SELECT 'transferir_stock', 'Transferir stock',
       'Mover inventario entre almacenes (descuenta el origen y abastece el destino)', 'Inventario', 'activo'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave = 'transferir_stock');

-- Si la fila ya existia pero sin categoria (entornos que corrieron 20260829_000003
-- antes de tener la clave), la alineamos con el resto del modulo.
UPDATE permisos
   SET categoria = 'Inventario'
 WHERE clave = 'transferir_stock' AND (categoria IS NULL OR categoria = '');
