<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EntregaItemUtilsTest extends TestCase
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

    public function testMarksProductAsNotDeliveredAndRestocksInventory(): void
    {
        $this->seedPedido(100, 5, 1, 'en_reparto', 900.00, 0.00, 900.00);
        $this->seedDetalle(1, 100, 10, 2, 300.00, 0.00, 'entregado');
        $this->seedDetalle(2, 100, 20, 1, 600.00, 0.00, 'entregado');
        $this->seedInventario(10, 1, 3);

        $result = dbMarkProductoNoEntregado($this->pdo, 100, 1, 5, 'El cliente ya no lo quiere', 5);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('no entregado', $result['message']);

        $detalle = $this->pdo->query('SELECT estado_entrega, motivo_rechazo FROM detalle_pedidos WHERE id_detalle = 1')->fetch();
        $this->assertSame('rechazado', $detalle['estado_entrega']);
        $this->assertSame('El cliente ya no lo quiere', $detalle['motivo_rechazo']);

        $stock = (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = 10 AND id_almacen = 1')->fetchColumn();
        $this->assertSame(5, $stock); // 3 + 2 devueltas

        $movimiento = $this->pdo->query("SELECT tipo_movimiento, cantidad, id_producto FROM movimientos_inventario WHERE id_producto = 10")->fetch();
        $this->assertSame('entrada', $movimiento['tipo_movimiento']);
        $this->assertSame(2, (int) $movimiento['cantidad']);

        $pedido = $this->pdo->query('SELECT subtotal, descuento_total, total FROM pedidos WHERE id_pedido = 100')->fetch();
        $this->assertEqualsWithDelta(600.00, (float) $pedido['subtotal'], 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $pedido['descuento_total'], 0.001);
        $this->assertEqualsWithDelta(600.00, (float) $pedido['total'], 0.001);
    }

    public function testAdjustsTotalsAccountingForLineDiscount(): void
    {
        // subtotal ya trae el descuento de linea restado (100 base - 20 descuento = 80).
        $this->seedPedido(101, 5, 1, 'pagado', 180.00, 20.00, 160.00);
        $this->seedDetalle(3, 101, 10, 1, 80.00, 20.00, 'entregado');
        $this->seedDetalle(4, 101, 20, 1, 80.00, 0.00, 'entregado');

        $result = dbMarkProductoNoEntregado($this->pdo, 101, 3, 5, 'Producto danado', 5);

        $this->assertTrue($result['success']);
        $pedido = $this->pdo->query('SELECT subtotal, descuento_total, total FROM pedidos WHERE id_pedido = 101')->fetch();
        // subtotal_base del item = 80 + 20 = 100; subtotal 180-100=80; descuento 20-20=0; total 160-80=80.
        $this->assertEqualsWithDelta(80.00, (float) $pedido['subtotal'], 0.001);
        $this->assertEqualsWithDelta(0.00, (float) $pedido['descuento_total'], 0.001);
        $this->assertEqualsWithDelta(80.00, (float) $pedido['total'], 0.001);
    }

    public function testCreatesInventoryRowWhenNoneExistsYet(): void
    {
        $this->seedPedido(102, 5, 1, 'en_reparto', 900.00, 0.00, 900.00);
        $this->seedDetalle(5, 102, 30, 4, 300.00, 0.00, 'entregado');
        $this->seedDetalle(6, 102, 20, 1, 600.00, 0.00, 'entregado');
        // A proposito no se siembra inventario_almacen para el producto 30.

        $result = dbMarkProductoNoEntregado($this->pdo, 102, 5, 5, 'Producto danado', 5);

        $this->assertTrue($result['success']);
        $stock = (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = 30 AND id_almacen = 1')->fetchColumn();
        $this->assertSame(4, $stock);
    }

    public function testRejectsWhenPedidoNotOwnedByRepartidor(): void
    {
        $this->seedPedido(103, 5, 1, 'en_reparto', 900.00, 0.00, 900.00);
        $this->seedDetalle(7, 103, 10, 1, 300.00, 0.00, 'entregado');
        $this->seedDetalle(8, 103, 20, 1, 600.00, 0.00, 'entregado');

        $result = dbMarkProductoNoEntregado($this->pdo, 103, 7, 999, 'Motivo', 999);

        $this->assertFalse($result['success']);
        $detalle = $this->pdo->query('SELECT estado_entrega FROM detalle_pedidos WHERE id_detalle = 7')->fetch();
        $this->assertSame('entregado', $detalle['estado_entrega']);
    }

    /**
     * @dataProvider nonEditableStatesProvider
     */
    public function testRejectsWhenPedidoStateIsNotEditable(string $estado): void
    {
        $this->seedPedido(104, 5, 1, $estado, 900.00, 0.00, 900.00);
        $this->seedDetalle(9, 104, 10, 1, 300.00, 0.00, 'entregado');
        $this->seedDetalle(10, 104, 20, 1, 600.00, 0.00, 'entregado');

        $result = dbMarkProductoNoEntregado($this->pdo, 104, 9, 5, 'Motivo', 5);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('estado actual', $result['message']);
    }

    public static function nonEditableStatesProvider(): array
    {
        return [
            'entregado' => ['entregado'],
            'cancelado' => ['cancelado'],
        ];
    }

    public function testRejectsWhenItemAlreadyRechazado(): void
    {
        $this->seedPedido(105, 5, 1, 'en_reparto', 900.00, 0.00, 900.00);
        $this->seedDetalle(11, 105, 10, 1, 300.00, 0.00, 'rechazado');
        $this->seedDetalle(12, 105, 20, 1, 600.00, 0.00, 'entregado');

        $result = dbMarkProductoNoEntregado($this->pdo, 105, 11, 5, 'Motivo', 5);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ya estaba marcado', $result['message']);
    }

    public function testRejectsWhenItemDoesNotBelongToPedido(): void
    {
        $this->seedPedido(106, 5, 1, 'en_reparto', 300.00, 0.00, 300.00);
        $this->seedDetalle(13, 106, 10, 1, 300.00, 0.00, 'entregado');
        $this->seedPedido(107, 5, 1, 'en_reparto', 300.00, 0.00, 300.00);
        $this->seedDetalle(14, 107, 20, 1, 300.00, 0.00, 'entregado');

        $result = dbMarkProductoNoEntregado($this->pdo, 106, 14, 5, 'Motivo', 5);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('no pertenece', $result['message']);
    }

    public function testRejectsWhenItIsTheLastRemainingDeliveredItem(): void
    {
        $this->seedPedido(108, 5, 1, 'en_reparto', 300.00, 0.00, 300.00);
        $this->seedDetalle(15, 108, 10, 1, 300.00, 0.00, 'entregado');

        $result = dbMarkProductoNoEntregado($this->pdo, 108, 15, 5, 'Motivo', 5);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ultimo producto', $result['message']);
        $detalle = $this->pdo->query('SELECT estado_entrega FROM detalle_pedidos WHERE id_detalle = 15')->fetch();
        $this->assertSame('entregado', $detalle['estado_entrega']);
    }

    public function testAllowsLastItemWhenOthersAreAlreadyRejected(): void
    {
        // Solo cuenta como "restante" los que siguen en estado_entrega = 'entregado'.
        $this->seedPedido(109, 5, 1, 'en_reparto', 900.00, 0.00, 900.00);
        $this->seedDetalle(16, 109, 10, 1, 300.00, 0.00, 'rechazado');
        $this->seedDetalle(17, 109, 20, 1, 600.00, 0.00, 'entregado');

        $result = dbMarkProductoNoEntregado($this->pdo, 109, 17, 5, 'Motivo', 5);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('ultimo producto', $result['message']);
    }

    public function testReturnsFailureForInvalidIds(): void
    {
        $result = dbMarkProductoNoEntregado($this->pdo, 0, 1, 5, 'Motivo', 5);
        $this->assertFalse($result['success']);

        $result = dbMarkProductoNoEntregado($this->pdo, 100, 0, 5, 'Motivo', 5);
        $this->assertFalse($result['success']);

        $result = dbMarkProductoNoEntregado($this->pdo, 100, 1, 0, 'Motivo', 5);
        $this->assertFalse($result['success']);
    }

    public function testAdminCanRemoveProductFromAlreadyDeliveredOrderWithoutRepartidorFilter(): void
    {
        // Uso de admin/encargado (views/asignar_entregas.php): sin filtro de repartidor y
        // con 'entregado' en la lista de estados permitidos.
        $this->seedPedido(111, 5, 1, 'entregado', 900.00, 0.00, 900.00);
        $this->seedDetalle(20, 111, 10, 2, 300.00, 0.00, 'entregado');
        $this->seedDetalle(21, 111, 20, 1, 600.00, 0.00, 'entregado');
        $this->seedInventario(10, 1, 0);

        $result = dbMarkProductoNoEntregado($this->pdo, 111, 20, null, 'Ajuste de staff', 9, ['pendiente_pago', 'pagado', 'en_reparto', 'entregado']);

        $this->assertTrue($result['success']);
        $detalle = $this->pdo->query('SELECT estado_entrega FROM detalle_pedidos WHERE id_detalle = 20')->fetch();
        $this->assertSame('rechazado', $detalle['estado_entrega']);
        $stock = (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = 10 AND id_almacen = 1')->fetchColumn();
        $this->assertSame(2, $stock);
    }

    public function testAdminFilterStillRejectsEntregadoWithDefaultStates(): void
    {
        // Sin pasar $estadosPermitidos, 'entregado' sigue sin ser editable (comportamiento
        // del repartidor no cambia por default).
        $this->seedPedido(112, 5, 1, 'entregado', 900.00, 0.00, 900.00);
        $this->seedDetalle(22, 112, 10, 1, 300.00, 0.00, 'entregado');
        $this->seedDetalle(23, 112, 20, 1, 600.00, 0.00, 'entregado');

        $result = dbMarkProductoNoEntregado($this->pdo, 112, 22, null, 'Ajuste de staff', 9);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('estado actual', $result['message']);
    }

    public function testReturnsFailureForEmptyMotivo(): void
    {
        $this->seedPedido(110, 5, 1, 'en_reparto', 900.00, 0.00, 900.00);
        $this->seedDetalle(18, 110, 10, 1, 300.00, 0.00, 'entregado');
        $this->seedDetalle(19, 110, 20, 1, 600.00, 0.00, 'entregado');

        $result = dbMarkProductoNoEntregado($this->pdo, 110, 18, 5, '   ', 5);

        $this->assertFalse($result['success']);
    }

    private function createSchema(): void
    {
        $this->pdo->exec('CREATE TABLE pedidos (id_pedido INTEGER PRIMARY KEY, id_repartidor INTEGER NOT NULL, id_almacen INTEGER NOT NULL, estado TEXT NOT NULL, subtotal REAL NOT NULL DEFAULT 0, descuento_total REAL NOT NULL DEFAULT 0, total REAL NOT NULL DEFAULT 0)');
        $this->pdo->exec('CREATE TABLE detalle_pedidos (id_detalle INTEGER PRIMARY KEY, id_pedido INTEGER NOT NULL, id_producto INTEGER NOT NULL, cantidad INTEGER NOT NULL, subtotal REAL NOT NULL DEFAULT 0, monto_descuento REAL NOT NULL DEFAULT 0, estado_entrega TEXT NOT NULL DEFAULT \'entregado\', motivo_rechazo TEXT NULL)');
        $this->pdo->exec('CREATE TABLE inventario_almacen (id_producto INTEGER NOT NULL, id_almacen INTEGER NOT NULL, cantidad_actual INTEGER NOT NULL DEFAULT 0, stock_minimo INTEGER NOT NULL DEFAULT 0, stock_maximo INTEGER NOT NULL DEFAULT 0)');
        $this->pdo->exec('CREATE TABLE movimientos_inventario (id_movimiento INTEGER PRIMARY KEY AUTOINCREMENT, id_producto INTEGER NOT NULL, tipo_movimiento TEXT NOT NULL, id_almacen_destino INTEGER NULL, cantidad INTEGER NOT NULL, id_usuario INTEGER NULL, observacion TEXT NULL)');
    }

    private function seedPedido(int $idPedido, int $idRepartidor, int $idAlmacen, string $estado, float $subtotal, float $descuentoTotal, float $total): void
    {
        $this->pdo->prepare('INSERT INTO pedidos (id_pedido, id_repartidor, id_almacen, estado, subtotal, descuento_total, total) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$idPedido, $idRepartidor, $idAlmacen, $estado, $subtotal, $descuentoTotal, $total]);
    }

    private function seedDetalle(int $idDetalle, int $idPedido, int $idProducto, int $cantidad, float $subtotal, float $montoDescuento, string $estadoEntrega): void
    {
        $this->pdo->prepare('INSERT INTO detalle_pedidos (id_detalle, id_pedido, id_producto, cantidad, subtotal, monto_descuento, estado_entrega) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$idDetalle, $idPedido, $idProducto, $cantidad, $subtotal, $montoDescuento, $estadoEntrega]);
    }

    private function seedInventario(int $idProducto, int $idAlmacen, int $cantidadActual): void
    {
        $this->pdo->prepare('INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual) VALUES (?, ?, ?)')->execute([$idProducto, $idAlmacen, $cantidadActual]);
    }
}
