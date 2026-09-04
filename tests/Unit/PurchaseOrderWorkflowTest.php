<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Flujo de órdenes de compra: generar orden desde la lista sugerida, ocultar
 * los productos en pedido, surtir (subir inventario + movimiento + cerrar) y
 * regresar pospuestos.
 */
final class PurchaseOrderWorkflowTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->createSchema();
    }

    public function testCreateFromItemsGroupsByBranchInsertsHeaderAndLines(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedWarehouse(2, 'Sucursal Norte');
        $this->seedProduct(10, 'Producto A');
        $this->seedProduct(20, 'Producto B');
        $this->seedProduct(30, 'Producto C');

        $result = purchaseOrderCreateFromItems($this->pdo, [
            ['id_producto' => 10, 'id_almacen' => 1, 'cantidad' => 3, 'precio_costo' => 10.0],
            ['id_producto' => 20, 'id_almacen' => 1, 'cantidad' => 2, 'precio_costo' => 5.0],
            ['id_producto' => 30, 'id_almacen' => 2, 'cantidad' => 4, 'precio_costo' => 2.5],
        ], 7);

        $this->assertCount(2, $result['ordenes']);
        $this->assertSame(3, $result['lineas']);

        $ordenes = $this->pdo->query('SELECT id_orden_compra, id_almacen, estado, total_estimado FROM ordenes_compra ORDER BY id_almacen')->fetchAll();
        $this->assertSame('enviada', $ordenes[0]['estado']);
        $this->assertSame(40.0, (float) $ordenes[0]['total_estimado']); // 3*10 + 2*5
        $this->assertSame(10.0, (float) $ordenes[1]['total_estimado']); // 4*2.5

        $lineas = $this->pdo->query('SELECT id_producto, cantidad_solicitada, cantidad_recibida FROM detalle_orden_compra ORDER BY id_producto')->fetchAll();
        $this->assertCount(3, $lineas);
        $this->assertSame(0, (int) $lineas[0]['cantidad_recibida']);
        $this->assertSame(3, (int) $lineas[0]['cantidad_solicitada']);
    }

    public function testProductOnOpenOrderIsHiddenFromSuggestions(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Producto A');
        $this->seedInventory(10, 1, 1, 2, 5);

        $this->assertCount(1, purchaseOrderFetchSuggestions($this->pdo, true, null)['listaCompra']);

        purchaseOrderCreateFromItems($this->pdo, [
            ['id_producto' => 10, 'id_almacen' => 1, 'cantidad' => 4, 'precio_costo' => 10.0],
        ], 7);

        $this->assertCount(0, purchaseOrderFetchSuggestions($this->pdo, true, null)['listaCompra']);
    }

    public function testReceiveFullBumpsInventoryCreatesMovementAndClosesOrder(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Producto A');
        $this->seedInventory(10, 1, 1, 2, 5);

        $created = purchaseOrderCreateFromItems($this->pdo, [
            ['id_producto' => 10, 'id_almacen' => 1, 'cantidad' => 4, 'precio_costo' => 10.0],
        ], 7);
        $idOrden = $created['ordenes'][0];

        $idDetalle = (int) $this->pdo->query('SELECT id_detalle FROM detalle_orden_compra')->fetchColumn();

        $res = purchaseOrderReceive($this->pdo, $idOrden, [
            ['id_detalle' => $idDetalle, 'cantidad_recibida' => 4],
        ], 7);

        $this->assertSame(1, $res['recibidas']);
        $this->assertSame(0, $res['faltantes']);

        $this->assertSame(5, (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = 10 AND id_almacen = 1')->fetchColumn());

        $mov = $this->pdo->query("SELECT tipo_movimiento, id_almacen_destino, cantidad, id_usuario, observacion FROM movimientos_inventario")->fetch();
        $this->assertSame('entrada', $mov['tipo_movimiento']);
        $this->assertSame(1, (int) $mov['id_almacen_destino']);
        $this->assertSame(4, (int) $mov['cantidad']);
        $this->assertSame(7, (int) $mov['id_usuario']);
        $this->assertStringContainsString('Recepción orden de compra', (string) $mov['observacion']);

        $this->assertSame('recibida', $this->pdo->query('SELECT estado FROM ordenes_compra')->fetchColumn());
        $this->assertSame(4, (int) $this->pdo->query('SELECT cantidad_recibida FROM detalle_orden_compra')->fetchColumn());
    }

    public function testUnsentLinesAreAssumedComplete(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Producto A');
        $this->seedInventory(10, 1, 0, 2, 6);

        $created = purchaseOrderCreateFromItems($this->pdo, [
            ['id_producto' => 10, 'id_almacen' => 1, 'cantidad' => 6, 'precio_costo' => 1.0],
        ], 7);

        $res = purchaseOrderReceive($this->pdo, $created['ordenes'][0], [], 7);

        $this->assertSame(1, $res['recibidas']);
        $this->assertSame(6, (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen')->fetchColumn());
    }

    public function testMissingLineDoesNotTouchInventoryAndProductReappears(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Producto A');
        $this->seedInventory(10, 1, 1, 2, 5);

        $created = purchaseOrderCreateFromItems($this->pdo, [
            ['id_producto' => 10, 'id_almacen' => 1, 'cantidad' => 4, 'precio_costo' => 10.0],
        ], 7);
        $idDetalle = (int) $this->pdo->query('SELECT id_detalle FROM detalle_orden_compra')->fetchColumn();

        $res = purchaseOrderReceive($this->pdo, $created['ordenes'][0], [
            ['id_detalle' => $idDetalle, 'cantidad_recibida' => 0],
        ], 7);

        $this->assertSame(0, $res['recibidas']);
        $this->assertSame(1, $res['faltantes']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen')->fetchColumn(), 'el stock no cambia');
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM movimientos_inventario')->fetchColumn());
        $this->assertSame('recibida', $this->pdo->query('SELECT estado FROM ordenes_compra')->fetchColumn());

        // Al cerrarse la orden, el producto (que sigue bajo mínimo) vuelve a la lista.
        $this->assertCount(1, purchaseOrderFetchSuggestions($this->pdo, true, null)['listaCompra']);
    }

    public function testReceiveRejectsClosedOrder(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Producto A');
        $this->seedInventory(10, 1, 1, 2, 5);

        $created = purchaseOrderCreateFromItems($this->pdo, [
            ['id_producto' => 10, 'id_almacen' => 1, 'cantidad' => 4, 'precio_costo' => 10.0],
        ], 7);
        $idOrden = $created['ordenes'][0];

        purchaseOrderReceive($this->pdo, $idOrden, [], 7);

        $this->expectException(RuntimeException::class);
        purchaseOrderReceive($this->pdo, $idOrden, [], 7);
    }

    public function testListOpenReturnsOrdersWithLines(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Producto A');
        $this->seedProduct(20, 'Producto B');

        purchaseOrderCreateFromItems($this->pdo, [
            ['id_producto' => 10, 'id_almacen' => 1, 'cantidad' => 3, 'precio_costo' => 10.0],
            ['id_producto' => 20, 'id_almacen' => 1, 'cantidad' => 2, 'precio_costo' => 5.0],
        ], 7);

        $ordenes = purchaseOrderListOpen($this->pdo, true, null);
        $this->assertCount(1, $ordenes);
        $this->assertSame('Matriz', $ordenes[0]['sucursal']);
        $this->assertCount(2, $ordenes[0]['lineas']);
    }

    public function testCancelReleasesProductsBackToSuggestions(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Producto A');
        $this->seedInventory(10, 1, 1, 2, 5);

        $created = purchaseOrderCreateFromItems($this->pdo, [
            ['id_producto' => 10, 'id_almacen' => 1, 'cantidad' => 4, 'precio_costo' => 10.0],
        ], 7);

        $this->assertCount(0, purchaseOrderFetchSuggestions($this->pdo, true, null)['listaCompra']);

        $this->assertSame(1, purchaseOrderCancel($this->pdo, $created['ordenes'][0]));
        $this->assertSame('cancelada', $this->pdo->query('SELECT estado FROM ordenes_compra')->fetchColumn());
        $this->assertCount(1, purchaseOrderFetchSuggestions($this->pdo, true, null)['listaCompra']);
        $this->assertSame(0, purchaseOrderCancel($this->pdo, $created['ordenes'][0]), 'cancelar dos veces no hace nada');
    }

    public function testListPostponedAndReactivate(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedUser(9, 'Ana Admin');
        $this->seedProduct(10, 'Producto A');
        $this->seedInventory(10, 1, 1, 2, 5);

        purchaseOrderPostponeItems($this->pdo, [
            ['id_producto' => 10, 'id_almacen' => 1, 'motivo' => 'Proveedor sin stock'],
        ], 9);

        $pospuestos = purchaseOrderListPostponed($this->pdo, true, null);
        $this->assertCount(1, $pospuestos);
        $this->assertSame('Producto A', $pospuestos[0]['nombre']);
        $this->assertSame('Ana Admin', $pospuestos[0]['pospuesto_por']);
        $this->assertCount(0, purchaseOrderFetchSuggestions($this->pdo, true, null)['listaCompra']);

        $idPost = (int) $pospuestos[0]['id_postergacion'];
        $this->assertSame(1, purchaseOrderReactivatePostponed($this->pdo, $idPost));
        $this->assertSame(0, purchaseOrderReactivatePostponed($this->pdo, $idPost), 'reactivar dos veces no hace nada');

        $this->assertCount(1, purchaseOrderFetchSuggestions($this->pdo, true, null)['listaCompra']);
        $this->assertCount(0, purchaseOrderListPostponed($this->pdo, true, null));
    }

    // ------------------------------------------------------------------

    private function createSchema(): void
    {
        $this->pdo->exec('CREATE TABLE almacenes (id_almacen INTEGER PRIMARY KEY, nombre TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE usuarios (id_usuario INTEGER PRIMARY KEY, nombre TEXT NOT NULL)');
        $this->pdo->exec("CREATE TABLE productos (id_producto INTEGER PRIMARY KEY, nombre TEXT NOT NULL, sku TEXT NULL, precio_costo REAL NOT NULL DEFAULT 0, precio_venta REAL NOT NULL DEFAULT 0, estado TEXT NOT NULL DEFAULT 'activo')");
        $this->pdo->exec('CREATE TABLE inventario_almacen (id_inventario INTEGER PRIMARY KEY AUTOINCREMENT, id_producto INTEGER NOT NULL, id_almacen INTEGER NOT NULL, cantidad_actual INTEGER NOT NULL DEFAULT 0, stock_minimo INTEGER NOT NULL DEFAULT 2, stock_maximo INTEGER NOT NULL DEFAULT 5)');
        $this->pdo->exec('CREATE TABLE categorias (id_categoria INTEGER PRIMARY KEY, nombre TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE producto_categorias (id_producto INTEGER NOT NULL, id_categoria INTEGER NOT NULL)');
        $this->pdo->exec("CREATE TABLE movimientos_inventario (id_movimiento INTEGER PRIMARY KEY AUTOINCREMENT, id_producto INTEGER NOT NULL, tipo_movimiento TEXT NOT NULL, id_almacen_destino INTEGER NULL, cantidad INTEGER NOT NULL, id_usuario INTEGER NULL, observacion TEXT NULL)");
        $this->pdo->exec("CREATE TABLE purchase_order_postponed_items (id_postergacion INTEGER PRIMARY KEY AUTOINCREMENT, id_producto INTEGER NOT NULL, id_almacen INTEGER NOT NULL, estado TEXT NOT NULL DEFAULT 'pendiente', motivo TEXT NULL, pospuesto_por INTEGER NULL, pospuesto_en TEXT DEFAULT CURRENT_TIMESTAMP, reactivado_en TEXT NULL)");
        $this->pdo->exec('CREATE UNIQUE INDEX uq_po_postergado_producto_almacen ON purchase_order_postponed_items(id_producto, id_almacen)');
        $this->pdo->exec("CREATE TABLE ordenes_compra (id_orden_compra INTEGER PRIMARY KEY AUTOINCREMENT, id_usuario INTEGER NOT NULL, id_almacen INTEGER NOT NULL, referencia TEXT NOT NULL, fecha_creacion TEXT DEFAULT CURRENT_TIMESTAMP, estado TEXT NOT NULL DEFAULT 'borrador', total_estimado REAL NOT NULL DEFAULT 0, observaciones TEXT NULL)");
        $this->pdo->exec('CREATE TABLE detalle_orden_compra (id_detalle INTEGER PRIMARY KEY AUTOINCREMENT, id_orden_compra INTEGER NOT NULL, id_producto INTEGER NOT NULL, cantidad_solicitada INTEGER NOT NULL, cantidad_recibida INTEGER NOT NULL DEFAULT 0, costo_unitario REAL NOT NULL DEFAULT 0)');
    }

    private function seedWarehouse(int $id, string $nombre): void
    {
        $this->pdo->prepare('INSERT INTO almacenes (id_almacen, nombre) VALUES (?, ?)')->execute([$id, $nombre]);
    }

    private function seedUser(int $id, string $nombre): void
    {
        $this->pdo->prepare('INSERT INTO usuarios (id_usuario, nombre) VALUES (?, ?)')->execute([$id, $nombre]);
    }

    private function seedProduct(int $id, string $nombre): void
    {
        $this->pdo->prepare("INSERT INTO productos (id_producto, nombre, sku, precio_costo, precio_venta, estado) VALUES (?, ?, ?, ?, ?, 'activo')")
            ->execute([$id, $nombre, 'SKU-' . $id, 10.0, 20.0]);
    }

    private function seedInventory(int $idProducto, int $idAlmacen, int $actual, int $minimo, int $maximo): void
    {
        $this->pdo->prepare('INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual, stock_minimo, stock_maximo) VALUES (?, ?, ?, ?, ?)')
            ->execute([$idProducto, $idAlmacen, $actual, $minimo, $maximo]);
    }
}
