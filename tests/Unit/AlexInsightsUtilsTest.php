<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cubre las funciones puras de core/alex_insights_utils.php (parseo de texto, clasificacion por
 * palabras clave, descifrado de PII). Las funciones que consultan la BD (LOCATE/SUBSTRING de
 * MySQL, no soportadas por el SQLite en memoria que usa esta suite) ya se verificaron a mano
 * contra datos reales -- ver la sesion de implementacion de "Alex Insights".
 */
final class AlexInsightsUtilsTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PII_ENCRYPTION_KEY');
        parent::tearDown();
    }

    // --- alexInsightsExtractOrderReference ---

    public function testExtractOrderReferenceFindsWebNumeroPedido(): void
    {
        $ref = alexInsightsExtractOrderReference('Cliente solicita cancelar el pedido WEB-6A85446198B17');
        $this->assertSame(['tipo' => 'numero', 'valor' => 'WEB-6A85446198B17'], $ref);
    }

    public function testExtractOrderReferenceFindsBareIdPedido(): void
    {
        $ref = alexInsightsExtractOrderReference('Cambio de direccion en pedido ya agendado #45');
        $this->assertSame(['tipo' => 'id', 'valor' => '45'], $ref);
    }

    public function testExtractOrderReferencePrefersWebNumeroOverBareId(): void
    {
        $ref = alexInsightsExtractOrderReference('Pedido WEB-6A85446198B17 (antes referido como #45)');
        $this->assertSame(['tipo' => 'numero', 'valor' => 'WEB-6A85446198B17'], $ref);
    }

    public function testExtractOrderReferenceReturnsNullWithoutAnyReference(): void
    {
        $this->assertNull(alexInsightsExtractOrderReference('Cliente solicita descuento del 50% en su pedido'));
    }

    // --- alexInsightsLooksLikeCancellation ---

    public function testLooksLikeCancellationDetectsCancelar(): void
    {
        $this->assertTrue(alexInsightsLooksLikeCancellation('Cliente solicita cancelar el pedido WEB-6A85446198B17'));
    }

    public function testLooksLikeCancellationDetectsYaNoQuiero(): void
    {
        $this->assertTrue(alexInsightsLooksLikeCancellation('El cliente dice que ya no quiere el pedido'));
    }

    public function testLooksLikeCancellationIsCaseInsensitive(): void
    {
        $this->assertTrue(alexInsightsLooksLikeCancellation('CLIENTE QUIERE CANCELAR SU COMPRA'));
    }

    public function testLooksLikeCancellationFalseForUnrelatedReasons(): void
    {
        $this->assertFalse(alexInsightsLooksLikeCancellation('Cliente solicita descuento por mayoreo (50 piezas de Ajo negro) para reventa en farmacia.'));
        $this->assertFalse(alexInsightsLooksLikeCancellation('Cambio de direccion en pedido ya agendado #45'));
    }

    // --- alexInsightsContactoLabel ---

    public function testContactoLabelPrefersNombrePerfilWhenPresent(): void
    {
        $this->assertSame('Oscar', alexInsightsContactoLabel(['nombre_perfil' => 'Oscar', 'wa_id' => '5213319990004']));
    }

    public function testContactoLabelFallsBackToWaIdWhenNombrePerfilIsNull(): void
    {
        // Caso real observado: nombre_perfil llega NULL (no '') en varias conversaciones.
        $this->assertSame('5213319990004', alexInsightsContactoLabel(['nombre_perfil' => null, 'wa_id' => '5213319990004']));
    }

    public function testContactoLabelFallsBackToWaIdWhenNombrePerfilIsBlank(): void
    {
        $this->assertSame('5213319990004', alexInsightsContactoLabel(['nombre_perfil' => '   ', 'wa_id' => '5213319990004']));
    }

    // --- alexInsightsClassifyNonCancellationReason ---
    // Casos tomados de motivo_transferencia reales para no perder la ambiguedad observada.

    public function testClassifyDetectsDescuento(): void
    {
        $this->assertSame(
            'descuento',
            alexInsightsClassifyNonCancellationReason('Cliente solicita descuento por mayoreo (50 piezas de Ajo negro) para reventa en farmacia.')
        );
    }

    public function testClassifyDetectsFriccionCheckout(): void
    {
        $this->assertSame(
            'friccion_checkout',
            alexInsightsClassifyNonCancellationReason('Cliente quiere comprar pero falta su direccion completa de entrega.')
        );
    }

    public function testClassifyDetectsSeguridad(): void
    {
        $this->assertSame(
            'seguridad',
            alexInsightsClassifyNonCancellationReason('Cliente solicita acceso de administrador y credenciales del sistema')
        );
    }

    public function testClassifyDetectsSistema(): void
    {
        $this->assertSame(
            'sistema',
            alexInsightsClassifyNonCancellationReason('Alex incluyo la bandera [PASE_A_HUMANO] en su respuesta (baja confianza o requiere atencion personalizada).')
        );
    }

    public function testClassifyFallsBackToOtroForAmbiguousDireccionMention(): void
    {
        // Caso real: menciona "direccion" pero es un servicio postventa, no friccion de checkout
        // (no contiene "falta la/su direccion"). Tradeoff de precision aceptado en v1.
        $this->assertSame(
            'otro',
            alexInsightsClassifyNonCancellationReason('Cambio de direccion en pedido ya agendado #45')
        );
    }

    // --- alexInsightsDecryptClienteNombre ---

    public function testDecryptClienteNombreReturnsFallbackWhenEmpty(): void
    {
        $this->assertSame('Cliente', alexInsightsDecryptClienteNombre(null));
        $this->assertSame('Cliente', alexInsightsDecryptClienteNombre(''));
        $this->assertSame('Sin dato', alexInsightsDecryptClienteNombre('  ', 'Sin dato'));
    }

    public function testDecryptClienteNombrePassesThroughPlainText(): void
    {
        $this->assertSame('Oscar Orozco', alexInsightsDecryptClienteNombre('Oscar Orozco'));
    }

    public function testDecryptClienteNombreDecryptsRealCiphertext(): void
    {
        putenv('PII_ENCRYPTION_KEY=clave-de-prueba-unicamente-1234567890');
        $cifrado = piiEncryptValue('Oscar Orozco');

        $this->assertNotNull($cifrado);
        $this->assertTrue(piiIsEncryptedValue((string)$cifrado));
        $this->assertSame('Oscar Orozco', alexInsightsDecryptClienteNombre($cifrado));
    }
}
