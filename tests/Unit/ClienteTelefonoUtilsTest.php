<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ClienteTelefonoUtilsTest extends TestCase
{
    private PDO $pdo;
    private string $llaveOriginal = '';
    private string $logAnterior = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('CREATE TABLE clientes (id_cliente INTEGER PRIMARY KEY, nombre TEXT NULL, telefono TEXT NULL)');
        $this->pdo->exec('CREATE TABLE pedidos (id_pedido INTEGER PRIMARY KEY, id_cliente INTEGER NULL, estado TEXT NOT NULL, telefono_entrega TEXT NULL)');

        $this->llaveOriginal = (string) (getenv('PII_ENCRYPTION_KEY') ?: '');
        $this->logAnterior = (string) ini_get('error_log');
        ini_set('error_log', sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cliente_telefono_test.log');
    }

    protected function tearDown(): void
    {
        putenv('PII_ENCRYPTION_KEY=' . $this->llaveOriginal);
        if ($this->llaveOriginal !== '') {
            $_SERVER['PII_ENCRYPTION_KEY'] = $this->llaveOriginal;
            $_ENV['PII_ENCRYPTION_KEY'] = $this->llaveOriginal;
        } else {
            unset($_SERVER['PII_ENCRYPTION_KEY'], $_ENV['PII_ENCRYPTION_KEY']);
        }
        ini_set('error_log', $this->logAnterior);
        @unlink(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cliente_telefono_test.log');
        parent::tearDown();
    }

    private function conLlave(callable $fn): void
    {
        putenv('PII_ENCRYPTION_KEY=test-key-1234567890');
        $_SERVER['PII_ENCRYPTION_KEY'] = 'test-key-1234567890';
        $_ENV['PII_ENCRYPTION_KEY'] = 'test-key-1234567890';
        $fn();
    }

    private function pedido(int $id, int $cliente, string $estado, ?string $telefono): void
    {
        $this->pdo->prepare('INSERT INTO pedidos (id_pedido, id_cliente, estado, telefono_entrega) VALUES (?, ?, ?, ?)')
            ->execute([$id, $cliente, $estado, $telefono]);
    }

    private function telefonoDePedido(int $id): ?string
    {
        $v = $this->pdo->query('SELECT telefono_entrega FROM pedidos WHERE id_pedido = ' . $id)->fetchColumn();

        return $v === false ? null : ($v === null ? null : (string) $v);
    }

    // ------------------------------------------------------------ telefonoDigitos10

    public function testDigitos10AceptaFormatosComunes(): void
    {
        $this->assertSame('3310301214', telefonoDigitos10('3310301214'));
        $this->assertSame('3310301214', telefonoDigitos10('(331) - 030 - 1214'));
        $this->assertSame('3310301214', telefonoDigitos10(' 331-030-1214 '));
        $this->assertSame('3310301214', telefonoDigitos10('+52 33 1030 1214'));
        $this->assertSame('3310301214', telefonoDigitos10('5213310301214'), 'lada 521 de WhatsApp');
        $this->assertSame('3310301214', telefonoDigitos10('523310301214'));
    }

    public function testDigitos10RechazaLoQueNoEsUnTelefonoDe10Digitos(): void
    {
        $this->assertNull(telefonoDigitos10(null));
        $this->assertNull(telefonoDigitos10(''));
        $this->assertNull(telefonoDigitos10('   '));
        $this->assertNull(telefonoDigitos10('no tengo'));
        $this->assertNull(telefonoDigitos10('331030121'), '9 digitos');
        $this->assertNull(telefonoDigitos10('33103012145'), '11 digitos');
        $this->assertNull(telefonoDigitos10('209358885511206'), 'un LID de WhatsApp no es un telefono');
    }

    // ------------------------------------------------------------ relleno

    public function testDetectaNumerosDeRelleno(): void
    {
        $this->assertTrue(telefonoParecePlaceholder('3312345678'), 'el del incidente');
        $this->assertTrue(telefonoParecePlaceholder('1234567890'));
        $this->assertTrue(telefonoParecePlaceholder('0123456789'));
        $this->assertTrue(telefonoParecePlaceholder('9876543210'));
        $this->assertTrue(telefonoParecePlaceholder('3300000000'));
        $this->assertTrue(telefonoParecePlaceholder('5555555555'));
        $this->assertTrue(telefonoParecePlaceholder('(331) - 234 - 5678'), 'con formato');
        $this->assertTrue(telefonoParecePlaceholder('3398765432'));
    }

    public function testNoMarcaTelefonosReales(): void
    {
        $this->assertFalse(telefonoParecePlaceholder('3310301214'));
        $this->assertFalse(telefonoParecePlaceholder('3318635185'));
        $this->assertFalse(telefonoParecePlaceholder('3312349876'), 'solo 4 consecutivos');
        $this->assertFalse(telefonoParecePlaceholder('3311223344'));
        $this->assertFalse(telefonoParecePlaceholder('3333344444'), 'repetidos pero menos de 7 seguidos');
        $this->assertFalse(telefonoParecePlaceholder(null));
        $this->assertFalse(telefonoParecePlaceholder('abc'), 'lo invalido no es "relleno", es invalido');
    }

    // ------------------------------------------------------------ resolver el telefono del pedido

    public function testElNumeroDelChatGanaSobreUnNumeroDeRelleno(): void
    {
        $r = telefonoResolverParaPedido('3312345678', '3310301214');

        $this->assertSame('3310301214', $r['telefono'], 'El caso real: el pedido 75 habria llevado el numero del chat');
        $this->assertSame('chat', $r['origen']);
        $this->assertNull($r['alterno']);
        $this->assertSame('3312345678', $r['descartado']);
    }

    public function testElNumeroDelChatGanaSobreOtroNumeroValidoQueQuedaComoAlterno(): void
    {
        $r = telefonoResolverParaPedido('(331) 863-5185', '3310301214');

        $this->assertSame('3310301214', $r['telefono']);
        $this->assertSame('3318635185', $r['alterno'], 'Se avisa al equipo, pero no es el numero del pedido');
    }

    public function testElMismoNumeroDelChatNoEsAlterno(): void
    {
        $r = telefonoResolverParaPedido('331 030 1214', '3310301214');

        $this->assertSame('3310301214', $r['telefono']);
        $this->assertNull($r['alterno']);
    }

    public function testSinNumeroDelChatSeUsaElDictadoSiEsValido(): void
    {
        $r = telefonoResolverParaPedido('3318635185', null);

        $this->assertSame('3318635185', $r['telefono']);
        $this->assertSame('dictado', $r['origen']);
    }

    public function testSinNumeroDelChatUnDictadoDeRellenoSeRechaza(): void
    {
        $r = telefonoResolverParaPedido('3312345678', null);

        $this->assertSame('', $r['telefono']);
        $this->assertSame('ninguno', $r['origen']);
        $this->assertSame('3312345678', $r['descartado']);
    }

    public function testSinNingunNumeroNoHayTelefono(): void
    {
        foreach ([[null, null], ['', ''], ['no tengo', null], ['123', '209358885511206']] as [$dictado, $chat]) {
            $r = telefonoResolverParaPedido($dictado, $chat);
            $this->assertSame('', $r['telefono'], (string) $dictado);
            $this->assertSame('ninguno', $r['origen']);
        }
    }

    // ------------------------------------------------------------ telefono actual del cliente

    public function testObtenerTelefonoPlanoLeeYDescifra(): void
    {
        $this->conLlave(function (): void {
            $this->pdo->prepare('INSERT INTO clientes (id_cliente, telefono) VALUES (?, ?)')->execute([1, piiEncryptValue('(331) - 030 - 1214')]);
            $this->pdo->prepare('INSERT INTO clientes (id_cliente, telefono) VALUES (?, ?)')->execute([2, '3318635185']);
            $this->pdo->prepare('INSERT INTO clientes (id_cliente, telefono) VALUES (?, ?)')->execute([3, null]);
            $this->pdo->prepare('INSERT INTO clientes (id_cliente, telefono) VALUES (?, ?)')->execute([4, '  ']);

            $this->assertSame('(331) - 030 - 1214', clienteObtenerTelefonoPlano($this->pdo, 1));
            $this->assertSame('3318635185', clienteObtenerTelefonoPlano($this->pdo, 2));
            $this->assertNull(clienteObtenerTelefonoPlano($this->pdo, 3));
            $this->assertNull(clienteObtenerTelefonoPlano($this->pdo, 4));
            $this->assertNull(clienteObtenerTelefonoPlano($this->pdo, 999));
        });
    }

    // ------------------------------------------------------------ sincronizar pedidos abiertos

    public function testSincronizaLosPedidosQueEranCopiaDelTelefonoViejo(): void
    {
        $this->pedido(1, 10, 'pendiente_pago', '3318635185');
        $this->pedido(2, 10, 'pagado', '(331) - 863 - 5185');
        $this->pedido(3, 10, 'en_reparto', '3318635185');

        $r = clienteSincronizarTelefonoPedidosAbiertos($this->pdo, 10, '(331) - 863 - 5185', '(331) - 030 - 1214');

        $this->assertSame([1, 2, 3], array_column($r, 'id_pedido'));
        foreach ([1, 2, 3] as $id) {
            $this->assertSame('3310301214', $this->telefonoDePedido($id));
        }
        $this->assertSame('3318635185', $r[0]['anterior']);
        $this->assertSame('3310301214', $r[0]['nuevo']);
    }

    public function testSincronizaUnPedidoConNumeroDeRellenoAunSiElClienteNoTeniaTelefono(): void
    {
        $this->pedido(75, 248, 'pendiente_pago', '3312345678'); // el caso real

        $r = clienteSincronizarTelefonoPedidosAbiertos($this->pdo, 248, null, '(331) - 030 - 1214');

        $this->assertCount(1, $r);
        $this->assertSame('3310301214', $this->telefonoDePedido(75));
    }

    public function testSincronizaUnTelefonoInvalido(): void
    {
        $this->pedido(1, 10, 'pagado', '331');
        $this->pedido(2, 10, 'pagado', 'sin telefono');

        $r = clienteSincronizarTelefonoPedidosAbiertos($this->pdo, 10, null, '3310301214');

        $this->assertSame([1, 2], array_column($r, 'id_pedido'));
    }

    public function testNoTocaUnPedidoConOtroNumeroValido(): void
    {
        // Contacto de entrega distinto a proposito (p. ej. la persona que recibe): se respeta.
        $this->pedido(1, 10, 'pendiente_pago', '3318635185');

        $r = clienteSincronizarTelefonoPedidosAbiertos($this->pdo, 10, '3310000001', '3310301214');

        $this->assertSame([], $r);
        $this->assertSame('3318635185', $this->telefonoDePedido(1));
    }

    public function testNoTocaPedidosCerradosNiDeOtrosClientes(): void
    {
        $this->pedido(1, 10, 'entregado', '3318635185');
        $this->pedido(2, 10, 'cancelado', '3318635185');
        $this->pedido(3, 11, 'pendiente_pago', '3318635185'); // otro cliente
        $this->pedido(4, 10, 'pendiente_pago', '3318635185'); // este si

        $r = clienteSincronizarTelefonoPedidosAbiertos($this->pdo, 10, '3318635185', '3310301214');

        $this->assertSame([4], array_column($r, 'id_pedido'));
        $this->assertSame('3318635185', $this->telefonoDePedido(1));
        $this->assertSame('3318635185', $this->telefonoDePedido(2));
        $this->assertSame('3318635185', $this->telefonoDePedido(3));
    }

    public function testIgnoraPedidosSinTelefonoDeEntrega(): void
    {
        $this->pedido(1, 10, 'pendiente_pago', null);
        $this->pedido(2, 10, 'pendiente_pago', '   ');

        $this->assertSame([], clienteSincronizarTelefonoPedidosAbiertos($this->pdo, 10, '3318635185', '3310301214'));
        $this->assertNull($this->telefonoDePedido(1), 'sin telefono propio, la ruta usa el del cliente');
    }

    public function testSiYaTieneElNumeroNuevoNoLoReescribe(): void
    {
        $this->pedido(1, 10, 'pendiente_pago', '(331) - 030 - 1214');

        $this->assertSame([], clienteSincronizarTelefonoPedidosAbiertos($this->pdo, 10, '3318635185', '3310301214'));
        $this->assertSame('(331) - 030 - 1214', $this->telefonoDePedido(1));
    }

    public function testNoHaceNadaSiElTelefonoNoCambioOElNuevoEsInvalido(): void
    {
        $this->pedido(1, 10, 'pendiente_pago', '3318635185');

        $this->assertSame([], clienteSincronizarTelefonoPedidosAbiertos($this->pdo, 10, '3318635185', '(331) 863-5185'), 'mismo numero, otro formato');
        $this->assertSame([], clienteSincronizarTelefonoPedidosAbiertos($this->pdo, 10, '3318635185', '123'));
        $this->assertSame([], clienteSincronizarTelefonoPedidosAbiertos($this->pdo, 10, '3318635185', null));
        $this->assertSame([], clienteSincronizarTelefonoPedidosAbiertos($this->pdo, 0, '3318635185', '3310301214'));
        $this->assertSame('3318635185', $this->telefonoDePedido(1));
    }

    public function testEntiendeUnTelefonoDeEntregaCifrado(): void
    {
        $this->conLlave(function (): void {
            $this->pedido(1, 10, 'pendiente_pago', piiEncryptValue('3318635185'));

            $r = clienteSincronizarTelefonoPedidosAbiertos($this->pdo, 10, '3318635185', '3310301214');

            $this->assertSame([1], array_column($r, 'id_pedido'));
            $this->assertSame('3318635185', $r[0]['anterior'], 'El "anterior" se devuelve descifrado');
            $this->assertSame('3310301214', $this->telefonoDePedido(1));
        });
    }

    public function testNoFallaSiLaColumnaTelefonoEntregaNoExiste(): void
    {
        $sinColumna = new PDO('sqlite::memory:');
        $sinColumna->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $sinColumna->exec('CREATE TABLE pedidos (id_pedido INTEGER PRIMARY KEY, id_cliente INTEGER, estado TEXT)');

        $this->assertSame([], clienteSincronizarTelefonoPedidosAbiertos($sinColumna, 10, '3318635185', '3310301214'));
    }

    public function testAuditarNoLanzaConListaVaciaOConPedidos(): void
    {
        clienteAuditarSincronizacionTelefono(10, []);
        clienteAuditarSincronizacionTelefono(10, [['id_pedido' => 1, 'anterior' => '3318635185', 'nuevo' => '3310301214']]);

        $this->addToAssertionCount(1); // la auditoria nunca debe romper el flujo de negocio
    }

    // ------------------------------------------------------------ el cliente pide usar OTRO numero

    public function testUnNumeroDistintoConfirmadoPorElClienteGanaAlDelChat(): void
    {
        $r = telefonoResolverParaPedido('3318635185', '3310301214', true);

        $this->assertSame('3318635185', $r['telefono']);
        $this->assertSame('dictado_confirmado', $r['origen']);
        $this->assertSame('3310301214', $r['alterno'], 'El del chat queda como dato informativo');
    }

    public function testSinConfirmacionExplicitaElDictadoNuncaLeGanaAlDelChat(): void
    {
        $r = telefonoResolverParaPedido('3318635185', '3310301214', false);

        $this->assertSame('3310301214', $r['telefono']);
        $this->assertSame('chat', $r['origen']);
    }

    public function testLaConfirmacionNoRescataUnNumeroDeRelleno(): void
    {
        $r = telefonoResolverParaPedido('3312345678', '3310301214', true);

        $this->assertSame('3310301214', $r['telefono'], 'Aunque el modelo marque "confirmado", un relleno no vale');
        $this->assertSame('chat', $r['origen']);
    }

    public function testLaConfirmacionConElMismoNumeroDelChatNoCambiaNada(): void
    {
        $r = telefonoResolverParaPedido('331 030 1214', '3310301214', true);

        $this->assertSame('3310301214', $r['telefono']);
        $this->assertSame('chat', $r['origen']);
        $this->assertNull($r['alterno']);
    }

    public function testLaConfirmacionSinNumeroDelChatSeTrataComoDictadoNormal(): void
    {
        $r = telefonoResolverParaPedido('3318635185', null, true);

        $this->assertSame('3318635185', $r['telefono']);
        $this->assertSame('dictado', $r['origen']);
    }
}
