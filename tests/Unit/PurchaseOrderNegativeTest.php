<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pruebas NEGATIVAS de core/purchase_order_utils.php: entradas invalidas, vacias,
 * corruptas y casos de borde deben rechazarse / filtrarse sin efectos colaterales
 * (sin movimientos de inventario fantasma, sin transacciones abiertas, sin fatales).
 */
final class PurchaseOrderNegativeTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec('CREATE TABLE almacenes (id_almacen INTEGER PRIMARY KEY, nombre TEXT NOT NULL)');
        $this->pdo->exec("CREATE TABLE productos (id_producto INTEGER PRIMARY KEY, nombre TEXT NOT NULL, sku TEXT NULL, precio_costo REAL NOT NULL DEFAULT 0, precio_venta REAL NOT NULL DEFAULT 0, estado TEXT NOT NULL DEFAULT 'activo')");
        $this->pdo->exec('CREATE TABLE inventario_almacen (id_inventario INTEGER PRIMARY KEY AUTOINCREMENT, id_producto INTEGER NOT NULL, id_almacen INTEGER NOT NULL, cantidad_actual INTEGER NOT NULL DEFAULT 0, stock_minimo INTEGER NOT NULL DEFAULT 2, stock_maximo INTEGER NOT NULL DEFAULT 5)');
        $this->pdo->exec('CREATE TABLE categorias (id_categoria INTEGER PRIMARY KEY, nombre TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE producto_categorias (id_producto INTEGER NOT NULL, id_categoria INTEGER NOT NULL)');
        $this->pdo->exec("CREATE TABLE movimientos_inventario (id_movimiento INTEGER PRIMARY KEY AUTOINCREMENT, id_producto INTEGER NOT NULL, tipo_movimiento TEXT NOT NULL, id_almacen_destino INTEGER NULL, cantidad INTEGER NOT NULL, id_usuario INTEGER NULL, observacion TEXT NULL)");
        $this->pdo->exec("CREATE TABLE purchase_order_postponed_items (id_postergacion INTEGER PRIMARY KEY AUTOINCREMENT, id_producto INTEGER NOT NULL, id_almacen INTEGER NOT NULL, estado TEXT NOT NULL DEFAULT 'pendiente', motivo TEXT NULL, pospuesto_por INTEGER NULL, pospuesto_en TEXT DEFAULT CURRENT_TIMESTAMP, reactivado_en TEXT NULL)");
        $this->pdo->exec('CREATE UNIQUE INDEX uq_po_post ON purchase_order_postponed_items(id_producto, id_almacen)');
    }

    private function movementCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM movimientos_inventario')->fetchColumn();
    }

    private function seedInv(int $prod, int $alm, int $actual, int $min = 2, int $max = 5): void
    {
        $this->pdo->prepare('INSERT INTO productos (id_producto, nombre) VALUES (?, ?)')->execute([$prod, "P$prod"]);
        $this->pdo->prepare('INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual, stock_minimo, stock_maximo) VALUES (?,?,?,?,?)')
             ->execute([$prod, $alm, $actual, $min, $max]);
    }

    // ---------------------------------------------------------------------
    // purchaseOrderNormalizeInboundItems
    // ---------------------------------------------------------------------

    /**
     * @dataProvider invalidInboundItemSets
     */
    public function testNormalizeInboundDropsInvalidItems(array $input): void
    {
        $this->assertSame([], purchaseOrderNormalizeInboundItems($input));
    }

    public static function invalidInboundItemSets(): array
    {
        return [
            'vacio'                 => [[]],
            'id_producto 0'         => [[['id_producto' => 0, 'id_almacen' => 1, 'cantidad' => 5]]],
            'id_producto negativo'  => [[['id_producto' => -1, 'id_almacen' => 1, 'cantidad' => 5]]],
            'id_almacen 0'          => [[['id_producto' => 1, 'id_almacen' => 0, 'cantidad' => 5]]],
            'cantidad 0'            => [[['id_producto' => 1, 'id_almacen' => 1, 'cantidad' => 0]]],
            'cantidad negativa'     => [[['id_producto' => 1, 'id_almacen' => 1, 'cantidad' => -3]]],
            'falta cantidad'        => [[['id_producto' => 1, 'id_almacen' => 1]]],
            'entradas no-array'     => [['basura', 42, null, true]],
            'cantidad no numerica'  => [[['id_producto' => 1, 'id_almacen' => 1, 'cantidad' => 'abc']]],
        ];
    }

    public function testNormalizeInboundKeepsOnlyValidAndWhitelistedKeys(): void
    {
        $out = purchaseOrderNormalizeInboundItems([
            ['id_producto' => 7, 'id_almacen' => 3, 'cantidad' => 4, 'precio_costo' => '999', 'hack' => '<script>'],
            ['id_producto' => 0, 'id_almacen' => 3, 'cantidad' => 4],           // se descarta
            'no-array',                                                          // se descarta
        ]);

        $this->assertCount(1, $out);
        $this->assertSame(['id_producto' => 7, 'id_almacen' => 3, 'cantidad' => 4], $out[0]);
        $this->assertArrayNotHasKey('precio_costo', $out[0]);
        $this->assertArrayNotHasKey('hack', $out[0]);
    }

    public function testNormalizeInboundCoercesNumericStringsWithoutThrowing(): void
    {
        $out = purchaseOrderNormalizeInboundItems([['id_producto' => '5abc', 'id_almacen' => '2', 'cantidad' => '3xyz']]);
        $this->assertSame([['id_producto' => 5, 'id_almacen' => 2, 'cantidad' => 3]], $out);
    }

    // ---------------------------------------------------------------------
    // purchaseOrderNormalizePostponeItems
    // ---------------------------------------------------------------------

    public function testNormalizePostponeDropsInvalidAndDefaultsMotivo(): void
    {
        $out = purchaseOrderNormalizePostponeItems([
            ['id_producto' => 0, 'id_almacen' => 1],                 // id invalido -> fuera
            ['id_producto' => 9, 'id_almacen' => 0],                 // almacen invalido -> fuera
            'basura',                                                // no-array -> fuera
            ['id_producto' => 9, 'id_almacen' => 2],                 // sin motivo -> default
            ['id_producto' => 10, 'id_almacen' => 2, 'motivo' => '   Sin proveedor   '],
        ]);

        $this->assertCount(2, $out);
        $this->assertSame('No disponible por proveedor', $out[0]['motivo']);
        $this->assertSame('Sin proveedor', $out[1]['motivo']);
    }

    public function testNormalizePostponeEmptyStaysEmpty(): void
    {
        $this->assertSame([], purchaseOrderNormalizePostponeItems([]));
        $this->assertSame([], purchaseOrderNormalizePostponeItems(['x', 1, null]));
    }

    // ---------------------------------------------------------------------
    // purchaseOrderProcessSingleInbound  (rutas de rechazo)
    // ---------------------------------------------------------------------

    public function testSingleInboundInvalidArgLeavesNoOpenTransactionAndNoMovement(): void
    {
        $this->seedInv(1, 1, 2);
        try {
            purchaseOrderProcessSingleInbound($this->pdo, 1, 1, 0, 5); // cantidad 0
            $this->fail('esperaba InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('Datos de entrada inválidos.', $e->getMessage());
        }
        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(0, $this->movementCount());
        $this->assertSame(2, (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen')->fetchColumn());
    }

    public function testSingleInboundWrongBranchRejectedWithNoSideEffects(): void
    {
        $this->seedInv(1, 1, 4);                                  // producto solo en almacen 1
        try {
            purchaseOrderProcessSingleInbound($this->pdo, 1, 99, 3, 5); // se pide para almacen 99
            $this->fail('esperaba RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertSame('El producto no está asignado a esta sucursal.', $e->getMessage());
        }
        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(0, $this->movementCount());
        $this->assertSame(4, (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen WHERE id_almacen = 1')->fetchColumn());
    }

    // ---------------------------------------------------------------------
    // purchaseOrderProcessInbound (batch) — rutas negativas
    // ---------------------------------------------------------------------

    public function testBatchInboundEmptyReturnsZeroAndDoesNothing(): void
    {
        $this->assertSame(0, purchaseOrderProcessInbound($this->pdo, [], 1));
        $this->assertSame(0, $this->movementCount());
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function testBatchInboundAllInvalidReturnsZero(): void
    {
        $this->seedInv(1, 1, 5);
        $processed = purchaseOrderProcessInbound($this->pdo, [
            ['id_producto' => 0, 'id_almacen' => 1, 'cantidad' => 3],
            ['id_producto' => 1, 'id_almacen' => 1, 'cantidad' => 0],
            ['id_producto' => 1, 'id_almacen' => 1, 'cantidad' => -9],
            'no-array',
        ], 1);

        $this->assertSame(0, $processed);
        $this->assertSame(0, $this->movementCount());
        $this->assertSame(5, (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen')->fetchColumn());
    }

    public function testBatchInboundSkipsItemWhoseInventoryRowDoesNotExistWithoutPhantomMovement(): void
    {
        // No hay ninguna fila en inventario_almacen: el UPDATE no afecta filas.
        $processed = purchaseOrderProcessInbound($this->pdo, [
            ['id_producto' => 123, 'id_almacen' => 1, 'cantidad' => 5],
        ], 1);

        $this->assertSame(0, $processed, 'no debe contar como procesado');
        $this->assertSame(0, $this->movementCount(), 'no debe insertar movimiento fantasma');
    }

    public function testBatchInboundMixedProcessesOnlyValidRows(): void
    {
        $this->seedInv(1, 1, 5);   // valido
        $this->seedInv(2, 1, 5);   // se le pasa cantidad 0

        $processed = purchaseOrderProcessInbound($this->pdo, [
            ['id_producto' => 1, 'id_almacen' => 1, 'cantidad' => 4],
            ['id_producto' => 2, 'id_almacen' => 1, 'cantidad' => 0],   // invalido
            ['id_producto' => 777, 'id_almacen' => 1, 'cantidad' => 2], // inexistente
        ], 50);

        $this->assertSame(1, $processed);
        $this->assertSame(1, $this->movementCount());
        $this->assertSame(9, (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = 1')->fetchColumn());
        $this->assertSame(5, (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = 2')->fetchColumn());
    }

    // ---------------------------------------------------------------------
    // purchaseOrderPostponeItems — rutas negativas
    // ---------------------------------------------------------------------

    public function testPostponeItemsWithGarbageReturnsZeroWithoutOpeningTransaction(): void
    {
        $this->assertSame(0, purchaseOrderPostponeItems($this->pdo, [], 1));
        $this->assertSame(0, purchaseOrderPostponeItems($this->pdo, [['id_producto' => 0, 'id_almacen' => 0]], 1));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM purchase_order_postponed_items')->fetchColumn());
        $this->assertFalse($this->pdo->inTransaction());
    }

    // ---------------------------------------------------------------------
    // purchaseOrderFetchSuggestions — que NO liste lo que no debe
    // ---------------------------------------------------------------------

    public function testFetchSuggestionsExcludesStockAboveMinimum(): void
    {
        $this->pdo->prepare('INSERT INTO almacenes (id_almacen, nombre) VALUES (1, ?)')->execute(['Matriz']);
        $this->seedInv(1, 1, 10, 2, 20); // 10 > min 2  => NO debe aparecer

        $res = purchaseOrderFetchSuggestions($this->pdo, true, null);
        $this->assertCount(0, $res['listaCompra']);
    }

    public function testFetchSuggestionsExcludesPostponedProduct(): void
    {
        $this->pdo->prepare('INSERT INTO almacenes (id_almacen, nombre) VALUES (1, ?)')->execute(['Matriz']);
        $this->seedInv(1, 1, 1, 2, 5); // bajo minimo => apareceria...
        purchaseOrderPostponeItems($this->pdo, [['id_producto' => 1, 'id_almacen' => 1]], 9); // ...pero pospuesto

        $res = purchaseOrderFetchSuggestions($this->pdo, true, null);
        $this->assertCount(0, $res['listaCompra']);
    }

    public function testFetchSuggestionsEncargadoWrongWarehouseGetsNothing(): void
    {
        $this->pdo->prepare('INSERT INTO almacenes (id_almacen, nombre) VALUES (1, ?)')->execute(['Matriz']);
        $this->seedInv(1, 1, 0, 2, 5); // faltante real en almacen 1

        $res = purchaseOrderFetchSuggestions($this->pdo, false, 2); // encargado del almacen 2
        $this->assertCount(0, $res['listaCompra']);
    }
}
