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
