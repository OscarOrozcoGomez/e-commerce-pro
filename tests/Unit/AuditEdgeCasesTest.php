<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pruebas NEGATIVAS y de borde de la auditoria: lo que puede salir mal con entradas raras,
 * hostiles o extremas. Complementa AuditUtilsTest (camino feliz).
 *
 * La idea de cada prueba es que, si alguien "simplifica" una regla, falle aqui y no en produccion
 * (un dato personal en claro en el log, un cambio real que no se registra, un endpoint que escapa
 * del registro, una pantalla que truena con una URL rara).
 */
final class AuditEdgeCasesTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['usuario']);
        foreach (['REQUEST_URI', 'SCRIPT_NAME', 'HTTP_USER_AGENT', 'REMOTE_ADDR', 'HTTP_X_FORWARDED_FOR'] as $k) {
            unset($_SERVER[$k]);
        }
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_id('');
        }
    }

    /* ================= enmascarado: valores raros ================= */

    public function testVaciosNoSeTocanAunqueLaClaveSeaSecreta(): void
    {
        $this->assertSame('', auditEnmascararValor('contrasena', ''));
        $this->assertNull(auditEnmascararValor('contrasena', null));
        $this->assertSame('', auditEnmascararValor('telefono', ''));
    }

    public function testCeroNoSeConfundeConVacio(): void
    {
        $this->assertSame('0', auditEnmascararValor('cantidad', '0'));
        $this->assertSame(0, auditEnmascararValor('cantidad', 0));
        $this->assertSame(0.0, auditEnmascararValor('precio', 0.0));
        $this->assertFalse(auditEnmascararValor('activo', false));
    }

    #[DataProvider('telefonosRaros')]
    public function testTelefonosRarosNuncaSeMuestranCompletos(mixed $entrada, string $esperado): void
    {
        $this->assertSame($esperado, auditEnmascararValor('telefono', $entrada));
    }

    /** @return array<string,array{0:mixed,1:string}> */
    public static function telefonosRaros(): array
    {
        return [
            'con lada internacional' => ['+52 33 1863 5185', '******5185'],
            'con guiones y parentesis' => ['(331) - 863 - 5185', '******5185'],
            'sin digitos' => ['abc', '****'],
            'solo cuatro digitos' => ['1234', '****'],
            'cinco digitos' => ['12345', '******2345'],
            'ceros' => ['0000', '****'],
            'espacios alrededor' => ['   3318635185   ', '******5185'],
            'como float' => [3318635185.0, '******5185'],
            'con extension' => ['3318635185 ext', '******5185'],
        ];
    }

    #[DataProvider('correosRaros')]
    public function testCorreosRarosNuncaSeMuestranCompletos(string $entrada, string $esperado): void
    {
        $this->assertSame($esperado, auditEnmascararPii('email', $entrada));
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function correosRaros(): array
    {
        return [
            'minimo' => ['a@b', 'a***@b'],
            'sin usuario' => ['@dominio.com', '***'],
            'sin arroba' => ['no-es-correo', '***'],
            'dominio vacio' => ['x@', 'x***@'],
            'doble arroba' => ['user@@x.com', 'u***@@x.com'],
            'unicode' => ['ñandú@mail.com', 'ñ***@mail.com'],
            'con espacios' => ['  a@b.com ', 'a***@b.com'],
        ];
    }

    public function testCifradoConEspaciosPreviosTambienSeOculta(): void
    {
        $this->assertSame('[cifrado]', auditEnmascararPii('telefono', '  ENCv1:abc'));
        $this->assertSame('[cifrado]', auditEnmascararPii('direccion', "\nENCv1:abc"));
    }

    public function testDireccionCortaYMultibyteNoGeneraUtf8Invalido(): void
    {
        $this->assertSame('Av...', auditEnmascararPii('direccion', 'Av'));

        $enmascarada = auditEnmascararPii('direccion', 'Calle Ñandú Ñañez 12345, Colonia Ñuñoa');
        $this->assertTrue(mb_check_encoding($enmascarada, 'UTF-8'), 'no debe cortar un caracter multibyte a la mitad');
        $this->assertStringNotContainsString('Ñuñoa', $enmascarada);
    }

    public function testTextoGiganteSeRecortaYSigueSiendoUtf8Valido(): void
    {
        $recortado = auditEnmascararValor('nota', str_repeat('ñ', 10000));
        $this->assertLessThanOrEqual(303, mb_strlen($recortado));
        $this->assertTrue(mb_check_encoding($recortado, 'UTF-8'));
    }

    /** Falsos positivos: campos de negocio normales NO deben venir ocultos/enmascarados (el log seria inutil). */
    #[DataProvider('clavesDeNegocio')]
    public function testCamposDeNegocioNoSeEnmascaranPorError(string $clave): void
    {
        $this->assertFalse(auditClaveEsSecreta($clave), "$clave no es secreto");
        $this->assertNull(auditClaveTipoPii($clave), "$clave no es dato personal");
        $this->assertSame('valor 123', auditEnmascararValor($clave, 'valor 123'));
    }

    /** @return array<string,array{0:string}> */
    public static function clavesDeNegocio(): array
    {
        $claves = [
            'nombre', 'nombre_corto', 'nombre_variante', 'sku', 'codigo_barras', 'codigo_lote', 'precio_venta',
            'precio_costo', 'precio_oferta', 'precio_comparacion', 'cantidad_actual', 'cantidad_restante',
            'stock_minimo', 'stock_maximo', 'estado', 'tipo_movimiento', 'observaciones', 'fecha_caducidad',
            'id_almacen', 'id_producto', 'descripcion', 'alias', 'unidad', 'dia_semana', 'hora_inicio', 'nota',
            'motivo', 'rol', 'permisos', 'en_oferta', 'activo', 'referencia', 'total_estimado', 'canal', 'severidad',
        ];
        return array_combine($claves, array_map(static fn(string $c): array => [$c], $claves));
    }

    #[DataProvider('clavesSensibles')]
    public function testCamposSensiblesSiSeProtegen(string $clave): void
    {
        $this->assertTrue(auditClaveEsSecreta($clave) || auditClaveTipoPii($clave) !== null, "$clave debe protegerse");
    }

    /** @return array<string,array{0:string}> */
    public static function clavesSensibles(): array
    {
        $claves = [
            'contrasena', 'password', 'PASSWORD', 'nueva_contrasena', 'csrf_token', 'token_hash', 'api_key',
            'apiKey', 'secret', 'otp', 'authorization', 'telefono', 'telefono_cliente', 'celular', 'whatsapp',
            'wa_id', 'email', 'correo', 'correo_electronico', 'direccion', 'domicilio', 'maps_link', 'latitud',
        ];
        return array_combine($claves, array_map(static fn(string $c): array => [$c], $claves));
    }

    /* ================= diff: valores limite ================= */

    public function testDiffDeArreglosVaciosNoRegistraNada(): void
    {
        $this->assertSame(['antes' => [], 'despues' => []], auditDiff([], []));
        $this->assertSame(['antes' => [], 'despues' => []], auditDiff([], [], ['a', 'b']));
    }

    public function testPasarUnPrecioACeroSiEsUnCambio(): void
    {
        foreach ([['250', '0'], ['250', '0.00'], ['0', ''], ['0.00', null], [0, null]] as [$antes, $despues]) {
            $diff = auditDiff(['precio' => $antes], ['precio' => $despues]);
            $this->assertNotSame([], $diff['despues'], json_encode([$antes, $despues]) . ' es un cambio real');
        }
    }

    public function testCeroCeroPuntoCeroYFalsoSonLoMismo(): void
    {
        $this->assertSame([], auditDiff(['p' => 0], ['p' => '0'])['despues']);
        $this->assertSame([], auditDiff(['p' => '0.00'], ['p' => 0])['despues']);
        $this->assertSame([], auditDiff(['p' => 0], ['p' => false])['despues']);
    }

    public function testErrorDeRedondeoDeFlotantesNoSeReportaComoCambio(): void
    {
        $this->assertSame([], auditDiff(['p' => 0.1 + 0.2], ['p' => 0.3])['despues']);
        $this->assertSame([], auditDiff(['p' => '1.10'], ['p' => 1.1])['despues']);
        $this->assertSame([], auditDiff(['p' => '-5'], ['p' => '-5.00'])['despues']);
    }

    public function testIdentificadoresLargosDistintosNoSeColapsanPorPrecisionDeFlotante(): void
    {
        // Como float, estos dos valores de 17 digitos son IGUALES; como identificadores son distintos.
        $diff = auditDiff(['id' => '12345678901234567'], ['id' => '12345678901234568']);
        $this->assertNotSame([], $diff['despues']);

        $diff = auditDiff(['codigo_barras' => '75012345678901'], ['codigo_barras' => '75012345678902']);
        $this->assertNotSame([], $diff['despues']);
    }

    public function testMayusculasMinusculasYEspaciosInternosSiSonCambio(): void
    {
        $this->assertNotSame([], auditDiff(['n' => 'Ñu'], ['n' => 'ñu'])['despues']);
        $this->assertNotSame([], auditDiff(['n' => 'Vitamina C'], ['n' => 'Vitamina  C'])['despues']);
        $this->assertSame([], auditDiff(['n' => 'Vitamina C '], ['n' => "\tVitamina C"])['despues']);
    }

    public function testCambioDeNegativoConCeroAlaIzquierdaSiSeDetecta(): void
    {
        $this->assertNotSame([], auditDiff(['s' => '-05'], ['s' => '-5'])['despues']);
    }

    public function testArreglosSeComparanPorContenidoYOrden(): void
    {
        $this->assertSame([], auditDiff(['c' => [1, 2]], ['c' => [1, 2]])['despues']);
        $this->assertNotSame([], auditDiff(['c' => [1, 2]], ['c' => [2, 1]])['despues']);
    }

    public function testCambioDeTelefonoSeDetectaAunqueLaMascaraSeaIgual(): void
    {
        // Mismos ultimos 4 digitos: el diff se calcula sobre el valor REAL, no sobre el enmascarado.
        $diff = auditDiff(['telefono' => '3318635185'], ['telefono' => '3311115185']);
        $this->assertSame('******5185', $diff['antes']['telefono']);
        $this->assertSame('******5185', $diff['despues']['telefono']);
    }

    public function testListaBlancaConCampoInexistenteOConDuplicadosNoTruena(): void
    {
        $this->assertSame([], auditDiff(['a' => 1], ['a' => 2], ['zzz'])['despues']);

        $diff = auditDiff(['a' => 1], ['a' => 2], ['a', 'a', 'a']);
        $this->assertSame(['a' => 2], $diff['despues']);
    }

    public function testArreglosListaConIndicesNumericosSeComparan(): void
    {
        $diff = auditDiff([1, 2, 3], [1, 9, 3]);
        $this->assertSame([1 => 2], $diff['antes']);
        $this->assertSame([1 => 9], $diff['despues']);
    }

    public function testValoresNoFinitosONoEscalaresNoRompenElDiff(): void
    {
        $diff = auditDiff(['a' => NAN, 'b' => INF, 'c' => new stdClass()], ['a' => NAN, 'b' => -INF, 'c' => new stdClass()]);
        $this->assertIsArray($diff['antes']);
        $this->assertArrayHasKey('b', $diff['despues'], 'INF -> -INF es un cambio');
    }

    public function testResumenDeCambiosConLimitesRaros(): void
    {
        $this->assertSame('', auditResumenCambios([], []));
        $this->assertSame('x: (vacio) -> (vacio)', auditResumenCambios(['x' => null], ['x' => '']));
        $this->assertSame('x: si -> no', auditResumenCambios(['x' => true], ['x' => false]));
        // solo en "antes" (campo quitado)
        $this->assertSame('viejo: 1 -> (vacio)', auditResumenCambios(['viejo' => 1], []));
        // limites absurdos: no lanzan excepcion
        $this->assertIsString(auditResumenCambios(['x' => 'aaaa'], ['x' => 'bbbb'], 0));
        $this->assertIsString(auditResumenCambios(['x' => 'aaaa'], ['x' => 'bbbb'], -5));
    }

    /* ================= payload y JSON ================= */

    public function testSecretoAnidadoNuncaSeFiltraEnNingunNivel(): void
    {
        $payload = auditSanitizarPayload([
            'a' => ['b' => ['c' => ['contrasena' => 'S3CRETO-PROFUNDO']]],
            'password' => ['x' => 'S3CRETO-EN-ARREGLO'],
            'usuario' => ['password' => 'S3CRETO-NIVEL-1', 'telefono' => '3318635185'],
            'lista' => [['token' => 'S3CRETO-EN-LISTA']],
        ]);

        $json = (string) json_encode($payload);
        foreach (['S3CRETO-PROFUNDO', 'S3CRETO-EN-ARREGLO', 'S3CRETO-NIVEL-1', 'S3CRETO-EN-LISTA', '3318635185'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $json);
        }
        $this->assertSame('[oculto]', $payload['password']);
    }

    public function testPayloadEnormeYMuyAnidadoQuedaAcotado(): void
    {
        $anidado = [];
        for ($i = 0; $i < 200; $i++) {
            $anidado["k$i"] = array_fill(0, 200, str_repeat('x', 500));
        }
        $payload = auditSanitizarPayload($anidado);

        $this->assertLessThanOrEqual(41, count($payload), 'maximo 40 campos + marcador de omitidos');
        // El tope duro lo pone el JSON que se guarda: nunca mas de AUDIT_MAX_JSON_BYTES.
        $json = (string) auditJsonCompacto($payload);
        $this->assertLessThanOrEqual(AUDIT_MAX_JSON_BYTES, strlen($json));
        $this->assertStringContainsString('_truncado', $json);
    }

    public function testPayloadConClavesYValoresExtranosNoTruena(): void
    {
        $payload = auditSanitizarPayload([0 => 'a', '' => 'b', 'k ü ñ 😀' => 'c', 'n' => null, 'f' => 1.5, 'b' => true, 'o' => new stdClass(), 'r' => "\xB1\x31"]);

        $this->assertSame('[objeto]', $payload['o']);
        $this->assertNull($payload['n']);
        $this->assertTrue($payload['b']);
        $this->assertNotNull(auditJsonCompacto($payload), 'UTF-8 invalido se sustituye, no se pierde el evento');
    }

    public function testJsonCompactoEnElLimiteExacto(): void
    {
        $datos = ['a' => 'xxxx']; // {"a":"xxxx"} = 12 bytes
        $this->assertSame('{"a":"xxxx"}', auditJsonCompacto($datos, 12));
        $this->assertStringContainsString('_truncado', (string) auditJsonCompacto($datos, 11));
    }

    public function testJsonCompactoTruncadoListaComoMuchoSesentaCampos(): void
    {
        $datos = [];
        for ($i = 0; $i < 300; $i++) {
            $datos["campo_$i"] = str_repeat('z', 100);
        }
        $json = (string) auditJsonCompacto($datos);
        $decodificado = json_decode($json, true);

        $this->assertTrue($decodificado['_truncado']);
        $this->assertCount(60, $decodificado['campos']);
    }

    public function testJsonCompactoConUtf8InvalidoONoFinitoNoLanzaExcepcion(): void
    {
        $this->assertNotNull(auditJsonCompacto(['a' => "\xB1\x31"]));
        $resultado = auditJsonCompacto(['a' => NAN, 'b' => INF]);
        $this->assertTrue($resultado === null || is_string($resultado));
    }

    /* ================= lecturas por POST / endpoints excluidos ================= */

    public function testAccionDeLecturaEsInsensibleAMayusculasYEspacios(): void
    {
        $this->assertTrue(auditAccionEsLectura('LIST'));
        $this->assertTrue(auditAccionEsLectura('  list  '));
        $this->assertTrue(auditAccionEsLectura('Get_One'));
    }

    public function testPalabrasParecidasPeroDistintasNoSeTomanPorLectura(): void
    {
        foreach (['listing', 'lista', 'getaway', 'loader', 'checkout', 'datastore', 'reader', '   ', '0'] as $accion) {
            $this->assertFalse(auditAccionEsLectura($accion), $accion);
        }
    }

    public function testEndpointExcluidoNoSePuedeEvadirConPathInfo(): void
    {
        // El script real es products_manager.php; "/log_activity.php" es solo PATH_INFO.
        $this->assertFalse(auditEndpointSinRegistro('/api/products_manager.php/log_activity.php'));
        $this->assertFalse(auditEndpointSinRegistro('/api/ventas.php/login.php'));
        $this->assertTrue(auditEndpointSinRegistro('/api/log_activity.php/lo/que/sea'));
    }

    public function testEndpointExcluidoConMayusculasQueryYRutasRaras(): void
    {
        $this->assertTrue(auditEndpointSinRegistro('/API/LOG_ACTIVITY.PHP'));
        $this->assertTrue(auditEndpointSinRegistro('/api/../api/log_activity.php?x=1#frag'));
        $this->assertFalse(auditEndpointSinRegistro(''));
        $this->assertFalse(auditEndpointSinRegistro('/'));
        $this->assertFalse(auditEndpointSinRegistro('/api/log_activity'));
        $this->assertFalse(auditEndpointSinRegistro('/api/log_activity.php.bak'));
        $this->assertFalse(auditEndpointSinRegistro('http:///'));
    }

    /* ================= severidad y etiquetas ================= */

    public function testSeveridadDeAccionesVaciasOLegadas(): void
    {
        $this->assertSame('info', auditSeveridadPorDefecto(''));
        $this->assertSame('info', auditSeveridadPorDefecto('crear'));
        $this->assertSame('alerta', auditSeveridadPorDefecto('cancelar'), 'las acciones antiguas en minusculas tambien');
        $this->assertSame('alerta', auditSeveridadPorDefecto('eliminar_algo'));
    }

    public function testTodaAccionConVerboDestructivoEsAlerta(): void
    {
        foreach (array_keys(auditMapaEtiquetasAccion()) as $accion) {
            if (preg_match('/(ELIMINAD|BORRAD|DENEGAD|BLOQUEO|FALLID)/i', (string) $accion)) {
                $this->assertSame('alerta', auditSeveridadPorDefecto((string) $accion), $accion);
            }
        }
    }

    public function testTodaEtiquetaTieneSeveridadValidaYTextoNoVacio(): void
    {
        foreach (auditMapaEtiquetasAccion() as $accion => $etiqueta) {
            $this->assertNotSame('', trim($etiqueta), "etiqueta vacia para $accion");
            $this->assertContains(auditSeveridadPorDefecto((string) $accion), AUDIT_SEVERIDADES, (string) $accion);
            $this->assertLessThanOrEqual(AUDIT_MAX_ACCION, strlen((string) $accion), "$accion no cabe en la columna accion");
        }
    }

    public function testMapaDeEtiquetasNoTieneClavesDuplicadas(): void
    {
        // PHP no avisa de claves repetidas en un arreglo literal: la ultima gana en silencio.
        $codigo = (string) file_get_contents(dirname(__DIR__, 2) . '/core/audit_utils.php');
        foreach (['auditMapaEtiquetasAccion', 'auditEtiquetaTabla'] as $funcion) {
            $inicio = strpos($codigo, "function {$funcion}(");
            $this->assertNotFalse($inicio);
            $fin = strpos($codigo, "\nfunction ", $inicio + 10);
            $cuerpo = substr($codigo, $inicio, ($fin === false ? strlen($codigo) : $fin) - $inicio);

            preg_match_all("/^\s+'([A-Za-z_]+)' => '/m", $cuerpo, $m);
            $repetidas = array_keys(array_filter(array_count_values($m[1]), static fn(int $n): bool => $n > 1));
            $this->assertNotEmpty($m[1]);
            $this->assertSame([], $repetidas, "claves repetidas en $funcion: " . implode(', ', $repetidas));
        }
    }

    public function testEtiquetaDeAccionesExtranas(): void
    {
        $this->assertSame('(sin acción)', auditEtiquetaAccion(''));
        $this->assertSame('(sin acción)', auditEtiquetaAccion('   '));
        $this->assertSame('X', auditEtiquetaAccion('X'));
        $this->assertTrue(mb_check_encoding(auditEtiquetaAccion('ÁRBOL_ÑANDÚ_😀'), 'UTF-8'));
    }

    /* ================= user agent ================= */

    #[DataProvider('userAgents')]
    public function testResumenDeUserAgent(?string $ua, string $esperado): void
    {
        $this->assertSame($esperado, auditResumirUserAgent($ua));
    }

    /** @return array<string,array{0:?string,1:string}> */
    public static function userAgents(): array
    {
        return [
            'nulo' => [null, ''],
            'vacio' => ['', ''],
            'basura' => ['asdf qwer', 'Otro · Otro'],
            'curl' => ['curl/8.4.0', 'Otro · Otro'],
            'opera' => ['Mozilla/5.0 (Windows NT 10.0) Chrome/120 Safari/537 OPR/100', 'Opera · Windows'],
            'firefox' => ['Mozilla/5.0 (X11; Linux x86_64; rv:120.0) Gecko/20100101 Firefox/120.0', 'Firefox · Linux'],
            'safari mac' => ['Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605 Version/17 Safari/605', 'Safari · Mac'],
            'ipad' => ['Mozilla/5.0 (iPad; CPU OS 17) AppleWebKit/605 Version/17 Mobile Safari/604', 'Safari · iPad'],
            'chrome android' => ['Mozilla/5.0 (Linux; Android 14) Chrome/120 Mobile Safari/537', 'Chrome · Android'],
        ];
    }

    public function testUserAgentGigantePoneAcotadoElTrabajo(): void
    {
        $this->assertSame('Otro · Otro', auditResumirUserAgent(str_repeat('a', 2_000_000)));
    }

    /* ================= contexto de la peticion ================= */

    public function testSesionCorruptaONoArregloNoRompeElContexto(): void
    {
        $_SESSION['usuario'] = 'esto-no-es-un-arreglo';
        $ctx = auditContextoActual();
        $this->assertNull($ctx['id_usuario']);
        $this->assertNull($ctx['usuario_nombre']);

        $_SESSION['usuario'] = [];
        $this->assertNull(auditContextoActual()['id_usuario']);
    }

    #[DataProvider('idsDeUsuarioInvalidos')]
    public function testIdDeUsuarioInvalidoQuedaNulo(mixed $id): void
    {
        $_SESSION['usuario'] = ['id_usuario' => $id, 'nombre' => 'X', 'rol' => 'admin'];
        $this->assertNull(auditContextoActual()['id_usuario']);
        $this->assertNull(auditContextoActual(['id_usuario' => $id])['id_usuario']);
    }

    /** @return array<string,array{0:mixed}> */
    public static function idsDeUsuarioInvalidos(): array
    {
        return ['cero' => [0], 'cero texto' => ['0'], 'negativo' => [-3], 'texto' => ['abc'], 'vacio' => [''], 'nulo' => [null]];
    }

    public function testSucursalInvalidaQuedaNula(): void
    {
        foreach ([0, '0', -1, 'x', null] as $almacen) {
            $_SESSION['usuario'] = ['id_usuario' => 1, 'id_almacen' => $almacen];
            $this->assertNull(auditContextoActual()['id_almacen'], json_encode($almacen));
        }
        $_SESSION['usuario'] = ['id_usuario' => 1, 'id_almacen' => '3'];
        $this->assertSame(3, auditContextoActual()['id_almacen']);
    }

    public function testUserAgentYUrlEnormesSeAcotanALaColumna(): void
    {
        $_SERVER['HTTP_USER_AGENT'] = str_repeat('ñ', 5000);
        $_SERVER['REQUEST_URI'] = '/' . str_repeat('a', 5000);
        $ctx = auditContextoActual();

        $this->assertLessThanOrEqual(255, mb_strlen($ctx['user_agent']));
        $this->assertLessThanOrEqual(255, mb_strlen($ctx['url']));
        $this->assertTrue(mb_check_encoding($ctx['user_agent'], 'UTF-8'));
    }

    public function testUriMalformadaOAusenteNoLanzaExcepcion(): void
    {
        foreach ([null, '', '?', '//', 'http:///', '/a b/%zz', "/x\0y"] as $uri) {
            if ($uri === null) {
                unset($_SERVER['REQUEST_URI']);
            } else {
                $_SERVER['REQUEST_URI'] = $uri;
            }
            $this->assertIsString(auditContextoActual()['url']);
        }
    }

    public function testLaIpSalePorSiempreDeRemoteAddrYNoDeCabecerasFalsificables(): void
    {
        $_SERVER['REMOTE_ADDR'] = '187.10.20.30';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '6.6.6.6';
        $this->assertSame('187.10.20.30', auditContextoActual()['ip']);

        unset($_SERVER['REMOTE_ADDR']);
        $this->assertSame('0.0.0.0', auditContextoActual()['ip']);
    }

    public function testLaHuellaDeSesionNoExponeElIdentificadorReal(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->markTestSkipped('ya hay una sesion activa en este proceso');
        }
        session_id('abc123sesionsecreta');
        $ctx = auditContextoActual();

        $this->assertSame(substr(hash('sha256', 'abc123sesionsecreta'), 0, 12), $ctx['sesion_hash']);
        $this->assertStringNotContainsString('abc123', (string) json_encode($ctx));

        session_id('');
        $this->assertNull(auditContextoActual()['sesion_hash']);
    }

    /* ================= fotos de registros con PII cifrada ================= */

    private function pdoConClientes(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE clientes (id_cliente INTEGER PRIMARY KEY, nombre TEXT, email TEXT, telefono TEXT, id_almacen INTEGER, estado TEXT, id_usuario INTEGER)');
        return $pdo;
    }

    private function conLlaveDePii(callable $prueba): void
    {
        $original = $_SERVER['PII_ENCRYPTION_KEY'] ?? null;
        $_SERVER['PII_ENCRYPTION_KEY'] = 'llave-de-prueba-solo-para-tests-0123456789';
        try {
            $prueba();
        } finally {
            if ($original === null) {
                unset($_SERVER['PII_ENCRYPTION_KEY']);
            } else {
                $_SERVER['PII_ENCRYPTION_KEY'] = $original;
            }
        }
    }

    public function testReCifrarElMismoTelefonoNoSeReportaComoCambio(): void
    {
        $this->conLlaveDePii(function (): void {
            $pdo = $this->pdoConClientes();
            $cifrado1 = piiEncryptValue('(331) - 863 - 5185');
            $pdo->prepare('INSERT INTO clientes (id_cliente, nombre, telefono, estado) VALUES (1, ?, ?, ?)')->execute([piiEncryptValue('Juan Perez'), $cifrado1, 'activo']);

            $antes = auditSnapshotCliente($pdo, 1);
            // El cifrado no es determinista: el mismo texto produce OTRO ENCv1 cada vez.
            $cifrado2 = piiEncryptValue('(331) - 863 - 5185');
            $this->assertNotSame($cifrado1, $cifrado2);
            $pdo->prepare('UPDATE clientes SET telefono = ? WHERE id_cliente = 1')->execute([$cifrado2]);
            $despues = auditSnapshotCliente($pdo, 1);

            $this->assertSame('(331) - 863 - 5185', $antes['telefono'], 'la foto descifra para poder comparar');
            $this->assertSame([], auditDiff($antes, $despues)['despues'], 'sin cambio real no hay diff');
        });
    }

    public function testCambioRealDeTelefonoCifradoSeRegistraSinMostrarNiElCifradoNiElTextoPlano(): void
    {
        $this->conLlaveDePii(function (): void {
            $pdo = $this->pdoConClientes();
            $pdo->prepare('INSERT INTO clientes (id_cliente, nombre, telefono) VALUES (1, ?, ?)')->execute(['x', piiEncryptValue('3318635185')]);
            $antes = auditSnapshotCliente($pdo, 1);
            $pdo->prepare('UPDATE clientes SET telefono = ? WHERE id_cliente = 1')->execute([piiEncryptValue('3311112222')]);
            $diff = auditDiff($antes, auditSnapshotCliente($pdo, 1));

            $json = (string) json_encode($diff);
            $this->assertSame('******5185', $diff['antes']['telefono']);
            $this->assertSame('******2222', $diff['despues']['telefono']);
            $this->assertStringNotContainsString('ENCv1', $json);
            $this->assertStringNotContainsString('3318635185', $json);
            $this->assertStringNotContainsString('3311112222', $json);
        });
    }

    public function testCifradoIlegibleNuncaSeVuelcaAlLog(): void
    {
        $this->conLlaveDePii(function (): void {
            $pdo = $this->pdoConClientes();
            $pdo->exec("INSERT INTO clientes (id_cliente, nombre, telefono) VALUES (1, 'x', 'ENCv1:basura-que-no-descifra')");
            $foto = auditSnapshotCliente($pdo, 1);
            $diff = auditDiff($foto, ['nombre' => 'x', 'telefono' => '3311112222']);

            $this->assertStringNotContainsString('basura', (string) json_encode($diff));
        });
    }

    public function testFotoSinLlaveDePiiNoLanzaExcepcion(): void
    {
        $original = $_SERVER['PII_ENCRYPTION_KEY'] ?? null;
        unset($_SERVER['PII_ENCRYPTION_KEY'], $_ENV['PII_ENCRYPTION_KEY']);
        $env = getenv('PII_ENCRYPTION_KEY');
        putenv('PII_ENCRYPTION_KEY');
        try {
            $pdo = $this->pdoConClientes();
            $pdo->exec("INSERT INTO clientes (id_cliente, nombre, telefono) VALUES (1, 'x', 'ENCv1:algo')");
            $this->assertIsArray(auditSnapshotCliente($pdo, 1));
        } finally {
            if ($original !== null) {
                $_SERVER['PII_ENCRYPTION_KEY'] = $original;
            }
            if ($env !== false) {
                putenv('PII_ENCRYPTION_KEY=' . $env);
            }
        }
    }

    /* ================= fotos: tablas y ids invalidos ================= */

    public function testNombresDeCategoriasIgnoraIdsInvalidosYDuplicados(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("CREATE TABLE categorias (id_categoria INTEGER PRIMARY KEY, nombre TEXT)");
        $pdo->exec("INSERT INTO categorias VALUES (5, 'Oferta'), (6, 'Otra')");

        $this->assertSame([5 => 'Oferta'], auditNombresCategorias($pdo, [0, -1, 'abc', null, '5', 5, '5']));
        $this->assertSame([], auditNombresCategorias($pdo, []));
        $this->assertSame([], auditNombresCategorias($pdo, [0, -3, 'x']));
        $this->assertSame([], auditNombresCategorias($pdo, [999]));
    }

    public function testCategoriasDeProductoToleraTablasFaltantesOIdInvalido(): void
    {
        $vacio = new PDO('sqlite::memory:');
        $vacio->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->assertSame([], auditCategoriasDeProducto($vacio, 1), 'sin tablas: no debe lanzar');
        $this->assertSame([], auditCategoriasDeProducto($vacio, 0));
        $this->assertSame([], auditCategoriasDeProducto($vacio, -7));
        $this->assertSame([], auditNombresCategorias($vacio, [1, 2]));
    }

    public function testNombreDeRegistroRechazaIdentificadoresConInyeccion(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE productos (id_producto INTEGER PRIMARY KEY, nombre TEXT)");
        $pdo->exec("INSERT INTO productos VALUES (1, 'Vitamina')");

        $this->assertSame('', auditNombreRegistro($pdo, 'productos', 'id_producto', "nombre FROM productos; DROP TABLE productos; --", 1));
        $this->assertSame('', auditNombreRegistro($pdo, 'productos; DROP TABLE productos', 'id_producto', 'nombre', 1));
        $this->assertSame('', auditNombreRegistro($pdo, 'productos', 'id_producto', 'nombre', -1));
        $this->assertSame('Vitamina', auditNombreRegistro($pdo, 'productos', 'id_producto', 'nombre', 1), 'la tabla sigue intacta');
    }

    public function testFotoDeTablaConNombreRaroSeRechaza(): void
    {
        $pdo = new PDO('sqlite::memory:');
        foreach (["productos`", 'Productos', 'pro ductos', '', 'productos--', "pro\nductos"] as $tabla) {
            $this->assertSame([], auditSnapshotFila($pdo, $tabla, 'id_producto', 1, ['nombre']), json_encode($tabla));
        }
    }
}
