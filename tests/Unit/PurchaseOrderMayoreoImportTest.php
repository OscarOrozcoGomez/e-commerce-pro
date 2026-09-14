<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Import de un pedido de mayoreo.blife.mx (JSON estructurado que deja
 * scripts/mayoreo_pedidos.mjs): numeros de presentacion, match con desempate por
 * presentacion, normalizacion (regalos $0) y vista previa.
 */
final class PurchaseOrderMayoreoImportTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec(
            "CREATE TABLE productos (id_producto INTEGER PRIMARY KEY, nombre TEXT NOT NULL, sku TEXT NULL,
             nombre_variante TEXT NULL, precio_costo REAL NOT NULL DEFAULT 0, estado TEXT NOT NULL DEFAULT 'activo')"
        );
        $this->pdo->exec(
            "CREATE TABLE ordenes_compra (id_orden_compra INTEGER PRIMARY KEY AUTOINCREMENT, id_usuario INTEGER,
             id_almacen INTEGER NOT NULL, referencia TEXT NOT NULL, estado TEXT NOT NULL DEFAULT 'borrador')"
        );
        $this->pdo->exec(
            "CREATE TABLE detalle_orden_compra (id_detalle INTEGER PRIMARY KEY AUTOINCREMENT, id_orden_compra INTEGER,
             id_producto INTEGER NOT NULL, cantidad_solicitada INTEGER NOT NULL, cantidad_recibida INTEGER DEFAULT 0)"
        );
    }

    private function prod(int $id, string $nombre, ?string $sku = null, ?string $variante = null, float $costo = 0.0): void
    {
        $this->pdo->prepare(
            "INSERT INTO productos (id_producto, nombre, sku, nombre_variante, precio_costo) VALUES (?, ?, ?, ?, ?)"
        )->execute([$id, $nombre, $sku, $variante, $costo]);
    }

    // ---- purchaseOrderPresentacionNumeros --------------------------------

    public function testPresentacionNumerosBasico(): void
    {
        self::assertSame([120], purchaseOrderPresentacionNumeros('120 porciones'));
        self::assertSame([300, 125], purchaseOrderPresentacionNumeros('300 Caps | 125 µg'));
        self::assertSame([200, 500], purchaseOrderPresentacionNumeros('200 Caps | 500 g'));
        self::assertSame([], purchaseOrderPresentacionNumeros('sin numeros aqui'));
    }

    public function testPresentacionNumerosIgnoraAniosYCeros(): void
    {
        self::assertSame([50], purchaseOrderPresentacionNumeros('50 ml (2026)'));
    }

    // ---- purchaseOrderMatchProductPresentacion --------------------------

    public function testDesempataCreatinaPorPresentacion(): void
    {
        $this->prod(67, 'Creatine Monohydrate Powder | Creatina Monohidratada en Polvo (120 tomas)', 'BLIFE-CREATINA-120', 'Unidades');
        $this->prod(68, 'Creatine Monohydrate Powder | Creatina Monohidratada en Polvo (60 tomas)', 'BLIFE-CREATINA-60', 'Unidades');
        $cat = $this->pdo->query('SELECT id_producto, nombre, sku, nombre_variante FROM productos')->fetchAll(PDO::FETCH_ASSOC);

        $r120 = purchaseOrderMatchProductPresentacion($cat, 'Creatine Monohydrate Powder B Life. Creatina Monohidratada en Polvo', '120 porciones', 3);
        self::assertSame(67, $r120[0]['id_producto'], 'con "120" debe ganar el de 120 tomas');

        $r60 = purchaseOrderMatchProductPresentacion($cat, 'Creatine Monohydrate Powder B Life. Creatina Monohidratada en Polvo', '60 tomas', 3);
        self::assertSame(68, $r60[0]['id_producto'], 'con "60" debe ganar el de 60 tomas');
    }

    public function testSinNumeroDePresentacionNoRompeElOrden(): void
    {
        $this->prod(10, 'Collagen Blend | Colageno', 'BLIFE-COLL');
        $cat = $this->pdo->query('SELECT id_producto, nombre, sku, nombre_variante FROM productos')->fetchAll(PDO::FETCH_ASSOC);
        $r = purchaseOrderMatchProductPresentacion($cat, 'Collagen Blend', '', 3);
        self::assertSame(10, $r[0]['id_producto']);
    }

    // ---- purchaseOrderNormalizeMayoreoPedido ---------------------------

    public function testNormalizeMarcaRegalosYDescartaCantidadCero(): void
    {
        $pedido = [
            'numero' => 'BLM015728',
            'fecha_compra' => 'Septiembre 01, 2026',
            'items' => [
                ['nombre' => 'Maca Blend', 'presentacion' => '', 'cantidad' => 2, 'precio_unitario' => 188.37],
                ['nombre' => 'Sachet Naranja 1 pieza 100% OFF', 'presentacion' => '1 Pz', 'cantidad' => 2, 'precio_unitario' => 0.0],
                ['nombre' => 'Basura', 'presentacion' => '', 'cantidad' => 0, 'precio_unitario' => 10.0],
                ['nombre' => '', 'presentacion' => '', 'cantidad' => 3, 'precio_unitario' => 5.0],
            ],
        ];

        $norm = purchaseOrderNormalizeMayoreoPedido($pedido);

        self::assertSame('BLM015728', $norm['numero']);
        self::assertCount(2, $norm['items'], 'descarta cantidad<=0 y nombre vacio');
        self::assertFalse($norm['items'][0]['es_regalo']);
        self::assertTrue($norm['items'][1]['es_regalo']);
        self::assertSame(188.37, $norm['items'][0]['precio_unitario']);
    }

    // ---- purchaseOrderBuildMayoreoPreview -----------------------------

    public function testPreviewMapeaPresentacionPrecioYRegalos(): void
    {
        $this->prod(67, 'Creatine Monohydrate Powder | Creatina Monohidratada en Polvo (120 tomas)', 'BLIFE-CREATINA-120', null, 0.0);
        $this->prod(68, 'Creatine Monohydrate Powder | Creatina Monohidratada en Polvo (60 tomas)', 'BLIFE-CREATINA-60', null, 0.0);
        $this->prod(5, 'Maca Blend | Maca', 'BLIFE-MACA');

        $pedido = [
            'numero' => 'BLM015728',
            'items' => [
                ['nombre' => 'Creatine Monohydrate Powder B Life. Creatina Monohidratada en Polvo', 'presentacion' => '120 porciones', 'cantidad' => 2, 'precio_unitario' => 125.37],
                ['nombre' => 'Maca Blend', 'presentacion' => '', 'cantidad' => 2, 'precio_unitario' => 188.37],
                ['nombre' => 'Producto Que No Existe En El Catalogo XYZ', 'presentacion' => '', 'cantidad' => 1, 'precio_unitario' => 99.0],
                ['nombre' => 'Aceite de Canela 100% OFF', 'presentacion' => '50 ml', 'cantidad' => 1, 'precio_unitario' => 0.0],
            ],
        ];

        $prev = purchaseOrderBuildMayoreoPreview($this->pdo, $pedido, 1);

        self::assertSame('BLM015728', $prev['numero']);
        self::assertCount(4, $prev['rows']);

        // Fila 0: Creatina -> mapea al de 120 tomas, con presentacion y precio.
        self::assertSame(67, $prev['rows'][0]['sugerido_id_producto']);
        self::assertSame('120 porciones', $prev['rows'][0]['presentacion']);
        self::assertSame(125.37, $prev['rows'][0]['precio_unitario']);
        self::assertFalse($prev['rows'][0]['es_regalo']);

        // Fila 1: Maca -> mapea.
        self::assertSame(5, $prev['rows'][1]['sugerido_id_producto']);

        // Fila 2: sin match -> sugerido 0 y warning sin_match.
        self::assertSame(0, $prev['rows'][2]['sugerido_id_producto']);
        $tiposWarn = array_column($prev['warnings'], 'tipo');
        self::assertContains('sin_match', $tiposWarn);

        // Fila 3: regalo -> marcado, y NO genera warning de sin_match.
        self::assertTrue($prev['rows'][3]['es_regalo']);
        self::assertCount(1, $prev['warnings'], 'el regalo sin match no cuenta como warning');
    }

    public function testPreviewAdjuntaOrdenAbiertaSiCoincide(): void
    {
        $this->prod(5, 'Maca Blend | Maca', 'BLIFE-MACA');
        $this->pdo->exec("INSERT INTO ordenes_compra (id_almacen, referencia, estado) VALUES (1, 'OC-TEST', 'enviada')");
        $idOc = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO detalle_orden_compra (id_orden_compra, id_producto, cantidad_solicitada) VALUES (?, ?, ?)')
            ->execute([$idOc, 5, 10]);

        $pedido = ['numero' => 'BLM1', 'items' => [
            ['nombre' => 'Maca Blend', 'presentacion' => '', 'cantidad' => 2, 'precio_unitario' => 100.0],
        ]];

        $prev = purchaseOrderBuildMayoreoPreview($this->pdo, $pedido, 1);
        self::assertSame($idOc, $prev['rows'][0]['id_orden_compra']);
        self::assertSame('OC-TEST', $prev['rows'][0]['referencia']);
    }
}
