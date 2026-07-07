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
            'APP_ENV' => getenv('APP_ENV') === false ? null : (string) getenv('APP_ENV'),
        ];
    }

    protected function tearDown(): void
    {
        $this->restoreEnv('CATALOG_PERF_LOG');
        $this->restoreEnv('CATALOG_PERF_LOG_PATH');
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
