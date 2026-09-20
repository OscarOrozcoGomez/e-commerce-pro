<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Precios de un renglon en la tarjeta de entrega (views/entregas.php). Lo importante: un descuento de linea del POS
 * (precio_original = precio_unitario, subtotal ya rebajado) ahora SI cuenta como descuento visible, ademas de la
 * oferta (precio_original > precio_unitario) que ya se mostraba.
 */
final class EntregaPreciosItemTest extends TestCase
{
    public function testSinDescuentoNoTacha(): void
    {
        $p = entregaPreciosItem(1, 99.99, 99.99, 99.99);
        $this->assertFalse($p['con_descuento']);
        $this->assertFalse($p['mostrar_unitario']);
        $this->assertEqualsWithDelta(99.99, $p['subtotal'], 0.001);
    }

    public function testOfertaTachaElPrecioDeListaPorTodasLasPiezas(): void
    {
        $p = entregaPreciosItem(2, 99.99, 129.99, 199.98);
        $this->assertTrue($p['con_descuento']);
        $this->assertTrue($p['mostrar_unitario']);
        $this->assertEqualsWithDelta(259.98, $p['lista_total'], 0.001);
        $this->assertEqualsWithDelta(199.98, $p['subtotal'], 0.001);
    }

    public function testDescuentoDeLineaDelPosSeDetectaAunqueOriginalYUnitarioSeanIguales(): void
    {
        // POS: 1 pieza de 99.99 con 10 de descuento => subtotal 89.99, precio_original = precio_unitario.
        $p = entregaPreciosItem(1, 99.99, 99.99, 89.99);
        $this->assertTrue($p['con_descuento']);
        $this->assertEqualsWithDelta(99.99, $p['lista_total'], 0.001);
        $this->assertEqualsWithDelta(89.99, $p['subtotal'], 0.001);
    }

    public function testDescuentoDeLineaConVariasPiezasCuadraConElUnitario(): void
    {
        // 2 x 99.99 = 199.98 de lista, con 20 de descuento => 179.98. Se muestra "(2 x $99.99 c/u)" y la lista tachada.
        $p = entregaPreciosItem(2, 99.99, 99.99, 179.98);
        $this->assertTrue($p['con_descuento']);
        $this->assertTrue($p['mostrar_unitario']);
        $this->assertEqualsWithDelta(199.98, $p['lista_total'], 0.001);
    }

    public function testOfertaMasDescuentoDeLineaTachaLaListaOriginal(): void
    {
        // Lista 129.99, oferta 99.99, y ademas 10 de descuento de linea => subtotal 89.99.
        $p = entregaPreciosItem(1, 99.99, 129.99, 89.99);
        $this->assertTrue($p['con_descuento']);
        $this->assertEqualsWithDelta(129.99, $p['lista_total'], 0.001);
    }

    public function testSubtotalNuloSeAsumeUnitarioPorCantidad(): void
    {
        $p = entregaPreciosItem(3, 10.0, 10.0, null);
        $this->assertEqualsWithDelta(30.0, $p['subtotal'], 0.001);
        $this->assertFalse($p['con_descuento']);
    }

    public function testDiferenciaDeCentavosPorRedondeoNoCuentaComoDescuento(): void
    {
        // 3 x 33.33 = 99.99 pero el subtotal guardado es 99.98 por redondeo: no es un descuento real.
        $p = entregaPreciosItem(3, 33.33, 33.33, 99.99);
        $this->assertFalse($p['con_descuento']);
        // Y una diferencia real de un centavo por pieza mal redondeada sigue sin ser descuento: umbral de 0.004.
        $q = entregaPreciosItem(1, 10.00, 10.00, 9.996);
        $this->assertFalse($q['con_descuento']);
    }

    public function testProductoSinPrecioNoInventaDescuento(): void
    {
        $p = entregaPreciosItem(1, 0.0, 0.0, 0.0);
        $this->assertFalse($p['con_descuento']);
        $this->assertFalse($p['mostrar_unitario']);
    }

    public function testCantidadInvalidaSeNormalizaAUno(): void
    {
        $p = entregaPreciosItem(0, 50.0, 50.0, 50.0);
        $this->assertSame(1, $p['cantidad']);
        $this->assertFalse($p['mostrar_unitario']);
    }
}
