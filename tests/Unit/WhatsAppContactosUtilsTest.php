<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WhatsAppContactosUtilsTest extends TestCase
{
    public function testContactoNombrePrefiereElNombreDeCliente(): void
    {
        $this->assertSame('Guadalupe Gutierrez', waContactoNombre('Lupita', 'Guadalupe Gutierrez'));
    }

    public function testContactoNombreCaeAlPerfilDeWhatsApp(): void
    {
        $this->assertSame('Lupita', waContactoNombre('Lupita', ''));
        $this->assertSame('Lupita', waContactoNombre('  Lupita  ', null));
    }

    public function testContactoNombreGenericoSinDatos(): void
    {
        $this->assertSame('Contacto sin nombre', waContactoNombre('', ''));
        $this->assertSame('Contacto sin nombre', waContactoNombre(null, null));
    }

    public function testContactoSubtituloConNumeroReal(): void
    {
        $this->assertSame('+52 33 3404 0398', waContactoSubtitulo('523334040398'));
    }

    public function testContactoSubtituloConLidAvisaQueNoHayNumero(): void
    {
        // 15 digitos que no empiezan con 52 => LID de WhatsApp.
        $this->assertSame('Sin numero (WhatsApp no lo comparte)', waContactoSubtitulo('208654024347887'));
    }

    public function testContactoSubtituloCaeAlTelefonoResueltoParaUnLid(): void
    {
        // Caso real: 168 de 172 conversaciones son LID pero scripts/resolver_lids_whatsapp.php
        // ya logro resolver el telefono real detras de la mayoria.
        $this->assertSame(
            '+52 322 168 7282',
            waContactoSubtitulo('208654024347887', '3221687282')
        );
    }

    public function testContactoSubtituloPrefiereElNumeroRealSobreElResuelto(): void
    {
        $this->assertSame(
            '+52 33 3404 0398',
            waContactoSubtitulo('523334040398', '3221687282')
        );
    }

    public function testContactoSubtituloIgnoraUnTelefonoResueltoMalFormado(): void
    {
        $this->assertSame(
            'Sin numero (WhatsApp no lo comparte)',
            waContactoSubtitulo('208654024347887', '12345')
        );
    }

    // --- waWhatsAppLinkPhone(): numero para el boton "Abrir WhatsApp" (wa.me) ---

    public function testWhatsAppLinkPhoneConNumeroReal(): void
    {
        $this->assertSame('523334040398', waWhatsAppLinkPhone('523334040398', null));
    }

    public function testWhatsAppLinkPhoneCaeAlTelefonoResueltoParaUnLid(): void
    {
        $this->assertSame('523221687282', waWhatsAppLinkPhone('208654024347887', '3221687282'));
    }

    public function testWhatsAppLinkPhoneVacioParaUnLidSinResolver(): void
    {
        $this->assertSame('', waWhatsAppLinkPhone('208654024347887', null));
    }

    // --- waDescifrarPii(): nunca debe enseñar texto cifrado crudo en pantalla ---

    private function withPiiEncryptionKey(string $key, callable $fn): void
    {
        $original = (string) (getenv('PII_ENCRYPTION_KEY') ?: '');
        putenv('PII_ENCRYPTION_KEY=' . $key);
        $_SERVER['PII_ENCRYPTION_KEY'] = $key;
        $_ENV['PII_ENCRYPTION_KEY'] = $key;
        try {
            $fn();
        } finally {
            putenv('PII_ENCRYPTION_KEY=' . $original);
            if ($original !== '') {
                $_SERVER['PII_ENCRYPTION_KEY'] = $original;
                $_ENV['PII_ENCRYPTION_KEY'] = $original;
            } else {
                unset($_SERVER['PII_ENCRYPTION_KEY'], $_ENV['PII_ENCRYPTION_KEY']);
            }
        }
    }

    public function testDescifrarPiiRegresaTextoPlanoSinCambios(): void
    {
        $this->assertSame('Guadalupe Gutierrez', waDescifrarPii('Guadalupe Gutierrez'));
        $this->assertSame('', waDescifrarPii(''));
        $this->assertSame('', waDescifrarPii(null));
    }

    public function testDescifrarPiiDescifraUnValorRealmenteCifrado(): void
    {
        $this->withPiiEncryptionKey('llave-de-prueba-1234567890', function (): void {
            $cifrado = piiEncryptValue('Guadalupe Gutierrez');
            $this->assertSame('Guadalupe Gutierrez', waDescifrarPii($cifrado));
        });
    }

    public function testDescifrarPiiRegresaVacioCuandoElDescifradoFallaEnVezDeFugarElTextoCifrado(): void
    {
        // Caso real: un cliente (#248 en produccion) cuyo nombre quedo cifrado con una
        // llave vieja (de antes de una rotacion) que ya nadie tiene -- piiDecryptValue()
        // cae de vuelta al texto cifrado original cuando la llave actual no calza. Antes de
        // este fix, el panel de "Contactos de WhatsApp" mostraba literalmente
        // "ENCv1:AYHXrbe0XZWn/..." como si fuera el nombre del cliente.
        $cifradoConOtraLlave = $this->cifrarConLlave('Nombre Que Ya No Se Puede Leer', 'llave-vieja-de-antes-de-rotar');

        $this->withPiiEncryptionKey('llave-actual-despues-de-rotar', function () use ($cifradoConOtraLlave): void {
            $this->assertSame('', waDescifrarPii($cifradoConOtraLlave));
        });
    }

    private function cifrarConLlave(string $texto, string $llave): string
    {
        $cifrado = '';
        $this->withPiiEncryptionKey($llave, function () use ($texto, &$cifrado): void {
            $cifrado = (string) piiEncryptValue($texto);
        });

        return $cifrado;
    }

    public function testRolEtiquetaYEsCliente(): void
    {
        $this->assertSame('Cliente', waRolEtiqueta('user'));
        $this->assertSame('Alex', waRolEtiqueta('assistant'));
        $this->assertSame('Asesor', waRolEtiqueta('humano'));
        $this->assertTrue(waRolEsCliente('user'));
        $this->assertFalse(waRolEsCliente('assistant'));
        $this->assertFalse(waRolEsCliente('humano'));
    }

    public function testRangoFechasPorDefectoSonLosUltimosSieteDias(): void
    {
        [$desde, $hasta] = waContactosRangoFechas(null, null);

        $hoy = new DateTimeImmutable('today');
        $this->assertSame($hoy->format('Y-m-d'), $hasta);
        $this->assertSame($hoy->sub(new DateInterval('P6D'))->format('Y-m-d'), $desde);
    }

    public function testRangoFechasRespetaValoresValidos(): void
    {
        [$desde, $hasta] = waContactosRangoFechas('2026-09-01', '2026-09-05');
        $this->assertSame('2026-09-01', $desde);
        $this->assertSame('2026-09-05', $hasta);
    }

    public function testRangoFechasInvierteSiVienenAlReves(): void
    {
        [$desde, $hasta] = waContactosRangoFechas('2026-09-10', '2026-09-02');
        $this->assertSame('2026-09-02', $desde);
        $this->assertSame('2026-09-10', $hasta);
    }

    public function testRangoFechasLimitaLaVentanaA92Dias(): void
    {
        [$desde, $hasta] = waContactosRangoFechas('2020-01-01', '2026-09-30');
        $this->assertSame('2026-09-30', $hasta);
        $this->assertSame(
            (new DateTimeImmutable('2026-09-30'))->sub(new DateInterval('P92D'))->format('Y-m-d'),
            $desde
        );
    }

    public function testRangoFechasIgnoraBasuraYUsaElDefault(): void
    {
        [$desde, $hasta] = waContactosRangoFechas('no-es-fecha', '31/12/2026');
        $hoy = new DateTimeImmutable('today');
        $this->assertSame($hoy->format('Y-m-d'), $hasta);
        $this->assertSame($hoy->sub(new DateInterval('P6D'))->format('Y-m-d'), $desde);
    }

    public function testAgruparPorDia(): void
    {
        $filas = [
            ['dia' => '2026-09-07', 'id_conversacion' => 1],
            ['dia' => '2026-09-07', 'id_conversacion' => 2],
            ['dia' => '2026-09-06', 'id_conversacion' => 1],
        ];
        $porDia = waAgruparPorDia($filas);

        $this->assertSame(['2026-09-07', '2026-09-06'], array_keys($porDia));
        $this->assertCount(2, $porDia['2026-09-07']);
        $this->assertCount(1, $porDia['2026-09-06']);
    }
}
