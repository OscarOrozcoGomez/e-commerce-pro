<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

/**
 * logAudit() / logAuditCambios() / la red de seguridad de peticiones, contra SQLite y con un
 * getPDO() falso. Se centra en los MODOS DE FALLA: la auditoria nunca debe tumbar la operacion de
 * negocio, nunca debe perder un evento por un limite de columna, y nunca debe guardar un secreto.
 *
 * Cada prueba corre en su propio proceso: getPDO() no existe en la suite (el resto de las pruebas
 * dependen de que no exista) y aqui hace falta definirlo, ademas de que el contador de eventos por
 * peticion es estatico.
 */
#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class AuditLogAuditFailureModesTest extends TestCase
{
    private const ESQUEMA_LEGADO = 'CREATE TABLE logs_auditoria (
        id_log INTEGER PRIMARY KEY AUTOINCREMENT, id_usuario INTEGER, accion TEXT NOT NULL,
        tabla_afectada TEXT NOT NULL, id_registro INTEGER, detalles TEXT, ip_address TEXT,
        fecha TEXT DEFAULT CURRENT_TIMESTAMP)';

    private const ESQUEMA_NUEVO = 'CREATE TABLE logs_auditoria (
        id_log INTEGER PRIMARY KEY AUTOINCREMENT, id_usuario INTEGER, accion TEXT NOT NULL,
        tabla_afectada TEXT NOT NULL, id_registro INTEGER, detalles TEXT, ip_address TEXT,
        fecha TEXT DEFAULT CURRENT_TIMESTAMP, usuario_nombre TEXT, usuario_rol TEXT, id_almacen INTEGER,
        sesion_hash TEXT, user_agent TEXT, url TEXT, metodo TEXT, origen TEXT NOT NULL DEFAULT \'web\',
        severidad TEXT NOT NULL DEFAULT \'info\', datos_antes TEXT, datos_despues TEXT)';

    private string $logDeErrores = '';

    protected function setUp(): void
    {
        // El error_log de logAudit (cuando traga una excepcion a proposito) no debe ensuciar la salida.
        $this->logDeErrores = sys_get_temp_dir() . '/audit_test_errores_' . getmypid() . '.log';
        ini_set('error_log', $this->logDeErrores);

        $_SERVER['REMOTE_ADDR'] = '187.10.20.30';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0) Chrome/120 Safari/537';
        $_SERVER['REQUEST_URI'] = '/api/prueba.php?token=SECRETO-EN-LA-URL';
        $_SERVER['SCRIPT_NAME'] = '/api/prueba.php';
        $_SERVER['REQUEST_METHOD'] = 'POST';

        if (!function_exists('getPDO')) {
            function getPDO(): PDO
            {
                $pdo = $GLOBALS['audit_test_pdo'] ?? null;
                if (!$pdo instanceof PDO) {
                    throw new RuntimeException('BD caida (simulada)');
                }
                return $pdo;
            }
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->logDeErrores);
    }

    private function bd(string $esquema = self::ESQUEMA_NUEVO): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec($esquema);
        return $GLOBALS['audit_test_pdo'] = $pdo;
    }

    /** @return array<int,array<string,mixed>> */
    private function filas(PDO $pdo): array
    {
        return $pdo->query('SELECT * FROM logs_auditoria ORDER BY id_log')->fetchAll();
    }

    private function sesion(int $id = 7, string $nombre = 'Maria Encargada', string $rol = 'encargado'): void
    {
        $_SESSION['usuario'] = ['id_usuario' => $id, 'nombre' => $nombre, 'rol' => $rol, 'id_almacen' => 2];
    }

    /* ================= logAudit ================= */

    public function testGuardaActorDispositivoYContexto(): void
    {
        $pdo = $this->bd();
        $this->sesion();

        logAudit('PRODUCTO_EDITADO', 'productos', 27, 'detalle', ['a' => 1], ['a' => 2], ['severidad' => 'alerta']);

        [$fila] = $this->filas($pdo);
        $this->assertSame(7, (int) $fila['id_usuario']);
        $this->assertSame('Maria Encargada', $fila['usuario_nombre']);
        $this->assertSame('encargado', $fila['usuario_rol']);
        $this->assertSame(2, (int) $fila['id_almacen']);
        $this->assertSame('187.10.20.30', $fila['ip_address']);
        $this->assertSame('/api/prueba.php', $fila['url'], 'sin query string');
        $this->assertSame('alerta', $fila['severidad']);
        $this->assertSame('{"a":1}', $fila['datos_antes']);
        $this->assertSame('{"a":2}', $fila['datos_despues']);
        $this->assertStringNotContainsString('SECRETO-EN-LA-URL', (string) json_encode($fila));
    }

    public function testAccionYTablaMasLargasQueLaColumnaSeTruncanSinPerderElEvento(): void
    {
        $pdo = $this->bd();

        logAudit(str_repeat('A', 80), str_repeat('t', 80), 1, 'x');

        [$fila] = $this->filas($pdo);
        $this->assertSame(50, strlen($fila['accion']));
        $this->assertSame(50, strlen($fila['tabla_afectada']));
    }

    public function testDetallesEnormesYMultibyteSeCortanEnUnLimiteSinRomperUtf8(): void
    {
        $pdo = $this->bd();

        logAudit('X', 't', 1, str_repeat('ñ', 9000));

        [$fila] = $this->filas($pdo);
        $this->assertSame(AUDIT_MAX_DETALLES, mb_strlen($fila['detalles']));
        $this->assertTrue(mb_check_encoding($fila['detalles'], 'UTF-8'));
    }

    public function testDatosAntesYDespuesGigantesSeGuardanComoMarcadorDeTruncado(): void
    {
        $pdo = $this->bd();

        logAudit('X', 't', 1, 'x', ['k' => str_repeat('z', 50000)], ['k' => str_repeat('y', 50000)]);

        [$fila] = $this->filas($pdo);
        $this->assertLessThanOrEqual(AUDIT_MAX_JSON_BYTES, strlen($fila['datos_antes']));
        $this->assertStringContainsString('_truncado', $fila['datos_despues']);
    }

    public function testSeveridadInventadaCaeAlValorPorDefectoDeLaAccion(): void
    {
        $pdo = $this->bd();

        logAudit('CLIENTE_ELIMINADO', 'clientes', 1, 'x', null, null, ['severidad' => 'critica!!']);
        logAudit('LOGIN_EXITOSO', 'usuarios', 1, 'x', null, null, ['severidad' => "alerta'; DROP TABLE logs_auditoria;--"]);

        $filas = $this->filas($pdo);
        $this->assertSame('alerta', $filas[0]['severidad']);
        $this->assertSame('info', $filas[1]['severidad']);
    }

    public function testSinSesionQuedaAnonimoSinTronar(): void
    {
        $pdo = $this->bd();
        unset($_SESSION['usuario']);

        logAudit('LOGIN_FALLIDO', 'usuarios', null, 'x');

        [$fila] = $this->filas($pdo);
        $this->assertNull($fila['id_usuario']);
        $this->assertNull($fila['usuario_nombre']);
        $this->assertNull($fila['id_registro']);
    }

    public function testElActorForzadoGanaSobreLaSesionYPuedeSerNulo(): void
    {
        $pdo = $this->bd();
        $this->sesion(7);

        logAudit('A', 't', 1, 'x', null, null, ['id_usuario' => 9, 'usuario_nombre' => 'Otra', 'usuario_rol' => 'cliente']);
        logAudit('B', 't', 1, 'x', null, null, ['id_usuario' => null]);

        $filas = $this->filas($pdo);
        $this->assertSame(9, (int) $filas[0]['id_usuario']);
        $this->assertSame('Otra', $filas[0]['usuario_nombre']);
        $this->assertNull($filas[1]['id_usuario'], 'forzar null = sin actor, aunque haya sesion');
    }

    public function testInyeccionSqlEnLosDatosSeGuardaComoTextoLiteral(): void
    {
        $pdo = $this->bd();
        $this->sesion(7, "Robert'); DROP TABLE logs_auditoria;--");
        $malo = "'); DROP TABLE logs_auditoria; --";

        logAudit($malo, $malo, 1, $malo, [$malo => $malo], null);

        $filas = $this->filas($pdo);
        $this->assertCount(1, $filas, 'la tabla sigue existiendo y tiene la fila');
        $this->assertSame($malo, $filas[0]['detalles']);
    }

    /* ---------- la auditoria nunca tumba la operacion de negocio ---------- */

    public function testBaseDeDatosCaidaNoLanzaExcepcion(): void
    {
        $GLOBALS['audit_test_pdo'] = null; // getPDO() lanzara RuntimeException

        logAudit('PRODUCTO_EDITADO', 'productos', 1, 'x');

        $this->assertStringContainsString('BD caida', (string) file_get_contents($this->logDeErrores), 'el error se registra en error_log, no se propaga');
        $this->assertSame(1, auditEventosEnPeticion(), 'el intento cuenta aunque la BD haya fallado');
    }

    public function testTablaInexistenteNoLanzaExcepcion(): void
    {
        $GLOBALS['audit_test_pdo'] = new PDO('sqlite::memory:'); // sin tabla logs_auditoria
        $GLOBALS['audit_test_pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        logAudit('X', 't', 1, 'x');
        $this->assertTrue(true, 'no se propago ninguna excepcion');
    }

    public function testEsquemaAnteriorALaMigracionHaceRespaldoAlFormatoLegado(): void
    {
        $pdo = $this->bd(self::ESQUEMA_LEGADO);
        $this->sesion();

        logAuditCambios('PRODUCTO_EDITADO', 'productos', 27, ['precio_oferta' => null], ['precio_oferta' => '120'], [], ['contexto' => 'Vitamina C']);

        $filas = $this->filas($pdo);
        $this->assertCount(1, $filas, 'el deploy subio el codigo pero la migracion aun no corre: no se pierde el evento');
        $this->assertSame('PRODUCTO_EDITADO', $filas[0]['accion']);
        $this->assertSame(7, (int) $filas[0]['id_usuario']);
        $this->assertStringContainsString('precio_oferta: (vacio) -> 120', $filas[0]['detalles'], 'el resumen del cambio viaja en detalles');
    }

    public function testOtroErrorDeBdNoActivaElRespaldoLegadoNiSePropaga(): void
    {
        // Esquema NUEVO (todas las columnas existen) pero que rechaza toda fila: el error NO es de columna
        // faltante, asi que no debe intentarse el formato legado ni propagarse.
        $pdo = $this->bd(str_replace('datos_despues TEXT)', 'datos_despues TEXT, CHECK (length(accion) > 100))', self::ESQUEMA_NUEVO));

        logAudit('X', 't', 1, 'x');

        $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM logs_auditoria')->fetchColumn());
    }

    public function testModoDegradadoEscribeArchivoYNoUsaLaBd(): void
    {
        $archivo = dirname(__DIR__, 2) . '/audit_fallback.log';
        if (file_exists($archivo)) {
            $this->markTestSkipped('ya existe un audit_fallback.log real; no se toca');
        }
        $pdo = $this->bd();
        $this->sesion();
        putenv('LOGIN_DEGRADED_MODE=1');
        $_SERVER['LOGIN_DEGRADED_MODE'] = '1';

        try {
            logAuditCambios('PRODUCTO_EDITADO', 'productos', 27, ['p' => '1'], ['p' => '2'], [], ['severidad' => 'alerta']);

            $this->assertSame([], $this->filas($pdo), 'en modo degradado no se toca la BD');
            $this->assertFileExists($archivo);
            $linea = json_decode((string) file_get_contents($archivo), true);
            $this->assertSame('PRODUCTO_EDITADO', $linea['accion']);
            $this->assertSame('Maria Encargada', $linea['usuario_nombre']);
            $this->assertSame('alerta', $linea['severidad']);
            $this->assertSame('{"p":"2"}', $linea['datos_despues']);
        } finally {
            @unlink($archivo);
            putenv('LOGIN_DEGRADED_MODE');
            unset($_SERVER['LOGIN_DEGRADED_MODE']);
        }
    }

    public function testElContadorDeEventosSubeConCadaRegistro(): void
    {
        $this->bd();
        $this->assertSame(0, auditEventosEnPeticion());

        logAudit('A', 't', 1, 'x');
        logAudit('B', 't', 1, 'x');

        $this->assertSame(2, auditEventosEnPeticion());
    }

    /* ================= logAuditCambios ================= */

    public function testSinCambiosRealesNoEscribeNadaYDevuelveFalso(): void
    {
        $pdo = $this->bd();

        $this->assertFalse(logAuditCambios('X', 't', 1, ['p' => '250.00', 'n' => ' a '], ['p' => 250, 'n' => 'a']));
        $this->assertFalse(logAuditCambios('X', 't', 1, [], []));
        $this->assertSame([], $this->filas($pdo));
        $this->assertSame(0, auditEventosEnPeticion(), 'un guardado sin cambios no cuenta como evento (la red de seguridad si lo veria)');
    }

    public function testConCambiosGuardaSoloLoQueCambioConContexto(): void
    {
        $pdo = $this->bd();

        $this->assertTrue(logAuditCambios('PRODUCTO_EDITADO', 'productos', 27, ['a' => 1, 'b' => 'x'], ['a' => 2, 'b' => 'x'], [], ['contexto' => 'Vitamina C']));

        [$fila] = $this->filas($pdo);
        $this->assertSame('Vitamina C | a: 1 -> 2', $fila['detalles']);
        $this->assertSame('{"a":1}', $fila['datos_antes']);
        $this->assertSame('{"a":2}', $fila['datos_despues']);
    }

    public function testNingunDatoPersonalNiSecretoLlegaEnClaroAlaTabla(): void
    {
        $pdo = $this->bd();
        $this->sesion();

        logAuditCambios(
            'CLIENTE_EDITADO',
            'clientes',
            3,
            ['nombre' => 'Juan', 'telefono' => '3318635185', 'email' => 'juan.perez@gmail.com', 'direccion' => 'Av. Vallarta 1234 Col. Americana', 'contrasena' => 'vieja-S3CRETA', 'token' => 'TOK-VIEJO'],
            ['nombre' => 'Juan P', 'telefono' => '3311112222', 'email' => 'jp@yahoo.com', 'direccion' => 'Av. Mexico 999 Col. Chapalita', 'contrasena' => 'nueva-S3CRETA', 'token' => 'TOK-NUEVO'],
            [],
            ['contexto' => 'Cliente "Juan"']
        );

        $volcado = (string) json_encode($this->filas($pdo));
        foreach (['3318635185', '3311112222', 'juan.perez@gmail.com', 'jp@yahoo.com', 'Vallarta 1234', 'Chapalita', 'S3CRETA', 'TOK-VIEJO', 'TOK-NUEVO'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $volcado, "'$prohibido' no debe estar en el log");
        }
        $this->assertStringContainsString('******5185', $volcado);
    }

    public function testCambioSoloEnCampoFueraDeLaListaBlancaNoRegistra(): void
    {
        $pdo = $this->bd();

        $this->assertFalse(logAuditCambios('X', 't', 1, ['a' => 1, 'ruido' => 'x'], ['a' => 1, 'ruido' => 'y'], ['a']));
        $this->assertSame([], $this->filas($pdo));
    }

    /* ================= acceso denegado ================= */

    public function testAccesoDenegadoQuedaComoAlertaConElRecurso(): void
    {
        $pdo = $this->bd();
        $this->sesion(9, 'Vendedor', 'vendedor');

        auditAccesoDenegado("permiso 'gestionar_usuarios' en /views/users.php");

        [$fila] = $this->filas($pdo);
        $this->assertSame('ACCESO_DENEGADO', $fila['accion']);
        $this->assertSame('alerta', $fila['severidad']);
        $this->assertSame('vendedor', $fila['usuario_rol']);
        $this->assertStringContainsString('gestionar_usuarios', $fila['detalles']);
    }

    /* ================= red de seguridad de peticiones ================= */

    public function testPeticionSinSesionNoSeRegistra(): void
    {
        $pdo = $this->bd();
        unset($_SESSION['usuario']);
        $_POST = ['nombre' => 'x'];

        auditRegistrarPeticionMutante();

        $this->assertSame([], $this->filas($pdo));
    }

    public function testPeticionQueYaRegistroPorSuCuentaNoSeDuplica(): void
    {
        $pdo = $this->bd();
        $this->sesion();
        $_POST = ['nombre' => 'x'];
        logAudit('PRODUCTO_EDITADO', 'productos', 1, 'registro propio');

        auditRegistrarPeticionMutante();

        $this->assertCount(1, $this->filas($pdo));
    }

    public function testLecturasPorPostYPeticionesVaciasNoSeRegistran(): void
    {
        $pdo = $this->bd();
        $this->sesion();

        foreach (['list', 'get_one', 'buscar_cliente', 'FETCH'] as $accion) {
            $_POST = ['x' => '1'];
            $_GET = ['action' => $accion];
            auditRegistrarPeticionMutante();
        }
        $_POST = [];
        $_GET = [];
        $_FILES = [];
        auditRegistrarPeticionMutante();

        $this->assertSame([], $this->filas($pdo));
    }

    public function testLaAccionPuedeVenirEnElCuerpoOEnLaQueryString(): void
    {
        $pdo = $this->bd();
        $this->sesion();

        $_POST = ['accion' => 'guardar_algo', 'x' => '1'];
        $_GET = [];
        auditRegistrarPeticionMutante();

        $filas = $this->filas($pdo);
        $this->assertCount(1, $filas);
        $this->assertStringContainsString('acción=guardar_algo', $filas[0]['detalles']);
    }

    public function testPayloadDeLaPeticionNoLlevaSecretosNiTokenCsrfNiDatosPersonales(): void
    {
        $pdo = $this->bd();
        $this->sesion();
        $_GET = ['action' => 'save'];
        $_POST = ['nombre' => 'Algo', 'contrasena' => 'S3CRETA-1', 'csrf_token' => 'CSRF-TOK', 'password' => 'S3CRETA-2', 'telefono' => '3318635185', 'email' => 'x@y.com'];

        auditRegistrarPeticionMutante();

        $volcado = (string) json_encode($this->filas($pdo));
        foreach (['S3CRETA-1', 'S3CRETA-2', 'CSRF-TOK', '3318635185', 'x@y.com'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $volcado, $prohibido);
        }
        $this->assertStringContainsString('Algo', $volcado);
    }

    public function testArchivosSubidosSoloDejanElNombreDelCampo(): void
    {
        $pdo = $this->bd();
        $this->sesion();
        $_GET = ['action' => 'save'];
        $_POST = [];
        $_FILES = ['imagenes' => ['name' => ['secreto-personal.jpg'], 'tmp_name' => ['/tmp/x'], 'error' => [0]]];

        auditRegistrarPeticionMutante();

        [$fila] = $this->filas($pdo);
        $this->assertStringContainsString('imagenes', $fila['datos_despues']);
        $this->assertStringNotContainsString('secreto-personal', (string) json_encode($fila));
    }

    public function testPeticionGiganteSigueGuardandoseAcotada(): void
    {
        $pdo = $this->bd();
        $this->sesion();
        $_GET = ['action' => 'save'];
        $_POST = [];
        for ($i = 0; $i < 5000; $i++) {
            $_POST["campo_$i"] = str_repeat('v', 300);
        }

        auditRegistrarPeticionMutante();

        [$fila] = $this->filas($pdo);
        $this->assertLessThanOrEqual(AUDIT_MAX_JSON_BYTES, strlen($fila['datos_despues']));
        $this->assertNotNull(json_decode($fila['datos_despues'], true), 'sigue siendo JSON valido');
    }

    /* ================= cambio de categorias (ofertas) ================= */

    public function testCategoriasSinCambioNoRegistran(): void
    {
        $pdo = $this->bd();

        auditRegistrarCambioCategorias(1, 'X', [1 => 'A'], [1 => 'A']);
        auditRegistrarCambioCategorias(1, 'X', [], []);

        $this->assertSame([], $this->filas($pdo));
    }

    public function testAgregarOQuitarLaCategoriaOfertaEsAlertaEnCualquierEscritura(): void
    {
        $pdo = $this->bd();

        auditRegistrarCambioCategorias(1, 'P1', [], [9 => 'Oferta']);
        auditRegistrarCambioCategorias(2, 'P2', [], [9 => 'OFERTAS']);
        auditRegistrarCambioCategorias(3, 'P3', [], [9 => '  oferta  ']);
        auditRegistrarCambioCategorias(4, 'P4', [9 => 'Oferta', 1 => 'A'], [1 => 'A']); // se QUITA de oferta

        foreach ($this->filas($pdo) as $fila) {
            $this->assertSame('alerta', $fila['severidad'], $fila['detalles']);
            $this->assertStringContainsString('(OFERTA)', $fila['detalles']);
        }
    }

    public function testCategoriaParecidaAOfertaNoDisparaLaAlerta(): void
    {
        $pdo = $this->bd();

        auditRegistrarCambioCategorias(1, 'P', [], [5 => 'Ofertas de verano']);
        auditRegistrarCambioCategorias(2, 'P', [], [6 => 'Oferton']);

        foreach ($this->filas($pdo) as $fila) {
            $this->assertSame('aviso', $fila['severidad']);
            $this->assertStringNotContainsString('(OFERTA)', $fila['detalles']);
        }
    }

    public function testCambioDeCategoriasSinNombreDeProductoNoDejaSeparadorHuerfano(): void
    {
        $pdo = $this->bd();

        auditRegistrarCambioCategorias(1, '', [], [5 => 'Probioticos']);

        [$fila] = $this->filas($pdo);
        $this->assertSame('agregó: Probioticos', $fila['detalles']);
    }
}
