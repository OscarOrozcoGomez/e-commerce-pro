<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SaleInventoryBypassUtilsTest extends TestCase
{
    public function testBypassesWhenAuthorizedUserIncludesKeyword(): void
    {
        $result = resolveVentaSinInventario('Muestra para cliente SININVENTARIO gracias', true, 'SININVENTARIO');

        $this->assertTrue($result['sin_inventario']);
        $this->assertSame('Muestra para cliente  gracias', $result['observaciones']);
    }

    public function testIsCaseInsensitiveOnTheKeyword(): void
    {
        $result = resolveVentaSinInventario('venta de cortesia sininventario', true, 'SININVENTARIO');

        $this->assertTrue($result['sin_inventario']);
        $this->assertSame('venta de cortesia', $result['observaciones']);
    }

    public function testDoesNotBypassWhenUserIsNotAuthorized(): void
    {
        $result = resolveVentaSinInventario('SININVENTARIO', false, 'SININVENTARIO');

        $this->assertFalse($result['sin_inventario']);
        $this->assertSame('SININVENTARIO', $result['observaciones']);
    }

    public function testDoesNotBypassWhenKeywordIsAbsent(): void
    {
        $result = resolveVentaSinInventario('Entregar antes de las 5pm', true, 'SININVENTARIO');

        $this->assertFalse($result['sin_inventario']);
        $this->assertSame('Entregar antes de las 5pm', $result['observaciones']);
    }

    public function testDoesNotBypassWhenKeywordIsConfiguredEmpty(): void
    {
        $result = resolveVentaSinInventario('cualquier nota', true, '');

        $this->assertFalse($result['sin_inventario']);
        $this->assertSame('cualquier nota', $result['observaciones']);
    }

    public function testStripsKeywordEvenWhenItIsTheOnlyContent(): void
    {
        $result = resolveVentaSinInventario('  SININVENTARIO  ', true, 'SININVENTARIO');

        $this->assertTrue($result['sin_inventario']);
        $this->assertSame('', $result['observaciones']);
    }

    public function testStripsAllOccurrencesOfTheKeyword(): void
    {
        $result = resolveVentaSinInventario('SININVENTARIO doble SININVENTARIO', true, 'SININVENTARIO');

        $this->assertTrue($result['sin_inventario']);
        $this->assertSame('doble', $result['observaciones']);
    }
}
