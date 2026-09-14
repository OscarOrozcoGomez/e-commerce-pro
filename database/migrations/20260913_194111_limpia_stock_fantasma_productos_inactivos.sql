-- Migracion: limpia_stock_fantasma_productos_inactivos. Idempotente.
--
-- 26 productos con estado='inactivo' (eliminados desde la ficha, ver la accion
-- 'delete' en api/products_manager.php) seguian cargando stock en
-- inventario_almacen -- 11,031 unidades en total, todas en Almacen Central --
-- sin ningun lote que las respalde. Confirmado a mano contra produccion
-- (2026-09-13): un producto inactivo nunca aparece en ningun listado de la app
-- (ni con el filtro "Todos" de Gestionar Productos) ni se puede vender, asi que
-- ese stock es inservible y solo ensucia para siempre el conteo de
-- "Inconsistencias stock/lotes" en Control de Caducidades
-- (views/caducidades.php, loteFetchDescuadres en core/lote_caducidad_utils.php).
--
-- Se deja registro en movimientos_inventario (mismo patron que un ajuste manual
-- desde la ficha de producto) para poder rastrear despues que paso con ese stock.
--
-- Fuera de alcance a proposito: los 15 productos 'archivado' con el mismo
-- problema (6,511 u.) -- 'archivado' no es lo mismo que 'eliminado', se revisan
-- aparte. Tampoco se toca ningun producto 'activo' (se confirmo que ninguno
-- tiene este patron en produccion).

INSERT INTO movimientos_inventario (id_producto, tipo_movimiento, id_almacen_destino, cantidad, id_usuario, observacion)
SELECT
    ia.id_producto,
    'ajuste',
    ia.id_almacen,
    -ia.cantidad_actual,
    NULL,
    'Limpieza stock fantasma: producto inactivo (eliminado) sin lotes que lo respalden (migracion 20260913_194111)'
FROM inventario_almacen ia
JOIN productos p ON p.id_producto = ia.id_producto
WHERE p.estado = 'inactivo' AND ia.cantidad_actual > 0;

UPDATE inventario_almacen ia
JOIN productos p ON p.id_producto = ia.id_producto
SET ia.cantidad_actual = 0
WHERE p.estado = 'inactivo' AND ia.cantidad_actual > 0;
