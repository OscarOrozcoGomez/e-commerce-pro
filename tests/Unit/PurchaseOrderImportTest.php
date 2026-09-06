<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Importar un pedido de proveedor desde una captura / correo (sin IA) y surtir:
 * parseo del texto, normalización, match nombre->producto, vista previa y commit
 * (entradas directas + recepción de órdenes de compra abiertas).
 *
 * Objetivo: romper la feature — positivos, negativos y edges hostiles. Los edges
 * de comportamiento "congelado" están marcados con [CONGELADO] en el nombre/doc.
 */
final class PurchaseOrderImportTest extends TestCase
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

    // =====================================================================
    // A) purchaseOrderParseSupplierText
    // =====================================================================

    public function testParseBlifeEmailExactSevenItems(): void
    {
        $texto = <<<TXT
        Resumen del pedido
        Collagen Blend × 2
        \$399.00 \$251.37
        Creatine Monohydrate Powder B Life.
        Creatina Monohidratada en Polvo (60 tomas) × 2
        \$249.00 \$125.37
        Creatine Monohydrate Powder B Life.
        Creatina Monohidratada en Polvo (120 tomas) × 2
        \$679.00 \$396.27
        D3 B Life | Vitamina D3 × 3
        \$399.00 \$219.87
        Maca Blend × 2
        \$349.00 \$188.37
        Maca Blend × 2
        \$219.00 \$106.47
        Myo & D-Chiro Inositol Platinum en Polvo × 1
        \$520.00 \$300.00
        Subtotal \$1,687.48
        Envío gratis
        Total a pagar \$1,687.48
        TXT;

        $items = purchaseOrderParseSupplierText($texto);

        $this->assertCount(7, $items);
        $this->assertSame('Collagen Blend', $items[0]['nombre']);
        $this->assertSame(2, $items[0]['cantidad']);
        $this->assertSame('Creatine Monohydrate Powder B Life. Creatina Monohidratada en Polvo (60 tomas)', $items[1]['nombre']);
        $this->assertSame(2, $items[1]['cantidad']);
        $this->assertSame('D3 B Life | Vitamina D3', $items[3]['nombre']);
        $this->assertSame(3, $items[3]['cantidad']);
        $this->assertSame('Myo & D-Chiro Inositol Platinum en Polvo', $items[6]['nombre']);
        $this->assertSame(1, $items[6]['cantidad']);
    }

    public function testParseTruncatedLastLineGoesToUnparsed(): void
    {
        $texto = "Maca Blend × 2\n\$219.00 \$106.47\nMyo & D-Chiro Inositol Platinum en";
        $unparsed = [];

        $items = purchaseOrderParseSupplierText($texto, $unparsed);

        $this->assertCount(1, $items);
        $this->assertSame('Maca Blend', $items[0]['nombre']);
        $this->assertContains('Myo & D-Chiro Inositol Platinum en', $unparsed);
    }

    /** @dataProvider multiplicationForms */
    public function testParseAcceptsMultiplicationSignAndAsciiX(string $line, int $esperada): void
    {
        $items = purchaseOrderParseSupplierText($line);
        $this->assertCount(1, $items);
        $this->assertSame('Producto Demo', $items[0]['nombre']);
        $this->assertSame($esperada, $items[0]['cantidad']);
    }

    public static function multiplicationForms(): array
    {
        return [
            'signo x unicode'   => ['Producto Demo × 2', 2],
            'x minuscula'       => ['Producto Demo x 2', 2],
            'X mayuscula'       => ['Producto Demo X 2', 2],
            'sin espacio'       => ['Producto Demo x2', 2],
            'con piezas'        => ['Producto Demo × 3 pzas', 3],
        ];
    }

    public function testParseQuantityFirst(): void
    {
        $items = purchaseOrderParseSupplierText("2 x Collagen Blend\n3 × Maca Blend");
        $this->assertCount(2, $items);
        $this->assertSame('Collagen Blend', $items[0]['nombre']);
        $this->assertSame(2, $items[0]['cantidad']);
        $this->assertSame('Maca Blend', $items[1]['nombre']);
        $this->assertSame(3, $items[1]['cantidad']);
    }

    public function testParseAlternateQuantityForms(): void
    {
        $items = purchaseOrderParseSupplierText(
            "Alpha Blend (3)\n"
            . "Beta Blend .......... 4\n"
            . "Gamma Blend\nCantidad: 5\n"
            . "Delta Blend\nQty 6"
        );
        $mapa = [];
        foreach ($items as $it) {
            $mapa[$it['nombre']] = $it['cantidad'];
        }
        $this->assertSame(3, $mapa['Alpha Blend']);
        $this->assertSame(4, $mapa['Beta Blend']);
        $this->assertSame(5, $mapa['Gamma Blend']);
        $this->assertSame(6, $mapa['Delta Blend']);
    }

    public function testParseJoinsMultilineNameUntilTerminator(): void
    {
        $items = purchaseOrderParseSupplierText("Super\nMega\nUltra Blend × 2");
        $this->assertCount(1, $items);
        $this->assertSame('Super Mega Ultra Blend', $items[0]['nombre']);
        $this->assertSame(2, $items[0]['cantidad']);
    }

    /** @dataProvider bulletLines */
    public function testParseStripsLeadingBullets(string $line): void
    {
        $items = purchaseOrderParseSupplierText($line);
        $this->assertCount(1, $items);
        $this->assertSame('Collagen Blend', $items[0]['nombre']);
        $this->assertSame(2, $items[0]['cantidad']);
    }

    public static function bulletLines(): array
    {
        return [
            ['• Collagen Blend × 2'],
            ['- Collagen Blend × 2'],
            ['* Collagen Blend × 2'],
            ['1. Collagen Blend × 2'],
            ['2) Collagen Blend × 2'],
        ];
    }

    public function testParseHandlesCrlfAndTabs(): void
    {
        $items = purchaseOrderParseSupplierText("Collagen Blend\t×\t2\r\nMaca Blend × 1\r");
        $this->assertCount(2, $items);
        $this->assertSame('Collagen Blend', $items[0]['nombre']);
        $this->assertSame('Maca Blend', $items[1]['nombre']);
    }

    public function testParseUnicodeNameSurvives(): void
    {
        $items = purchaseOrderParseSupplierText('Ñoño Blend Piña × 2');
        $this->assertCount(1, $items);
        $this->assertSame('Ñoño Blend Piña', $items[0]['nombre']);
    }

    /** @dataProvider emptyish */
    public function testParseEmptyReturnsNoItems(string $texto): void
    {
        $this->assertSame([], purchaseOrderParseSupplierText($texto));
    }

    public static function emptyish(): array
    {
        return [['',], ['   '], ["\n\n\n"], ["\t\t"]];
    }

    public function testParseOnlyPriceLinesReturnsNothing(): void
    {
        $this->assertSame([], purchaseOrderParseSupplierText("\$1,299.00\n\$980.50 MXN\n459.00"));
    }

    /** @dataProvider headerLines */
    public function testParseIgnoresHeadersAndTotals(string $line): void
    {
        $items = purchaseOrderParseSupplierText("Collagen Blend × 1\n" . $line);
        $this->assertCount(1, $items, "«{$line}» no debe producir ítem");
        $this->assertSame('Collagen Blend', $items[0]['nombre']);
    }

    public static function headerLines(): array
    {
        return [
            ['Resumen del pedido'],
            ['Subtotal'],
            ['Subtotal: $1,299.00'],
            ['Total a pagar $2,279.00'],
            ['Gran total'],
            ['Envío'],
            ['Envio gratis'],
            ['IVA (16%)'],
            ['Impuestos'],
            ['Descuento'],
        ];
    }

    public function testParseLineWithoutQuantityIsNotAnItem(): void
    {
        $unparsed = [];
        $items = purchaseOrderParseSupplierText("Notas del vendedor: entregar por la tarde", $unparsed);
        $this->assertSame([], $items);
        $this->assertNotEmpty($unparsed);
    }

    /** @dataProvider nonPositiveQty */
    public function testParseRejectsNonPositiveQuantity(string $line): void
    {
        $this->assertSame([], purchaseOrderParseSupplierText($line));
    }

    public static function nonPositiveQty(): array
    {
        return [['Producto Demo × 0'], ['Producto Demo x 0'], ['Producto Demo × -3']];
    }

    public function testParseClampsHugeQuantityFrozen(): void
    {
        $items = purchaseOrderParseSupplierText('Producto Demo × 999999999');
        $this->assertSame(9999, $items[0]['cantidad']);
    }

    public function testParseDecimalQuantityFloorsFrozen(): void
    {
        $items = purchaseOrderParseSupplierText('Producto Demo × 2.9');
        $this->assertSame(2, $items[0]['cantidad']);
    }

    public function testParseThousandsSeparatorInQuantityFrozen(): void
    {
        $items = purchaseOrderParseSupplierText('Producto Demo × 1,200');
        $this->assertSame(1200, $items[0]['cantidad']);
    }

    public function testParseMultipleTimesSignInNameLastWinsFrozen(): void
    {
        $items = purchaseOrderParseSupplierText('Omega 3-6-9 × Complex × 2');
        $this->assertCount(1, $items);
        $this->assertSame('Omega 3-6-9 × Complex', $items[0]['nombre']);
        $this->assertSame(2, $items[0]['cantidad']);
    }

    public function testParseVeryLongLineDoesNotHang(): void
    {
        $line = str_repeat('Blend ', 4000) . '× 2';
        $start = microtime(true);
        $items = purchaseOrderParseSupplierText($line);
        $this->assertLessThan(2.0, microtime(true) - $start);
        $this->assertCount(1, $items);
        $this->assertSame(2, $items[0]['cantidad']);
    }

    public function testParseMalformedUtf8DoesNotFatal(): void
    {
        $items = purchaseOrderParseSupplierText("Collagen \xC0\xC1 Blend × 2");
        $this->assertCount(1, $items);
        $this->assertSame(2, $items[0]['cantidad']);
    }

    public function testParseNullBytesStripped(): void
    {
        $items = purchaseOrderParseSupplierText("Collagen\0 Blend × 2");
        $this->assertCount(1, $items);
        $this->assertStringNotContainsString("\0", $items[0]['nombre']);
    }

    public function testParseHtmlPasteBestEffortFrozen(): void
    {
        $items = purchaseOrderParseSupplierText('<td>Collagen Blend</td><td>× 2</td>');
        $this->assertCount(1, $items);
        $this->assertSame('Collagen Blend', $items[0]['nombre']);
        $this->assertSame(2, $items[0]['cantidad']);
    }

    public function testParseNumericOnlyName(): void
    {
        $items = purchaseOrderParseSupplierText('12345 × 2');
        $this->assertCount(1, $items);
        $this->assertSame('12345', $items[0]['nombre']);
    }

    // =====================================================================
    // B) purchaseOrderNormalizeText
    // =====================================================================

    public function testNormalizeStripsAccentsAndCase(): void
    {
        $this->assertSame('colageno', purchaseOrderNormalizeText('Colágeno'));
        $this->assertSame('aeioun', purchaseOrderNormalizeText('ÁÉÍÓÚÑ'));
        $this->assertSame('nono', purchaseOrderNormalizeText('Ñoño'));
    }

    public function testNormalizePunctuationToSpaces(): void
    {
        $this->assertSame('d3 k2', purchaseOrderNormalizeText('D3+K2'));
        $this->assertSame('40 1', purchaseOrderNormalizeText('40:1'));
    }

    public function testNormalizeCollapsesWhitespaceAndTrims(): void
    {
        $this->assertSame('maca blend', purchaseOrderNormalizeText("  Maca    Blend \n"));
    }

    public function testNormalizeEmptyAndAsciiPassthrough(): void
    {
        $this->assertSame('', purchaseOrderNormalizeText(''));
        $this->assertSame('collagen blend', purchaseOrderNormalizeText('collagen blend'));
    }

    public function testNormalizeIsIdempotent(): void
    {
        $once = purchaseOrderNormalizeText('D3 B Life | Vitamina D3');
        $this->assertSame($once, purchaseOrderNormalizeText($once));
    }

    // =====================================================================
    // C) purchaseOrderMatchProduct
    // =====================================================================

    private function catalogo(): array
    {
        return [
            ['id_producto' => 10, 'nombre' => 'Collagen Blend | Colágeno', 'sku' => 'BLIFE-COL-001'],
            ['id_producto' => 11, 'nombre' => 'Collagen Blend Kids', 'sku' => 'BLIFE-COL-002'],
            ['id_producto' => 12, 'nombre' => 'Creatine Monohydrate Powder | Creatina Monohidratada en Polvo', 'sku' => 'BLIFE-CREATINA-60'],
            ['id_producto' => 13, 'nombre' => 'D3 | Vitamina D3', 'sku' => 'BLIFE-D3|-043'],
            ['id_producto' => 14, 'nombre' => 'Coconut Oil D3+K2 | Aceite de Coco', 'sku' => 'BLIFE-COC-036'],
        ];
    }

    public function testMatchExactNameTopScore(): void
    {
        $r = purchaseOrderMatchProduct($this->catalogo(), 'Collagen Blend');
        $this->assertSame(10, $r[0]['id_producto']);
        $this->assertGreaterThanOrEqual(95.0, $r[0]['score']);
    }

    public function testMatchPrefersPlainOverVariant(): void
    {
        $r = purchaseOrderMatchProduct($this->catalogo(), 'Collagen Blend × 2');
        $this->assertSame(10, $r[0]['id_producto']);
    }

    public function testMatchAgainstSpanishHalf(): void
    {
        $r = purchaseOrderMatchProduct($this->catalogo(), 'Creatina Monohidratada en Polvo');
        $this->assertSame(12, $r[0]['id_producto']);
    }

    public function testMatchBySku(): void
    {
        $r = purchaseOrderMatchProduct($this->catalogo(), 'Articulo raro BLIFE-D3|-043 sin nombre');
        $this->assertSame(13, $r[0]['id_producto']);
    }

    public function testMatchToleratesMissingAccents(): void
    {
        $r = purchaseOrderMatchProduct($this->catalogo(), 'Colageno');
        $this->assertSame(10, $r[0]['id_producto']);
    }

    public function testMatchTokenOrderInvariant(): void
    {
        $a = purchaseOrderMatchProduct($this->catalogo(), 'Blend Collagen');
        $b = purchaseOrderMatchProduct($this->catalogo(), 'Collagen Blend');
        $this->assertSame($b[0]['id_producto'], $a[0]['id_producto']);
    }

    public function testMatchEmptyCatalogOrQueryReturnsEmpty(): void
    {
        $this->assertSame([], purchaseOrderMatchProduct([], 'Collagen'));
        $this->assertSame([], purchaseOrderMatchProduct($this->catalogo(), '   '));
    }

    public function testMatchNoGoodCandidateStaysBelowThreshold(): void
    {
        $r = purchaseOrderMatchProduct($this->catalogo(), 'Zzzz Xyzzy Qwerty');
        $this->assertNotEmpty($r);
        $this->assertLessThan(45.0, $r[0]['score']);
    }

    public function testMatchDuplicateNamesStableOrderById(): void
    {
        $cat = [
            ['id_producto' => 30, 'nombre' => 'Iron Blend', 'sku' => 'A'],
            ['id_producto' => 20, 'nombre' => 'Iron Blend', 'sku' => 'B'],
        ];
        $r = purchaseOrderMatchProduct($cat, 'Iron Blend');
        $this->assertSame(20, $r[0]['id_producto']);
        $this->assertSame(30, $r[1]['id_producto']);
    }

    public function testMatchShortQueryRanksBestFirst(): void
    {
        $r = purchaseOrderMatchProduct($this->catalogo(), 'D3');
        $this->assertSame(13, $r[0]['id_producto']);
    }

    public function testMatchCatalogNameEmptyLeftOfPipeNoCrash(): void
    {
        $cat = [['id_producto' => 1, 'nombre' => '| solo derecha', 'sku' => null]];
        $r = purchaseOrderMatchProduct($cat, 'solo derecha');
        $this->assertSame(1, $r[0]['id_producto']);
    }

    public function testMatchTopNLargerThanCatalogReturnsAll(): void
    {
        $r = purchaseOrderMatchProduct($this->catalogo(), 'Blend', 50);
        $this->assertCount(5, $r);
    }

    public function testMatchScoreDeterministicAndBounded(): void
    {
        $a = purchaseOrderMatchProduct($this->catalogo(), 'Creatina en Polvo');
        $b = purchaseOrderMatchProduct($this->catalogo(), 'Creatina en Polvo');
        $this->assertEquals($a, $b);
        foreach ($a as $row) {
            $this->assertGreaterThanOrEqual(0.0, $row['score']);
            $this->assertLessThanOrEqual(100.0, $row['score']);
        }
    }

    // =====================================================================
    // D) purchaseOrderBuildImportPreview
    // =====================================================================

    public function testPreviewNoOpenOrderAllDirect(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Collagen Blend | Colágeno');
        $this->seedProduct(11, 'Maca Blend');

        $preview = purchaseOrderBuildImportPreview($this->pdo, "Collagen Blend × 2\nMaca Blend × 1", 1);

        $this->assertCount(2, $preview['rows']);
        foreach ($preview['rows'] as $row) {
            $this->assertNull($row['id_detalle']);
            $this->assertGreaterThan(0, $row['sugerido_id_producto']);
        }
    }

    public function testPreviewFlagsOpenOrderLineSameWarehouse(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Collagen Blend | Colágeno');
        $this->seedProduct(11, 'Maca Blend');
        [$idOrden, $detalles] = $this->seedOpenOrder(1, 'OC-TEST-1', [[10, 5]]);

        $preview = purchaseOrderBuildImportPreview($this->pdo, "Collagen Blend × 2\nMaca Blend × 1", 1);

        $byProd = [];
        foreach ($preview['rows'] as $r) {
            $byProd[$r['sugerido_id_producto']] = $r;
        }
        $this->assertSame($detalles[0], $byProd[10]['id_detalle']);
        $this->assertSame($idOrden, $byProd[10]['id_orden_compra']);
        $this->assertSame('OC-TEST-1', $byProd[10]['referencia']);
        $this->assertNull($byProd[11]['id_detalle']);
    }

    public function testPreviewIgnoresOpenOrderOfOtherWarehouse(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedWarehouse(2, 'Norte');
        $this->seedProduct(10, 'Collagen Blend');
        $this->seedOpenOrder(2, 'OC-OTRA', [[10, 5]]);

        $preview = purchaseOrderBuildImportPreview($this->pdo, "Collagen Blend × 2", 1);

        $this->assertNull($preview['rows'][0]['id_detalle']);
    }

    public function testPreviewIgnoresClosedOrder(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Collagen Blend');
        [$idOrden] = $this->seedOpenOrder(1, 'OC-CERRADA', [[10, 5]]);
        $this->pdo->prepare("UPDATE ordenes_compra SET estado = 'recibida' WHERE id_orden_compra = ?")->execute([$idOrden]);

        $preview = purchaseOrderBuildImportPreview($this->pdo, "Collagen Blend × 2", 1);

        $this->assertNull($preview['rows'][0]['id_detalle']);
    }

    public function testPreviewWarningsListUnparsedAndLowScore(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Collagen Blend');

        $preview = purchaseOrderBuildImportPreview(
            $this->pdo,
            "Collagen Blend × 2\nProducto Inexistente Rarísimo × 1\nNota suelta sin cantidad",
            1
        );

        $tipos = array_column($preview['warnings'], 'tipo');
        $this->assertContains('sin_match', $tipos);
        $this->assertContains('no_parseada', $tipos);
    }

    public function testPreviewEmptyTextReturnsNoRows(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $preview = purchaseOrderBuildImportPreview($this->pdo, "   ", 1);
        $this->assertSame([], $preview['rows']);
    }

    public function testPreviewEmptyCatalogRowsWithoutSuggestion(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $preview = purchaseOrderBuildImportPreview($this->pdo, "Collagen Blend × 2", 1);

        $this->assertCount(1, $preview['rows']);
        $this->assertSame(0, $preview['rows'][0]['sugerido_id_producto']);
        $this->assertSame('sin_match', $preview['warnings'][0]['tipo']);
    }

    public function testPreviewProductOnTwoOpenOrdersPicksLowestOrderIdFrozen(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Collagen Blend');
        [$oc1] = $this->seedOpenOrder(1, 'OC-1', [[10, 3]]);
        [$oc2] = $this->seedOpenOrder(1, 'OC-2', [[10, 4]]);
        $this->assertLessThan($oc2, $oc1);

        $preview = purchaseOrderBuildImportPreview($this->pdo, "Collagen Blend × 2", 1);

        $this->assertSame($oc1, $preview['rows'][0]['id_orden_compra']);
    }

    public function testPreviewSameProductParsedTwiceYieldsTwoRows(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Collagen Blend');

        $preview = purchaseOrderBuildImportPreview($this->pdo, "Collagen Blend × 2\nCollagen Blend × 3", 1);

        $this->assertCount(2, $preview['rows']);
    }

    public function testPreviewArchivadoExcludedFromCatalog(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Collagen Blend', 'SKU-10', 'archivado');

        $preview = purchaseOrderBuildImportPreview($this->pdo, "Collagen Blend × 2", 1);

        $this->assertSame(0, $preview['rows'][0]['sugerido_id_producto']);
    }

    public function testPreviewNonexistentWarehouseNoFlagsNoCrash(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Collagen Blend');

        $preview = purchaseOrderBuildImportPreview($this->pdo, "Collagen Blend × 2", 999);

        $this->assertCount(1, $preview['rows']);
        $this->assertNull($preview['rows'][0]['id_detalle']);
    }

    public function testPreviewSqlPayloadDoesNotAlterSchema(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Collagen Blend');

        purchaseOrderBuildImportPreview($this->pdo, "Robert'); DROP TABLE productos;-- × 1", 1);

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM productos')->fetchColumn());
    }

    // =====================================================================
    // E) purchaseOrderCommitImport
    // =====================================================================

    public function testCommitAllDirectInboundBumpsStockAndMovements(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Collagen Blend');
        $this->seedProduct(11, 'Maca Blend');
        $this->seedInventory(10, 1, 0, 2, 20);
        $this->seedInventory(11, 1, 5, 2, 20);

        $res = purchaseOrderCommitImport($this->pdo, [
            ['id_producto' => 10, 'cantidad' => 3],
            ['id_producto' => 11, 'cantidad' => 2],
        ], 1, 7);

        $this->assertSame(2, $res['entradas_directas']);
        $this->assertSame(0, $res['ordenes_cerradas']);
        $this->assertSame(3, (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = 10')->fetchColumn());
        $this->assertSame(7, (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = 11')->fetchColumn());
        $this->assertSame(2, (int) $this->pdo->query("SELECT COUNT(*) FROM movimientos_inventario WHERE tipo_movimiento = 'entrada'")->fetchColumn());
        $this->assertSame(7, (int) $this->pdo->query('SELECT id_usuario FROM movimientos_inventario LIMIT 1')->fetchColumn());
    }

    public function testCommitOpenOrderMatchedQtyRestZeroClosesOrder(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Collagen Blend');
        $this->seedProduct(11, 'Maca Blend');
        $this->seedInventory(10, 1, 1, 2, 20);
        $this->seedInventory(11, 1, 1, 2, 20);
        [$idOrden, $det] = $this->seedOpenOrder(1, 'OC-X', [[10, 5], [11, 4]]);

        $res = purchaseOrderCommitImport($this->pdo, [
            ['id_producto' => 10, 'cantidad' => 5, 'id_detalle' => $det[0]],
        ], 1, 7);

        $this->assertSame(1, $res['ordenes_cerradas']);
        $this->assertSame(1, $res['lineas_oc']);
        $this->assertSame('recibida', $this->pdo->query('SELECT estado FROM ordenes_compra')->fetchColumn());
        $this->assertSame(6, (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = 10')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = 11')->fetchColumn(), 'la línea no incluida queda en 0 recibido');
        $this->assertSame(0, (int) $this->pdo->query('SELECT cantidad_recibida FROM detalle_orden_compra WHERE id_detalle = ' . $det[1])->fetchColumn());
    }

    public function testCommitMixedOcAndDirect(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Collagen Blend');
        $this->seedProduct(11, 'Maca Blend');
        $this->seedInventory(10, 1, 0, 2, 20);
        $this->seedInventory(11, 1, 0, 2, 20);
        [, $det] = $this->seedOpenOrder(1, 'OC-M', [[10, 5]]);

        $res = purchaseOrderCommitImport($this->pdo, [
            ['id_producto' => 10, 'cantidad' => 5, 'id_detalle' => $det[0]],
            ['id_producto' => 11, 'cantidad' => 2],
        ], 1, 7);

        $this->assertSame(1, $res['ordenes_cerradas']);
        $this->assertSame(1, $res['entradas_directas']);
        $this->assertSame(5, (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = 10')->fetchColumn());
        $this->assertSame(2, (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = 11')->fetchColumn());
    }

    public function testCommitAllOrderLinesMatchedBumpsEverything(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Collagen Blend');
        $this->seedProduct(11, 'Maca Blend');
        $this->seedInventory(10, 1, 0, 2, 20);
        $this->seedInventory(11, 1, 0, 2, 20);
        [, $det] = $this->seedOpenOrder(1, 'OC-FULL', [[10, 5], [11, 3]]);

        purchaseOrderCommitImport($this->pdo, [
            ['id_producto' => 10, 'cantidad' => 5, 'id_detalle' => $det[0]],
            ['id_producto' => 11, 'cantidad' => 3, 'id_detalle' => $det[1]],
        ], 1, 7);

        $this->assertSame(5, (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = 10')->fetchColumn());
        $this->assertSame(3, (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = 11')->fetchColumn());
        $this->assertSame('recibida', $this->pdo->query('SELECT estado FROM ordenes_compra')->fetchColumn());
    }

    public function testCommitTwoOpenOrdersBothClosed(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Collagen Blend');
        $this->seedProduct(11, 'Maca Blend');
        $this->seedInventory(10, 1, 0, 2, 20);
        $this->seedInventory(11, 1, 0, 2, 20);
        [, $d1] = $this->seedOpenOrder(1, 'OC-A', [[10, 5]]);
        [, $d2] = $this->seedOpenOrder(1, 'OC-B', [[11, 2]]);

        $res = purchaseOrderCommitImport($this->pdo, [
            ['id_producto' => 10, 'cantidad' => 5, 'id_detalle' => $d1[0]],
            ['id_producto' => 11, 'cantidad' => 2, 'id_detalle' => $d2[0]],
        ], 1, 7);

        $this->assertSame(2, $res['ordenes_cerradas']);
        $this->assertSame(0, (int) $this->pdo->query("SELECT COUNT(*) FROM ordenes_compra WHERE estado != 'recibida'")->fetchColumn());
    }

    public function testCommitDirectInboundReleasesPostponed(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedUser(9, 'Ana');
        $this->seedProduct(10, 'Collagen Blend');
        $this->seedInventory(10, 1, 0, 2, 20);
        $this->seedProduct(11, 'Maca Blend');
        $this->seedInventory(11, 1, 0, 2, 20);
        purchaseOrderPostponeItems($this->pdo, [['id_producto' => 11, 'id_almacen' => 1, 'motivo' => 'x']], 9);

        purchaseOrderCommitImport($this->pdo, [['id_producto' => 10, 'cantidad' => 2]], 1, 7);

        $this->assertSame('reactivado', $this->pdo->query('SELECT estado FROM purchase_order_postponed_items')->fetchColumn());
    }

    public function testCommitEmptyRowsIsNoop(): void
    {
        $res = purchaseOrderCommitImport($this->pdo, [], 1, 7);

        $this->assertSame(
            ['ordenes_cerradas' => 0, 'lineas_oc' => 0, 'entradas_directas' => 0, 'ignoradas' => 0],
            $res
        );
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function testCommitRowWithoutProductIsIgnored(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $res = purchaseOrderCommitImport($this->pdo, [
            ['id_producto' => 0, 'cantidad' => 3],
            ['cantidad' => 2],
            'no-array',
        ], 1, 7);

        $this->assertSame(3, $res['ignoradas']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM movimientos_inventario')->fetchColumn());
    }

    public function testCommitZeroAndNegativeQtyIgnored(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Collagen Blend');
        $this->seedInventory(10, 1, 5, 2, 20);

        $res = purchaseOrderCommitImport($this->pdo, [
            ['id_producto' => 10, 'cantidad' => 0],
            ['id_producto' => 10, 'cantidad' => -4],
        ], 1, 7);

        $this->assertSame(2, $res['ignoradas']);
        $this->assertSame(5, (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = 10')->fetchColumn());
    }

    public function testCommitProductNotInWarehouseInventoryNoPhantomMovementFrozen(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Collagen Blend'); // sin fila en inventario_almacen

        $res = purchaseOrderCommitImport($this->pdo, [['id_producto' => 10, 'cantidad' => 3]], 1, 7);

        $this->assertSame(0, $res['entradas_directas']);
        $this->assertSame(1, $res['ignoradas']);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM movimientos_inventario')->fetchColumn());
    }

    public function testCommitDetalleFromClosedOrderIsIgnoredFrozen(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Collagen Blend');
        $this->seedInventory(10, 1, 1, 2, 20);
        [$idOrden, $det] = $this->seedOpenOrder(1, 'OC-C', [[10, 5]]);
        $this->pdo->prepare("UPDATE ordenes_compra SET estado = 'recibida' WHERE id_orden_compra = ?")->execute([$idOrden]);

        $res = purchaseOrderCommitImport($this->pdo, [
            ['id_producto' => 10, 'cantidad' => 5, 'id_detalle' => $det[0]],
        ], 1, 7);

        $this->assertSame(0, $res['ordenes_cerradas']);
        $this->assertSame(1, $res['ignoradas']);
        $this->assertSame(1, (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = 10')->fetchColumn());
    }

    public function testCommitDetalleFromOtherWarehouseIsIgnoredFrozen(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedWarehouse(2, 'Norte');
        $this->seedProduct(10, 'Collagen Blend');
        $this->seedInventory(10, 1, 1, 2, 20);
        [, $det] = $this->seedOpenOrder(2, 'OC-N', [[10, 5]]);

        $res = purchaseOrderCommitImport($this->pdo, [
            ['id_producto' => 10, 'cantidad' => 5, 'id_detalle' => $det[0]],
        ], 1, 7);

        $this->assertSame(0, $res['ordenes_cerradas']);
        $this->assertSame(1, $res['ignoradas']);
    }

    public function testCommitDuplicateDirectRowsSameProductSummedFrozen(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Collagen Blend');
        $this->seedInventory(10, 1, 0, 2, 50);

        $res = purchaseOrderCommitImport($this->pdo, [
            ['id_producto' => 10, 'cantidad' => 3],
            ['id_producto' => 10, 'cantidad' => 4],
        ], 1, 7);

        $this->assertSame(1, $res['entradas_directas']);
        $this->assertSame(7, (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = 10')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM movimientos_inventario')->fetchColumn());
    }

    public function testCommitDuplicateRowsSameDetalleAreSummedFrozen(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Collagen Blend');
        $this->seedInventory(10, 1, 0, 2, 50);
        [, $det] = $this->seedOpenOrder(1, 'OC-D', [[10, 10]]);

        purchaseOrderCommitImport($this->pdo, [
            ['id_producto' => 10, 'cantidad' => 3, 'id_detalle' => $det[0]],
            ['id_producto' => 10, 'cantidad' => 4, 'id_detalle' => $det[0]],
        ], 1, 7);

        $this->assertSame(7, (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = 10')->fetchColumn());
        $this->assertSame(7, (int) $this->pdo->query('SELECT cantidad_recibida FROM detalle_orden_compra WHERE id_detalle = ' . $det[0])->fetchColumn());
    }

    public function testCommitHugeQuantityClampedFrozen(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Collagen Blend');
        $this->seedInventory(10, 1, 0, 2, 50);

        purchaseOrderCommitImport($this->pdo, [['id_producto' => 10, 'cantidad' => 5000000]], 1, 7);

        $this->assertSame(9999, (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = 10')->fetchColumn());
    }

    public function testCommitInvalidWarehouseIgnoresEverything(): void
    {
        $res = purchaseOrderCommitImport($this->pdo, [
            ['id_producto' => 10, 'cantidad' => 3],
            ['id_producto' => 11, 'cantidad' => 2],
        ], 0, 7);

        $this->assertSame(2, $res['ignoradas']);
        $this->assertSame(0, $res['entradas_directas']);
    }

    public function testCommitLargeBatchCompletes(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $rows = [];
        for ($i = 1; $i <= 200; $i++) {
            $this->seedProduct($i, 'Producto ' . $i);
            $this->seedInventory($i, 1, 0, 2, 9999);
            $rows[] = ['id_producto' => $i, 'cantidad' => 1];
        }

        $res = purchaseOrderCommitImport($this->pdo, $rows, 1, 7);

        $this->assertSame(200, $res['entradas_directas']);
        $this->assertSame(200, (int) $this->pdo->query("SELECT COUNT(*) FROM movimientos_inventario")->fetchColumn());
    }

    public function testCommitErrorMidwayRollsBackEverything(): void
    {
        // PDO que truena justo antes de cerrar la orden: para entonces ya se subió
        // stock y se insertó el movimiento dentro de la transacción, así que el
        // rollback tiene que deshacer trabajo real.
        $this->pdo = new class ('sqlite::memory:') extends PDO {
            public function prepare(string $query, array $options = []): PDOStatement|false
            {
                if (str_contains($query, "UPDATE ordenes_compra SET estado = 'recibida'")) {
                    throw new RuntimeException('fallo simulado a mitad del commit');
                }
                return parent::prepare($query, $options);
            }
        };
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->createSchema();
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Collagen Blend');
        $this->seedInventory(10, 1, 1, 2, 20);
        [, $det] = $this->seedOpenOrder(1, 'OC-ROLL', [[10, 5]]);

        try {
            purchaseOrderCommitImport($this->pdo, [
                ['id_producto' => 10, 'cantidad' => 5, 'id_detalle' => $det[0]],
            ], 1, 7);
            $this->fail('debió lanzar');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('fallo simulado', $e->getMessage());
        }

        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(1, (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = 10')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM movimientos_inventario')->fetchColumn());
        $this->assertNotSame('recibida', $this->pdo->query('SELECT estado FROM ordenes_compra')->fetchColumn());
    }

    public function testCommitSqlPayloadInRowsDoesNotAlterSchema(): void
    {
        $this->seedWarehouse(1, 'Matriz');
        $this->seedProduct(10, 'Collagen Blend');
        $this->seedInventory(10, 1, 0, 2, 20);

        purchaseOrderCommitImport($this->pdo, [
            ['id_producto' => 10, 'cantidad' => "2); DROP TABLE productos;--"],
        ], 1, 7);

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM productos')->fetchColumn());
        // "2); DROP..." casteado a int = 2
        $this->assertSame(2, (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = 10')->fetchColumn());
    }

    // =====================================================================
    // Helpers (esquema espejo de PurchaseOrderWorkflowTest)
    // =====================================================================

    private function createSchema(): void
    {
        $this->pdo->exec('CREATE TABLE almacenes (id_almacen INTEGER PRIMARY KEY, nombre TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE usuarios (id_usuario INTEGER PRIMARY KEY, nombre TEXT NOT NULL)');
        $this->pdo->exec("CREATE TABLE productos (id_producto INTEGER PRIMARY KEY, nombre TEXT NOT NULL, sku TEXT NULL, precio_costo REAL NOT NULL DEFAULT 0, precio_venta REAL NOT NULL DEFAULT 0, estado TEXT NOT NULL DEFAULT 'activo')");
        $this->pdo->exec('CREATE TABLE inventario_almacen (id_inventario INTEGER PRIMARY KEY AUTOINCREMENT, id_producto INTEGER NOT NULL, id_almacen INTEGER NOT NULL, cantidad_actual INTEGER NOT NULL DEFAULT 0, stock_minimo INTEGER NOT NULL DEFAULT 2, stock_maximo INTEGER NOT NULL DEFAULT 5)');
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

    private function seedProduct(int $id, string $nombre, string $sku = null, string $estado = 'activo'): void
    {
        $this->pdo->prepare("INSERT INTO productos (id_producto, nombre, sku, precio_costo, precio_venta, estado) VALUES (?, ?, ?, 10.0, 20.0, ?)")
            ->execute([$id, $nombre, $sku ?? ('SKU-' . $id), $estado]);
    }

    private function seedInventory(int $idProducto, int $idAlmacen, int $actual, int $minimo, int $maximo): void
    {
        $this->pdo->prepare('INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual, stock_minimo, stock_maximo) VALUES (?, ?, ?, ?, ?)')
            ->execute([$idProducto, $idAlmacen, $actual, $minimo, $maximo]);
    }

    /**
     * @param array<int, array{0:int,1:int}> $lineas [id_producto, cantidad_solicitada]
     * @return array{0:int, 1:array<int,int>} [id_orden_compra, [id_detalle...]]
     */
    private function seedOpenOrder(int $idAlmacen, string $referencia, array $lineas): array
    {
        $this->pdo->prepare("INSERT INTO ordenes_compra (id_usuario, id_almacen, referencia, estado, total_estimado) VALUES (1, ?, ?, 'enviada', 0)")
            ->execute([$idAlmacen, $referencia]);
        $idOrden = (int) $this->pdo->lastInsertId();

        $detalles = [];
        $stmt = $this->pdo->prepare('INSERT INTO detalle_orden_compra (id_orden_compra, id_producto, cantidad_solicitada, cantidad_recibida, costo_unitario) VALUES (?, ?, ?, 0, 10.0)');
        foreach ($lineas as [$idProducto, $cantidad]) {
            $stmt->execute([$idOrden, $idProducto, $cantidad]);
            $detalles[] = (int) $this->pdo->lastInsertId();
        }

        return [$idOrden, $detalles];
    }
}
