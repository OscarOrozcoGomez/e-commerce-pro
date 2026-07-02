<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PickupOfferUtilsTest extends TestCase
{
    public function testCalculatePickupOfferUsesPieceTierDiscountWhenConfigured(): void
    {
        $settings = [
            'activo' => true,
            'descuento_porcentaje' => 0.0,
            'descuento_fijo' => 0.0,
            'subtotal_minimo' => 0.0,
            'piezas_minimas' => 1,
            'tope_descuento' => 0.0,
            'mensaje_publico' => 'x',
            'descuentos_por_pieza' => [
                1 => 15.0,
                2 => 30.0,
                3 => 45.0,
            ],
        ];

        $result = calculatePickupOffer(150.0, 3, $settings);

        $this->assertTrue($result['elegible']);
        $this->assertSame(45.0, $result['ahorro']);
        $this->assertSame(105.0, $result['total_sucursal']);
    }

    public function testCalculatePickupOfferReturnsZeroWhenNoTierMatchesYet(): void
    {
        $settings = [
            'activo' => true,
            'descuento_porcentaje' => 0.0,
            'descuento_fijo' => 0.0,
            'subtotal_minimo' => 0.0,
            'piezas_minimas' => 1,
            'tope_descuento' => 0.0,
            'mensaje_publico' => 'x',
            'descuentos_por_pieza' => [
                2 => 20.0,
                4 => 50.0,
            ],
        ];

        $result = calculatePickupOffer(100.0, 1, $settings);

        $this->assertTrue($result['elegible']);
        $this->assertSame(0.0, $result['ahorro']);
        $this->assertSame(100.0, $result['total_sucursal']);
    }

    public function testCalculatePickupOfferFallsBackToClosestLowerTierWhenExactNotConfigured(): void
    {
        $settings = [
            'activo' => true,
            'descuento_porcentaje' => 0.0,
            'descuento_fijo' => 0.0,
            'subtotal_minimo' => 0.0,
            'piezas_minimas' => 1,
            'tope_descuento' => 0.0,
            'mensaje_publico' => 'x',
            'descuentos_por_pieza' => [
                1 => 15.0,
                2 => 30.0,
                3 => 45.0,
            ],
        ];

        $result = calculatePickupOffer(200.0, 5, $settings);

        $this->assertSame(45.0, $result['ahorro']);
        $this->assertSame(155.0, $result['total_sucursal']);
    }

    public function testCalculatePickupOfferIgnoresLegacyCapWhenUsingPieceTiers(): void
    {
        $settings = [
            'activo' => true,
            'descuento_porcentaje' => 0.0,
            'descuento_fijo' => 0.0,
            'subtotal_minimo' => 0.0,
            'piezas_minimas' => 1,
            'tope_descuento' => 15.0,
            'mensaje_publico' => 'x',
            'descuentos_por_pieza' => [
                1 => 20.0,
            ],
        ];

        $result = calculatePickupOffer(120.0, 1, $settings);

        $this->assertSame(20.0, $result['ahorro']);
        $this->assertSame(100.0, $result['total_sucursal']);
    }

    public function testCalculatePickupOfferReturnsNotEligibleWhenInactive(): void
    {
        $settings = [
            'activo' => false,
            'descuento_porcentaje' => 10.0,
            'descuento_fijo' => 0.0,
            'subtotal_minimo' => 0.0,
            'piezas_minimas' => 1,
            'tope_descuento' => 0.0,
            'mensaje_publico' => 'x',
            'descuentos_por_pieza' => [
                1 => 10.0,
            ],
        ];

        $result = calculatePickupOffer(150.0, 2, $settings);

        $this->assertFalse($result['elegible']);
        $this->assertSame(0.0, $result['faltante_subtotal']);
        $this->assertSame(0, $result['faltante_piezas']);
        $this->assertSame(0.0, $result['ahorro']);
    }
}
