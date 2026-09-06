<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cobertura de core/blife_sync_utils.php — sincronización de la ficha de producto con la
 * tienda Shopify de B-Life. Se enfoca en entradas basura, casos límite y rutas negativas.
 */
final class BlifeSyncUtilsTest extends TestCase
{
    /** Catálogo mínimo con un producto de 1 variante y otro de 2, para no tocar la red. */
    private function fakeCatalog(): array
    {
        return [
            [
                'id' => 8931685236890,
                'handle' => 'ashwagandha-raiz',
                'variants' => [
                    ['id' => 48022771925146, 'title' => '200 Caps', 'sku' => 'BLIFEASH', 'barcode' => '0713152996663'],
                ],
            ],
            [
                'id' => 8945698701466,
                'handle' => 'omega-3-platinum',
                'variants' => [
                    ['id' => 48099709878426, 'title' => '180 Caps'],
                    ['id' => 48099709911194, 'title' => '90 Caps'],
                ],
            ],
        ];
    }

    private function assertResolveThrows(string $input, string $msgContains, ?array $catalogo = null): void
    {
        try {
            blifeResolveHandle($input, $catalogo);
            $this->fail('Se esperaba una excepción para: ' . var_export($input, true));
        } catch (Throwable $e) {
            $this->assertStringContainsString(
                $msgContains,
                $e->getMessage(),
                'Mensaje inesperado para ' . var_export($input, true)
            );
        }
    }

    // ------------------------------------------------------------------
    // blifeResolveHandle — entradas inválidas
    // ------------------------------------------------------------------

    public function testResolveHandleRejectsEmptyAndBlankInput(): void
    {
        $this->assertResolveThrows('', 'Pega el identificador');
        $this->assertResolveThrows('   ', 'Pega el identificador');
        $this->assertResolveThrows("\t\n  \r", 'Pega el identificador');
    }

    public function testResolveHandleRejectsOldMongoObjectId(): void
    {
        $this->assertResolveThrows('64e66956a255455c4e653a62', 'ID viejo de B-Life');
        // Mismo id en mayúsculas: el patrón es case-insensitive.
        $this->assertResolveThrows('64E66956A255455C4E653A62', 'ID viejo de B-Life');
    }

    public function testResolveHandleRejectsGarbageAndInjectionLikeInput(): void
    {
        $this->assertResolveThrows('ab', 'No reconozco');                         // demasiado corto
        $this->assertResolveThrows('<script>alert(1)</script>', 'No reconozco');  // XSS
        $this->assertResolveThrows('../../../etc/passwd', 'No reconozco');        // path traversal
        $this->assertResolveThrows("'; DROP TABLE productos; --", 'No reconozco');// SQLi-like
        $this->assertResolveThrows('handle con espacios', 'No reconozco');
        $this->assertResolveThrows('omega_3_platinum', 'No reconozco');           // Shopify usa guiones, no "_"
        $this->assertResolveThrows('café-münchen', 'No reconozco');               // acentos/unicode fuera de [A-Za-z0-9-]
    }

    public function testResolveHandleNumericIdNotInCatalogThrows(): void
    {
        $this->assertResolveThrows('99999999', 'No encontré', $this->fakeCatalog());
        $this->assertResolveThrows('8931685236890', 'No encontré', []); // catálogo vacío
    }

    // ------------------------------------------------------------------
    // blifeResolveHandle — entradas válidas
    // ------------------------------------------------------------------

    /**
     * @dataProvider validHandleProvider
     */
    public function testResolveHandleAcceptsHandlesUrlsAndIds(string $input, array $esperado): void
    {
        $this->assertSame($esperado, blifeResolveHandle($input, $this->fakeCatalog()));
    }

    public static function validHandleProvider(): array
    {
        return [
            'handle simple'                 => ['omega-3-platinum', ['omega-3-platinum', null]],
            'handle en mayúsculas'          => ['Omega-3-Platinum', ['omega-3-platinum', null]],
            'handle de 3 chars'             => ['a-b', ['a-b', null]],
            'handle + ?variant='            => ['omega-3-platinum?variant=48099709911194', ['omega-3-platinum', 48099709911194]],
            'handle + #fragmento'           => ['omega-3-platinum#reviews', ['omega-3-platinum', null]],
            'URL blife.mx /Product/<id>'    => ['https://www.blife.mx/Product/8931685236890', ['ashwagandha-raiz', null]],
            'URL con slash final'           => ['https://www.blife.mx/Product/8931685236890/', ['ashwagandha-raiz', null]],
            'URL Shopify + variante'        => ['https://blifemx.myshopify.com/products/omega-3-platinum?variant=48099709911194', ['omega-3-platinum', 48099709911194]],
            'URL scheme en mayúsculas'      => ['HTTPS://WWW.BLIFE.MX/products/Omega-3-Platinum', ['omega-3-platinum', null]],
            'gid ProductVariant'            => ['gid://shopify/ProductVariant/48099709911194', ['omega-3-platinum', 48099709911194]],
            'gid Product'                   => ['gid://shopify/Product/8945698701466', ['omega-3-platinum', null]],
            'id de producto numérico'       => ['8931685236890', ['ashwagandha-raiz', null]],
            'id de variante numérico'       => ['48099709911194', ['omega-3-platinum', 48099709911194]],
        ];
    }

    public function testResolveHandleTrimsSurroundingWhitespace(): void
    {
        $this->assertSame(['omega-3-platinum', null], blifeResolveHandle("  omega-3-platinum \n", $this->fakeCatalog()));
    }

    public function testResolveHandleTwentyThreeHexIsTreatedAsHandleNotObjectId(): void
    {
        // Solo 24 hex exactos se rechazan como "id viejo"; 23 cae en la regla de handle.
        $id23 = '64e66956a255455c4e653a6';
        $this->assertSame([$id23, null], blifeResolveHandle($id23, $this->fakeCatalog()));
    }

    public function testResolveHandleVariantMatchReturnsTheMatchedVariantId(): void
    {
        // El ?variant= trae 911194 pero el path apunta a la variante 878426:
        // cuando el número resuelve a una VARIANTE, gana esa variante, no el hint.
        $r = blifeResolveHandle('gid://shopify/ProductVariant/48099709878426?variant=48099709911194', $this->fakeCatalog());
        $this->assertSame(['omega-3-platinum', 48099709878426], $r);
    }

    public function testResolveHandleProductMatchKeepsTheVariantHint(): void
    {
        // El número resuelve a un PRODUCTO -> se conserva el hint del ?variant=.
        $r = blifeResolveHandle('8945698701466?variant=48099709911194', $this->fakeCatalog());
        $this->assertSame(['omega-3-platinum', 48099709911194], $r);
    }

    // ------------------------------------------------------------------
    // blifeHtmlToText
    // ------------------------------------------------------------------

    public function testHtmlToTextEmptyAndBlank(): void
    {
        $this->assertSame('', blifeHtmlToText(''));
        $this->assertSame('', blifeHtmlToText("   \n\t  "));
        $this->assertSame('', blifeHtmlToText('<p>&nbsp;</p><p> </p>'));
    }

    public function testHtmlToTextParagraphsBecomeDoubleNewline(): void
    {
        $this->assertSame("A\n\nB", blifeHtmlToText('<p>A</p><p>B</p>'));
        $this->assertSame("A\n\nB", blifeHtmlToText('<p>A</p><p> </p><p>B</p>'));
    }

    public function testHtmlToTextBrVariantsBecomeSingleNewline(): void
    {
        $this->assertSame("A\nB\nC\nD", blifeHtmlToText('A<br>B<br/>C<br />D'));
    }

    public function testHtmlToTextDecodesEntitiesAndNbsp(): void
    {
        $this->assertSame('& <tag> "x"', blifeHtmlToText('&amp; &lt;tag&gt; &quot;x&quot;'));
        // &nbsp; codificado y literal (0xC2A0) se normalizan a espacio simple.
        $this->assertSame('a b', blifeHtmlToText("a&nbsp;\xC2\xA0b"));
    }

    public function testHtmlToTextListItemsGetBullets(): void
    {
        $out = blifeHtmlToText('<ul><li>uno</li><li>dos</li></ul>');
        $this->assertStringContainsString('• uno', $out);
        $this->assertStringContainsString('• dos', $out);
    }

    public function testHtmlToTextStripsTagsAndCollapsesExcessNewlines(): void
    {
        $out = blifeHtmlToText("<p>Uno</p>\n\n\n\n\n<p>Dos</p>");
        $this->assertSame(0, preg_match('~\n{3,}~', $out), 'No debe quedar bloque de 3+ saltos');
        $this->assertSame("Uno\n\nDos", $out);
    }

    public function testHtmlToTextRemovesScriptTagButLeavesInertText(): void
    {
        $out = blifeHtmlToText('<script>alert(1)</script><p>Texto real</p>');
        $this->assertStringNotContainsString('<', $out);
        $this->assertStringNotContainsString('>', $out);
        $this->assertStringContainsString('Texto real', $out);
    }

    public function testHtmlToTextHandlesNestedTags(): void
    {
        $this->assertSame('Anidado', blifeHtmlToText('<div><p><strong>Anidado</strong></p></div>'));
    }

    // ------------------------------------------------------------------
    // blifeTabText
    // ------------------------------------------------------------------

    public function testTabTextReturnsEmptyWhenTabMissing(): void
    {
        $this->assertSame('', blifeTabText('', 'ingredientes', 1));
        $this->assertSame('', blifeTabText('<div id="modo-uso-1">x</div>', 'ingredientes', 1));
    }

    public function testTabTextRequiresExactProductIdMatch(): void
    {
        $html = '<div id="ingredientes-5">Contenido</div>';
        $this->assertSame('Contenido', blifeTabText($html, 'ingredientes', 5));
        $this->assertSame('', blifeTabText($html, 'ingredientes', 99));
        // Colisión por prefijo: 50 no debe casar cuando se pide 5.
        $this->assertSame('', blifeTabText('<div id="ingredientes-50">X</div>', 'ingredientes', 5));
    }

    public function testTabTextIgnoresExtraAttributesBeforeCloseBracket(): void
    {
        $html = '<div id="ingredientes-7" class="tab-content" style="display:none">  Raíz de X  </div>';
        $this->assertSame('Raíz de X', blifeTabText($html, 'ingredientes', 7));
    }

    public function testTabTextConvertsBrAndDecodesEntities(): void
    {
        $html = '<div id="modo-uso-3">Tomar 2<br>una vez al d&iacute;a</div>';
        $this->assertSame("Tomar 2\nuna vez al día", blifeTabText($html, 'modo-uso', 3));
    }

    public function testTabTextWithoutClosingDivReturnsEmpty(): void
    {
        $this->assertSame('', blifeTabText('<div id="ingredientes-1">Sin cierre', 'ingredientes', 1));
    }

    public function testTabTextPicksTheRequestedTabAmongSeveral(): void
    {
        $html = '<div id="modo-uso-9">MODO</div><div id="ingredientes-9">ING</div>';
        $this->assertSame('ING', blifeTabText($html, 'ingredientes', 9));
        $this->assertSame('MODO', blifeTabText($html, 'modo-uso', 9));
    }

    public function testTabTextStripsInjectedMarkup(): void
    {
        $out = blifeTabText('<div id="ingredientes-1"><img src=x onerror=alert(1)>ok</div>', 'ingredientes', 1);
        $this->assertStringNotContainsString('<', $out);
        $this->assertStringContainsString('ok', $out);
    }

    public function testTabTextQuotesRegexSpecialPrefix(): void
    {
        // El prefijo se pasa por preg_quote: "." debe ser literal, no comodín.
        $this->assertSame('Z', blifeTabText('<div id="a.b-4">Z</div>', 'a.b', 4));
        $this->assertSame('', blifeTabText('<div id="axb-4">Z</div>', 'a.b', 4));
    }

    // ------------------------------------------------------------------
    // blifeVariantMetaFromJsonLd
    // ------------------------------------------------------------------

    private function jsonLd(array $obj): string
    {
        return '<script type="application/ld+json">' . json_encode($obj) . '</script>';
    }

    public function testJsonLdReturnsEmptyWhenAbsentOrInvalid(): void
    {
        $this->assertSame([], blifeVariantMetaFromJsonLd(''));
        $this->assertSame([], blifeVariantMetaFromJsonLd('<html><body>sin json-ld</body></html>'));
        $this->assertSame([], blifeVariantMetaFromJsonLd('<script type="application/ld+json">{ esto no es json </script>'));
    }

    public function testJsonLdParsesProductGroupHasVariant(): void
    {
        $html = $this->jsonLd([
            '@type' => 'ProductGroup',
            'hasVariant' => [
                ['@id' => '/products/x?variant=111#variant', 'sku' => 'SKU-A', 'gtin' => '0001'],
                ['@id' => '/products/x?variant=222#variant', 'sku' => 'SKU-B', 'gtin' => '0002'],
            ],
        ]);

        $this->assertSame([
            111 => ['sku' => 'SKU-A', 'barcode' => '0001'],
            222 => ['sku' => 'SKU-B', 'barcode' => '0002'],
        ], blifeVariantMetaFromJsonLd($html));
    }

    public function testJsonLdSupportsGraphWrapperAndUrlField(): void
    {
        $html = $this->jsonLd([
            '@graph' => [
                ['@type' => 'Organization', 'name' => 'B Life'],
                ['@type' => 'ProductGroup', 'hasVariant' => [
                    ['url' => 'https://blife.mx/products/x?variant=333', 'sku' => 'S', 'gtin13' => '13-DIGITS'],
                ]],
            ],
        ]);

        $this->assertSame([333 => ['sku' => 'S', 'barcode' => '13-DIGITS']], blifeVariantMetaFromJsonLd($html));
    }

    public function testJsonLdSkipsVariantsWithoutVariantParamAndToleratesMissingFields(): void
    {
        $html = $this->jsonLd([
            '@type' => 'ProductGroup',
            'hasVariant' => [
                ['@id' => '/products/x#no-variant-param', 'sku' => 'IGNORAR'],
                ['@id' => '/products/x?variant=444'], // sin sku ni gtin
                'no-es-un-objeto',
            ],
        ]);

        $this->assertSame([444 => ['sku' => '', 'barcode' => '']], blifeVariantMetaFromJsonLd($html));
    }

    public function testJsonLdIgnoresNodesWhereHasVariantIsNotAList(): void
    {
        $html = $this->jsonLd(['@type' => 'ProductGroup', 'hasVariant' => 'roto']);
        $this->assertSame([], blifeVariantMetaFromJsonLd($html));
    }

    public function testJsonLdReadsSecondScriptWhenFirstIsUnrelated(): void
    {
        $html = $this->jsonLd(['@type' => 'WebSite', 'name' => 'x'])
            . "\n"
            . $this->jsonLd(['@type' => 'ProductGroup', 'hasVariant' => [
                ['@id' => '/p?variant=555', 'sku' => 'S5', 'gtin' => 'G5'],
            ]]);

        $this->assertSame([555 => ['sku' => 'S5', 'barcode' => 'G5']], blifeVariantMetaFromJsonLd($html));
    }
}
