<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ProductDisplayUtilsTest extends TestCase
{
    public function testNormalizePresentationTextReturnsEmptyForGenericUnits(): void
    {
        $this->assertSame('', normalizePresentationText('Unidades'));
    }

    public function testNormalizePresentationTextKeepsSpecificPresentation(): void
    {
        $this->assertSame('Cápsulas', normalizePresentationText('Cápsulas'));
    }

    public function testBuildProductDisplayNameSkipsGenericUnits(): void
    {
        $this->assertSame('Omega 3', buildProductDisplayName('Omega 3', 'Unidades', 'Unidades'));
    }

    public function testBuildProductDisplayNameUsesSpecificVariantWhenAvailable(): void
    {
        $this->assertSame('Omega 3 (180 Caps)', buildProductDisplayName('Omega 3', '180 Caps', 'Unidades'));
    }

    public function testNormalizeProductDisplayRowAddsDerivedFields(): void
    {
        $row = normalizeProductDisplayRow([
            'nombre' => 'Ally Blend',
            'nombre_variante' => 'Unidades',
            'unidad' => 'Unidades',
        ]);

        $this->assertSame('', $row['display_nombre_variante']);
        $this->assertSame('', $row['display_unidad']);
        $this->assertSame('Ally Blend', $row['display_cart_name']);
    }
}