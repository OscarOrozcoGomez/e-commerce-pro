<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * consultar_ofertas (Alex): combina la categoria de Ofertas (core/oferta_pricing.php) con
 * el control de caducidades por lote (core/lote_caducidad_utils.php) para que Alex nunca
 * ofrezca un producto cuyo unico stock restante ya caduco o no alcanza a consumirse antes
 * de caducar, aunque siga capturado en la categoria de Ofertas.
 *
 * Tambien cubre aiStockVendible()/aiStockVendiblePorLotesBatch() -- la misma regla la
 * comparten consultar_inventario (aiSearchInventory) y agendar_venta (aiResolveOrderItems),
 * por eso viven aqui y no en AiAssistantToolsTest.php: necesitan el esquema completo de
 * lotes_inventario/pedidos/detalle_pedidos que ese archivo no tiene.
 */
final class AiAssistantOfertasTest extends TestCase
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

    public function testIncluyeProductoEnOfertaSinLotesUsandoStockDeInventario(): void
    {
        $idOferta = $this->seedCategoriaOferta();
        $this->seedProducto(1, 'Omega 3', 499.0, 200.0);
        $this->ponerEnOferta(1, $idOferta);
        $this->seedInventario(1, 1, 5);

        $ofertas = aiListarOfertasVigentes($this->pdo);

        $this->assertCount(1, $ofertas);
        $this->assertSame(1, $ofertas[0]['id_producto']);
        $this->assertSame(250.0, $ofertas[0]['precio_oferta']); // costo + $50
        $this->assertSame(499.0, $ofertas[0]['precio_normal']);
        $this->assertSame(249.0, $ofertas[0]['ahorro']);
        $this->assertSame(5, $ofertas[0]['stock']);
    }

    public function testExcluyeProductoQueNoEstaEnLaCategoriaDeOfertas(): void
    {
        $this->seedProducto(2, 'Vitamina C', 150.0, 60.0);
        $this->seedInventario(2, 1, 10);

        $this->assertSame([], aiListarOfertasVigentes($this->pdo));
    }

    public function testUsaPrecioOfertaManualCuandoElAdminLoCapturo(): void
    {
        $idOferta = $this->seedCategoriaOferta();
        $this->seedProducto(3, 'Colageno', 399.0, 150.0, 180.0);
        $this->ponerEnOferta(3, $idOferta);
        $this->seedInventario(3, 1, 8);

        $ofertas = aiListarOfertasVigentes($this->pdo);

        $this->assertCount(1, $ofertas);
        $this->assertSame(180.0, $ofertas[0]['precio_oferta']);
    }

    public function testExcluyeProductoCuyoUnicoLoteYaCaduco(): void
    {
        $idOferta = $this->seedCategoriaOferta();
        $this->seedProducto(4, 'Zinc', 120.0, 50.0);
        $this->ponerEnOferta(4, $idOferta);
        // El inventario del sistema todavia marca existencia (descuadre real observado en
        // produccion: el lote ya caduco pero nadie ha limpiado inventario_almacen).
        $this->seedInventario(4, 1, 6);
        $this->seedLote(4, 'L-VENCIDO', $this->enDias(-10), 6);

        $this->assertSame([], aiListarOfertasVigentes($this->pdo));
    }

    public function testExcluyeProductoNoVendibleAunqueNoHayaCaducadoTodavia(): void
    {
        // El envase rinde mas dias de los que faltan para caducar: nadie alcanza a
        // terminarselo -> no_vendible, aunque la fecha de caducidad todavia no llegue.
        $idOferta = $this->seedCategoriaOferta();
        $this->seedProducto(5, 'Melatonina', 200.0, 80.0, null, null, 'activo', 60, 2); // 30 dias de tratamiento
        $this->ponerEnOferta(5, $idOferta);
        $this->seedInventario(5, 1, 4);
        $this->seedLote(5, 'L-CORTO', $this->enDias(10), 4); // caduca en 10 dias, rinde 30

        $this->assertSame([], aiListarOfertasVigentes($this->pdo));
    }

    public function testIncluyeSoloElStockDelLoteVendibleCuandoHayOtroYaCaducado(): void
    {
        $idOferta = $this->seedCategoriaOferta();
        $this->seedProducto(6, 'Magnesio', 180.0, 70.0);
        $this->ponerEnOferta(6, $idOferta);
        $this->seedInventario(6, 1, 9);
        $this->seedLote(6, 'L-VIEJO', $this->enDias(-5), 4);   // caduco: no cuenta
        $this->seedLote(6, 'L-NUEVO', $this->enDias(400), 5);  // vigente: si cuenta

        $ofertas = aiListarOfertasVigentes($this->pdo);

        $this->assertCount(1, $ofertas);
        $this->assertSame(5, $ofertas[0]['stock']);
    }

    public function testBusquedaTextoFiltraPorNombre(): void
    {
        $idOferta = $this->seedCategoriaOferta();
        $this->seedProducto(7, 'Omega 3', 499.0, 200.0);
        $this->seedProducto(8, 'Colageno', 399.0, 150.0);
        $this->ponerEnOferta(7, $idOferta);
        $this->ponerEnOferta(8, $idOferta);
        $this->seedInventario(7, 1, 5);
        $this->seedInventario(8, 1, 5);

        $ofertas = aiListarOfertasVigentes($this->pdo, 'colageno');

        $this->assertCount(1, $ofertas);
        $this->assertSame(8, $ofertas[0]['id_producto']);
    }

    public function testToolConsultarOfertasRegresaMensajeCuandoNoHayNinguna(): void
    {
        $resultado = aiToolConsultarOfertas($this->pdo, []);

        $this->assertTrue($resultado['ok']);
        $this->assertSame([], $resultado['ofertas']);
        $this->assertSame('No hay ninguna oferta vigente ahorita.', $resultado['message']);
    }

    public function testToolConsultarOfertasRegresaLaListaCuandoSiHay(): void
    {
        $idOferta = $this->seedCategoriaOferta();
        $this->seedProducto(9, 'Creatina', 350.0, 140.0);
        $this->ponerEnOferta(9, $idOferta);
        $this->seedInventario(9, 1, 3);

        $resultado = aiToolConsultarOfertas($this->pdo, []);

        $this->assertTrue($resultado['ok']);
        $this->assertCount(1, $resultado['ofertas']);
        $this->assertSame(1, $resultado['total_encontradas']);
        $this->assertArrayNotHasKey('message', $resultado);
    }

    public function testToolConsultarOfertasAvisaCuandoHayMasDeLasQueSeMuestran(): void
    {
        $idOferta = $this->seedCategoriaOferta();
        // AI_OFERTAS_SEARCH_LIMIT = 12 -- sembramos una mas para forzar el aviso de truncado.
        for ($i = 100; $i < 113; $i++) {
            $this->seedProducto($i, "Producto Oferta {$i}", 100.0, 40.0);
            $this->ponerEnOferta($i, $idOferta);
            $this->seedInventario($i, 1, 5);
        }

        $resultado = aiToolConsultarOfertas($this->pdo, []);

        $this->assertSame(13, $resultado['total_encontradas']);
        $this->assertCount(12, $resultado['ofertas']);
        $this->assertArrayHasKey('message', $resultado);
        $this->assertStringContainsString('13 ofertas vigentes', $resultado['message']);
    }

    // ------------------------------------------------------------------
    // aiStockVendible() / aiStockVendiblePorLotesBatch(): la misma regla de "no ofrecer
    // ni vender lo que ya caduco o no alcanza a consumirse a tiempo" aplicada a
    // consultar_inventario (aiSearchInventory) y agendar_venta (aiResolveOrderItems), no
    // solo a consultar_ofertas.
    // ------------------------------------------------------------------

    public function testAiSearchInventoryCapaStockAUnidadesRealmenteVendibles(): void
    {
        // Producto normal, ni siquiera en la categoria de Ofertas -- consultar_inventario
        // tampoco debe presumir mas existencia de la que en realidad se puede vender.
        $this->seedProducto(20, 'Proteina Whey', 599.0, 250.0);
        $this->seedInventario(20, 1, 20);
        $this->seedLote(20, 'L-VIEJO', $this->enDias(-3), 20);

        $resultados = aiSearchInventory($this->pdo, 'Proteina Whey');

        $this->assertCount(1, $resultados);
        $this->assertSame(0, $resultados[0]['stock']);
    }

    public function testAiSearchInventoryNoAcotaStockDeProductoSinLotesRegistrados(): void
    {
        $this->seedProducto(21, 'Vitamina D', 199.0, 80.0);
        $this->seedInventario(21, 1, 7);

        $resultados = aiSearchInventory($this->pdo, 'Vitamina D');

        $this->assertSame(7, $resultados[0]['stock']);
    }

    public function testAiResolveOrderItemsRechazaPedidoQueSuperaElStockRealmenteVendible(): void
    {
        $this->seedProducto(22, 'Melatonina', 200.0, 80.0);
        $this->seedInventario(22, 1, 10);
        $this->seedLote(22, 'L-CADUCADO', $this->enDias(-1), 6); // 6 de las 10 ya caducaron
        $this->seedLote(22, 'L-VIGENTE', $this->enDias(300), 4); // solo 4 realmente vendibles

        $resultado = aiResolveOrderItems($this->pdo, [['id_producto' => 22, 'cantidad' => 5]]);

        $this->assertSame([], $resultado['items']);
        $this->assertCount(1, $resultado['errores']);
        $this->assertStringContainsString('disponible: 4', $resultado['errores'][0]);
    }

    public function testAiResolveOrderItemsAceptaPedidoDentroDelStockRealmenteVendible(): void
    {
        $this->seedProducto(23, 'Creatina', 350.0, 140.0);
        $this->seedInventario(23, 1, 10);
        $this->seedLote(23, 'L-CADUCADO', $this->enDias(-1), 6);
        $this->seedLote(23, 'L-VIGENTE', $this->enDias(300), 4);

        $resultado = aiResolveOrderItems($this->pdo, [['id_producto' => 23, 'cantidad' => 4]]);

        $this->assertCount(1, $resultado['items']);
        $this->assertSame([], $resultado['errores']);
        $this->assertSame(4, $resultado['items'][0]['quantity']);
    }

    private function enDias(int $dias): string
    {
        return (new DateTimeImmutable('today'))->modify(($dias >= 0 ? '+' : '') . $dias . ' days')->format('Y-m-d');
    }

    private function createSchema(): void
    {
        $this->pdo->exec("CREATE TABLE almacenes (id_almacen INTEGER PRIMARY KEY, nombre TEXT NOT NULL)");
        $this->pdo->exec("CREATE TABLE productos (
            id_producto INTEGER PRIMARY KEY, nombre TEXT NOT NULL, nombre_variante TEXT NULL,
            codigo_barras TEXT NOT NULL DEFAULT '', descripcion TEXT NULL,
            ingredientes TEXT NULL, beneficios TEXT NULL, modo_uso TEXT NULL, tabla_nutrimental TEXT NULL,
            precio_venta REAL NOT NULL DEFAULT 0, precio_costo REAL NOT NULL DEFAULT 0,
            precio_oferta REAL NULL, categoria TEXT NULL,
            capsulas_por_envase INTEGER NULL, porcion_capsulas INTEGER NULL,
            estado TEXT NOT NULL DEFAULT 'activo'
        )");
        $this->pdo->exec("CREATE TABLE inventario_almacen (
            id_producto INTEGER NOT NULL, id_almacen INTEGER NOT NULL, cantidad_actual INTEGER NOT NULL DEFAULT 0
        )");
        $this->pdo->exec("CREATE TABLE categorias (id_categoria INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT, estado TEXT DEFAULT 'activo')");
        $this->pdo->exec("CREATE TABLE producto_categorias (id_producto INTEGER, id_categoria INTEGER, PRIMARY KEY (id_producto, id_categoria))");
        $this->pdo->exec("CREATE TABLE lotes_inventario (
            id_lote INTEGER PRIMARY KEY AUTOINCREMENT,
            id_producto INTEGER NOT NULL, id_almacen INTEGER NULL,
            codigo_lote TEXT NOT NULL, fecha_caducidad TEXT NOT NULL, fecha_ingreso TEXT NOT NULL,
            cantidad_inicial INTEGER NOT NULL, cantidad_restante INTEGER NOT NULL,
            estado TEXT NOT NULL DEFAULT 'activo'
        )");
        $this->pdo->exec("CREATE TABLE pedidos (
            id_pedido INTEGER PRIMARY KEY AUTOINCREMENT, estado TEXT NOT NULL DEFAULT 'pagado',
            afecta_inventario INTEGER NOT NULL DEFAULT 1, fecha_creacion TEXT NOT NULL
        )");
        $this->pdo->exec("CREATE TABLE detalle_pedidos (
            id_detalle INTEGER PRIMARY KEY AUTOINCREMENT, id_pedido INTEGER NOT NULL,
            id_producto INTEGER NOT NULL, cantidad INTEGER NOT NULL DEFAULT 1,
            estado_entrega TEXT NOT NULL DEFAULT 'entregado'
        )");
        $this->pdo->exec("INSERT INTO almacenes (id_almacen, nombre) VALUES (1, 'Matriz')");
    }

    private function seedCategoriaOferta(): int
    {
        $this->pdo->exec("INSERT INTO categorias (nombre, estado) VALUES ('Oferta', 'activo')");

        return (int) $this->pdo->lastInsertId();
    }

    private function ponerEnOferta(int $idProducto, int $idCategoria): void
    {
        $this->pdo->prepare('INSERT INTO producto_categorias (id_producto, id_categoria) VALUES (?, ?)')
            ->execute([$idProducto, $idCategoria]);
    }

    private function seedProducto(
        int $id,
        string $nombre,
        float $precioVenta,
        float $precioCosto,
        ?float $precioOferta = null,
        ?string $variante = null,
        string $estado = 'activo',
        ?int $capsulasPorEnvase = null,
        ?int $porcionCapsulas = null
    ): void {
        $this->pdo->prepare(
            'INSERT INTO productos (id_producto, nombre, nombre_variante, precio_venta, precio_costo, precio_oferta, estado, capsulas_por_envase, porcion_capsulas)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $nombre, $variante, $precioVenta, $precioCosto, $precioOferta, $estado, $capsulasPorEnvase, $porcionCapsulas]);
    }

    private function seedInventario(int $idProducto, int $idAlmacen, int $cantidad): void
    {
        $this->pdo->prepare('INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual) VALUES (?, ?, ?)')
            ->execute([$idProducto, $idAlmacen, $cantidad]);
    }

    private function seedLote(int $idProducto, string $codigo, string $fechaCaducidad, int $cantidad, int $idAlmacen = 1): void
    {
        $this->pdo->prepare(
            'INSERT INTO lotes_inventario (id_producto, id_almacen, codigo_lote, fecha_caducidad, fecha_ingreso, cantidad_inicial, cantidad_restante)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $idProducto,
            $idAlmacen,
            $codigo,
            $fechaCaducidad,
            (new DateTimeImmutable('today'))->modify('-30 days')->format('Y-m-d'),
            $cantidad,
            $cantidad,
        ]);
    }
}
