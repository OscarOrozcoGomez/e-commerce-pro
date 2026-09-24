<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Corre tests/js/entregas_wa_ruta.test.mjs (aviso de WhatsApp de la ruta en entregas.php) con
 * node --test, para que la parte JS tambien truene en `composer test` / CI. Sin node, se omite.
 */
final class EntregasWaRutaJsTest extends TestCase
{
    public function testAvisoWhatsappDeRutaEnNode(): void
    {
        $nodeVersion = [];
        exec('node --version 2>&1', $nodeVersion, $codeVersion);
        if ($codeVersion !== 0) {
            $this->markTestSkipped('node no esta instalado; no se pueden correr las pruebas JS.');
        }

        $archivo = realpath(__DIR__ . '/../js/entregas_wa_ruta.test.mjs');
        $this->assertNotFalse($archivo);

        $salida = [];
        exec('node --test ' . escapeshellarg($archivo) . ' 2>&1', $salida, $code);

        $this->assertSame(0, $code, implode("\n", $salida));
    }
}
