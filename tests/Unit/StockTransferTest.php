<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cubre stockTransferExecute(): la lógica de "Transferencia entre Almacenes"
 * (views/transfer_stock.php + api/transfer_stock.php).
 *
 * Verifica que el inventario se afecta en AMBOS lados de forma atómica, que sólo
 * se mueve stock disponible (descontando apartados), que los lotes se reasignan
 * FEFO y que ninguna ruta de error deja una transacción abierta.
 */
final class StockTransferTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec("CREATE TABLE almacenes (
            id_almacen INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre TEXT NOT NULL,
            estado TEXT NOT NULL DEFAULT 'activo'
        )");
        $this->pdo->exec('CREATE TABLE productos (
            id_producto INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre TEXT NOT NULL
        )');
        $this->pdo->exec('CREATE TABLE inventario_almacen (
            id_inventario INTEGER PRIMARY KEY AUTOINCREMENT,
            id_producto INTEGER NOT NULL,
            id_almacen INTEGER NOT NULL,
            cantidad_actual INTEGER NOT NULL DEFAULT 0,
            cantidad_reservada INTEGER NOT NULL DEFAULT 0,
            UNIQUE (id_producto, id_almacen)
        )');
        $this->pdo->exec("CREATE TABLE movimientos_inventario (
            id_movimiento INTEGER PRIMARY KEY AUTOINCREMENT,
            id_producto INTEGER NOT NULL,
            tipo_movimiento TEXT NOT NULL,
            id_almacen_origen INTEGER NULL,
            id_almacen_destino INTEGER NULL,
            cantidad INTEGER NOT NULL,
            id_usuario INTEGER NULL,
            observacion TEXT NULL
        )");
        $this->pdo->exec("CREATE TABLE lotes_inventario (
            id_lote INTEGER PRIMARY KEY AUTOINCREMENT,
            id_producto INTEGER NOT NULL,
            id_almacen INTEGER NULL,
            codigo_lote TEXT NOT NULL,
            fecha_caducidad TEXT NOT NULL,
            cantidad_restante INTEGER NOT NULL,
            estado TEXT NOT NULL DEFAULT 'activo'
        )");

        $this->pdo->exec("INSERT INTO almacenes (id_almacen, nombre, estado) VALUES
            (1, 'Matriz', 'activo'), (2, 'Sucursal Centro', 'activo'), (3, 'Bodega Vieja', 'inactivo')");
        $this->pdo->exec("INSERT INTO productos (id_producto, nombre) VALUES (10, 'Producto A'), (11, 'Producto B')");
    }

    private function seedStock(int $idProducto, int $idAlmacen, int $actual, int $reservada = 0): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual, cantidad_reservada) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$idProducto, $idAlmacen, $actual, $reservada]);
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

    private function transfer(array $overrides = [], int $userId = 7): array
    {
        return stockTransferExecute($this->pdo, array_merge([
            'id_producto' => 10,
            'id_origen'   => 1,
            'id_destino'  => 2,
            'cantidad'    => 5,
            'observacion' => 'Resurtido semanal',
        ], $overrides), $userId);
    }

    public function testMovesStockOnBothSidesAndRecordsMovement(): void
    {
        $this->seedStock(10, 1, 20);
        $this->seedStock(10, 2, 3);

        $result = $this->transfer(['cantidad' => 8]);

        $this->assertSame(12, $this->stockOf(10, 1), 'El origen debe bajar 8');
        $this->assertSame(11, $this->stockOf(10, 2), 'El destino debe subir 8');
        $this->assertSame(12, $result['disponible_origen']);

        $mov = $this->pdo->query('SELECT * FROM movimientos_inventario')->fetch();
        $this->assertSame('transferencia', $mov['tipo_movimiento']);
        $this->assertSame(1, (int) $mov['id_almacen_origen']);
        $this->assertSame(2, (int) $mov['id_almacen_destino']);
        $this->assertSame(8, (int) $mov['cantidad']);
        $this->assertSame(7, (int) $mov['id_usuario']);
        $this->assertStringContainsString('Resurtido semanal', $mov['observacion']);
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function testCreatesDestinationRowWhenProductNotPresentThere(): void
    {
        $this->seedStock(10, 1, 10); // el destino (2) no tiene fila para el producto 10

        $this->transfer(['cantidad' => 4]);

        $this->assertSame(6, $this->stockOf(10, 1));
        $this->assertSame(4, $this->stockOf(10, 2));
    }

    public function testRejectsWhenAvailableStockIsInsufficientBecauseOfReservations(): void
    {
        // 10 en existencia pero 8 apartadas -> sólo 2 disponibles.
        $this->seedStock(10, 1, 10, 8);
        $this->seedStock(10, 2, 0);

        try {
            $this->transfer(['cantidad' => 5]);
            $this->fail('Se esperaba RuntimeException por stock disponible insuficiente');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('insuficiente', strtolower($e->getMessage()));
        }

        $this->assertSame(10, $this->stockOf(10, 1), 'El origen no debe cambiar');
        $this->assertSame(0, $this->stockOf(10, 2), 'El destino no debe cambiar');
        $this->assertSame(0, $this->movementCount());
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function testAllowsTransferOfExactlyTheAvailableStock(): void
    {
        $this->seedStock(10, 1, 10, 6); // 4 disponibles
        $this->seedStock(10, 2, 0);

        $this->transfer(['cantidad' => 4]);

        $this->assertSame(6, $this->stockOf(10, 1));
        $this->assertSame(4, $this->stockOf(10, 2));
    }

    public function testRejectsSameOriginAndDestination(): void
    {
        $this->seedStock(10, 1, 10);

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->transfer(['id_origen' => 1, 'id_destino' => 1]);
        } finally {
            $this->assertSame(10, $this->stockOf(10, 1));
            $this->assertSame(0, $this->movementCount());
            $this->assertFalse($this->pdo->inTransaction());
        }
    }

    /**
     * @dataProvider invalidPayloads
     */
    public function testRejectsInvalidPayload(array $overrides): void
    {
        $this->seedStock(10, 1, 10);

        try {
            $this->transfer($overrides);
            $this->fail('Se esperaba InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(0, $this->movementCount());
            $this->assertSame(10, $this->stockOf(10, 1));
        }
    }

    /** @return array<string, array{0: array<string,mixed>}> */
    public static function invalidPayloads(): array
    {
        return [
            'cantidad cero'      => [['cantidad' => 0]],
            'cantidad negativa'  => [['cantidad' => -3]],
            'producto invalido'  => [['id_producto' => 0]],
            'origen invalido'    => [['id_origen' => 0]],
            'destino invalido'   => [['id_destino' => 0]],
        ];
    }

    public function testRejectsInactiveWarehouse(): void
    {
        $this->seedStock(10, 1, 10);

        $this->expectException(RuntimeException::class);
        try {
            $this->transfer(['id_destino' => 3]); // almacén 3 = inactivo
        } finally {
            $this->assertSame(10, $this->stockOf(10, 1));
            $this->assertSame(0, $this->movementCount());
            $this->assertFalse($this->pdo->inTransaction());
        }
    }

    public function testRejectsNonexistentProduct(): void
    {
        $this->expectException(RuntimeException::class);
        try {
            $this->transfer(['id_producto' => 999]);
        } finally {
            $this->assertSame(0, $this->movementCount());
            $this->assertFalse($this->pdo->inTransaction());
        }
    }

    public function testRejectsWhenOriginHasNoInventoryRow(): void
    {
        $this->seedStock(10, 2, 10); // sólo el destino tiene inventario

        $this->expectException(RuntimeException::class);
        try {
            $this->transfer(['cantidad' => 2]);
        } finally {
            $this->assertSame(10, $this->stockOf(10, 2), 'El destino no debe cambiar');
            $this->assertSame(0, $this->movementCount());
            $this->assertFalse($this->pdo->inTransaction());
        }
    }

    public function testUserIdMustBePositive(): void
    {
        $this->seedStock(10, 1, 10);

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->transfer([], 0);
        } finally {
            $this->assertSame(10, $this->stockOf(10, 1));
            $this->assertSame(0, $this->movementCount());
        }
    }

    public function testReassignsWholeLotsFefoAndReportsUnitsWithoutLot(): void
    {
        $this->seedStock(10, 1, 20);
        $this->seedStock(10, 2, 0);

        // Lote más viejo: 3 u. (cabe). Siguiente: 10 u. (no cabe en el remanente de 4).
        $this->pdo->exec("INSERT INTO lotes_inventario (id_producto, id_almacen, codigo_lote, fecha_caducidad, cantidad_restante, estado) VALUES
            (10, 1, 'L-VIEJO', '2026-01-31', 3, 'activo'),
            (10, 1, 'L-NUEVO', '2026-06-30', 10, 'activo')");

        $result = $this->transfer(['cantidad' => 7]);

        $viejo = $this->pdo->query("SELECT id_almacen FROM lotes_inventario WHERE codigo_lote = 'L-VIEJO'")->fetchColumn();
        $nuevo = $this->pdo->query("SELECT id_almacen FROM lotes_inventario WHERE codigo_lote = 'L-NUEVO'")->fetchColumn();

        $this->assertSame(2, (int) $viejo, 'El lote más próximo a caducar se mueve al destino');
        $this->assertSame(1, (int) $nuevo, 'El lote que no cabe entero se queda en origen');
        $this->assertSame(1, $result['lotes_movidos']);
        $this->assertSame(4, $result['unidades_sin_lote'], '7 movidas - 3 con lote = 4 sin detalle de lote');

        $mov = $this->pdo->query('SELECT observacion FROM movimientos_inventario')->fetchColumn();
        $this->assertStringContainsString('sin detalle de lote', $mov);

        // El inventario numérico sí se movió completo en ambos lados.
        $this->assertSame(13, $this->stockOf(10, 1));
        $this->assertSame(7, $this->stockOf(10, 2));
    }

    public function testDoesNotTouchOtherProductsOrWarehouses(): void
    {
        $this->seedStock(10, 1, 10);
        $this->seedStock(11, 1, 99);
        $this->seedStock(10, 2, 5);

        $this->transfer(['cantidad' => 3]);

        $this->assertSame(99, $this->stockOf(11, 1), 'Otro producto en el mismo almacén no debe cambiar');
        $this->assertSame(7, $this->stockOf(10, 1));
        $this->assertSame(8, $this->stockOf(10, 2));
    }

    // ---- stockTransferExecuteBatch(): lista de varios productos en una sola operación ----

    public function testBatchMovesSeveralProductsAndRecordsOneMovementEach(): void
    {
        $this->seedStock(10, 1, 20);
        $this->seedStock(11, 1, 15);
        $this->seedStock(10, 2, 1);

        $result = stockTransferExecuteBatch($this->pdo, 1, 2, [
            ['id_producto' => 10, 'cantidad' => 6],
            ['id_producto' => 11, 'cantidad' => 4],
        ], 7, 'Reacomodo');

        $this->assertSame(2, $result['lineas']);
        $this->assertSame(10, $result['unidades_totales']);

        $this->assertSame(14, $this->stockOf(10, 1));
        $this->assertSame(7, $this->stockOf(10, 2));
        $this->assertSame(11, $this->stockOf(11, 1));
        $this->assertSame(4, $this->stockOf(11, 2));

        $this->assertSame(2, $this->movementCount(), 'Un movimiento por producto');
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function testBatchConsolidatesRepeatedLinesForTheSameProduct(): void
    {
        $this->seedStock(10, 1, 20);

        $result = stockTransferExecuteBatch($this->pdo, 1, 2, [
            ['id_producto' => 10, 'cantidad' => 3],
            ['id_producto' => 10, 'cantidad' => 5],
        ], 7);

        $this->assertSame(1, $result['lineas']);
        $this->assertSame(8, $result['unidades_totales']);
        $this->assertSame(12, $this->stockOf(10, 1));
        $this->assertSame(8, $this->stockOf(10, 2));
        $this->assertSame(1, $this->movementCount());
    }

    public function testBatchIsAllOrNothingWhenOneLineFails(): void
    {
        $this->seedStock(10, 1, 20);
        $this->seedStock(11, 1, 2); // insuficiente para la segunda línea

        try {
            stockTransferExecuteBatch($this->pdo, 1, 2, [
                ['id_producto' => 10, 'cantidad' => 5],
                ['id_producto' => 11, 'cantidad' => 9],
            ], 7);
            $this->fail('Se esperaba RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('insuficiente', strtolower($e->getMessage()));
        }

        $this->assertSame(20, $this->stockOf(10, 1), 'La primera línea debe revertirse');
        $this->assertNull($this->stockOf(10, 2), 'No debe haberse creado inventario en destino');
        $this->assertSame(2, $this->stockOf(11, 1));
        $this->assertSame(0, $this->movementCount());
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function testBatchRejectsEmptyList(): void
    {
        $this->expectException(InvalidArgumentException::class);
        stockTransferExecuteBatch($this->pdo, 1, 2, [], 7);
    }

    public function testBatchRejectsLineWithoutQuantity(): void
    {
        $this->seedStock(10, 1, 20);

        try {
            stockTransferExecuteBatch($this->pdo, 1, 2, [
                ['id_producto' => 10, 'cantidad' => 0],
            ], 7);
            $this->fail('Se esperaba InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(20, $this->stockOf(10, 1));
            $this->assertSame(0, $this->movementCount());
            $this->assertFalse($this->pdo->inTransaction());
        }
    }

    // ================================================================
    //  Casos extra: entradas hostiles, límites y "hacerlo tronar"
    // ================================================================

    private function reservedOf(int $idProducto, int $idAlmacen): ?int
    {
        $stmt = $this->pdo->prepare('SELECT cantidad_reservada FROM inventario_almacen WHERE id_producto = ? AND id_almacen = ?');
        $stmt->execute([$idProducto, $idAlmacen]);
        $v = $stmt->fetchColumn();

        return $v === false ? null : (int) $v;
    }

    private function loteAlmacenOf(string $codigo): ?int
    {
        $stmt = $this->pdo->prepare('SELECT id_almacen FROM lotes_inventario WHERE codigo_lote = ?');
        $stmt->execute([$codigo]);
        $v = $stmt->fetchColumn();

        // fetchColumn() -> false si no hay fila; null si la fila existe con id_almacen NULL.
        return ($v === false || $v === null) ? null : (int) $v;
    }

    private function lastMovementObservacion(): ?string
    {
        $v = $this->pdo->query('SELECT observacion FROM movimientos_inventario ORDER BY id_movimiento DESC LIMIT 1')->fetchColumn();

        return $v === false ? null : (string) $v;
    }

    private function seedLote(int $idProducto, ?int $idAlmacen, string $codigo, string $caducidad, int $restante, string $estado = 'activo'): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO lotes_inventario (id_producto, id_almacen, codigo_lote, fecha_caducidad, cantidad_restante, estado)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$idProducto, $idAlmacen, $codigo, $caducidad, $restante, $estado]);
    }

    // ---- POSITIVOS: coerción de tipos que SÍ debe funcionar --------------------

    public function testAcceptsNumericStringInputs(): void
    {
        $this->seedStock(10, 1, 10);

        $result = stockTransferExecute($this->pdo, [
            'id_producto' => '10',
            'id_origen'   => '1',
            'id_destino'  => '2',
            'cantidad'    => '4',
            'observacion' => 'strings',
        ], 7);

        $this->assertSame(4, $result['cantidad']);
        $this->assertSame(6, $this->stockOf(10, 1));
        $this->assertSame(4, $this->stockOf(10, 2));
    }

    public function testTruncatesFloatQuantityToInteger(): void
    {
        $this->seedStock(10, 1, 10);

        $this->transfer(['cantidad' => 5.9]);   // (int) 5.9 === 5

        $this->assertSame(5, $this->stockOf(10, 1));
        $this->assertSame(5, $this->stockOf(10, 2));
    }

    public function testTruncatesFloatQuantityStringToInteger(): void
    {
        $this->seedStock(10, 1, 10);

        $this->transfer(['cantidad' => '5.9']);

        $this->assertSame(5, $this->stockOf(10, 1));
    }

    public function testLeavesReservedStockUntouchedAtOrigin(): void
    {
        $this->seedStock(10, 1, 10, 4); // 6 disponibles
        $this->seedStock(10, 2, 0, 2);  // el destino tenía 2 apartadas de otra cosa

        $this->transfer(['cantidad' => 6]);

        $this->assertSame(4, $this->stockOf(10, 1));
        $this->assertSame(4, $this->reservedOf(10, 1), 'La reserva del origen no debe cambiar');
        $this->assertSame(6, $this->stockOf(10, 2));
        $this->assertSame(2, $this->reservedOf(10, 2), 'La reserva del destino no debe cambiar');
    }

    // ---- NEGATIVOS / HOSTILES: no debe tronar, debe rechazar limpio -----------

    public function testRejectsArrayAsProductIdWithoutSideEffects(): void
    {
        $this->seedStock(10, 1, 10);

        try {
            stockTransferExecute($this->pdo, [
                'id_producto' => [1, 2],
                'id_origen'   => 1,
                'id_destino'  => 2,
                'cantidad'    => 3,
            ], 7);
            $this->fail('Se esperaba InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(10, $this->stockOf(10, 1));
            $this->assertSame(0, $this->movementCount());
            $this->assertFalse($this->pdo->inTransaction());
        }
    }

    public function testRejectsArrayAsQuantityWithoutSideEffects(): void
    {
        $this->seedStock(10, 1, 10);

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->transfer(['cantidad' => ['boom']]);
        } finally {
            $this->assertSame(10, $this->stockOf(10, 1));
            $this->assertSame(0, $this->movementCount());
        }
    }

    /**
     * @dataProvider garbageScalars
     */
    public function testRejectsGarbageScalarQuantities(mixed $cantidad): void
    {
        $this->seedStock(10, 1, 10);

        try {
            $this->transfer(['cantidad' => $cantidad]);
            $this->fail('Se esperaba InvalidArgumentException para: ' . var_export($cantidad, true));
        } catch (InvalidArgumentException $e) {
            $this->assertSame(10, $this->stockOf(10, 1));
            $this->assertSame(0, $this->movementCount());
        }
    }

    /** @return array<string, array{0: mixed}> */
    public static function garbageScalars(): array
    {
        return [
            'texto puro'        => ['abc'],
            'solo espacios'     => ['   '],
            'vacio'             => [''],
            'cero string'       => ['0'],
            'negativo string'   => ['-4'],
            'false'             => [false],
            'notacion cero'     => ['0x0'],
        ];
    }

    public function testHugeQuantityIsRejectedAsInsufficientNotOverflow(): void
    {
        $this->seedStock(10, 1, 5);
        $this->seedStock(10, 2, 0);

        try {
            $this->transfer(['cantidad' => PHP_INT_MAX]);
            $this->fail('Se esperaba RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('insuficiente', strtolower($e->getMessage()));
        }

        $this->assertSame(5, $this->stockOf(10, 1), 'No debe quedar stock negativo ni desbordado');
        $this->assertSame(0, $this->stockOf(10, 2));
        $this->assertSame(0, $this->movementCount());
    }

    // ---- LÍMITES exactos ------------------------------------------------------

    public function testRejectsExactlyOneUnitOverAvailable(): void
    {
        $this->seedStock(10, 1, 10, 3); // 7 disponibles

        $this->expectException(RuntimeException::class);
        try {
            $this->transfer(['cantidad' => 8]);
        } finally {
            $this->assertSame(10, $this->stockOf(10, 1));
            $this->assertSame(0, $this->movementCount());
        }
    }

    public function testZeroStockRowAtOriginRejects(): void
    {
        $this->seedStock(10, 1, 0);

        $this->expectException(RuntimeException::class);
        try {
            $this->transfer(['cantidad' => 1]);
        } finally {
            $this->assertSame(0, $this->stockOf(10, 1));
            $this->assertSame(0, $this->movementCount());
        }
    }

    public function testReservedEqualToActualLeavesNothingToMove(): void
    {
        $this->seedStock(10, 1, 5, 5);

        $this->expectException(RuntimeException::class);
        try {
            $this->transfer(['cantidad' => 1]);
        } finally {
            $this->assertSame(5, $this->stockOf(10, 1));
        }
    }

    public function testNegativeReservedIsClampedSoOriginNeverGoesNegative(): void
    {
        // Dato corrupto: reserva negativa. No debe "regalar" disponible extra.
        $this->seedStock(10, 1, 10, -100);
        $this->seedStock(10, 2, 0);

        $this->transfer(['cantidad' => 10]); // exactamente lo que hay en existencia
        $this->assertSame(0, $this->stockOf(10, 1));
        $this->assertSame(10, $this->stockOf(10, 2));

        // Y una unidad más ya no se puede.
        $this->seedStock(11, 1, 10, -5);
        $this->expectException(RuntimeException::class);
        stockTransferExecute($this->pdo, [
            'id_producto' => 11, 'id_origen' => 1, 'id_destino' => 2, 'cantidad' => 11,
        ], 7);
    }

    // ---- OBSERVACIÓN --------------------------------------------------------

    public function testBlankObservationBecomesSinNota(): void
    {
        $this->seedStock(10, 1, 10);

        $this->transfer(['observacion' => "   \t  "]);

        $this->assertSame('Transferencia: Sin nota', $this->lastMovementObservacion());
    }

    public function testLongObservationIsTruncated(): void
    {
        $this->seedStock(10, 1, 10);

        $this->transfer(['observacion' => str_repeat('A', 500)]);

        $obs = $this->lastMovementObservacion();
        $this->assertNotNull($obs);
        $this->assertStringStartsWith('Transferencia: AAAA', $obs);
        // 'Transferencia: ' (15) + 240 de nota = 255 como máximo.
        $this->assertLessThanOrEqual(255, strlen($obs));
    }

    public function testMaliciousObservationIsStoredLiterallyAndBreaksNothing(): void
    {
        $this->seedStock(10, 1, 10);

        $payload = "<script>x</script>'; DROP TABLE movimientos_inventario; --";
        $this->transfer(['observacion' => $payload]);

        $this->assertSame('Transferencia: ' . $payload, $this->lastMovementObservacion());
        // La tabla sigue viva y consultable.
        $this->assertSame(1, $this->movementCount());
        $this->assertSame(5, $this->stockOf(10, 1));
    }

    // ---- LOTES: FEFO y sus rarezas ---------------------------------------

    public function testLotsSummingExactlyToQuantityAllMoveWithNoLeftover(): void
    {
        $this->seedStock(10, 1, 20);
        $this->seedLote(10, 1, 'A', '2026-02-01', 4);
        $this->seedLote(10, 1, 'B', '2026-03-01', 6);

        $result = $this->transfer(['cantidad' => 10]);

        $this->assertSame(2, $result['lotes_movidos']);
        $this->assertSame(0, $result['unidades_sin_lote']);
        $this->assertSame(2, $this->loteAlmacenOf('A'));
        $this->assertSame(2, $this->loteAlmacenOf('B'));
        $this->assertStringNotContainsString('sin detalle de lote', (string) $this->lastMovementObservacion());
    }

    public function testFirstLotLargerThanQuantityMovesNoLot(): void
    {
        $this->seedStock(10, 1, 20);
        $this->seedLote(10, 1, 'GRANDE', '2026-02-01', 15);

        $result = $this->transfer(['cantidad' => 5]);

        $this->assertSame(0, $result['lotes_movidos']);
        $this->assertSame(0, $result['unidades_sin_lote'], 'sin lotes movidos, el reporte de "sin lote" queda en 0');
        $this->assertSame(1, $this->loteAlmacenOf('GRANDE'), 'el lote que no cabe entero se queda en origen');
        $this->assertSame(15, $this->stockOf(10, 1));
        $this->assertSame(5, $this->stockOf(10, 2));
    }

    public function testExpiredDepletedAndForeignWarehouseLotsAreSkipped(): void
    {
        $this->seedStock(10, 1, 20);
        $this->seedLote(10, 1, 'CADUCADO', '2025-01-01', 5, 'caducado');
        $this->seedLote(10, 1, 'AGOTADO', '2026-01-01', 0, 'activo');
        $this->seedLote(10, null, 'SIN-ALMACEN', '2026-01-15', 5, 'activo');
        $this->seedLote(10, 1, 'BUENO', '2026-06-01', 4, 'activo');

        $result = $this->transfer(['cantidad' => 4]);

        $this->assertSame(1, $result['lotes_movidos']);
        $this->assertSame(2, $this->loteAlmacenOf('BUENO'));
        $this->assertSame(1, $this->loteAlmacenOf('CADUCADO'));
        $this->assertSame(1, $this->loteAlmacenOf('AGOTADO'));
        $this->assertNull($this->loteAlmacenOf('SIN-ALMACEN'));
    }

    public function testFefoTieBreaksByLotId(): void
    {
        $this->seedStock(10, 1, 10);
        $this->seedLote(10, 1, 'PRIMERO', '2026-05-05', 2);  // id menor
        $this->seedLote(10, 1, 'SEGUNDO', '2026-05-05', 2);  // misma fecha, id mayor

        $this->transfer(['cantidad' => 2]);

        $this->assertSame(2, $this->loteAlmacenOf('PRIMERO'), 'a igual caducidad, el lote más antiguo (id menor) primero');
        $this->assertSame(1, $this->loteAlmacenOf('SEGUNDO'));
    }

    public function testPartialLotMarkerAppearsOnlyWhenSomeLotMovedAndSomeNot(): void
    {
        $this->seedStock(10, 1, 20);
        $this->seedLote(10, 1, 'CHICO', '2026-02-01', 3);   // cabe
        $this->seedLote(10, 1, 'GRANDE', '2026-03-01', 10);  // no cabe en el remanente de 2

        $result = $this->transfer(['cantidad' => 5]);

        $this->assertSame(1, $result['lotes_movidos']);
        $this->assertSame(2, $result['unidades_sin_lote']);
        $this->assertStringContainsString('sin detalle de lote', (string) $this->lastMovementObservacion());
    }

    // ---- TRANSACCIÓN del llamador --------------------------------------

    public function testHonorsCallerTransactionAndDoesNotCommitOrRollbackItself(): void
    {
        $this->seedStock(10, 1, 10);
        $this->pdo->beginTransaction();

        $this->transfer(['cantidad' => 4]);

        $this->assertTrue($this->pdo->inTransaction(), 'la transacción sigue siendo del llamador');
        $this->pdo->rollBack();

        $this->assertSame(10, $this->stockOf(10, 1), 'el rollback del llamador revierte todo');
        $this->assertNull($this->stockOf(10, 2));
        $this->assertSame(0, $this->movementCount());
    }

    public function testOnErrorInsideCallerTransactionItDoesNotAutoRollback(): void
    {
        $this->seedStock(10, 1, 2);
        $this->pdo->beginTransaction();

        try {
            $this->transfer(['cantidad' => 5]);
            $this->fail('Se esperaba RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertTrue($this->pdo->inTransaction(), 'el llamador es responsable de su propio rollback');
        }

        $this->pdo->rollBack();
        $this->assertSame(2, $this->stockOf(10, 1));
    }

    // ---- BATCH: límites y entradas hostiles --------------------------------

    public function testBatchAcceptsExactly200ConsolidatedLines(): void
    {
        $this->seedStock(10, 1, 200);

        $items = array_fill(0, 200, ['id_producto' => 10, 'cantidad' => 1]);
        $result = stockTransferExecuteBatch($this->pdo, 1, 2, $items, 7);

        $this->assertSame(1, $result['lineas']);
        $this->assertSame(200, $result['unidades_totales']);
        $this->assertSame(0, $this->stockOf(10, 1));
        $this->assertSame(200, $this->stockOf(10, 2));
        $this->assertSame(1, $this->movementCount());
    }

    public function testBatchRejects201Lines(): void
    {
        $this->seedStock(10, 1, 500);
        $items = array_fill(0, 201, ['id_producto' => 10, 'cantidad' => 1]);

        try {
            stockTransferExecuteBatch($this->pdo, 1, 2, $items, 7);
            $this->fail('Se esperaba InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(500, $this->stockOf(10, 1));
            $this->assertSame(0, $this->movementCount());
            $this->assertFalse($this->pdo->inTransaction());
        }
    }

    public function testBatchRejectsNonArrayLine(): void
    {
        $this->seedStock(10, 1, 10);

        $this->expectException(InvalidArgumentException::class);
        try {
            stockTransferExecuteBatch($this->pdo, 1, 2, ['no soy un renglón'], 7);
        } finally {
            $this->assertSame(10, $this->stockOf(10, 1));
            $this->assertSame(0, $this->movementCount());
        }
    }

    public function testBatchRejectsArrayValuedProductIdWithoutSideEffects(): void
    {
        $this->seedStock(10, 1, 10);

        try {
            stockTransferExecuteBatch($this->pdo, 1, 2, [
                ['id_producto' => 10, 'cantidad' => 2],
                ['id_producto' => ['x'], 'cantidad' => 1],
            ], 7);
            $this->fail('Se esperaba InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(10, $this->stockOf(10, 1), 'la validación ocurre antes de abrir transacción');
            $this->assertSame(0, $this->movementCount());
            $this->assertFalse($this->pdo->inTransaction());
        }
    }

    public function testBatchRejectsAbsurdConsolidatedTotal(): void
    {
        $this->seedStock(10, 1, 10);

        try {
            stockTransferExecuteBatch($this->pdo, 1, 2, [
                ['id_producto' => 10, 'cantidad' => 600000000],
                ['id_producto' => 10, 'cantidad' => 600000000],
            ], 7);
            $this->fail('Se esperaba InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('grande', strtolower($e->getMessage()));
            $this->assertSame(10, $this->stockOf(10, 1));
            $this->assertSame(0, $this->movementCount());
        }
    }

    public function testBatchWithSameOriginAndDestinationRollsBackEverything(): void
    {
        $this->seedStock(10, 1, 10);
        $this->seedStock(11, 1, 10);

        try {
            stockTransferExecuteBatch($this->pdo, 1, 1, [
                ['id_producto' => 10, 'cantidad' => 2],
                ['id_producto' => 11, 'cantidad' => 2],
            ], 7);
            $this->fail('Se esperaba InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertSame(10, $this->stockOf(10, 1));
            $this->assertSame(10, $this->stockOf(11, 1));
            $this->assertSame(0, $this->movementCount());
            $this->assertFalse($this->pdo->inTransaction());
        }
    }

    public function testBatchWithZeroUserIdRejected(): void
    {
        $this->seedStock(10, 1, 10);

        $this->expectException(InvalidArgumentException::class);
        try {
            stockTransferExecuteBatch($this->pdo, 1, 2, [['id_producto' => 10, 'cantidad' => 2]], 0);
        } finally {
            $this->assertSame(10, $this->stockOf(10, 1));
            $this->assertSame(0, $this->movementCount());
        }
    }

    public function testBatchConsolidationMakesRepeatedLinesFailAsOneWhenOverStock(): void
    {
        // 7 disponibles; dos renglones de 5 y 3 => 8 consolidado => falla ENTERO.
        $this->seedStock(10, 1, 7);

        try {
            stockTransferExecuteBatch($this->pdo, 1, 2, [
                ['id_producto' => 10, 'cantidad' => 5],
                ['id_producto' => 10, 'cantidad' => 3],
            ], 7);
            $this->fail('Se esperaba RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('insuficiente', strtolower($e->getMessage()));
        }

        $this->assertSame(7, $this->stockOf(10, 1), 'nada se aplicó');
        $this->assertNull($this->stockOf(10, 2));
        $this->assertSame(0, $this->movementCount());
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function testBatchRollsBackEarlierProductWhenLaterProductDoesNotExist(): void
    {
        $this->seedStock(10, 1, 10);

        try {
            stockTransferExecuteBatch($this->pdo, 1, 2, [
                ['id_producto' => 10, 'cantidad' => 3],
                ['id_producto' => 999, 'cantidad' => 1],
            ], 7);
            $this->fail('Se esperaba RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no existe', strtolower($e->getMessage()));
        }

        $this->assertSame(10, $this->stockOf(10, 1), 'la primera línea, ya aplicada, se revierte');
        $this->assertNull($this->stockOf(10, 2));
        $this->assertSame(0, $this->movementCount());
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function testBatchAcceptsAssociativeItemsContainer(): void
    {
        $this->seedStock(10, 1, 10);
        $this->seedStock(11, 1, 10);

        // json_decode de un objeto JSON produce un array asociativo, no una lista.
        $items = [
            'linea-a' => ['id_producto' => 10, 'cantidad' => 2],
            'linea-b' => ['id_producto' => 11, 'cantidad' => 3],
        ];
        $result = stockTransferExecuteBatch($this->pdo, 1, 2, $items, 7);

        $this->assertSame(2, $result['lineas']);
        $this->assertSame(8, $this->stockOf(10, 1));
        $this->assertSame(7, $this->stockOf(11, 1));
    }

    public function testBatchDetailIsKeyedByProductWithPerLineNumbers(): void
    {
        $this->seedStock(10, 1, 10);
        $this->seedStock(11, 1, 10);

        $result = stockTransferExecuteBatch($this->pdo, 1, 2, [
            ['id_producto' => 10, 'cantidad' => 2],
            ['id_producto' => 11, 'cantidad' => 6],
        ], 7);

        $this->assertArrayHasKey(10, $result['detalle']);
        $this->assertArrayHasKey(11, $result['detalle']);
        $this->assertSame(2, $result['detalle'][10]['cantidad']);
        $this->assertSame(6, $result['detalle'][11]['cantidad']);
    }

    public function testBatchNumericStringQuantitiesWork(): void
    {
        $this->seedStock(10, 1, 10);

        $result = stockTransferExecuteBatch($this->pdo, 1, 2, [
            ['id_producto' => '10', 'cantidad' => '4'],
        ], 7);

        $this->assertSame(4, $result['unidades_totales']);
        $this->assertSame(6, $this->stockOf(10, 1));
    }
}
