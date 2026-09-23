<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PedidoItemAdminUtilsTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->createSchema();
        $this->seedProducto(10, 'Playera azul', 200.00, 80.00, 'activo');
        $this->seedProducto(11, 'Producto inactivo', 100.00, 40.00, 'inactivo');
        $this->seedProducto(12, 'Sin precio', 0.00, 0.00, 'activo');
        $this->seedInventario(10, 1, 5);
    }

    public function testAddsProductToOrderAndDecrementsStock(): void
    {
        $this->seedPedido(100, 'en_reparto', 1, 300.00, 300.00);

        $result = dbAdminAgregarProductoPedido($this->pdo, 100, 10, 2, 7);

        $this->assertTrue($result['success']);

        $detalle = $this->pdo->query('SELECT id_producto, cantidad, precio_unitario, subtotal, estado_entrega FROM detalle_pedidos WHERE id_pedido = 100')->fetch();
        $this->assertSame(10, (int) $detalle['id_producto']);
        $this->assertSame(2, (int) $detalle['cantidad']);
        $this->assertEqualsWithDelta(400.00, (float) $detalle['subtotal'], 0.001);
        $this->assertSame('entregado', $detalle['estado_entrega']);

        $stock = (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = 10 AND id_almacen = 1')->fetchColumn();
        $this->assertSame(3, $stock); // 5 - 2

        $pedido = $this->pdo->query('SELECT subtotal, total FROM pedidos WHERE id_pedido = 100')->fetch();
        $this->assertEqualsWithDelta(700.00, (float) $pedido['subtotal'], 0.001);
        $this->assertEqualsWithDelta(700.00, (float) $pedido['total'], 0.001);

        $mov = $this->pdo->query('SELECT tipo_movimiento, cantidad FROM movimientos_inventario WHERE id_producto = 10')->fetch();
        $this->assertSame('salida', $mov['tipo_movimiento']);
        $this->assertSame(2, (int) $mov['cantidad']);
    }

    public function testAllowsAddingProductToAlreadyDeliveredOrder(): void
    {
        // Este es el caso clave del pedido del usuario: "reabrir" un pedido ya entregado
        // para agregarle mas productos como parte de la misma venta.
        $this->seedPedido(101, 'entregado', 1, 300.00, 300.00);

        $result = dbAdminAgregarProductoPedido($this->pdo, 101, 10, 1, 7);

        $this->assertTrue($result['success']);
        $pedido = $this->pdo->query('SELECT estado, total FROM pedidos WHERE id_pedido = 101')->fetch();
        $this->assertSame('entregado', $pedido['estado']); // no cambia el estado del pedido
        $this->assertEqualsWithDelta(500.00, (float) $pedido['total'], 0.001);
    }

    public function testFailsWhenOrderIsCancelled(): void
    {
        $this->seedPedido(102, 'cancelado', 1, 300.00, 300.00);

        $result = dbAdminAgregarProductoPedido($this->pdo, 102, 10, 1, 7);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('cancelado', $result['message']);
        $stock = (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = 10 AND id_almacen = 1')->fetchColumn();
        $this->assertSame(5, $stock); // sin cambios
    }

    public function testFailsWhenInsufficientStock(): void
    {
        $this->seedPedido(103, 'en_reparto', 1, 300.00, 300.00);

        $result = dbAdminAgregarProductoPedido($this->pdo, 103, 10, 99, 7);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('inventario', $result['message']);
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM detalle_pedidos WHERE id_pedido = 103')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testFailsWhenProductNotActive(): void
    {
        $this->seedPedido(104, 'en_reparto', 1, 300.00, 300.00);

        $result = dbAdminAgregarProductoPedido($this->pdo, 104, 11, 1, 7);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('activo', $result['message']);
    }

    public function testFailsWhenProductHasNoSalePrice(): void
    {
        $this->seedPedido(105, 'en_reparto', 1, 300.00, 300.00);

        $result = dbAdminAgregarProductoPedido($this->pdo, 105, 12, 1, 7);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('precio', $result['message']);
    }

    public function testFailsWhenProductDoesNotExist(): void
    {
        $this->seedPedido(106, 'en_reparto', 1, 300.00, 300.00);

        $result = dbAdminAgregarProductoPedido($this->pdo, 106, 9999, 1, 7);

        $this->assertFalse($result['success']);
    }

    public function testFailsWhenOrderDoesNotExist(): void
    {
        $result = dbAdminAgregarProductoPedido($this->pdo, 9999, 10, 1, 7);

        $this->assertFalse($result['success']);
    }

    public function testFailsForInvalidQuantityOrIds(): void
    {
        $this->seedPedido(107, 'en_reparto', 1, 300.00, 300.00);

        $this->assertFalse(dbAdminAgregarProductoPedido($this->pdo, 0, 10, 1, 7)['success']);
        $this->assertFalse(dbAdminAgregarProductoPedido($this->pdo, 107, 0, 1, 7)['success']);
        $this->assertFalse(dbAdminAgregarProductoPedido($this->pdo, 107, 10, 0, 7)['success']);
        $this->assertFalse(dbAdminAgregarProductoPedido($this->pdo, 107, 10, -1, 7)['success']);
    }

    public function testChargesOfferPriceWithCostPlusMarginWhenProductIsInOffer(): void
    {
        $this->seedPedido(110, 'en_reparto', 1, 300.00, 300.00);
        $this->marcarEnOferta(10); // costo 80 -> oferta 130, lista 200

        $this->assertTrue(dbAdminAgregarProductoPedido($this->pdo, 110, 10, 2, 7)['success']);

        $d = $this->pdo->query('SELECT precio_original, precio_unitario, subtotal FROM detalle_pedidos WHERE id_pedido = 110')->fetch();
        $this->assertEqualsWithDelta(200.00, (float) $d['precio_original'], 0.001); // lista, para mostrarlo tachado
        $this->assertEqualsWithDelta(130.00, (float) $d['precio_unitario'], 0.001);
        $this->assertEqualsWithDelta(260.00, (float) $d['subtotal'], 0.001);
        $total = (float) $this->pdo->query('SELECT total FROM pedidos WHERE id_pedido = 110')->fetchColumn();
        $this->assertEqualsWithDelta(560.00, $total, 0.001);
    }

    public function testUsesManualOfferPriceOverride(): void
    {
        $this->seedPedido(111, 'en_reparto', 1, 300.00, 300.00);
        $this->marcarEnOferta(10);
        $this->pdo->exec('UPDATE productos SET precio_oferta = 100 WHERE id_producto = 10');

        $this->assertTrue(dbAdminAgregarProductoPedido($this->pdo, 111, 10, 1, 7)['success']);

        $d = $this->pdo->query('SELECT precio_unitario, subtotal FROM detalle_pedidos WHERE id_pedido = 111')->fetch();
        $this->assertEqualsWithDelta(100.00, (float) $d['precio_unitario'], 0.001);
        $this->assertEqualsWithDelta(100.00, (float) $d['subtotal'], 0.001);
    }

    public function testOfferPriceNeverExceedsListPrice(): void
    {
        $this->seedPedido(112, 'en_reparto', 1, 300.00, 300.00);
        $this->marcarEnOferta(10);
        $this->pdo->exec('UPDATE productos SET precio_oferta = 999 WHERE id_producto = 10');

        $this->assertTrue(dbAdminAgregarProductoPedido($this->pdo, 112, 10, 1, 7)['success']);

        $unitario = (float) $this->pdo->query('SELECT precio_unitario FROM detalle_pedidos WHERE id_pedido = 112')->fetchColumn();
        $this->assertEqualsWithDelta(200.00, $unitario, 0.001);
    }

    public function testChargesListPriceWhenProductIsNotInOffer(): void
    {
        $this->seedPedido(113, 'en_reparto', 1, 300.00, 300.00);
        $this->pdo->exec('UPDATE productos SET precio_oferta = 100 WHERE id_producto = 10'); // override sin categoria: no aplica

        $this->assertTrue(dbAdminAgregarProductoPedido($this->pdo, 113, 10, 1, 7)['success']);

        $unitario = (float) $this->pdo->query('SELECT precio_unitario FROM detalle_pedidos WHERE id_pedido = 113')->fetchColumn();
        $this->assertEqualsWithDelta(200.00, $unitario, 0.001);
    }

    private function marcarEnOferta(int $idProducto): void
    {
        $this->pdo->exec("INSERT INTO categorias (id_categoria, nombre) VALUES (1, 'Ofertas')");
        $this->pdo->exec("INSERT INTO producto_categorias (id_producto, id_categoria) VALUES ({$idProducto}, 1)");
    }

    private function createSchema(): void
    {
        $this->pdo->exec('CREATE TABLE categorias (id_categoria INTEGER PRIMARY KEY, nombre TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE producto_categorias (id_producto INTEGER NOT NULL, id_categoria INTEGER NOT NULL)');
        $this->pdo->exec('CREATE TABLE pedidos (id_pedido INTEGER PRIMARY KEY, estado TEXT NOT NULL, id_almacen INTEGER NOT NULL, subtotal REAL NOT NULL DEFAULT 0, descuento_total REAL NOT NULL DEFAULT 0, total REAL NOT NULL DEFAULT 0)');
        $this->pdo->exec('CREATE TABLE productos (id_producto INTEGER PRIMARY KEY, nombre TEXT NOT NULL, precio_venta REAL NOT NULL DEFAULT 0, precio_costo REAL NOT NULL DEFAULT 0, precio_oferta REAL NULL, estado TEXT NOT NULL DEFAULT \'activo\')');
        $this->pdo->exec('CREATE TABLE detalle_pedidos (id_detalle INTEGER PRIMARY KEY AUTOINCREMENT, id_pedido INTEGER NOT NULL, id_producto INTEGER NOT NULL, cantidad INTEGER NOT NULL, precio_original REAL NOT NULL DEFAULT 0, precio_unitario REAL NOT NULL DEFAULT 0, costo_unitario REAL NOT NULL DEFAULT 0, porcentaje_descuento REAL NOT NULL DEFAULT 0, monto_descuento REAL NOT NULL DEFAULT 0, subtotal REAL NOT NULL DEFAULT 0, estado_entrega TEXT NOT NULL DEFAULT \'entregado\')');
        $this->pdo->exec('CREATE TABLE inventario_almacen (id_producto INTEGER NOT NULL, id_almacen INTEGER NOT NULL, cantidad_actual INTEGER NOT NULL DEFAULT 0)');
        $this->pdo->exec('CREATE TABLE movimientos_inventario (id_movimiento INTEGER PRIMARY KEY AUTOINCREMENT, id_producto INTEGER NOT NULL, tipo_movimiento TEXT NOT NULL, id_almacen_origen INTEGER NULL, cantidad INTEGER NOT NULL, id_usuario INTEGER NULL, observacion TEXT NULL)');
    }

    private function seedPedido(int $idPedido, string $estado, int $idAlmacen, float $subtotal, float $total): void
    {
        $this->pdo->prepare('INSERT INTO pedidos (id_pedido, estado, id_almacen, subtotal, total) VALUES (?, ?, ?, ?, ?)')
            ->execute([$idPedido, $estado, $idAlmacen, $subtotal, $total]);
    }

    private function seedProducto(int $idProducto, string $nombre, float $precioVenta, float $precioCosto, string $estado): void
    {
        $this->pdo->prepare('INSERT INTO productos (id_producto, nombre, precio_venta, precio_costo, estado) VALUES (?, ?, ?, ?, ?)')
            ->execute([$idProducto, $nombre, $precioVenta, $precioCosto, $estado]);
    }

    private function seedInventario(int $idProducto, int $idAlmacen, int $cantidadActual): void
    {
        $this->pdo->prepare('INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual) VALUES (?, ?, ?)')->execute([$idProducto, $idAlmacen, $cantidadActual]);
    }
}
