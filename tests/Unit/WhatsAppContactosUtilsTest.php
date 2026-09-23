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

    public function testSeguimientosRangoMes(): void
    {
        $this->assertSame(['2026-09', '2026-09-01 00:00:00', '2026-09-30 23:59:59'], waSeguimientosRangoMes('2026-09'));
        $this->assertSame(['2028-02', '2028-02-01 00:00:00', '2028-02-29 23:59:59'], waSeguimientosRangoMes('2028-02'));
        // Basura o vacio: el mes actual.
        $actual = (new DateTimeImmutable('today'))->format('Y-m');
        $this->assertSame($actual, waSeguimientosRangoMes('2026-13')[0]);
        $this->assertSame($actual, waSeguimientosRangoMes(null)[0]);
        $this->assertSame($actual, waSeguimientosRangoMes('septiembre')[0]);
    }

    public function testSeguimientosDelMesSoloCuentaLosSeguimientosYSiRespondieronONo(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE clientes (id_cliente INTEGER PRIMARY KEY, nombre TEXT NULL)');
        $pdo->exec('CREATE TABLE whatsapp_conversaciones (id_conversacion INTEGER PRIMARY KEY AUTOINCREMENT, wa_id TEXT, telefono_resuelto TEXT NULL, nombre_perfil TEXT NULL, id_cliente INTEGER NULL, estado_bot TEXT DEFAULT "activo")');
        $pdo->exec('CREATE TABLE whatsapp_mensajes (id_mensaje INTEGER PRIMARY KEY AUTOINCREMENT, id_conversacion INTEGER, rol TEXT, enviado_whatsapp INTEGER DEFAULT 0, creado_en TEXT)');
        $msg = static function (int $conv, string $rol, string $cuando, int $env = 1) use ($pdo): void {
            $pdo->prepare('INSERT INTO whatsapp_mensajes (id_conversacion, rol, enviado_whatsapp, creado_en) VALUES (?, ?, ?, ?)')->execute([$conv, $rol, $env, $cuando]);
        };
        $conv = static function (string $wa) use ($pdo): int {
            $pdo->prepare('INSERT INTO whatsapp_conversaciones (wa_id, nombre_perfil) VALUES (?, ?)')->execute([$wa, 'Cliente ' . $wa]);
            return (int) $pdo->lastInsertId();
        };

        // 1: seguimiento (Alex, 30 h despues de su ultimo mensaje) y NO contesto.
        $c1 = $conv('5213300000001');
        $msg($c1, 'user', '2026-09-02 10:00:00'); $msg($c1, 'assistant', '2026-09-02 10:01:00'); $msg($c1, 'assistant', '2026-09-03 16:00:00');
        // 2: seguimiento y contesto a las 5 h.
        $c2 = $conv('5213300000002');
        $msg($c2, 'user', '2026-09-05 10:00:00'); $msg($c2, 'assistant', '2026-09-05 10:01:00'); $msg($c2, 'assistant', '2026-09-06 15:00:00'); $msg($c2, 'user', '2026-09-06 20:00:00');
        // 3: seguimiento que NO salio por WhatsApp.
        $c3 = $conv('5213300000003');
        $msg($c3, 'user', '2026-09-07 10:00:00'); $msg($c3, 'assistant', '2026-09-07 10:01:00'); $msg($c3, 'assistant', '2026-09-08 12:00:00', 0);
        // 4: solo una conversacion normal (Alex contesta 1 min despues del cliente): NO es seguimiento.
        $c4 = $conv('5213300000004');
        $msg($c4, 'user', '2026-09-10 10:00:00'); $msg($c4, 'assistant', '2026-09-10 10:01:00'); $msg($c4, 'user', '2026-09-10 10:05:00'); $msg($c4, 'assistant', '2026-09-10 10:06:00');
        // 5: dos mensajes de Alex seguidos pero con solo 2 h de diferencia: NO es seguimiento.
        $c5 = $conv('5213300000005');
        $msg($c5, 'user', '2026-09-11 10:00:00'); $msg($c5, 'assistant', '2026-09-11 10:01:00'); $msg($c5, 'assistant', '2026-09-11 12:00:00');
        // 6: seguimiento en OTRO mes: no entra.
        $c6 = $conv('5213300000006');
        $msg($c6, 'user', '2026-08-02 10:00:00'); $msg($c6, 'assistant', '2026-08-02 10:01:00'); $msg($c6, 'assistant', '2026-08-03 16:00:00');
        // 7: seguimiento y "respondio" 3 dias despues: ya no cuenta como respuesta al seguimiento.
        $c7 = $conv('5213300000007');
        $msg($c7, 'user', '2026-09-12 10:00:00'); $msg($c7, 'assistant', '2026-09-12 10:01:00'); $msg($c7, 'assistant', '2026-09-13 16:00:00'); $msg($c7, 'user', '2026-09-16 09:00:00');

        [$mes, $desde, $hasta] = waSeguimientosRangoMes('2026-09');
        $filas = waSeguimientosDelMes($pdo, $desde, $hasta);
        $porConv = array_column($filas, null, 'id_conversacion');

        $this->assertSame([$c7, $c3, $c2, $c1], array_column($filas, 'id_conversacion')); // mas reciente primero
        $this->assertFalse($porConv[$c1]['respondio']);
        $this->assertTrue($porConv[$c1]['salio']);
        $this->assertTrue($porConv[$c2]['respondio']);
        $this->assertFalse($porConv[$c3]['salio']);
        $this->assertFalse($porConv[$c7]['respondio']);
        $this->assertSame('Cliente 5213300000001', $porConv[$c1]['nombre_perfil']);
    }
}
