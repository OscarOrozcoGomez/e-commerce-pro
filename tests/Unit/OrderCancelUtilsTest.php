<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class OrderCancelUtilsTest extends TestCase
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

    public function testCancelsPendingOrderAndRestocksInventory(): void
    {
        $this->seedCliente(1, 5);
        $this->seedPedido(100, 'WEB-100', 1, 1, 'pendiente_pago');
        $this->seedDetalle(100, 10, 2);
        $this->seedDetalle(100, 20, 1);
        $this->seedInventario(10, 1, 3);
        $this->seedInventario(20, 1, 0);

        $result = dbCancelOrderByCustomer($this->pdo, 100, 5, 1, '');

        $this->assertTrue($result['success']);

        $pedido = $this->pdo->query('SELECT estado, observaciones FROM pedidos WHERE id_pedido = 100')->fetch();
        $this->assertSame('cancelado', $pedido['estado']);
        $this->assertStringContainsString('Cancelado por el cliente.', $pedido['observaciones']);

        $stockA = (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = 10 AND id_almacen = 1')->fetchColumn();
        $stockB = (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = 20 AND id_almacen = 1')->fetchColumn();
        $this->assertSame(5, $stockA);
        $this->assertSame(1, $stockB);

        $log = $this->pdo->query('SELECT id_motivo, motivo_detalle, cancelado_por FROM pedido_cancelaciones WHERE id_pedido = 100')->fetch();
        $this->assertSame(1, (int) $log['id_motivo']);
        $this->assertSame(5, (int) $log['cancelado_por']);
        $this->assertNull($log['motivo_detalle']);
    }

    public function testCancelsPaidOrderAndKeepsPriorObservations(): void
    {
        $this->seedCliente(1, 5);
        $this->seedPedido(101, 'WEB-101', 1, 1, 'pagado', 'ENTREGA: Domicilio | Cliente: Ana');

        $result = dbCancelOrderByCustomer($this->pdo, 101, 5, 2, '');

        $this->assertTrue($result['success']);

        $pedido = $this->pdo->query('SELECT estado, observaciones FROM pedidos WHERE id_pedido = 101')->fetch();
        $this->assertSame('cancelado', $pedido['estado']);
        $this->assertStringContainsString('ENTREGA: Domicilio | Cliente: Ana', $pedido['observaciones']);
        $this->assertStringContainsString('Cancelado por el cliente.', $pedido['observaciones']);
    }

    public function testDoesNotRestockAlreadyReleasedLineItems(): void
    {
        $this->seedCliente(1, 5);
        $this->seedPedido(102, 'WEB-102', 1, 1, 'pendiente_pago');
        $this->seedDetalle(102, 10, 0); // liberado por tiempo maximo de apartado
        $this->seedInventario(10, 1, 3);

        $result = dbCancelOrderByCustomer($this->pdo, 102, 5, 1, '');

        $this->assertTrue($result['success']);
        $stock = (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = 10 AND id_almacen = 1')->fetchColumn();
        $this->assertSame(3, $stock);
    }

    public function testRejectsWhenOrderBelongsToAnotherCustomer(): void
    {
        $this->seedCliente(1, 5);
        $this->seedPedido(103, 'WEB-103', 1, 1, 'pendiente_pago');

        $result = dbCancelOrderByCustomer($this->pdo, 103, 999, 1, '');

        $this->assertFalse($result['success']);
        $pedido = $this->pdo->query('SELECT estado FROM pedidos WHERE id_pedido = 103')->fetch();
        $this->assertSame('pendiente_pago', $pedido['estado']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM pedido_cancelaciones WHERE id_pedido = 103')->fetchColumn());
    }

    /**
     * @dataProvider nonCancelableStatesProvider
     */
    public function testRejectsWhenOrderIsNotInACancelableState(string $estado): void
    {
        $this->seedCliente(1, 5);
        $this->seedPedido(104, 'WEB-104', 1, 1, $estado);

        $result = dbCancelOrderByCustomer($this->pdo, 104, 5, 1, '');

        $this->assertFalse($result['success']);
        $pedido = $this->pdo->query('SELECT estado FROM pedidos WHERE id_pedido = 104')->fetch();
        $this->assertSame($estado, $pedido['estado']);
    }

    public static function nonCancelableStatesProvider(): array
    {
        return [
            'en reparto' => ['en_reparto'],
            'entregado' => ['entregado'],
            'ya cancelado' => ['cancelado'],
        ];
    }

    public function testRejectsWhenPickupOrderAlreadyAttended(): void
    {
        $this->createPickupNotificacionesTable();
        $this->seedCliente(1, 5);
        $this->seedPedido(105, 'WEB-105', 1, 1, 'pagado');
        $this->pdo->prepare('INSERT INTO pickup_notificaciones (id_pedido, estado) VALUES (?, ?)')->execute([105, 'atendida']);

        $result = dbCancelOrderByCustomer($this->pdo, 105, 5, 1, '');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('entregado/recogido', $result['message']);
        $pedido = $this->pdo->query('SELECT estado FROM pedidos WHERE id_pedido = 105')->fetch();
        $this->assertSame('pagado', $pedido['estado']);
    }

    public function testAllowsPickupOrderCancelWhenNotYetAttended(): void
    {
        $this->createPickupNotificacionesTable();
        $this->seedCliente(1, 5);
        $this->seedPedido(106, 'WEB-106', 1, 1, 'pagado');
        $this->pdo->prepare('INSERT INTO pickup_notificaciones (id_pedido, estado) VALUES (?, ?)')->execute([106, 'apartada']);

        $result = dbCancelOrderByCustomer($this->pdo, 106, 5, 1, '');

        $this->assertTrue($result['success']);
    }

    public function testStoresFreeTextDetailWhenProvided(): void
    {
        $this->seedCliente(1, 5);
        $this->seedPedido(107, 'WEB-107', 1, 1, 'pendiente_pago');

        $result = dbCancelOrderByCustomer($this->pdo, 107, 5, 8, '  Ya no lo necesito, compre en otra tienda  ');

        $this->assertTrue($result['success']);
        $log = $this->pdo->query('SELECT motivo_detalle FROM pedido_cancelaciones WHERE id_pedido = 107')->fetch();
        $this->assertSame('Ya no lo necesito, compre en otra tienda', $log['motivo_detalle']);
    }

    public function testTreatsNonPositiveMotivoAsNull(): void
    {
        $this->seedCliente(1, 5);
        $this->seedPedido(108, 'WEB-108', 1, 1, 'pendiente_pago');

        $result = dbCancelOrderByCustomer($this->pdo, 108, 5, 0, '');

        $this->assertTrue($result['success']);
        $log = $this->pdo->query('SELECT id_motivo FROM pedido_cancelaciones WHERE id_pedido = 108')->fetch();
        $this->assertNull($log['id_motivo']);
    }

    public function testReturnsFailureForInvalidIds(): void
    {
        $result = dbCancelOrderByCustomer($this->pdo, 0, 5, 1, '');
        $this->assertFalse($result['success']);

        $result = dbCancelOrderByCustomer($this->pdo, 100, 0, 1, '');
        $this->assertFalse($result['success']);
    }

    public function testReturnsFailureWhenOrderDoesNotExist(): void
    {
        $this->seedCliente(1, 5);

        $result = dbCancelOrderByCustomer($this->pdo, 999999, 5, 1, '');

        $this->assertFalse($result['success']);
    }

    private function createSchema(): void
    {
        $this->pdo->exec('CREATE TABLE clientes (id_cliente INTEGER PRIMARY KEY, id_usuario INTEGER NULL)');
        $this->pdo->exec('CREATE TABLE pedidos (id_pedido INTEGER PRIMARY KEY, numero_pedido TEXT NOT NULL, id_cliente INTEGER NOT NULL, id_almacen INTEGER NOT NULL, estado TEXT NOT NULL, observaciones TEXT NULL)');
        $this->pdo->exec('CREATE TABLE detalle_pedidos (id_detalle INTEGER PRIMARY KEY AUTOINCREMENT, id_pedido INTEGER NOT NULL, id_producto INTEGER NOT NULL, cantidad INTEGER NOT NULL)');
        $this->pdo->exec('CREATE TABLE inventario_almacen (id_producto INTEGER NOT NULL, id_almacen INTEGER NOT NULL, cantidad_actual INTEGER NOT NULL DEFAULT 0)');
        $this->pdo->exec('CREATE TABLE pedido_cancelaciones (id_cancelacion INTEGER PRIMARY KEY AUTOINCREMENT, id_pedido INTEGER NOT NULL UNIQUE, id_motivo INTEGER NULL, motivo_detalle TEXT NULL, cancelado_por INTEGER NOT NULL, fecha_cancelacion TEXT DEFAULT CURRENT_TIMESTAMP)');
    }

    private function createPickupNotificacionesTable(): void
    {
        $this->pdo->exec('CREATE TABLE pickup_notificaciones (id_pedido INTEGER NOT NULL, estado TEXT NOT NULL)');
    }

    private function seedCliente(int $idCliente, ?int $idUsuario): void
    {
        $this->pdo->prepare('INSERT INTO clientes (id_cliente, id_usuario) VALUES (?, ?)')->execute([$idCliente, $idUsuario]);
    }

    private function seedPedido(int $idPedido, string $numeroPedido, int $idCliente, int $idAlmacen, string $estado, string $observaciones = ''): void
    {
        $this->pdo->prepare('INSERT INTO pedidos (id_pedido, numero_pedido, id_cliente, id_almacen, estado, observaciones) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$idPedido, $numeroPedido, $idCliente, $idAlmacen, $estado, $observaciones]);
    }

    private function seedDetalle(int $idPedido, int $idProducto, int $cantidad): void
    {
        $this->pdo->prepare('INSERT INTO detalle_pedidos (id_pedido, id_producto, cantidad) VALUES (?, ?, ?)')->execute([$idPedido, $idProducto, $cantidad]);
    }

    private function seedInventario(int $idProducto, int $idAlmacen, int $cantidadActual): void
    {
        $this->pdo->prepare('INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual) VALUES (?, ?, ?)')->execute([$idProducto, $idAlmacen, $cantidadActual]);
    }
}
