<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ApiSecurityUtilsTest extends TestCase
{
    private string $directorio = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->directorio = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'api_sec_test_' . bin2hex(random_bytes(4));
        mkdir($this->directorio, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directorio . DIRECTORY_SEPARATOR . '*') ?: [] as $archivo) {
            @unlink($archivo);
        }
        @rmdir($this->directorio);
        parent::tearDown();
    }

    // ------------------------------------------------------------ metodo

    public function testMetodosDeEscrituraSonTodosMenosGetHeadYOptions(): void
    {
        foreach (['POST', 'post', ' PUT ', 'PATCH', 'DELETE'] as $m) {
            $this->assertTrue(apiMetodoEsEscritura($m), $m);
        }
        foreach (['GET', 'get', 'HEAD', 'OPTIONS'] as $m) {
            $this->assertFalse(apiMetodoEsEscritura($m), $m);
        }
    }

    public function testMetodoPermitido(): void
    {
        $this->assertTrue(apiMetodoPermitido('post', ['POST']));
        $this->assertTrue(apiMetodoPermitido('PUT', ['post', 'put']));
        $this->assertFalse(apiMetodoPermitido('GET', ['POST']));
        $this->assertFalse(apiMetodoPermitido('', ['POST']));
        $this->assertFalse(apiMetodoPermitido('POST', []));
    }

    // ------------------------------------------------------------ token CSRF

    public function testElTokenSaleDeLaCabeceraDelPostOSaleDelJson(): void
    {
        $this->assertSame('cab', apiTokenCsrfDePeticion(['HTTP_X_CSRF_TOKEN' => 'cab'], ['csrf_token' => 'post'], ['csrf_token' => 'json']));
        $this->assertSame('post', apiTokenCsrfDePeticion([], ['csrf_token' => 'post'], ['csrf_token' => 'json']));
        $this->assertSame('json', apiTokenCsrfDePeticion([], [], ['csrf_token' => 'json']));
        $this->assertSame('', apiTokenCsrfDePeticion([], [], null));
        $this->assertSame('', apiTokenCsrfDePeticion([], [], []));
    }

    public function testElTokenSeRecortaYSeIgnoranTiposRaros(): void
    {
        $this->assertSame('abc', apiTokenCsrfDePeticion(['HTTP_X_CSRF_TOKEN' => '  abc  '], [], null));
        $this->assertSame('', apiTokenCsrfDePeticion(['HTTP_X_CSRF_TOKEN' => '   '], [], null));
        $this->assertSame('json', apiTokenCsrfDePeticion([], ['csrf_token' => ['a', 'b']], ['csrf_token' => 'json']), 'un arreglo no es token');
        $this->assertSame('', apiTokenCsrfDePeticion([], [], ['csrf_token' => ['x']]));
        $this->assertSame('', apiTokenCsrfDePeticion([], [], ['csrf_token' => 12345]), 'un numero no es token');
    }

    public function testElTokenNuncaSeLeeDeLaUrl(): void
    {
        $antes = $_GET;
        $_GET['csrf_token'] = 'en-la-url';
        try {
            $this->assertSame('', apiTokenCsrfDePeticion($_SERVER, [], null));
        } finally {
            $_GET = $antes;
        }
    }

    public function testCsrfValidoSoloConElMismoTokenNoVacio(): void
    {
        $this->assertTrue(apiCsrfValido('abc123', 'abc123'));
        $this->assertFalse(apiCsrfValido('abc124', 'abc123'));
        $this->assertFalse(apiCsrfValido('ABC123', 'abc123'), 'distingue mayusculas');
        $this->assertFalse(apiCsrfValido('', 'abc123'));
        $this->assertFalse(apiCsrfValido('abc123', null), 'sin token en la sesion nada es valido');
        $this->assertFalse(apiCsrfValido('abc123', ''));
        $this->assertFalse(apiCsrfValido('', ''), 'vacio contra vacio NO es valido');
        $this->assertFalse(apiCsrfValido('abc', 'abc123'), 'prefijo');
    }

    // ------------------------------------------------------------ cron local

    public function testElCronLocalEsUnaConexionDirectaDesde127SinCabecerasDeProxy(): void
    {
        $this->assertTrue(apiEsCronLocal(['REMOTE_ADDR' => '127.0.0.1'], false));
    }

    public function testNoEsCronLocalConSesionODesdeOtraIp(): void
    {
        $this->assertFalse(apiEsCronLocal(['REMOTE_ADDR' => '127.0.0.1'], true), 'con sesion no es cron');
        $this->assertFalse(apiEsCronLocal(['REMOTE_ADDR' => '201.132.57.190'], false));
        $this->assertFalse(apiEsCronLocal(['REMOTE_ADDR' => '::1'], false));
        $this->assertFalse(apiEsCronLocal([], false));
    }

    public function testUnaPeticionQuePasoPorUnProxyNuncaEsCronLocalAunqueRemoteAddrSea127(): void
    {
        // El caso que preocupaba: nginx real_ip con set_real_ip_from 0.0.0.0/0 deja REMOTE_ADDR=127.0.0.1
        // si alguien manda CF-Connecting-IP: 127.0.0.1. Esas peticiones SIEMPRE traen cabeceras de proxy.
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_FORWARDED', 'HTTP_TRUE_CLIENT_IP'] as $cabecera) {
            $this->assertFalse(apiEsCronLocal(['REMOTE_ADDR' => '127.0.0.1', $cabecera => '127.0.0.1'], false), $cabecera);
            $this->assertFalse(apiEsCronLocal(['REMOTE_ADDR' => '127.0.0.1', $cabecera => '8.8.8.8'], false), $cabecera);
        }
        $this->assertTrue(apiEsCronLocal(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '   '], false), 'cabecera vacia = ausente');
    }

    // ------------------------------------------------------------ IP del cliente

    public function testIpDelClienteSoloAceptaIpsValidasYNoLeeCabecerasFalsificables(): void
    {
        $this->assertSame('187.190.40.22', apiIpCliente(['REMOTE_ADDR' => '187.190.40.22']));
        $this->assertSame('2001:db8::1', apiIpCliente(['REMOTE_ADDR' => '2001:db8::1']));
        $this->assertSame('0.0.0.0', apiIpCliente(['REMOTE_ADDR' => 'no-es-ip']));
        $this->assertSame('0.0.0.0', apiIpCliente([]));
        $this->assertSame('10.0.0.5', apiIpCliente(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4', 'HTTP_CF_CONNECTING_IP' => '5.6.7.8']), 'las cabeceras de proxy son falsificables: no se leen');
    }

    public function testClaveLimiteAgrupaIpv6PorSlash64(): void
    {
        $this->assertSame('187.190.40.22', apiClaveLimiteIp('187.190.40.22'));
        $this->assertSame(apiClaveLimiteIp('2001:db8:1:2::1'), apiClaveLimiteIp('2001:db8:1:2:ffff:eeee:dddd:cccc'), 'rotar direcciones dentro de un /64 no evade el limite');
        $this->assertNotSame(apiClaveLimiteIp('2001:db8:1:2::1'), apiClaveLimiteIp('2001:db8:1:3::1'));
    }

    public function testTodasLasCabecerasDeProxyConocidasDescartanElCronLocal(): void
    {
        foreach (API_CABECERAS_DE_PROXY as $cabecera) {
            $this->assertFalse(apiEsCronLocal(['REMOTE_ADDR' => '127.0.0.1', $cabecera => 'x'], false), $cabecera);
        }
        foreach (['HTTP_VIA', 'HTTP_CF_RAY', 'HTTP_X_VARNISH', 'HTTP_X_FORWARDED_PROTO', 'HTTP_CLIENT_IP'] as $cabecera) {
            $this->assertContains($cabecera, API_CABECERAS_DE_PROXY, "Debe estar en la lista: {$cabecera}");
        }
    }

    // ------------------------------------------------------------ limitador

    public function testPermiteHastaElMaximoYLuegoBloquea(): void
    {
        $r1 = rateLimitPermitir('k', 3, 60, $this->directorio, 1000);
        $r2 = rateLimitPermitir('k', 3, 60, $this->directorio, 1001);
        $r3 = rateLimitPermitir('k', 3, 60, $this->directorio, 1002);
        $r4 = rateLimitPermitir('k', 3, 60, $this->directorio, 1003);

        $this->assertTrue($r1['permitido']);
        $this->assertSame(2, $r1['restantes']);
        $this->assertTrue($r2['permitido']);
        $this->assertTrue($r3['permitido']);
        $this->assertSame(0, $r3['restantes']);
        $this->assertFalse($r4['permitido']);
        $this->assertSame(0, $r4['restantes']);
    }

    public function testDiceCuantoFaltaParaReintentar(): void
    {
        rateLimitPermitir('k', 2, 60, $this->directorio, 1000);
        rateLimitPermitir('k', 2, 60, $this->directorio, 1010);

        $bloqueado = rateLimitPermitir('k', 2, 60, $this->directorio, 1020);

        $this->assertFalse($bloqueado['permitido']);
        $this->assertSame(40, $bloqueado['reintentar_en'], 'la mas antigua (t=1000) sale de la ventana en t=1060');
    }

    public function testLaVentanaDesliza(): void
    {
        rateLimitPermitir('k', 2, 60, $this->directorio, 1000);
        rateLimitPermitir('k', 2, 60, $this->directorio, 1030);
        $this->assertFalse(rateLimitPermitir('k', 2, 60, $this->directorio, 1040)['permitido']);

        $this->assertTrue(rateLimitPermitir('k', 2, 60, $this->directorio, 1061)['permitido'], 'ya salio la de t=1000');
        $this->assertFalse(rateLimitPermitir('k', 2, 60, $this->directorio, 1062)['permitido'], 'quedan 1030 y 1061');
        $this->assertTrue(rateLimitPermitir('k', 2, 60, $this->directorio, 1091)['permitido'], 'ya salio la de t=1030');
    }

    public function testUnaPeticionBloqueadaNoAlargaElBloqueo(): void
    {
        rateLimitPermitir('k', 1, 60, $this->directorio, 1000);
        for ($t = 1001; $t < 1060; $t += 5) {
            $this->assertFalse(rateLimitPermitir('k', 1, 60, $this->directorio, $t)['permitido']);
        }

        $this->assertTrue(rateLimitPermitir('k', 1, 60, $this->directorio, 1061)['permitido'], 'los intentos rechazados no cuentan');
    }

    public function testCadaClaveTieneSuPropioContador(): void
    {
        rateLimitPermitir('ip-a', 1, 60, $this->directorio, 1000);

        $this->assertFalse(rateLimitPermitir('ip-a', 1, 60, $this->directorio, 1001)['permitido']);
        $this->assertTrue(rateLimitPermitir('ip-b', 1, 60, $this->directorio, 1001)['permitido']);
        $this->assertTrue(rateLimitPermitir('otro-endpoint|ip-a', 1, 60, $this->directorio, 1001)['permitido']);
    }

    public function testUnArchivoDeContadorCorruptoSeRecupera(): void
    {
        file_put_contents($this->directorio . DIRECTORY_SEPARATOR . hash('sha256', 'k') . '.json', '{basura no json');

        $r = rateLimitPermitir('k', 2, 60, $this->directorio, 1000);

        $this->assertTrue($r['permitido']);
        $this->assertSame(1, $r['restantes']);
    }

    public function testSiNoSePuedeEscribirElLimitadorPermite(): void
    {
        $inexistente = $this->directorio . DIRECTORY_SEPARATOR . 'no' . DIRECTORY_SEPARATOR . 'existe';

        $r = rateLimitPermitir('k', 1, 60, $inexistente, 1000);

        $this->assertTrue($r['permitido'], 'Un fallo del limitador nunca deja sin servicio');
        $this->assertTrue(rateLimitPermitir('k', 1, 60, $inexistente, 1001)['permitido']);
    }

    public function testLaClaveNoSeUsaComoNombreDeArchivo(): void
    {
        rateLimitPermitir('../../etc/passwd|1.2.3.4', 5, 60, $this->directorio, 1000);

        $archivos = glob($this->directorio . DIRECTORY_SEPARATOR . '*') ?: [];
        $this->assertCount(1, $archivos);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}\.json$/', basename($archivos[0]), 'Se hashea: ninguna clave puede escapar del directorio');
    }

    public function testLimpiarBorraSoloLosContadoresViejos(): void
    {
        rateLimitPermitir('viejo', 5, 60, $this->directorio, 1000);
        rateLimitPermitir('nuevo', 5, 60, $this->directorio, 1000);
        touch($this->directorio . DIRECTORY_SEPARATOR . hash('sha256', 'viejo') . '.json', 1000);
        touch($this->directorio . DIRECTORY_SEPARATOR . hash('sha256', 'nuevo') . '.json', 200000);

        $borrados = rateLimitLimpiar($this->directorio, 86400, 200100);

        $this->assertSame(1, $borrados);
        $this->assertFileDoesNotExist($this->directorio . DIRECTORY_SEPARATOR . hash('sha256', 'viejo') . '.json');
        $this->assertFileExists($this->directorio . DIRECTORY_SEPARATOR . hash('sha256', 'nuevo') . '.json');
    }

    public function testElDirectorioPorDefectoExisteYSePuedeEscribir(): void
    {
        $dir = rateLimitDirectorio();

        $this->assertDirectoryExists($dir);
        $this->assertTrue(is_writable($dir));
    }

    // ------------------------------------------------------------ cortes reales (proceso aparte)

    /**
     * Ejecuta un fragmento en un proceso PHP nuevo (exit real) y devuelve lo que imprimio.
     *
     * @param array<string, mixed> $contexto server/post/sesion/json
     */
    private function ejecutar(string $codigo, array $contexto = []): string
    {
        $id = bin2hex(random_bytes(3));
        $archivo = $this->directorio . DIRECTORY_SEPARATOR . 'caso_' . $id . '.php';
        $archivoCtx = $this->directorio . DIRECTORY_SEPARATOR . 'ctx_' . $id . '.json';
        $utils = str_replace('\\', '/', dirname(__DIR__, 2) . '/core/api_security_utils.php');
        // El contexto va en un archivo: escapeshellarg() destruye las comillas de un JSON en Windows.
        file_put_contents($archivoCtx, (string) json_encode($contexto));
        file_put_contents($archivo, "<?php\nrequire '{$utils}';\n\$ctx = json_decode(file_get_contents(" . var_export($archivoCtx, true) . "), true);\n"
            . "\$_SERVER = array_merge(\$_SERVER, \$ctx['server'] ?? []);\n\$_POST = \$ctx['post'] ?? [];\n\$_SESSION = \$ctx['sesion'] ?? [];\n"
            . $codigo . "\n");
        $salida = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($archivo) . ' 2>&1');
        @unlink($archivo);
        @unlink($archivoCtx);

        return $salida;
    }

    public function testSinTokenLaPeticionSeCortaYNoSigueElCodigo(): void
    {
        $salida = $this->ejecutar("apiRequerirCsrf();\necho 'PASO';", ['sesion' => ['csrf_token' => 'tok123']]);

        $this->assertStringNotContainsString('PASO', $salida, 'El codigo despues del chequeo no debe ejecutarse');
        $this->assertStringContainsString('"success":false', $salida);
        $this->assertStringContainsString('Token de seguridad invalido', $salida);
    }

    public function testConTokenIncorrectoTambienSeCorta(): void
    {
        $salida = $this->ejecutar("apiRequerirCsrf();\necho 'PASO';", ['sesion' => ['csrf_token' => 'tok123'], 'post' => ['csrf_token' => 'otro']]);

        $this->assertStringNotContainsString('PASO', $salida);
    }

    public function testSinTokenEnLaSesionNadaPasa(): void
    {
        $salida = $this->ejecutar("apiRequerirCsrf();\necho 'PASO';", ['post' => ['csrf_token' => 'tok123']]);

        $this->assertStringNotContainsString('PASO', $salida);
    }

    public function testConTokenValidoPorCabeceraPostOJsonSiPasa(): void
    {
        $sesion = ['csrf_token' => 'tok123'];

        $this->assertStringContainsString('PASO', $this->ejecutar("apiRequerirCsrf();\necho 'PASO';", ['sesion' => $sesion, 'server' => ['HTTP_X_CSRF_TOKEN' => 'tok123']]));
        $this->assertStringContainsString('PASO', $this->ejecutar("apiRequerirCsrf();\necho 'PASO';", ['sesion' => $sesion, 'post' => ['csrf_token' => 'tok123']]));
        $this->assertStringContainsString('PASO', $this->ejecutar("apiRequerirCsrf(['csrf_token' => 'tok123']);\necho 'PASO';", ['sesion' => $sesion]));
    }

    public function testMetodoNoPermitidoCortaConAllow(): void
    {
        $salida = $this->ejecutar("apiRequerirMetodo('POST');\necho 'PASO';", ['server' => ['REQUEST_METHOD' => 'GET']]);

        $this->assertStringNotContainsString('PASO', $salida);
        $this->assertStringContainsString('Metodo no permitido', $salida);

        $this->assertStringContainsString('PASO', $this->ejecutar("apiRequerirMetodo('POST');\necho 'PASO';", ['server' => ['REQUEST_METHOD' => 'POST']]));
    }

    public function testElLimitadorCortaLaPeticionAlExcederYPermiteAntes(): void
    {
        $dir = str_replace('\\', '/', $this->directorio);
        $ctx = ['server' => ['REMOTE_ADDR' => '187.190.40.22']];
        $codigo = "apiLimitarPeticiones('prueba', 2, 60, '{$dir}');\necho 'PASO';";

        $this->assertStringContainsString('PASO', $this->ejecutar($codigo, $ctx));
        $this->assertStringContainsString('PASO', $this->ejecutar($codigo, $ctx));
        $tercera = $this->ejecutar($codigo, $ctx);

        $this->assertStringNotContainsString('PASO', $tercera);
        $this->assertStringContainsString('Demasiadas peticiones', $tercera);
        $this->assertStringContainsString('"success":false', $tercera);

        $otraIp = $this->ejecutar($codigo, ['server' => ['REMOTE_ADDR' => '189.203.6.34']]);
        $this->assertStringContainsString('PASO', $otraIp, 'El limite es por IP');
    }
}
