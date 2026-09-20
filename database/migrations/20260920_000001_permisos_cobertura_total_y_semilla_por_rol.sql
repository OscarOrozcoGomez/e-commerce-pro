-- Migracion: cobertura total de permisos. Idempotente.
--
-- Parte 1 -- claves nuevas para lo que hoy solo decidia por rol (isAdmin()/isVendedor()/
-- !isCliente()) y no tenia clave propia. Ninguna se concede a un rol por defecto salvo
-- lo indicado en la parte 2: el admin entra por el short-circuit de isAdmin() dentro de
-- hasPermission(); a cualquier otro rol o persona se le concede desde Roles y Permisos.
--
--   configurar_notificaciones  views/notificaciones_pedidos.php + notificaciones_caducidades.php
--   ver_salud_sistema          views/salud_sistema.php
--   atender_chat               views/chat.php (lado personal) + api/chat_handler.php
--   vender_sin_inventario      api/ventas.php (palabra clave de venta sin inventario)
--   crear_categorias           api/products_manager.php + views/bulk_assign_category.php
--   asignar_categorias_masivo  views/bulk_assign_category.php + accion de api/products_manager.php
--                              (antes canBulkAssignCategories(): admin/encargado; quien ya tiene
--                              gestionar_productos sigue pudiendo, ver los guards)
--   declarar_liquidacion       api/vendor_settlement.php + tarjeta de liquidacion del dashboard
--
-- Parte 2 -- semilla neutra por rol. Hasta hoy las guardas eran "permiso O rol"
-- (p. ej. !hasPermission('inventario') && !isEncargado()), asi que quitarle el permiso a un
-- encargado desde el panel no le cerraba el acceso. Al retirar ese respaldo por rol, cada rol
-- conserva EXACTAMENTE lo que ya tenia de facto: aqui se le siembra la clave que antes le
-- daba el helper de rol. Solo inserta lo que falta (nunca quita nada), asi que el despliegue
-- es neutro; a partir de aqui el panel de Roles y Permisos manda de verdad.

-- ---------------------------------------------------------------------------------------
-- Parte 1: claves nuevas
-- ---------------------------------------------------------------------------------------
INSERT INTO permisos (clave, nombre, descripcion, categoria, estado)
SELECT nuevos.clave, nuevos.nombre, nuevos.descripcion, nuevos.categoria, 'activo'
FROM (
    SELECT 'configurar_notificaciones' AS clave, 'Configurar notificaciones' AS nombre,
           'Administrar los correos que reciben los avisos de pedidos y de caducidades' AS descripcion, 'Administracion' AS categoria
    UNION ALL
    SELECT 'ver_salud_sistema', 'Ver salud del sistema',
           'Ver el tablero de salud: cuentas bloqueadas, errores recientes y permisos en uso', 'Administracion'
    UNION ALL
    SELECT 'atender_chat', 'Atender chat de soporte',
           'Ver y responder el Centro de Mensajes (chat de soporte con clientes)', 'Ventas'
    UNION ALL
    SELECT 'vender_sin_inventario', 'Vender sin inventario',
           'Registrar una venta aunque no haya existencias (palabra clave en observaciones)', 'Ventas'
    UNION ALL
    SELECT 'crear_categorias', 'Crear categorias de producto',
           'Crear categorias nuevas al asignar categoria a muchos productos', 'Inventario'
    UNION ALL
    SELECT 'asignar_categorias_masivo', 'Asignar categoria a muchos productos',
           'Asignar una categoria a varios productos a la vez (sin poder editar la ficha de cada uno)', 'Inventario'
    UNION ALL
    SELECT 'declarar_liquidacion', 'Declarar liquidacion de ventas',
           'Declarar la liquidacion (corte) de las propias ventas desde el dashboard', 'Ventas'
) AS nuevos
WHERE NOT EXISTS (SELECT 1 FROM permisos p WHERE p.clave = nuevos.clave);

-- ---------------------------------------------------------------------------------------
-- Parte 2: semilla neutra por rol (lo que antes daba el helper de rol)
-- ---------------------------------------------------------------------------------------

-- encargado: antes entraba por isEncargado()/canManageDeliveryOrders()/canScheduleSalesOrders()/
-- canBulkAssignCategories()/isAdmin()||isEncargado(). Ojo: NO se siembra gestionar_productos; la
-- asignacion masiva de categorias tiene su propia clave para no abrirle la ficha de productos.
INSERT INTO rol_permisos (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso
FROM roles r
JOIN permisos p ON p.clave IN (
    'inventario', 'gestionar_clientes', 'gestionar_cancelaciones', 'gestionar_caducidades',
    'ver_notificaciones_pickup', 'asignar_entregas', 'asignar_categorias_masivo', 'realizar_ventas',
    'vender_sin_inventario'
)
WHERE r.nombre = 'encargado'
  AND NOT EXISTS (
      SELECT 1 FROM rol_permisos rp WHERE rp.id_rol = r.id_rol AND rp.id_permiso = p.id_permiso
  );

-- vendedor: antes entraba a ventas y a pickup por canScheduleSalesOrders()/isVendedor(), y era el
-- unico que podia declarar liquidacion.
INSERT INTO rol_permisos (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso
FROM roles r
JOIN permisos p ON p.clave IN ('realizar_ventas', 'ver_notificaciones_pickup', 'declarar_liquidacion')
WHERE r.nombre = 'vendedor'
  AND NOT EXISTS (
      SELECT 1 FROM rol_permisos rp WHERE rp.id_rol = r.id_rol AND rp.id_permiso = p.id_permiso
  );

-- repartidor: antes entraba a sus entregas y a la optimizacion de ruta por isRepartidor().
INSERT INTO rol_permisos (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso
FROM roles r
JOIN permisos p ON p.clave = 'ver_entregas'
WHERE r.nombre = 'repartidor'
  AND NOT EXISTS (
      SELECT 1 FROM rol_permisos rp WHERE rp.id_rol = r.id_rol AND rp.id_permiso = p.id_permiso
  );

-- Chat de soporte: antes lo atendia cualquier usuario que no fuera cliente (!isCliente()),
-- incluidos roles personalizados. admin pasa por isAdmin() y cliente nunca lo recibe.
INSERT INTO rol_permisos (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso
FROM roles r
JOIN permisos p ON p.clave = 'atender_chat'
WHERE r.nombre NOT IN ('admin', 'cliente')
  AND NOT EXISTS (
      SELECT 1 FROM rol_permisos rp WHERE rp.id_rol = r.id_rol AND rp.id_permiso = p.id_permiso
  );

-- El rol admin conserva todas las claves activas marcadas en la matriz (ver 20260904_000001).
INSERT INTO rol_permisos (id_rol, id_permiso)
SELECT r.id_rol, p.id_permiso
FROM roles r
JOIN permisos p ON p.estado = 'activo'
WHERE r.nombre = 'admin'
  AND NOT EXISTS (
      SELECT 1 FROM rol_permisos rp WHERE rp.id_rol = r.id_rol AND rp.id_permiso = p.id_permiso
  );
