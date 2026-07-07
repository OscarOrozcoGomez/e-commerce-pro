<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PurchaseOrderFlowTest extends TestCase
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

    public function testPostponeHidesItemFromSuggestedPurchaseList(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Producto A');
        $this->seedInventory(10, 1, 1, 2, 5);

        $before = purchaseOrderFetchSuggestions($this->pdo, true, null);
        $this->assertCount(1, $before['listaCompra']);

        $affected = purchaseOrderPostponeItems($this->pdo, [
            ['id_producto' => 10, 'id_almacen' => 1, 'motivo' => 'Proveedor sin stock'],
        ], 99);

        $this->assertSame(1, $affected);

        $after = purchaseOrderFetchSuggestions($this->pdo, true, null);
        $this->assertCount(0, $after['listaCompra']);

        $row = $this->pdo->query("SELECT estado, motivo, pospuesto_por FROM purchase_order_postponed_items WHERE id_producto = 10 AND id_almacen = 1")->fetch();
        $this->assertSame('pendiente', $row['estado']);
        $this->assertSame('Proveedor sin stock', $row['motivo']);
        $this->assertSame(99, (int) $row['pospuesto_por']);
    }

    public function testInboundUpdatesInventoryCreatesMovementAndReactivatesPostponedItems(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Producto A');
        $this->seedProduct(20, 'Producto B');

        $this->seedInventory(10, 1, 1, 2, 5);
        $this->seedInventory(20, 1, 1, 2, 5);

        purchaseOrderPostponeItems($this->pdo, [
            ['id_producto' => 10, 'id_almacen' => 1],
        ], 50);

        $processed = purchaseOrderProcessInbound($this->pdo, [
            ['id_producto' => 20, 'id_almacen' => 1, 'cantidad' => 4],
        ], 50);

        $this->assertSame(1, $processed);

        $stock = $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = 20 AND id_almacen = 1')->fetch();
        $this->assertSame(5, (int) $stock['cantidad_actual']);

        $movement = $this->pdo->query("SELECT id_producto, tipo_movimiento, id_almacen_destino, cantidad, id_usuario FROM movimientos_inventario")->fetch();
        $this->assertSame(20, (int) $movement['id_producto']);
        $this->assertSame('entrada', $movement['tipo_movimiento']);
        $this->assertSame(1, (int) $movement['id_almacen_destino']);
        $this->assertSame(4, (int) $movement['cantidad']);
        $this->assertSame(50, (int) $movement['id_usuario']);

        $postponed = $this->pdo->query('SELECT estado FROM purchase_order_postponed_items WHERE id_producto = 10 AND id_almacen = 1')->fetch();
        $this->assertSame('reactivado', $postponed['estado']);

        $nextRound = purchaseOrderFetchSuggestions($this->pdo, true, null);
        $productIds = array_map(static fn(array $row): int => (int) $row['id_producto'], $nextRound['listaCompra']);
        $this->assertContains(10, $productIds);
    }

    private function createSchema(): void
    {
        $this->pdo->exec('CREATE TABLE almacenes (id_almacen INTEGER PRIMARY KEY, nombre TEXT NOT NULL)');
        $this->pdo->exec("CREATE TABLE productos (id_producto INTEGER PRIMARY KEY, nombre TEXT NOT NULL, sku TEXT NULL, precio_costo REAL NOT NULL DEFAULT 0, precio_venta REAL NOT NULL DEFAULT 0, estado TEXT NOT NULL DEFAULT 'activo')");
        $this->pdo->exec('CREATE TABLE inventario_almacen (id_inventario INTEGER PRIMARY KEY AUTOINCREMENT, id_producto INTEGER NOT NULL, id_almacen INTEGER NOT NULL, cantidad_actual INTEGER NOT NULL DEFAULT 0, stock_minimo INTEGER NOT NULL DEFAULT 2, stock_maximo INTEGER NOT NULL DEFAULT 5)');
        $this->pdo->exec('CREATE TABLE categorias (id_categoria INTEGER PRIMARY KEY, nombre TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE producto_categorias (id_producto INTEGER NOT NULL, id_categoria INTEGER NOT NULL)');
        $this->pdo->exec("CREATE TABLE movimientos_inventario (id_movimiento INTEGER PRIMARY KEY AUTOINCREMENT, id_producto INTEGER NOT NULL, tipo_movimiento TEXT NOT NULL, id_almacen_destino INTEGER NULL, cantidad INTEGER NOT NULL, id_usuario INTEGER NULL, observacion TEXT NULL)");
        $this->pdo->exec("CREATE TABLE purchase_order_postponed_items (id_postergacion INTEGER PRIMARY KEY AUTOINCREMENT, id_producto INTEGER NOT NULL, id_almacen INTEGER NOT NULL, estado TEXT NOT NULL DEFAULT 'pendiente', motivo TEXT NULL, pospuesto_por INTEGER NULL, pospuesto_en TEXT DEFAULT CURRENT_TIMESTAMP, reactivado_en TEXT NULL)");
        $this->pdo->exec('CREATE UNIQUE INDEX uq_po_postergado_producto_almacen ON purchase_order_postponed_items(id_producto, id_almacen)');
    }

    private function seedWarehouse(int $id, string $nombre): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO almacenes (id_almacen, nombre) VALUES (?, ?)');
        $stmt->execute([$id, $nombre]);
    }

    private function seedProduct(int $id, string $nombre): void
    {
        $stmt = $this->pdo->prepare("INSERT INTO productos (id_producto, nombre, sku, precio_costo, precio_venta, estado) VALUES (?, ?, ?, ?, ?, 'activo')");
        $stmt->execute([$id, $nombre, 'SKU-' . $id, 10.0, 20.0]);
    }

    private function seedInventory(int $idProducto, int $idAlmacen, int $actual, int $minimo, int $maximo): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual, stock_minimo, stock_maximo) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$idProducto, $idAlmacen, $actual, $minimo, $maximo]);
    }
}
