<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pruebas de core/sale_delivery_mode.php: la logica pura que decide si una venta
 * capturada en api/ventas.php es "a domicilio" (reparto, cobro contra entrega) o
 * "en sucursal" (mostrador, cliente presente, cobrado en el acto).
 *
 * El riesgo real que cubren: (a) una integracion vieja que NO manda 'tipo_entrega'
 * debe seguir generando pedidos a domicilio -- cualquier regresion aqui cambiaria
 * en silencio como nace cada pedido; (b) que el estado inicial y el folio nunca se
 * crucen entre modos; (c) que el builder de observaciones no arroje ni mezcle
 * lineas de domicilio en una venta de mostrador.
 */
final class SaleDeliveryModeTest extends TestCase
{
    /** Valores permitidos por la columna pedidos.estado (ENUM en database.sql). */
    private const ESTADOS_VALIDOS = ['pendiente_pago', 'pagado', 'en_reparto', 'entregado', 'cancelado'];

    // ---------------------------------------------------------------------
    // saleDeliveryModeNormalize / saleDeliveryModeIsCounter -- casos positivos
    // ---------------------------------------------------------------------

    public function testNormalizeReconoceLosDosValoresCanonicos(): void
    {
        $this->assertSame(SALE_DELIVERY_MODE_COUNTER, saleDeliveryModeNormalize('Sucursal'));
        $this->assertSame(SALE_DELIVERY_MODE_HOME, saleDeliveryModeNormalize('Domicilio'));
    }

    public function testIsCounterEsTrueSoloParaSucursal(): void
    {
        $this->assertTrue(saleDeliveryModeIsCounter('Sucursal'));
        $this->assertFalse(saleDeliveryModeIsCounter('Domicilio'));
    }

    public function testNormalizeToleraMayusculasYEspacios(): void
    {
        foreach (['sucursal', 'SUCURSAL', 'sUcUrSaL', '  Sucursal', 'Sucursal  ', "\tSucursal\n", " sucursal "] as $entrada) {
            $this->assertSame(
                SALE_DELIVERY_MODE_COUNTER,
                saleDeliveryModeNormalize($entrada),
                "'$entrada' deberia normalizar a Sucursal"
            );
        }
    }

    public function testNormalizeToleraMayusculasYEspaciosEnDomicilio(): void
    {
        foreach (['domicilio', 'DOMICILIO', '  Domicilio  ', "Domicilio\n"] as $entrada) {
            $this->assertSame(SALE_DELIVERY_MODE_HOME, saleDeliveryModeNormalize($entrada));
        }
    }

    // ---------------------------------------------------------------------
    // Casos negativos: TODO lo desconocido cae a 'Domicilio' (default historico)
    // ---------------------------------------------------------------------

    public function testNormalizeCaeADomicilioParaCadenaVaciaONula(): void
    {
        $this->assertSame(SALE_DELIVERY_MODE_HOME, saleDeliveryModeNormalize(''));
        $this->assertSame(SALE_DELIVERY_MODE_HOME, saleDeliveryModeNormalize('   '));
        $this->assertSame(SALE_DELIVERY_MODE_HOME, saleDeliveryModeNormalize(null));
    }

    public function testNormalizeCaeADomicilioParaValoresDesconocidos(): void
    {
        // 'Mostrador'/'Pickup' NO se aceptan a proposito: el unico sinonimo de
        // mostrador reconocido es exactamente 'sucursal' (lo que manda el radio).
        foreach (['Mostrador', 'mostrador', 'Pickup', 'recoge', 'tienda', 'foo', 'entrega', '0', '1', 'true'] as $entrada) {
            $this->assertSame(
                SALE_DELIVERY_MODE_HOME,
                saleDeliveryModeNormalize($entrada),
                "'$entrada' NO deberia contar como venta de mostrador"
            );
            $this->assertFalse(saleDeliveryModeIsCounter($entrada));
        }
    }

    public function testNormalizeIgnoraCadenasQueSoloContienenLaPalabra(): void
    {
        // Coincidencia parcial no basta: debe ser el token completo.
        foreach (['Sucursal Centro', 'venta en sucursal', 'Sucursal;DROP TABLE pedidos', 'x-sucursal'] as $entrada) {
            $this->assertSame(SALE_DELIVERY_MODE_HOME, saleDeliveryModeNormalize($entrada), "'$entrada'");
        }
    }

    // ---------------------------------------------------------------------
    // Edge cases: tipos no-string no deben lanzar TypeError
    // ---------------------------------------------------------------------

    public function testNormalizeNoRevientaConTiposRaros(): void
    {
        $this->assertSame(SALE_DELIVERY_MODE_HOME, saleDeliveryModeNormalize(true));
        $this->assertSame(SALE_DELIVERY_MODE_HOME, saleDeliveryModeNormalize(false));
        $this->assertSame(SALE_DELIVERY_MODE_HOME, saleDeliveryModeNormalize(0));
        $this->assertSame(SALE_DELIVERY_MODE_HOME, saleDeliveryModeNormalize(42));
        $this->assertSame(SALE_DELIVERY_MODE_HOME, saleDeliveryModeNormalize(3.14));
        $this->assertSame(SALE_DELIVERY_MODE_HOME, saleDeliveryModeNormalize([]));
        $this->assertSame(SALE_DELIVERY_MODE_HOME, saleDeliveryModeNormalize(['Sucursal']));
        $this->assertSame(SALE_DELIVERY_MODE_HOME, saleDeliveryModeNormalize(new stdClass()));
    }

    public function testNormalizeConCadenaEnormeSigueSiendoSeguro(): void
    {
        $ruido = str_repeat('Sucursal ', 5000);
        $this->assertSame(SALE_DELIVERY_MODE_HOME, saleDeliveryModeNormalize($ruido));
        $this->assertSame(SALE_DELIVERY_MODE_COUNTER, saleDeliveryModeNormalize('   ' . "\t" . 'Sucursal' . "\n" . '   '));
    }

    public function testNormalizeSiempreDevuelveUnoDeLosDosLiterales(): void
    {
        foreach (['Sucursal', 'Domicilio', '', null, 'ruido', 999, [], "  sucursal  "] as $entrada) {
            $this->assertContains(
                saleDeliveryModeNormalize($entrada),
                [SALE_DELIVERY_MODE_HOME, SALE_DELIVERY_MODE_COUNTER]
            );
        }
    }

    // ---------------------------------------------------------------------
    // Folio y estado inicial: nunca se cruzan entre modos
    // ---------------------------------------------------------------------

    public function testFolioPrefix(): void
    {
        $this->assertSame('MOS-', saleDeliveryModeFolioPrefix(true));
        $this->assertSame('DOM-', saleDeliveryModeFolioPrefix(false));
    }

    public function testEstadoInicial(): void
    {
        $this->assertSame('pagado', saleDeliveryModeInitialEstado(true));
        $this->assertSame('pendiente_pago', saleDeliveryModeInitialEstado(false));
    }

    public function testEstadoInicialSiempreEsUnEnumValidoDePedidos(): void
    {
        $this->assertContains(saleDeliveryModeInitialEstado(true), self::ESTADOS_VALIDOS);
        $this->assertContains(saleDeliveryModeInitialEstado(false), self::ESTADOS_VALIDOS);
    }

    public function testModoYFolioYEstadoSonCoherentesEntreSi(): void
    {
        foreach (['Sucursal' => ['MOS-', 'pagado'], 'Domicilio' => ['DOM-', 'pendiente_pago'], 'basura' => ['DOM-', 'pendiente_pago']] as $entrada => [$folioEsperado, $estadoEsperado]) {
            $esMostrador = saleDeliveryModeIsCounter($entrada);
            $this->assertSame($folioEsperado, saleDeliveryModeFolioPrefix($esMostrador), "folio para '$entrada'");
            $this->assertSame($estadoEsperado, saleDeliveryModeInitialEstado($esMostrador), "estado para '$entrada'");
        }
    }

    // ---------------------------------------------------------------------
    // saleDeliveryModeObservacionesChunks
    // ---------------------------------------------------------------------

    public function testObservacionesDomicilioConDatosCompletos(): void
    {
        $chunks = saleDeliveryModeObservacionesChunks(false, 'Juan Perez', '(33) - 111 - 2222', 'Calle Falsa 123', 'Tocar timbre');

        $this->assertSame([
            'ENTREGA: Domicilio',
            'Cliente: Juan Perez',
            'Tel: (33) - 111 - 2222',
            'Dir: Calle Falsa 123',
            'Notas: Tocar timbre',
        ], $chunks);
    }

    public function testObservacionesSucursalOmiteLaLineaDeDireccion(): void
    {
        // Aunque se le pase una direccion, en mostrador NUNCA debe aparecer "Dir:".
        $chunks = saleDeliveryModeObservacionesChunks(true, 'Mostrador', '', 'Calle Falsa 123', '');

        $this->assertSame([
            'ENTREGA: Sucursal',
            'Cliente: Mostrador',
        ], $chunks);
        $this->assertNotContains('Dir: Calle Falsa 123', $chunks);
        foreach ($chunks as $linea) {
            $this->assertStringStartsNotWith('Dir:', $linea);
        }
    }

    public function testObservacionesSucursalConTelefonoYNotas(): void
    {
        $chunks = saleDeliveryModeObservacionesChunks(true, 'Ana', '3312345678', '', 'Pago con tarjeta');

        $this->assertSame([
            'ENTREGA: Sucursal',
            'Cliente: Ana',
            'Tel: 3312345678',
            'Notas: Pago con tarjeta',
        ], $chunks);
    }

    public function testObservacionesOmiteTelefonoVacioOEnBlanco(): void
    {
        $sinTel = saleDeliveryModeObservacionesChunks(false, 'Juan', '', 'Calle 1');
        $this->assertNotContains('Tel: ', $sinTel);
        $this->assertContains('Dir: Calle 1', $sinTel);

        $telEspacios = saleDeliveryModeObservacionesChunks(false, 'Juan', "   \t  ", 'Calle 1');
        foreach ($telEspacios as $linea) {
            $this->assertStringStartsNotWith('Tel:', $linea);
        }
    }

    public function testObservacionesOmiteNotasVaciasOEnBlanco(): void
    {
        $chunks = saleDeliveryModeObservacionesChunks(true, 'Ana', '', '', '     ');
        foreach ($chunks as $linea) {
            $this->assertStringStartsNotWith('Notas:', $linea);
        }
    }

    public function testObservacionesDomicilioSiempreIncluyeDireccionAunqueVacia(): void
    {
        // En domicilio la linea "Dir:" se agrega siempre (api/ventas.php ya valido
        // antes que la direccion no este vacia; el builder no re-valida).
        $chunks = saleDeliveryModeObservacionesChunks(false, 'Juan', '3300000000', '');
        $this->assertContains('Dir: ', $chunks);
    }

    public function testObservacionesDevuelveListaSecuencial(): void
    {
        $chunks = saleDeliveryModeObservacionesChunks(true, 'Ana', '3300000000', '', 'nota');
        $this->assertSame(range(0, count($chunks) - 1), array_keys($chunks));
    }

    public function testObservacionesPreservaCaracteresRarosDelNombre(): void
    {
        // El separador final es ' | '; si el nombre trae un pipe queda feo, pero
        // este builder no es responsable de sanitizarlo: debe pasarlo tal cual.
        $chunks = saleDeliveryModeObservacionesChunks(true, 'Bar | Grill "El Rey" <3', '', '');
        $this->assertSame('Cliente: Bar | Grill "El Rey" <3', $chunks[1]);
    }

    public function testObservacionesPrimeraLineaEsSiempreElModo(): void
    {
        $this->assertSame('ENTREGA: Sucursal', saleDeliveryModeObservacionesChunks(true, 'x', 'y', 'z', 'w')[0]);
        $this->assertSame('ENTREGA: Domicilio', saleDeliveryModeObservacionesChunks(false, 'x', 'y', 'z', 'w')[0]);
    }

    // ---------------------------------------------------------------------
    // saleDeliveryModeIsAllowedForUser -- el vendedor solo puede 'Sucursal'
    // ---------------------------------------------------------------------

    public function testQuienGestionaEntregasPuedeCualquierModo(): void
    {
        foreach (['Domicilio', 'Sucursal', '', null, 'ruido', 'Mostrador', [], 123] as $modo) {
            $this->assertTrue(
                saleDeliveryModeIsAllowedForUser($modo, true),
                'canManageDeliveryOrders=true deberia permitir cualquier modo'
            );
        }
    }

    public function testVendedorSoloPuedeModoSucursal(): void
    {
        // canManageDeliveryOrders = false (vendedor)
        $this->assertTrue(saleDeliveryModeIsAllowedForUser('Sucursal', false));
        $this->assertTrue(saleDeliveryModeIsAllowedForUser('  sucursal  ', false));
        $this->assertTrue(saleDeliveryModeIsAllowedForUser('SUCURSAL', false));

        $this->assertFalse(saleDeliveryModeIsAllowedForUser('Domicilio', false));
        $this->assertFalse(saleDeliveryModeIsAllowedForUser('', false), 'vacio normaliza a Domicilio -> no permitido');
        $this->assertFalse(saleDeliveryModeIsAllowedForUser(null, false), 'ausente normaliza a Domicilio -> no permitido');
        $this->assertFalse(saleDeliveryModeIsAllowedForUser('Mostrador', false), 'no es el token exacto -> Domicilio');
        $this->assertFalse(saleDeliveryModeIsAllowedForUser('cualquier cosa', false));
        $this->assertFalse(saleDeliveryModeIsAllowedForUser([], false));
    }

    public function testVendedorNoPuedeColarDomicilioConTrucosDeCapitalizacion(): void
    {
        foreach (['Domicilio', 'domicilio', 'DOMICILIO', ' Domicilio ', 'Domicilio ', "Domicilio\n"] as $modo) {
            $this->assertFalse(saleDeliveryModeIsAllowedForUser($modo, false), "'$modo'");
        }
    }
}
