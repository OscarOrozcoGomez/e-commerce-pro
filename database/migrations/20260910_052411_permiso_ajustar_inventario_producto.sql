-- Migracion: clave de permiso 'ajustar_inventario_producto'. Idempotente.
--
-- El bloque "Control de Inventario" de la ficha de producto (almacen + stock actual /
-- minimo / maximo, "Ajuste manual") estaba atado a isAdmin() por codigo, tanto en la
-- vista (views/products.php) como al guardar (api/products_manager.php). Un encargado
-- con 'gestionar_productos' entraba a la ficha pero no veia ni podia tocar ese bloque.
--
-- Ahora ambos puntos usan hasPermission('ajustar_inventario_producto'): el admin lo
-- conserva por el short-circuit de isAdmin() dentro de hasPermission(); a cualquier
-- otro rol o persona se le concede desde el panel de Roles y Permisos.
--
-- Sin filas en rol_permisos: no se auto-concede a encargado (hoy no lo tiene, asi que
-- no hay regresion). Para dárselo: Roles y Permisos -> rol Encargado -> activar la clave.

INSERT INTO permisos (clave, nombre, descripcion, categoria, estado)
SELECT 'ajustar_inventario_producto', 'Ajustar inventario desde la ficha de producto',
       'Ver y editar el stock actual, minimo y maximo por almacen en la ficha de producto (ajuste manual)', 'Inventario', 'activo'
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM permisos WHERE clave = 'ajustar_inventario_producto');

-- Si la fila ya existia pero sin categoria, la alineamos con el resto del modulo.
UPDATE permisos
   SET categoria = 'Inventario'
 WHERE clave = 'ajustar_inventario_producto' AND (categoria IS NULL OR categoria = '');
