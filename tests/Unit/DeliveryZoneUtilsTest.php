<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cobertura negativa / de borde para core/delivery_zone_utils.php -- el modulo compartido
 * por el checkout web, el panel de vendedor y el bot para clasificar la zona de entrega y
 * cobrar el envio foraneo (fuera de la periferia de Guadalajara).
 *
 * El objetivo explicito es TRONAR la funcionalidad: entradas basura, limites, unicode,
 * coordenadas invalidas, y los falsos positivos/negativos del match por texto que
 * provocaron el incidente real (pedido WEB a "Arvento, Tlajomulco" sin cobro de $40).
 */
final class DeliveryZoneUtilsTest extends TestCase
{
    /* -----------------------------------------------------------------
     * deliveryZoneStripAccentsLower
     * --------------------------------------------------------------- */

    public function testStripAccentsLowerNormalizesAccentsCaseAndTrim(): void
    {
        $this->assertSame('aeiounu', deliveryZoneStripAccentsLower('ÁÉÍÓÚñÜ'));
        $this->assertSame('tonala de guadalajara', deliveryZoneStripAccentsLower('  Tonalá de Guadalajara  '));
        $this->assertSame('', deliveryZoneStripAccentsLower(''));
        $this->assertSame('', deliveryZoneStripAccentsLower("\t\n  "));
    }

    /* -----------------------------------------------------------------
     * deliveryZoneValidCoord -- todo lo que NO debe pasar por valido
     * --------------------------------------------------------------- */

    public function testValidCoordRejectsGarbageNullAndOutOfRange(): void
    {
        $this->assertFalse(deliveryZoneValidCoord(null, null));
        $this->assertFalse(deliveryZoneValidCoord('abc', 'def'));
        $this->assertFalse(deliveryZoneValidCoord('', ''));
        $this->assertFalse(deliveryZoneValidCoord(20.6, null));
        $this->assertFalse(deliveryZoneValidCoord(91.0, 0.0));
        $this->assertFalse(deliveryZoneValidCoord(-90.001, 0.0));
        $this->assertFalse(deliveryZoneValidCoord(0.0, 180.5));
        $this->assertFalse(deliveryZoneValidCoord(0.0, -180.5));
        $this->assertFalse(deliveryZoneValidCoord(NAN, 10.0));
        $this->assertFalse(deliveryZoneValidCoord(10.0, INF));
        $this->assertFalse(deliveryZoneValidCoord(-INF, 10.0));
    }

    public function testValidCoordRejectsNullIslandZeroZero(): void
    {
        // (0,0) es el resultado tipico de un geocode que fallo: nunca es una direccion real.
        $this->assertFalse(deliveryZoneValidCoord(0, 0));
        $this->assertFalse(deliveryZoneValidCoord(0.0, 0.0));
        $this->assertFalse(deliveryZoneValidCoord('0', '0'));
    }

    public function testValidCoordAcceptsNumericStringsAndBoundaries(): void
    {
        $this->assertTrue(deliveryZoneValidCoord('20.6597', '-103.3496'));
        $this->assertTrue(deliveryZoneValidCoord(20.6597, -103.3496));
        $this->assertTrue(deliveryZoneValidCoord(90, 180));
        $this->assertTrue(deliveryZoneValidCoord(-90, -180));
    }

    /* -----------------------------------------------------------------
     * deliveryZoneHaversineKm
     * --------------------------------------------------------------- */

    public function testHaversineZeroForSamePoint(): void
    {
        $this->assertSame(0.0, deliveryZoneHaversineKm(20.6, -103.3, 20.6, -103.3));
    }

    public function testHaversineKnownDistanceGdlToCdmxIsAboutFourHundredSixtyKm(): void
    {
        $km = deliveryZoneHaversineKm(20.6736, -103.3440, 19.4326, -99.1332);
        $this->assertGreaterThan(430.0, $km);
        $this->assertLessThan(490.0, $km);
    }

    public function testHaversineIsSymmetricAndSmallForNearbyPoints(): void
    {
        $a = deliveryZoneHaversineKm(20.6050, -103.2400, 20.6140, -103.2400);
        $b = deliveryZoneHaversineKm(20.6140, -103.2400, 20.6050, -103.2400);
        $this->assertEqualsWithDelta($a, $b, 1e-9);
        $this->assertEqualsWithDelta(1.0, $a, 0.2); // ~1 km
    }

    /* -----------------------------------------------------------------
     * deliveryZonePointInPolygon
     * --------------------------------------------------------------- */

    private function squareAroundStore(): array
    {
        // Cuadrado holgado alrededor de la sucursal (lat 20.5..20.7, lng -103.4..-103.1).
        return [
            ['lat' => 20.5, 'lng' => -103.4],
            ['lat' => 20.7, 'lng' => -103.4],
            ['lat' => 20.7, 'lng' => -103.1],
            ['lat' => 20.5, 'lng' => -103.1],
        ];
    }

    public function testPointInPolygonInsideAndOutside(): void
    {
        $poly = $this->squareAroundStore();
        $this->assertTrue(deliveryZonePointInPolygon(20.61, -103.24, $poly));
        $this->assertFalse(deliveryZonePointInPolygon(20.20, -103.24, $poly)); // muy al sur
        $this->assertFalse(deliveryZonePointInPolygon(20.61, -102.50, $poly)); // muy al este
    }

    public function testPointInPolygonAcceptsPairVertexFormat(): void
    {
        $poly = [[20.5, -103.4], [20.7, -103.4], [20.7, -103.1], [20.5, -103.1]];
        $this->assertTrue(deliveryZonePointInPolygon(20.61, -103.24, $poly));
        $this->assertFalse(deliveryZonePointInPolygon(21.5, -103.24, $poly));
    }

    public function testPointInPolygonFalseForDegeneratePolygons(): void
    {
        $this->assertFalse(deliveryZonePointInPolygon(20.6, -103.3, []));
        $this->assertFalse(deliveryZonePointInPolygon(20.6, -103.3, [['lat' => 20.6, 'lng' => -103.3]]));
        $this->assertFalse(deliveryZonePointInPolygon(20.6, -103.3, [[20.5, -103.4], [20.7, -103.1]]));
    }

    public function testPointInPolygonIgnoresMalformedVertices(): void
    {
        $poly = [
            'no soy un vertice',
            ['lat' => 20.5, 'lng' => -103.4],
            42,
            ['lat' => 20.7, 'lng' => -103.4],
            ['lat' => 20.7, 'lng' => -103.1],
            ['lat' => 20.5, 'lng' => -103.1],
        ];
        $this->assertTrue(deliveryZonePointInPolygon(20.61, -103.24, $poly));
    }

    /* -----------------------------------------------------------------
     * deliveryZoneClassifyByText -- falsos positivos / negativos
     * --------------------------------------------------------------- */

    public function testClassifyByTextIndeterminadoForEmptyOrCluelessInput(): void
    {
        $this->assertSame('indeterminado', deliveryZoneClassifyByText(''));
        $this->assertSame('indeterminado', deliveryZoneClassifyByText('   '));
        $this->assertSame('indeterminado', deliveryZoneClassifyByText("\t\n"));
        $this->assertSame('indeterminado', deliveryZoneClassifyByText('Calle Falsa 123, CP 00000'));
        $this->assertSame('indeterminado', deliveryZoneClassifyByText('sin datos utiles aqui'));
    }

    public function testClassifyByTextPeripheralColonyOverridesZmgMunicipio(): void
    {
        // EL INCIDENTE: "Arvento" es Tlajomulco por nombre, pero fuera de la periferia
        // con reparto sin costo -- debe cobrar. El match de municipio NO debe ganarle.
        $this->assertSame('foraneo', deliveryZoneClassifyByText('Condominio Luna #32, Arvento, Tlajomulco'));
        $this->assertSame('foraneo', deliveryZoneClassifyByText('Circuito Arvento Poniente 45, Tlajomulco de Zuniga, Jalisco'));
        $this->assertSame('foraneo', deliveryZoneClassifyByText('ARVENTO'));
        $this->assertSame('foraneo', deliveryZoneClassifyByText('Hacienda Santa Fe, Tlajomulco'));
        $this->assertSame('foraneo', deliveryZoneClassifyByText('La Tijera, Tlajomulco, Jal'));
        $this->assertSame('foraneo', deliveryZoneClassifyByText('Cajititlán, Tlajomulco'));
    }

    public function testClassifyByTextStillRecognizesZmgMunicipios(): void
    {
        $this->assertSame('local', deliveryZoneClassifyByText('Av Vallarta 123, Col Americana, Guadalajara, Jal'));
        $this->assertSame('local', deliveryZoneClassifyByText('Calle Reforma 45, Zapopan'));
        $this->assertSame('local', deliveryZoneClassifyByText('Col Centro, Tonalá, Jalisco'));
        $this->assertSame('local', deliveryZoneClassifyByText('San Pedro Tlaquepaque, CP 45500'));
        $this->assertSame('local', deliveryZoneClassifyByText('TLAJOMULCO DE ZUÑIGA'));
    }

    public function testClassifyByTextForaneoForJaliscoOutsideZmg(): void
    {
        $this->assertSame('foraneo', deliveryZoneClassifyByText('Nicolas Bravo 221, CP 48900, Villa Purificacion, Jalisco'));
        $this->assertSame('foraneo', deliveryZoneClassifyByText('Autlan de Navarro, Jalisco'));
        $this->assertSame('foraneo', deliveryZoneClassifyByText('Puerto Vallarta, Jalisco'));
        $this->assertSame('foraneo', deliveryZoneClassifyByText('Tepatitlan de Morelos, Jalisco'));
    }

    /**
     * LIMITACION CONOCIDA del match por texto: hay municipios homonimos en otros estados
     * ("Tonalá, Chiapas"; "Nueva Guadalajara, Michoacán") y el clasificador de texto los
     * marca como 'local' porque solo mira el nombre del municipio. El arreglo real es la
     * ruta por COORDENADAS (deliveryZoneClassify), que aqui gana siempre que haya lat/lng.
     * Este test fija el comportamiento actual para que un cambio no pase inadvertido.
     */
    public function testClassifyByTextKnownFalsePositiveForHomonymousMunicipios(): void
    {
        $this->assertSame('local', deliveryZoneClassifyByText('Tonala, Chiapas'));
        $this->assertSame('local', deliveryZoneClassifyByText('Nueva Guadalajara, Michoacan'));
    }

    /* -----------------------------------------------------------------
     * deliveryZoneClassify -- hibrido: coordenadas mandan sobre el texto
     * --------------------------------------------------------------- */

    public function testClassifyCoordsWinOverContradictoryText(): void
    {
        // Coordenadas en la sucursal pero el texto dice "Arvento": manda la geometria -> local.
        $this->assertSame('local', deliveryZoneClassify(
            DELIVERY_ZONE_STORE_LAT,
            DELIVERY_ZONE_STORE_LNG,
            'Arvento, Tlajomulco'
        ));

        // Coordenadas en el Zocalo de CDMX pero el texto dice "Guadalajara Centro" -> foraneo.
        $this->assertSame('foraneo', deliveryZoneClassify(19.4326, -99.1332, 'Guadalajara Centro, Jalisco'));
    }

    public function testClassifyByRadiusNearVsFar(): void
    {
        $this->assertSame('local', deliveryZoneClassify(20.5000, -103.2400, '')); // ~11-12 km
        $this->assertSame('foraneo', deliveryZoneClassify(20.3600, -103.2400, '')); // ~27 km
    }

    public function testClassifyFallsBackToTextWhenCoordsInvalid(): void
    {
        $this->assertSame('foraneo', deliveryZoneClassify(null, null, 'Arvento, Tlajomulco'));
        $this->assertSame('local', deliveryZoneClassify('abc', 'def', 'Zapopan, Jalisco'));
        $this->assertSame('indeterminado', deliveryZoneClassify(0, 0, 'lugar sin pistas'));
        $this->assertSame('indeterminado', deliveryZoneClassify(0.0, 0.0, ''));
    }

    public function testClassifyUsesPolygonWhenProvidedElseRadius(): void
    {
        $poly = $this->squareAroundStore();
        $this->assertSame('local', deliveryZoneClassify(20.61, -103.24, '', $poly));
        $this->assertSame('foraneo', deliveryZoneClassify(20.20, -103.24, '', $poly));

        // Poligono degenerado (<3 vertices): se ignora y decide el radio.
        $this->assertSame('local', deliveryZoneClassify(20.5000, -103.2400, '', [[20.5, -103.4], [20.7, -103.1]]));
        $this->assertSame('local', deliveryZoneClassify(20.5000, -103.2400, '', []));
    }

    /* -----------------------------------------------------------------
     * deliveryZoneShippingFee
     * --------------------------------------------------------------- */

    public function testShippingFeeOnlyChargesForaneoUnderThreshold(): void
    {
        $this->assertSame(40.00, deliveryZoneShippingFee('foraneo', 0));
        $this->assertSame(40.00, deliveryZoneShippingFee('foraneo', 1));
        $this->assertSame(0.0, deliveryZoneShippingFee('foraneo', 2));
        $this->assertSame(0.0, deliveryZoneShippingFee('foraneo', 9));
        $this->assertSame(0.0, deliveryZoneShippingFee('local', 1));
        $this->assertSame(0.0, deliveryZoneShippingFee('indeterminado', 1));
    }

    public function testShippingFeeIsCaseSensitiveOnZoneString(): void
    {
        // El clasificador SIEMPRE regresa minusculas; una zona 'FORANEO' es un bug del
        // caller y aqui no debe cobrar (no adivinamos intencion).
        $this->assertSame(0.0, deliveryZoneShippingFee('FORANEO', 1));
        $this->assertSame(0.0, deliveryZoneShippingFee('Foraneo', 1));
        $this->assertSame(0.0, deliveryZoneShippingFee(' foraneo', 1));
    }

    public function testShippingFeeNegativeProductCountTreatedAsFew(): void
    {
        // count() nunca es negativo; si el caller manda basura, se cobra (no se regala envio).
        $this->assertSame(40.00, deliveryZoneShippingFee('foraneo', -5));
    }

    public function testShippingFeeHonorsCustomFeeAndThreshold(): void
    {
        $this->assertSame(55.00, deliveryZoneShippingFee('foraneo', 1, 55.00));
        $this->assertSame(40.00, deliveryZoneShippingFee('foraneo', 2, 40.00, 3)); // 2 < 3 => cobra
        $this->assertSame(0.0, deliveryZoneShippingFee('foraneo', 3, 40.00, 3));
        $this->assertSame(0.0, deliveryZoneShippingFee('foraneo', 1, -10.00)); // fee negativa => 0
    }

    /* -----------------------------------------------------------------
     * deliveryZoneResolveForOrder
     * --------------------------------------------------------------- */

    public function testResolveForOrderNoChargeForNonDomicilio(): void
    {
        foreach (['Sucursal', 'sucursal', 'SUCURSAL', 'Pickup', 'No especificado', ''] as $tipo) {
            $r = deliveryZoneResolveForOrder(19.4326, -99.1332, 'CDMX lejos', $tipo, 1);
            $this->assertSame('local', $r['zona'], "tipo={$tipo}");
            $this->assertSame(0.0, $r['costo_envio'], "tipo={$tipo}");
        }
    }

    public function testResolveForOrderChargesDomicilioForaneoWithOneProduct(): void
    {
        $r = deliveryZoneResolveForOrder(19.4326, -99.1332, 'CDMX', 'Domicilio', 1);
        $this->assertSame('foraneo', $r['zona']);
        $this->assertSame(40.00, $r['costo_envio']);
        $this->assertSame(1, $r['productos_distintos']);
    }

    public function testResolveForOrderFreeShippingWithTwoOrMoreProducts(): void
    {
        $r = deliveryZoneResolveForOrder(19.4326, -99.1332, 'CDMX', 'Domicilio', 2);
        $this->assertSame('foraneo', $r['zona']);
        $this->assertSame(0.0, $r['costo_envio']);
    }

    public function testResolveForOrderUsesTextWhenNoCoordsAndClampsNegativeCount(): void
    {
        $r = deliveryZoneResolveForOrder(null, null, 'Arvento, Tlajomulco', 'Domicilio', -3);
        $this->assertSame('foraneo', $r['zona']);
        $this->assertSame(40.00, $r['costo_envio']); // 0 distintos < 2 => cobra
        $this->assertSame(0, $r['productos_distintos']);
    }

    public function testResolveForOrderIndeterminadoDoesNotCharge(): void
    {
        $r = deliveryZoneResolveForOrder(null, null, '', 'Domicilio', 1);
        $this->assertSame('indeterminado', $r['zona']);
        $this->assertSame(0.0, $r['costo_envio']);
    }

    public function testResolveForOrderAlwaysReturnsExpectedKeys(): void
    {
        $r = deliveryZoneResolveForOrder(20.61, -103.24, 'Zapopan', 'Domicilio', 1);
        $this->assertArrayHasKey('zona', $r);
        $this->assertArrayHasKey('costo_envio', $r);
        $this->assertArrayHasKey('productos_distintos', $r);
    }

    /* -----------------------------------------------------------------
     * Wrappers de compatibilidad en core/ai_assistant.php
     * --------------------------------------------------------------- */

    public function testLegacyAiWrappersDelegateToSharedModule(): void
    {
        $this->assertSame(40.00, aiCalcularCargoEnvio('foraneo', 1));
        $this->assertSame(0.0, aiCalcularCargoEnvio('foraneo', 2));
        $this->assertSame(0.0, aiCalcularCargoEnvio('local', 1));

        $this->assertSame('local', aiClasificarZonaEntrega('Zapopan, Jalisco'));
        $this->assertSame('foraneo', aiClasificarZonaEntrega('Autlan de Navarro, Jalisco'));
        $this->assertSame('indeterminado', aiClasificarZonaEntrega(''));

        // Cambio de comportamiento INTENCIONAL vs. la version vieja: "Arvento, Tlajomulco"
        // antes daba 'local' (y no cobraba). Ahora el wrapper hereda la lista de colonias
        // perifericas y da 'foraneo'.
        $this->assertSame('foraneo', aiClasificarZonaEntrega('Arvento, Tlajomulco'));
    }
}
