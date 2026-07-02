<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PickupOfferUtilsTest extends TestCase
{
    public function testCalculatePickupOfferReturnsDiscountWhenEligible(): void
    {
        $settings = [
            'activo' => true,
            'descuento_porcentaje' => 10.0,
            'descuento_fijo' => 5.0,
            'subtotal_minimo' => 100.0,
            'piezas_minimas' => 2,
            'tope_descuento' => 0.0,
            'mensaje_publico' => 'x',
        ];

        $result = calculatePickupOffer(150.0, 3, $settings);

        $this->assertTrue($result['elegible']);
        $this->assertSame(20.0, $result['ahorro']);
        $this->assertSame(130.0, $result['total_sucursal']);
    }

    public function testCalculatePickupOfferRespectsCap(): void
    {
        $settings = [
            'activo' => true,
            'descuento_porcentaje' => 20.0,
            'descuento_fijo' => 0.0,
            'subtotal_minimo' => 0.0,
            'piezas_minimas' => 1,
            'tope_descuento' => 15.0,
            'mensaje_publico' => 'x',
        ];

        $result = calculatePickupOffer(120.0, 1, $settings);

        $this->assertSame(15.0, $result['ahorro']);
        $this->assertSame(105.0, $result['total_sucursal']);
    }

    public function testCalculatePickupOfferReturnsMissingRequirementsWhenNotEligible(): void
    {
        $settings = [
            'activo' => true,
            'descuento_porcentaje' => 10.0,
            'descuento_fijo' => 0.0,
            'subtotal_minimo' => 200.0,
            'piezas_minimas' => 4,
            'tope_descuento' => 0.0,
            'mensaje_publico' => 'x',
        ];

        $result = calculatePickupOffer(150.0, 2, $settings);

        $this->assertFalse($result['elegible']);
        $this->assertSame(50.0, $result['faltante_subtotal']);
        $this->assertSame(2, $result['faltante_piezas']);
        $this->assertSame(0.0, $result['ahorro']);
    }
}
