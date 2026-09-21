<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Reglas puras (sin DB) de la estrategia de productos por caducar: escalera de precio,
 * precio de paquete, resumen de riesgo por lotes y el argumento honesto que Alex le dice al
 * cliente. La parte con base de datos vive en EstrategiaCaducidadesTest.
 */
final class OfertaCaducidadPricingTest extends TestCase
{
    #[DataProvider('escaleraProvider')]
    public function testEscaleraPorSeveridad(?string $severidad, float $esperado): void
    {
        // venta 500, costo 200 -> piso 250
        $this->assertSame($esperado, ofertaPrecioEscalera(500.0, 200.0, $severidad));
    }

    public static function escaleraProvider(): array
    {
        return [
            'critico va directo al piso costo+50' => ['critico', 250.0],
            'urgente descuenta 30%' => ['urgente', 350.0],
            'planificar descuenta 15%' => ['planificar', 425.0],
            'sin_rotacion descuenta 15%' => ['sin_rotacion', 425.0],
            'sin severidad usa el escalon inicial' => [null, 425.0],
            'vigilar usa el escalon inicial' => ['vigilar', 425.0],
        ];
    }

    public function testEscaleraNuncaBajaDelPisoCostoMas50(): void
    {
        // venta 300, costo 200 -> piso 250; 30% de 300 = 210 < piso
        $this->assertSame(250.0, ofertaPrecioEscalera(300.0, 200.0, 'urgente'));
    }

    public function testEscaleraNuncaSuperaElPrecioDeVenta(): void
    {
        // margen delgado: costo+50 (250) > precio de venta (240) -> se queda en el precio normal
        $this->assertSame(240.0, ofertaPrecioEscalera(240.0, 200.0, 'critico'));
        $this->assertSame(240.0, ofertaPrecioEscalera(240.0, 200.0, 'planificar'));
    }

    public function testPaqueteDescuentaUnEscalonExtraSobreLaOferta(): void
    {
        // venta 500, costo 200, oferta 425 -> paquete 425 - 10% de 500 = 375
        $this->assertSame(375.0, ofertaPrecioPaquete(500.0, 200.0, 425.0));
    }

    public function testPaqueteSeRedondeaAPesosEnteros(): void
    {
        // venta 499, oferta 350 -> 350 - 49.90 = 300.10 -> $300 (no centavos raros)
        $this->assertSame(300.0, ofertaPrecioPaquete(499.0, 200.0, 350.0));
    }

    public function testPaqueteNoBajaDelPiso(): void
    {
        // oferta 260 -> 260 - 50 = 210 < piso 250 -> se queda en el piso
        $this->assertSame(250.0, ofertaPrecioPaquete(500.0, 200.0, 260.0));
    }

    public function testPaqueteEsNullCuandoLaOfertaYaEstaEnElPiso(): void
    {
        $this->assertNull(ofertaPrecioPaquete(500.0, 200.0, 250.0));
    }

    public function testCadPaqueteRequiereAlMenosDosPiezasEnRiesgo(): void
    {
        $this->assertNull(ofertaCadPaquete(500.0, 200.0, 425.0, 1, 10));
        $this->assertNull(ofertaCadPaquete(500.0, 200.0, 425.0, 5, 1));
    }

    public function testCadPaqueteTopaLaCantidadMaximaConLoQueHayEnRiesgo(): void
    {
        $paquete = ofertaCadPaquete(500.0, 200.0, 425.0, 3, 20);

        $this->assertSame(2, $paquete['cantidad_minima']);
        $this->assertSame(3, $paquete['cantidad_maxima']);
        $this->assertSame(375.0, $paquete['precio_unitario']);
        $this->assertSame(50.0, $paquete['ahorro_por_pieza']);
    }

    public function testCadPaqueteNoPasaDelStockVendible(): void
    {
        $this->assertSame(2, ofertaCadPaquete(500.0, 200.0, 425.0, 10, 2)['cantidad_maxima']);
    }

    public function testUrgenciaPorSeveridad(): void
    {
        $this->assertSame('alta', ofertaCadUrgencia('critico'));
        $this->assertSame('media', ofertaCadUrgencia('urgente'));
        $this->assertSame('baja', ofertaCadUrgencia('planificar'));
        $this->assertSame('baja', ofertaCadUrgencia('sin_rotacion'));
        $this->assertNull(ofertaCadUrgencia('vigilar'));
        $this->assertNull(ofertaCadUrgencia('ok'));
        $this->assertNull(ofertaCadUrgencia(null));
        $this->assertGreaterThan(ofertaCadUrgenciaRank('media'), ofertaCadUrgenciaRank('alta'));
        $this->assertGreaterThan(ofertaCadUrgenciaRank(null), ofertaCadUrgenciaRank('baja'));
    }

    public function testResumenRiesgoUsaLaPeorSeveridadEntreLotesVendibles(): void
    {
        $resumen = loteResumenRiesgoPorProducto([
            $this->lote(1, 'urgente', 5, '2027-01-10', 100),
            $this->lote(1, 'critico', 3, '2026-11-05', 40),
            $this->lote(1, 'ok', 50, '2028-01-01', 500),
        ])[1];

        $this->assertSame('critico', $resumen['severidad']);
        $this->assertTrue($resumen['en_riesgo']);
        $this->assertSame(8, $resumen['piezas_en_riesgo']); // 5 + 3, no cuenta el lote ok
        $this->assertSame(58, $resumen['stock_vendible']);
        $this->assertSame('2026-11-05', $resumen['fecha_caducidad']); // el que FEFO vende primero
        $this->assertSame(40, $resumen['dias_para_caducar']);
    }

    public function testResumenRiesgoIgnoraLotesCaducadosYNoVendibles(): void
    {
        $noVendible = $this->lote(2, 'critico', 4, '2026-10-01', 10);
        $noVendible['no_vendible'] = true;

        $resumen = loteResumenRiesgoPorProducto([
            $this->lote(2, 'caducado', 6, '2026-09-01', -19),
            $noVendible,
            $this->lote(2, 'ok', 9, '2028-01-01', 500),
        ])[2];

        $this->assertSame('ok', $resumen['severidad']);
        $this->assertFalse($resumen['en_riesgo']);
        $this->assertSame(0, $resumen['piezas_en_riesgo']);
        $this->assertSame(9, $resumen['stock_vendible']);
        $this->assertNull($resumen['fecha_caducidad']);
    }

    public function testResumenRiesgoSinNingunLoteVendibleNoTieneSeveridad(): void
    {
        $resumen = loteResumenRiesgoPorProducto([$this->lote(3, 'caducado', 6, '2026-09-01', -19)])[3];

        $this->assertNull($resumen['severidad']);
        $this->assertFalse($resumen['en_riesgo']);
        $this->assertSame(0, $resumen['stock_vendible']);
    }

    public function testResumenRiesgoSoloCalculaDiasDeTratamientoConDosisCapturada(): void
    {
        $sinPorcion = $this->lote(4, 'urgente', 2, '2027-01-01', 100);
        $sinPorcion['capsulas_por_envase'] = 60;
        $sinPorcion['porcion_capsulas'] = null;
        $this->assertNull(loteResumenRiesgoPorProducto([$sinPorcion])[4]['dias_tratamiento']);

        $conDosis = $sinPorcion;
        $conDosis['porcion_capsulas'] = 2;
        $this->assertSame(30, loteResumenRiesgoPorProducto([$conDosis])[4]['dias_tratamiento']);
    }

    public function testArgumentoHonestoCuentaLaFechaElMargenYLasPiezas(): void
    {
        $texto = ofertaCadArgumentoHonesto([
            'en_riesgo' => true,
            'fecha_caducidad' => '2027-03-15',
            'dias_para_caducar' => 176,
            'dias_tratamiento' => 60,
            'piezas_en_riesgo' => 4,
        ]);

        $this->assertStringContainsString('15 de marzo de 2027', $texto);
        $this->assertStringContainsString('unos 6 meses', $texto);
        $this->assertStringContainsString('unos 60 dias con la dosis sugerida por la marca', $texto);
        $this->assertStringContainsString('unos 116 dias de margen', $texto);
        $this->assertStringContainsString('Quedan 4 piezas', $texto);
        $this->assertStringNotContainsString('No tenemos capturada', $texto);
    }

    public function testArgumentoHonestoNoInventaRendimientoSinDosisCapturada(): void
    {
        $texto = ofertaCadArgumentoHonesto([
            'en_riesgo' => true,
            'fecha_caducidad' => '2026-10-05',
            'dias_para_caducar' => 15,
            'dias_tratamiento' => null,
            'piezas_en_riesgo' => 1,
        ]);

        $this->assertStringContainsString('en 15 dias', $texto);
        $this->assertStringNotContainsString('Un envase rinde', $texto);
        // Y se le prohibe explicitamente al modelo estimar la duracion por su cuenta (visto en prueba real).
        $this->assertStringContainsString('No tenemos capturada la duracion de un envase', $texto);
        $this->assertStringContainsString('Quedan 1 pieza con esa fecha', $texto);
    }

    public function testArgumentoHonestoNoPrometeTerminarloSiElEnvaseNoAlcanza(): void
    {
        $texto = ofertaCadArgumentoHonesto([
            'en_riesgo' => true,
            'fecha_caducidad' => '2026-10-05',
            'dias_para_caducar' => 15,
            'dias_tratamiento' => 60, // rinde mas de lo que falta para caducar
            'piezas_en_riesgo' => 2,
        ]);

        $this->assertStringNotContainsString('alcanza a terminarlo', $texto);
    }

    public function testArgumentoHonestoVacioSiNoHayRiesgo(): void
    {
        $this->assertSame('', ofertaCadArgumentoHonesto(['en_riesgo' => false, 'fecha_caducidad' => '2027-03-15']));
        $this->assertSame('', ofertaCadArgumentoHonesto(['en_riesgo' => true, 'fecha_caducidad' => null]));
    }

    private function lote(int $idProducto, string $severidad, int $restante, string $fecha, int $dias): array
    {
        return [
            'id_producto' => $idProducto,
            'severidad' => $severidad,
            'cantidad_restante' => $restante,
            'fecha_caducidad' => $fecha,
            'dias_hasta_caducar' => $dias,
            'capsulas_por_envase' => null,
            'porcion_capsulas' => null,
        ];
    }
}
