<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pruebas negativas para getCampaignStockAlerts() (core/stock_prediction.php): el
 * cruce entre calendario_campanas.productos_destacados (texto libre editado a mano
 * por staff -- "1,2,3") y las predicciones de inventario. El foco es que datos
 * corruptos/malformados en ese campo de texto nunca generen una alerta falsa ni
 * tronen, en vez de solo probar el camino feliz.
 */
final class StockPredictionTest extends TestCase
{
    public function testGeneratesAlertWhenStockRunsOutBeforeCampaignEnds(): void
    {
        $campanas = [
            ['nombre' => 'Campana X', 'fecha_fin' => '2026-09-10', 'productos_destacados' => '1'],
        ];
        $predicciones = [
            ['id_producto' => 1, 'nombre' => 'Producto Agotable', 'dias_restantes' => 2],
        ];

        $alertas = getCampaignStockAlerts($campanas, $predicciones, '2026-09-01');

        $this->assertCount(1, $alertas);
        $this->assertSame('Producto Agotable', $alertas[0]['producto']);
        $this->assertSame(2, $alertas[0]['dias_restantes_stock']);
        $this->assertSame(9, $alertas[0]['dias_para_fin_campana']);
    }

    public function testNoAlertWhenStockOutlastsTheCampaign(): void
    {
        $campanas = [
            ['nombre' => 'Campana Y', 'fecha_fin' => '2026-09-05', 'productos_destacados' => '1'],
        ];
        $predicciones = [
            ['id_producto' => 1, 'nombre' => 'Producto Sobrado', 'dias_restantes' => 100],
        ];

        $this->assertSame([], getCampaignStockAlerts($campanas, $predicciones, '2026-09-01'));
    }

    public function testSkipsCampaignWithEmptyProductosDestacados(): void
    {
        $campanas = [
            ['nombre' => 'Sin productos', 'fecha_fin' => '2026-09-10', 'productos_destacados' => null],
            ['nombre' => 'String vacio', 'fecha_fin' => '2026-09-10', 'productos_destacados' => ''],
            ['nombre' => 'Solo espacios', 'fecha_fin' => '2026-09-10', 'productos_destacados' => '   '],
        ];
        $predicciones = [['id_producto' => 1, 'nombre' => 'X', 'dias_restantes' => 0]];

        $this->assertSame([], getCampaignStockAlerts($campanas, $predicciones, '2026-09-01'));
    }

    public function testHandlesMalformedProductIdListWithoutCrashing(): void
    {
        // Texto corrupto/hostil en vez de una lista limpia de IDs: comas de mas, texto no
        // numerico, intento de inyeccion. intval() de cada pedazo debe degradar a 0 (que
        // se filtra), nunca tronar.
        $campanas = [
            [
                'nombre' => 'Campana Corrupta',
                'fecha_fin' => '2026-09-10',
                'productos_destacados' => "1,,,abc,2; DROP TABLE productos; --,3",
            ],
        ];
        $predicciones = [
            ['id_producto' => 1, 'nombre' => 'Uno', 'dias_restantes' => 0],
            ['id_producto' => 2, 'nombre' => 'Dos', 'dias_restantes' => 0],
            ['id_producto' => 3, 'nombre' => 'Tres', 'dias_restantes' => 0],
        ];

        $alertas = getCampaignStockAlerts($campanas, $predicciones, '2026-09-01');

        // intval() nunca truena con texto no numerico -- extrae el prefijo numerico que
        // encuentre (o 0 si no hay ninguno), y los ids <= 0 se descartan. Lo que importa
        // es que la funcion no truene ante el intento de inyeccion, no el valor exacto
        // que produzca cada fragmento.
        $this->assertCount(3, $alertas);
        $nombres = array_column($alertas, 'producto');
        sort($nombres);
        $this->assertSame(['Dos', 'Tres', 'Uno'], $nombres);
    }

    public function testDuplicateProductIdsInListDoNotProduceDuplicateAlerts(): void
    {
        $campanas = [
            ['nombre' => 'Campana Duplicada', 'fecha_fin' => '2026-09-10', 'productos_destacados' => '1,1,1,1'],
        ];
        $predicciones = [['id_producto' => 1, 'nombre' => 'Repetido', 'dias_restantes' => 0]];

        $alertas = getCampaignStockAlerts($campanas, $predicciones, '2026-09-01');
        $this->assertCount(1, $alertas);
    }

    public function testSkipsProductIdsWithNoMatchingPrediction(): void
    {
        $campanas = [
            ['nombre' => 'Campana', 'fecha_fin' => '2026-09-10', 'productos_destacados' => '999'],
        ];
        $predicciones = [['id_producto' => 1, 'nombre' => 'Otro producto', 'dias_restantes' => 0]];

        $this->assertSame([], getCampaignStockAlerts($campanas, $predicciones, '2026-09-01'));
    }

    public function testSkipsPredictionsWithNonNumericDiasRestantes(): void
    {
        // getPrediccionesInventario() usa el simbolo '—' (em dash) cuando no hay
        // historico de ventas suficiente para proyectar dias restantes -- eso no debe
        // tratarse como "0 dias" (lo que dispararia alertas falsas).
        $campanas = [
            ['nombre' => 'Campana', 'fecha_fin' => '2026-09-10', 'productos_destacados' => '1'],
        ];
        $predicciones = [['id_producto' => 1, 'nombre' => 'Sin historico', 'dias_restantes' => '—']];

        $this->assertSame([], getCampaignStockAlerts($campanas, $predicciones, '2026-09-01'));
    }

    public function testSkipsCampaignWithUnparseableFechaFinInsteadOfCrashing(): void
    {
        $campanas = [
            ['nombre' => 'Fecha Corrupta', 'fecha_fin' => 'no-es-una-fecha', 'productos_destacados' => '1'],
            ['nombre' => 'Fecha Vacia', 'fecha_fin' => '', 'productos_destacados' => '1'],
        ];
        $predicciones = [['id_producto' => 1, 'nombre' => 'X', 'dias_restantes' => 0]];

        $this->assertSame([], getCampaignStockAlerts($campanas, $predicciones, '2026-09-01'));
    }

    public function testCampaignThatAlreadyEndedNeverProducesAnAlert(): void
    {
        // Campana que ya termino (fecha_fin en el pasado): dias_para_fin_campana es
        // negativo, y con max(0, negativo) = 0, ningun dias_restantes (siempre >= 0)
        // puede ser menor que 0 -- no debe alertar sobre campanas muertas.
        $campanas = [
            ['nombre' => 'Campana Vieja', 'fecha_fin' => '2026-01-01', 'productos_destacados' => '1'],
        ];
        $predicciones = [['id_producto' => 1, 'nombre' => 'X', 'dias_restantes' => 0]];

        $this->assertSame([], getCampaignStockAlerts($campanas, $predicciones, '2026-09-01'));
    }

    public function testIgnoresZeroAndNegativeProductIds(): void
    {
        $campanas = [
            ['nombre' => 'Campana', 'fecha_fin' => '2026-09-10', 'productos_destacados' => '0,-1,-999'],
        ];
        // Nota: id_producto=0 en la prediccion no deberia poder alcanzarse nunca (los
        // productos reales empiezan en 1), pero aun si existiera, 0 y negativos se
        // filtran del lado de productos_destacados antes de buscar coincidencia.
        $predicciones = [['id_producto' => 0, 'nombre' => 'Fantasma', 'dias_restantes' => 0]];

        $this->assertSame([], getCampaignStockAlerts($campanas, $predicciones, '2026-09-01'));
    }

    public function testHandlesExtremelyLongProductosDestacadosStringWithoutCrashing(): void
    {
        $listaGigante = implode(',', range(1, 5000));
        $campanas = [
            ['nombre' => 'Campana Grande', 'fecha_fin' => '2026-09-10', 'productos_destacados' => $listaGigante],
        ];
        $predicciones = [['id_producto' => 2500, 'nombre' => 'En medio del rango', 'dias_restantes' => 0]];

        $alertas = getCampaignStockAlerts($campanas, $predicciones, '2026-09-01');
        $this->assertCount(1, $alertas);
    }

    public function testHandlesEmptyCampaignsAndEmptyPredictionsArrays(): void
    {
        $this->assertSame([], getCampaignStockAlerts([], [], '2026-09-01'));
        $this->assertSame([], getCampaignStockAlerts([['nombre' => 'X', 'fecha_fin' => '2026-09-10', 'productos_destacados' => '1']], [], '2026-09-01'));
    }

    public function testDefaultsToTodayWhenNoReferenceDateProvided(): void
    {
        // Sin pasar $hoy explicito, debe usar la fecha real de hoy -- se prueba con una
        // campana que termina muy lejos en el futuro para que la alerta dependa solo de
        // que la funcion efectivamente calcule "hoy" en vez de tronar por parametro nulo.
        $campanas = [
            ['nombre' => 'Futura', 'fecha_fin' => date('Y-m-d', strtotime('+3650 days')), 'productos_destacados' => '1'],
        ];
        $predicciones = [['id_producto' => 1, 'nombre' => 'X', 'dias_restantes' => 1]];

        $alertas = getCampaignStockAlerts($campanas, $predicciones);
        $this->assertCount(1, $alertas);
    }
}
