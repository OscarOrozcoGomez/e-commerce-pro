<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class EntregaCambioUtilsTest extends TestCase
{
    // ---- deliveryDiaKey -------------------------------------------------------

    public function testDiaKeyReturnsDatePartOfADatetime(): void
    {
        $this->assertSame('2026-08-31', deliveryDiaKey('2026-08-31 14:05:00'));
        $this->assertSame('2026-08-31', deliveryDiaKey('2026-08-31'));
    }

    public function testDiaKeyReturnsEmptyForBlankOrNull(): void
    {
        $this->assertSame('', deliveryDiaKey(null));
        $this->assertSame('', deliveryDiaKey(''));
        $this->assertSame('', deliveryDiaKey('   '));
    }

    public function testDiaKeyReturnsEmptyForUnparseableValue(): void
    {
        $this->assertSame('', deliveryDiaKey('no es fecha'));
        $this->assertSame('', deliveryDiaKey('0000-00-00 00:00:00'));
    }

    // ---- deliveryFormatDiaLabel --------------------------------------------------

    public function testFormatDiaLabelBuildsSpanishLabel(): void
    {
        // 2026-09-01 es martes.
        $this->assertSame('Martes 1 de septiembre de 2026', deliveryFormatDiaLabel('2026-09-01', '2026-01-01'));
    }

    public function testFormatDiaLabelMarksRelativeDays(): void
    {
        $this->assertStringEndsWith(' · hoy', deliveryFormatDiaLabel('2026-08-31 10:00:00', '2026-08-31'));
        $this->assertStringEndsWith(' · mañana', deliveryFormatDiaLabel('2026-09-01', '2026-08-31'));
        $this->assertStringEndsWith(' · ayer', deliveryFormatDiaLabel('2026-08-30', '2026-08-31'));
    }

    public function testFormatDiaLabelFallsBackWhenNoDate(): void
    {
        $this->assertSame('Sin día programado', deliveryFormatDiaLabel(null));
        $this->assertSame('Sin día programado', deliveryFormatDiaLabel(''));
        $this->assertSame('Sin día programado', deliveryFormatDiaLabel('basura'));
    }

    // ---- deliverySinEvidenciaReasonOptions / deliveryValidateSinEvidencia -------

    public function testReasonOptionsHaveExpectedKeys(): void
    {
        $opts = deliverySinEvidenciaReasonOptions();
        $this->assertArrayHasKey('olvide_foto', $opts);
        $this->assertArrayHasKey('otro', $opts);
        foreach ($opts as $label) {
            $this->assertNotSame('', trim($label));
        }
    }

    public function testValidateSinEvidenciaPassesThroughWhenNotOmitting(): void
    {
        $res = deliveryValidateSinEvidencia([]);
        $this->assertFalse($res['omitir']);
        $this->assertTrue($res['valid']);
        $this->assertSame('', $res['motivo_etiqueta']);
    }

    public function testValidateSinEvidenciaResolvesPresetReason(): void
    {
        $res = deliveryValidateSinEvidencia([
            'omitir_evidencia' => '1',
            'motivo_sin_evidencia' => 'fallo_camara',
        ]);
        $this->assertTrue($res['omitir']);
        $this->assertTrue($res['valid']);
        $this->assertSame('La camara o el telefono fallo', $res['motivo_etiqueta']);
    }

    public function testValidateSinEvidenciaRejectsUnknownReason(): void
    {
        $res = deliveryValidateSinEvidencia([
            'omitir_evidencia' => '1',
            'motivo_sin_evidencia' => 'inventado',
        ]);
        $this->assertTrue($res['omitir']);
        $this->assertFalse($res['valid']);
        $this->assertNotSame('', $res['error']);
    }

    public function testValidateSinEvidenciaRequiresTextForOtro(): void
    {
        $res = deliveryValidateSinEvidencia([
            'omitir_evidencia' => '1',
            'motivo_sin_evidencia' => 'otro',
            'motivo_sin_evidencia_otro' => '   ',
        ]);
        $this->assertFalse($res['valid']);

        $ok = deliveryValidateSinEvidencia([
            'omitir_evidencia' => '1',
            'motivo_sin_evidencia' => 'otro',
            'motivo_sin_evidencia_otro' => 'Se descargó el teléfono',
        ]);
        $this->assertTrue($ok['valid']);
        $this->assertSame('Se descargó el teléfono', $ok['motivo_etiqueta']);
    }

    public function testValidateSinEvidenciaTrimsOtroTo180Chars(): void
    {
        $largo = str_repeat('x', 250);
        $res = deliveryValidateSinEvidencia([
            'omitir_evidencia' => '1',
            'motivo_sin_evidencia' => 'otro',
            'motivo_sin_evidencia_otro' => $largo,
        ]);
        $this->assertTrue($res['valid']);
        $this->assertSame(180, mb_strlen($res['motivo_etiqueta']));
    }

    public function testValidateSinEvidenciaIgnoresFlagOtherThanExactlyOne(): void
    {
        foreach (['0', 'true', 'si', ''] as $flag) {
            $res = deliveryValidateSinEvidencia(['omitir_evidencia' => $flag]);
            $this->assertFalse($res['omitir'], "flag '$flag' no debe activar omitir");
            $this->assertTrue($res['valid']);
        }
    }

    // ---- publicacion omitida ------------------------------------------------------

    public function testBuildPublicacionOmitidaMarkerContainsTokenNameAndDate(): void
    {
        $marca = deliveryBuildPublicacionOmitidaMarker('Hector', '2026-08-26 22:30:00');
        $this->assertStringContainsString(DELIVERY_PUBLICACION_OMITIDA_TOKEN, $marca);
        $this->assertStringContainsString('2026-08-26 22:30', $marca);
        $this->assertStringContainsString('por Hector', $marca);
        $this->assertStringStartsWith(' | ', $marca);
    }

    public function testBuildPublicacionOmitidaMarkerFallsBackWhenNameBlank(): void
    {
        $marca = deliveryBuildPublicacionOmitidaMarker('   ', '2026-01-01 00:00:00');
        $this->assertStringContainsString('por repartidor', $marca);
    }

    public function testPublicacionFueOmitidaDetectsMarker(): void
    {
        $obs = 'ENTREGA: Domicilio | Cliente: Hector'
            . deliveryBuildPublicacionOmitidaMarker('Hector', '2026-08-27 09:00:00');
        $this->assertTrue(deliveryPublicacionFueOmitida($obs));
    }

    public function testPublicacionFueOmitidaFalseWhenAbsentOrNull(): void
    {
        $this->assertFalse(deliveryPublicacionFueOmitida(null));
        $this->assertFalse(deliveryPublicacionFueOmitida(''));
        $this->assertFalse(deliveryPublicacionFueOmitida('ENTREGA: Domicilio | Cliente: Hector'));
    }

    public function testMarkerIsPickedUpByNotLikeQueryPattern(): void
    {
        // El filtro de la vista usa: observaciones NOT LIKE '%PUBLICACION_OMITIDA%'
        $obs = 'algo' . deliveryBuildPublicacionOmitidaMarker('X', '2026-09-01 10:00:00');
        $this->assertMatchesRegularExpression('/' . preg_quote(DELIVERY_PUBLICACION_OMITIDA_TOKEN, '/') . '/', $obs);
    }

    // ---- deliveryParseMonto ---------------------------------------------------

    public function testParseMontoAcceptsNumbersAndCleansCurrencyText(): void
    {
        $this->assertSame(1250.5, deliveryParseMonto('$ 1,250.50'));
        $this->assertSame(200.0, deliveryParseMonto('200'));
        $this->assertSame(200.0, deliveryParseMonto(200));
        $this->assertSame(200.5, deliveryParseMonto(200.5));
        $this->assertSame(1000.0, deliveryParseMonto('1,000'));
        $this->assertSame(0.0, deliveryParseMonto('0'));
    }

    public function testParseMontoReturnsNullForNonNumeric(): void
    {
        $this->assertNull(deliveryParseMonto(''));
        $this->assertNull(deliveryParseMonto('   '));
        $this->assertNull(deliveryParseMonto('abc'));
        $this->assertNull(deliveryParseMonto('12.34.56'));
    }

    // ---- deliveryCalcularCambio --------------------------------------------------

    public function testCalcularCambioReturnsChangeWhenPaymentExceedsTotal(): void
    {
        $r = deliveryCalcularCambio(180.0, 200.0);
        $this->assertTrue($r['valid']);
        $this->assertTrue($r['suficiente']);
        $this->assertSame(20.0, $r['cambio']);
        $this->assertSame(0.0, $r['falta']);
        $this->assertSame(180.0, $r['total']);
        $this->assertSame(200.0, $r['paga_con']);
    }

    public function testCalcularCambioExactPaymentGivesZeroChange(): void
    {
        $r = deliveryCalcularCambio(180.0, 180.0);
        $this->assertTrue($r['suficiente']);
        $this->assertSame(0.0, $r['cambio']);
        $this->assertSame(0.0, $r['falta']);
    }

    public function testCalcularCambioShortPaymentReportsShortfallAndNoChange(): void
    {
        $r = deliveryCalcularCambio(180.0, 150.0);
        $this->assertTrue($r['valid']);
        $this->assertFalse($r['suficiente']);
        $this->assertSame(0.0, $r['cambio']);
        $this->assertSame(30.0, $r['falta']);
    }

    public function testCalcularCambioIsInvalidWhenNoPaymentEntered(): void
    {
        $r = deliveryCalcularCambio(180.0, '');
        $this->assertFalse($r['valid']);
        $this->assertSame(0.0, $r['cambio']);
        $this->assertSame(180.0, $r['falta']);
        $this->assertSame('', $r['error']);
    }

    public function testCalcularCambioRejectsNegativePayment(): void
    {
        $r = deliveryCalcularCambio(180.0, -5);
        $this->assertFalse($r['valid']);
        $this->assertNotSame('', $r['error']);
    }

    public function testCalcularCambioHandlesStringInputsWithCurrencyFormatting(): void
    {
        $r = deliveryCalcularCambio('1,180.00', '$1,200');
        $this->assertTrue($r['valid']);
        $this->assertSame(20.0, $r['cambio']);
    }

    public function testCalcularCambioTreatsNegativeOrBrokenTotalAsZero(): void
    {
        $r = deliveryCalcularCambio(-50.0, 100.0);
        $this->assertSame(0.0, $r['total']);
        $this->assertSame(100.0, $r['cambio']);

        $r2 = deliveryCalcularCambio('basura', 100.0);
        $this->assertSame(0.0, $r2['total']);
        $this->assertSame(100.0, $r2['cambio']);
    }

    public function testCalcularCambioRoundsToTwoDecimals(): void
    {
        $r = deliveryCalcularCambio(10.001, 20.006);
        $this->assertSame(10.0, $r['total']);      // 10.001 -> 10.00
        $this->assertSame(20.01, $r['paga_con']);  // 20.006 -> 20.01
        $this->assertSame(10.01, $r['cambio']);
    }
}
