<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CatalogoUtilsTest extends TestCase
{
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->envBackup = [
            'CATALOG_PERF_LOG' => getenv('CATALOG_PERF_LOG') === false ? null : (string) getenv('CATALOG_PERF_LOG'),
            'CATALOG_PERF_LOG_PATH' => getenv('CATALOG_PERF_LOG_PATH') === false ? null : (string) getenv('CATALOG_PERF_LOG_PATH'),
            'CATALOG_PERF_LOG_MAX_BYTES' => getenv('CATALOG_PERF_LOG_MAX_BYTES') === false ? null : (string) getenv('CATALOG_PERF_LOG_MAX_BYTES'),
            'APP_ENV' => getenv('APP_ENV') === false ? null : (string) getenv('APP_ENV'),
        ];
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('CATALOG_PERF_LOG');
        $this->restoreEnv('CATALOG_PERF_LOG_PATH');
        $this->restoreEnv('CATALOG_PERF_LOG_MAX_BYTES');
        $this->restoreEnv('APP_ENV');

        parent::tearDown();
    }

    public function testCatalogCollapseProductsMergesVariantsAndKeepsBestValues(): void
    {
        $rows = [
            [
                'id_producto' => 1,
                'nombre' => 'Omega 3',
                'precio_desde' => 299,
                'precio_venta' => 299,
                'precio_comparacion_desde' => 350,
                'imagen' => '/assets/img/default-product.svg',
                'descripcion' => '',
                'total_variantes' => 1,
            ],
            [
                'id_producto' => 2,
                'nombre' => '  omega 3  ',
                'precio_desde' => 279,
                'precio_venta' => 279,
                'precio_comparacion_desde' => 340,
                'imagen' => 'omega3-real.jpg',
                'descripcion' => 'Suplemento premium',
                'total_variantes' => 1,
            ],
        ];

        $collapsed = catalogCollapseProducts($rows);

        $this->assertCount(1, $collapsed);
        $this->assertSame(279.0, (float) $collapsed[0]['precio_desde']);
        $this->assertSame(279.0, (float) $collapsed[0]['precio_venta']);
        $this->assertSame(340.0, (float) $collapsed[0]['precio_comparacion_desde']);
        $this->assertSame('omega3-real.jpg', (string) $collapsed[0]['imagen']);
        $this->assertSame('Suplemento premium', (string) $collapsed[0]['descripcion']);
        $this->assertSame(2, (int) $collapsed[0]['total_variantes']);
    }

    // ------------------------------------------------------------------
    // catalogBuildQueries — orden "disponibles primero" + toggle "Ver agotados"
    // ------------------------------------------------------------------

    private function pdoStub(): PDO
    {
        // getPublicSellableWarehouseIds() no toca la conexión (devuelve [1]); basta un PDO real.
        return new PDO('sqlite::memory:');
    }

    public function testCatalogBuildQueriesOrdersAvailableFirst(): void
    {
        $parts = catalogBuildQueries($this->pdoStub(), '', '');

        $this->assertSame(
            'ORDER BY (COALESCE(stk.stock_familia, 0) > 0) DESC, p.nombre ASC',
            $parts['order_by']
        );
    }

    public function testCatalogBuildQueriesJoinsFamilyStockInBothMainAndCount(): void
    {
        $parts = catalogBuildQueries($this->pdoStub(), '', '');

        foreach (['sql_main', 'sql_count'] as $key) {
            $this->assertStringContainsString('stk ON stk.root_id = p.id_producto', $parts[$key], $key);
            $this->assertStringContainsString('SUM(COALESCE(ia.cantidad_actual, 0)) AS stock_familia', $parts[$key], $key);
            // Los ids de almacén vendible se incrustan como lista literal, no como placeholder.
            $this->assertStringContainsString('ia.id_almacen IN (1)', $parts[$key], $key);
        }
    }

    public function testCatalogBuildQueriesDefaultKeepsAgotadosVisible(): void
    {
        $parts = catalogBuildQueries($this->pdoStub(), '', ''); // incluirAgotados = true por defecto

        $this->assertStringNotContainsString('COALESCE(stk.stock_familia, 0) > 0', $parts['sql_main']);
        $this->assertStringNotContainsString('COALESCE(stk.stock_familia, 0) > 0', $parts['sql_count']);
    }

    public function testCatalogBuildQueriesHidesAgotadosInBothQueriesWhenToggledOff(): void
    {
        $parts = catalogBuildQueries($this->pdoStub(), '', '', false);

        $this->assertStringContainsString('COALESCE(stk.stock_familia, 0) > 0', $parts['sql_main']);
        $this->assertStringContainsString('COALESCE(stk.stock_familia, 0) > 0', $parts['sql_count']);
    }

    public function testCatalogBuildQueriesWithoutFiltersHasNoBoundParams(): void
    {
        $parts = catalogBuildQueries($this->pdoStub(), '', '');
        $this->assertSame([], $parts['params']);
    }

    public function testCatalogBuildQueriesAplicaPrecioDeOfertaSinAgregarParametros(): void
    {
        $parts = catalogBuildQueries($this->pdoStub(), '', '');

        // El precio efectivo (rebaja de oferta: precio_oferta o costo+50) se expone como
        // columna y alimenta el "Desde $X" de la familia.
        $this->assertStringContainsString('AS precio_efectivo', $parts['sql_main']);
        $this->assertStringContainsString("LOWER(c_of.nombre) IN ('oferta', 'ofertas')", $parts['sql_main']);
        $this->assertStringContainsString('GREATEST(p.precio_costo, 0) + 50', $parts['sql_main']);

        // Sigue sin parametros ligados y la logica no toca la consulta de conteo.
        $this->assertSame([], $parts['params']);
        $this->assertStringNotContainsString('precio_efectivo', $parts['sql_count']);
    }

    public function testCatalogBuildQueriesBindsCategoryAndSearchParams(): void
    {
        $parts = catalogBuildQueries($this->pdoStub(), 'Suplementos', 'omega', false);

        $this->assertArrayHasKey(':cat', $parts['params']);
        $this->assertSame('Suplementos', $parts['params'][':cat']);
        $this->assertStringContainsString('JOIN producto_categorias', $parts['sql_main']);
        $this->assertStringContainsString('JOIN producto_categorias', $parts['sql_count']);

        foreach ([':search_name', ':search_code', ':search_variant', ':search_ex', ':search_ex_code', ':search_ex_variant'] as $p) {
            $this->assertArrayHasKey($p, $parts['params']);
            $this->assertSame('%omega%', $parts['params'][$p]);
        }

        // El filtro de agotados y los de categoría/búsqueda conviven en el mismo WHERE.
        $this->assertStringContainsString('COALESCE(stk.stock_familia, 0) > 0', $parts['sql_main']);
    }

    public function testCatalogBuildPaginationMetaReturnsExpectedHasMore(): void
    {
        $metaPage1 = catalogBuildPaginationMeta(20, 9, 1);
        $metaPage3 = catalogBuildPaginationMeta(20, 9, 3);

        $this->assertSame(3, $metaPage1['total_pages']);
        $this->assertTrue($metaPage1['has_more']);

        $this->assertSame(3, $metaPage3['total_pages']);
        $this->assertFalse($metaPage3['has_more']);
    }

    public function testCatalogBuildPaginationMetaSanitizesInvalidInput(): void
    {
        $meta = catalogBuildPaginationMeta(0, 0, 0);

        $this->assertSame(1, $meta['items_per_page']);
        $this->assertSame(1, $meta['current_page']);
        $this->assertSame(0, $meta['total_pages']);
        $this->assertFalse($meta['has_more']);
    }

    public function testCatalogPerfLogEntryWritesJsonLineAndCanBeReadBack(): void
    {
        $path = sys_get_temp_dir() . '/catalog_perf_test_' . uniqid('', true) . '.log';
        putenv('CATALOG_PERF_LOG_PATH=' . $path);

        catalogPerfLogEntry([
            'request_id' => 'abc123',
            'timings' => ['total_ms' => 12.34],
        ]);

        $lines = catalogPerfReadLastLines(10);
        $this->assertNotEmpty($lines);

        $decoded = json_decode((string) end($lines), true);
        $this->assertIsArray($decoded);
        $this->assertSame('abc123', $decoded['entry']['request_id']);

        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function testCatalogPerfEnabledForRequestSupportsEnvAndQueryFlag(): void
    {
        putenv('CATALOG_PERF_LOG=0');
        putenv('APP_ENV=production');

        $this->assertFalse(catalogPerfEnabledForRequest([]));
        $this->assertTrue(catalogPerfEnabledForRequest(['perf' => '1']));

        putenv('CATALOG_PERF_LOG=1');
        $this->assertTrue(catalogPerfEnabledForRequest([]));
    }

    public function testCatalogPerfLogEntryRoundsFloatValuesToTwoDecimals(): void
    {
        $path = sys_get_temp_dir() . '/catalog_perf_round_test_' . uniqid('', true) . '.log';
        putenv('CATALOG_PERF_LOG_PATH=' . $path);

        catalogPerfLogEntry([
            'timings' => [
                'request_total_ms' => 14.6299999999,
                'query_ms' => 12.1600000001,
            ],
        ]);

        $lines = catalogPerfReadLastLines(1);
        $decoded = json_decode((string) ($lines[0] ?? ''), true);

        $this->assertIsArray($decoded);
        $this->assertSame(14.63, (float) ($decoded['entry']['timings']['request_total_ms'] ?? 0));
        $this->assertSame(12.16, (float) ($decoded['entry']['timings']['query_ms'] ?? 0));

        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function testCatalogPerfLogRotatesWhenMaxBytesExceeded(): void
    {
        $path = sys_get_temp_dir() . '/catalog_perf_rotate_test_' . uniqid('', true) . '.log';
        putenv('CATALOG_PERF_LOG_PATH=' . $path);
        putenv('CATALOG_PERF_LOG_MAX_BYTES=10240');

        $payload = str_repeat('X', 12000);

        file_put_contents($path, $payload);
        catalogPerfRotateLogIfNeeded($path);

        $this->assertTrue(is_file($path));
        $this->assertTrue(is_file($path . '.1'));

        if (is_file($path)) {
            @unlink($path);
        }
        if (is_file($path . '.1')) {
            @unlink($path . '.1');
        }
    }

    private function restoreEnv(string $key): void
    {
        $value = $this->envBackup[$key] ?? null;
        if ($value === null) {
            putenv($key);
            return;
        }

        putenv($key . '=' . $value);
    }
}
