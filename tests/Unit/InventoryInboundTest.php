<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cubre purchaseOrderProcessSingleInbound(), la lógica que api/inventory_handler.php
 * usa para el formulario "Entrada Individual" y para la "Carga Rápida" fila por fila
 * de views/inventario_entradas.php.
 */
final class InventoryInboundTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec('CREATE TABLE inventario_almacen (
            id_inventario INTEGER PRIMARY KEY AUTOINCREMENT,
            id_producto INTEGER NOT NULL,
            id_almacen INTEGER NOT NULL,
            cantidad_actual INTEGER NOT NULL DEFAULT 0,
            stock_minimo INTEGER NOT NULL DEFAULT 2,
            stock_maximo INTEGER NOT NULL DEFAULT 5
        )');
        $this->pdo->exec("CREATE TABLE movimientos_inventario (
            id_movimiento INTEGER PRIMARY KEY AUTOINCREMENT,
            id_producto INTEGER NOT NULL,
            tipo_movimiento TEXT NOT NULL,
            id_almacen_destino INTEGER NULL,
            cantidad INTEGER NOT NULL,
            id_usuario INTEGER NULL,
            observacion TEXT NULL
        )");
    }

    private function seedInventory(int $idProducto, int $idAlmacen, int $actual): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual) VALUES (?, ?, ?)');
        $stmt->execute([$idProducto, $idAlmacen, $actual]);
    }

    private function stockOf(int $idProducto, int $idAlmacen): ?int
    {
        $stmt = $this->pdo->prepare('SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = ? AND id_almacen = ?');
        $stmt->execute([$idProducto, $idAlmacen]);
        $value = $stmt->fetchColumn();

        return $value === false ? null : (int) $value;
    }

    private function movementCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM movimientos_inventario')->fetchColumn();
    }

    public function testIncrementsStockAndRecordsMovement(): void
    {
        $this->seedInventory(10, 1, 2);

        purchaseOrderProcessSingleInbound($this->pdo, 10, 1, 3, 77, 'Factura A-100');

        $this->assertSame(5, $this->stockOf(10, 1));

        $movement = $this->pdo->query('SELECT * FROM movimientos_inventario')->fetch();
        $this->assertSame(10, (int) $movement['id_producto']);
        $this->assertSame('entrada', $movement['tipo_movimiento']);
        $this->assertSame(1, (int) $movement['id_almacen_destino']);
        $this->assertSame(3, (int) $movement['cantidad']);
        $this->assertSame(77, (int) $movement['id_usuario']);
        $this->assertSame('Factura A-100', $movement['observacion']);
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function testUsesDefaultObservationWhenOmitted(): void
    {
        $this->seedInventory(10, 1, 0);

        purchaseOrderProcessSingleInbound($this->pdo, 10, 1, 1, 5);

        $observacion = $this->pdo->query('SELECT observacion FROM movimientos_inventario')->fetchColumn();
        $this->assertSame('Entrada manual', $observacion);
    }

    /**
     * Regresión: antes, un producto que no pertenece a la sucursal dejaba el UPDATE
     * sin efecto pero igual insertaba el movimiento de inventario.
     */
    public function testRejectsProductNotAssignedToThatBranchWithoutSideEffects(): void
    {
        $this->seedInventory(10, 1, 4); // el producto existe en la sucursal 1, no en la 2

        try {
            purchaseOrderProcessSingleInbound($this->pdo, 10, 2, 6, 1);
            $this->fail('Se esperaba RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('El producto no está asignado a esta sucursal.', $e->getMessage());
        }

        $this->assertSame(4, $this->stockOf(10, 1), 'El stock de la sucursal 1 no debe cambiar');
        $this->assertSame(0, $this->movementCount(), 'No debe registrarse ningún movimiento');
        $this->assertFalse($this->pdo->inTransaction(), 'No debe quedar una transacción abierta');
    }

    public function testRejectsProductThatDoesNotExistAnywhere(): void
    {
        $this->expectException(RuntimeException::class);

        try {
            purchaseOrderProcessSingleInbound($this->pdo, 999, 1, 2, 1);
        } finally {
            $this->assertSame(0, $this->movementCount());
        }
    }

    /**
     * @dataProvider invalidPayloads
     */
    public function testRejectsInvalidPayload(int $idProducto, int $idAlmacen, int $cantidad): void
    {
        $this->seedInventory(10, 1, 2);

        try {
            purchaseOrderProcessSingleInbound($this->pdo, $idProducto, $idAlmacen, $cantidad, 1);
            $this->fail('Se esperaba InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Datos de entrada inválidos.', $e->getMessage());
        }

        $this->assertSame(0, $this->movementCount());
        $this->assertSame(2, $this->stockOf(10, 1));
    }

    /**
     * @return array<string, array{0:int,1:int,2:int}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'cantidad cero'      => [10, 1, 0],
            'cantidad negativa'  => [10, 1, -5],
            'id_producto cero'   => [0, 1, 3],
            'id_almacen cero'    => [10, 0, 3],
            'todo en cero'       => [0, 0, 0],
        ];
    }

    public function testConsecutiveInboundsAccumulate(): void
    {
        $this->seedInventory(10, 1, 0);

        purchaseOrderProcessSingleInbound($this->pdo, 10, 1, 4, 1, 'Carga rápida de inventario');
        purchaseOrderProcessSingleInbound($this->pdo, 10, 1, 6, 1, 'Carga rápida de inventario');

        $this->assertSame(10, $this->stockOf(10, 1));
        $this->assertSame(2, $this->movementCount());
    }
}
