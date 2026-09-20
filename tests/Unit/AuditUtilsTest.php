<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Auditoria (logs_auditoria): comparar antes/despues, enmascarar datos sensibles, clasificar
 * severidad, etiquetas y contexto. Todo es logica pura (sin BD); las "fotos" de registros se
 * prueban contra SQLite en memoria.
 */
final class AuditUtilsTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SESSION['usuario']);
        unset($_SERVER['REQUEST_URI'], $_SERVER['SCRIPT_NAME'], $_SERVER['HTTP_USER_AGENT']);
    }

    /* ---------- secretos y datos personales ---------- */

    public function testSecretosNuncaSeGuardan(): void
    {
        foreach (['contrasena', 'nueva_contrasena', 'password', 'csrf_token', 'api_key_variable', 'otp_code', 'tarjeta_numero'] as $clave) {
            $this->assertTrue(auditClaveEsSecreta($clave), $clave);
            $this->assertSame('[oculto]', auditEnmascararValor($clave, 'valor-real'), $clave);
        }
        $this->assertFalse(auditClaveEsSecreta('precio_venta'));
        $this->assertFalse(auditClaveEsSecreta('codigo_lote'));
    }

    public function testTelefonoSeEnmascaraConUltimosCuatroDigitos(): void
    {
        $this->assertSame('******5185', auditEnmascararValor('telefono', '(331) - 863 - 5185'));
        $this->assertSame('******5185', auditEnmascararValor('telefono_cliente', '3318635185'));
        $this->assertSame('******5185', auditEnmascararValor('telefono', 3318635185)); // numerico
        $this->assertSame('****', auditEnmascararPii('telefono', '12'));
    }

    public function testCorreoYDireccionSeEnmascaran(): void
    {
        $this->assertSame('j***@gmail.com', auditEnmascararValor('email', 'juan.perez@gmail.com'));
        $this->assertSame('***', auditEnmascararPii('email', 'no-es-correo'));
        $direccion = auditEnmascararValor('direccion', 'Av. Vallarta 1234, Col. Americana, Guadalajara');
        $this->assertStringStartsWith('Av. Vallarta 1', $direccion);
        $this->assertStringEndsWith('...', $direccion);
        $this->assertStringNotContainsString('Guadalajara', $direccion);
    }

    public function testDatoCifradoEnReposoNuncaSeMuestra(): void
    {
        $this->assertSame('[cifrado]', auditEnmascararValor('telefono', 'ENCv1:abcdef'));
        $this->assertSame('[cifrado]', auditEnmascararPii('email', 'ENCv1:xyz'));
    }

    public function testValoresNoEscalaresSeResumen(): void
    {
        $this->assertSame('[3 elemento(s)]', auditEnmascararValor('lista', [1, 2, 3]));
        $this->assertSame('[objeto]', auditEnmascararValor('x', new stdClass()));
        $this->assertNull(auditEnmascararValor('x', null));
        $this->assertTrue(auditEnmascararValor('activo', true));
    }

    /* ---------- diff antes/despues ---------- */

    public function testDiffDevuelveSoloLoQueCambio(): void
    {
        $diff = auditDiff(
            ['nombre' => 'Vitamina C', 'precio_venta' => '250.00', 'precio_oferta' => null, 'estado' => 'activo'],
            ['nombre' => 'Vitamina C', 'precio_venta' => 250, 'precio_oferta' => '120', 'estado' => 'activo']
        );

        $this->assertSame(['precio_oferta' => null], $diff['antes']);
        $this->assertSame(['precio_oferta' => '120'], $diff['despues']);
    }

    public function testDiffTrataEquivalentesComoIguales(): void
    {
        $diff = auditDiff(
            ['a' => '10', 'b' => null, 'c' => 1, 'd' => ' x '],
            ['a' => '10.00', 'b' => '', 'c' => true, 'd' => 'x']
        );
        $this->assertSame([], $diff['antes']);
        $this->assertSame([], $diff['despues']);
    }

    public function testDiffDetectaCambioEnIdentificadoresConCeroALaIzquierda(): void
    {
        $diff = auditDiff(['sku' => '0012345'], ['sku' => '12345']);
        $this->assertSame(['sku' => '0012345'], $diff['antes']);
        $this->assertSame(['sku' => '12345'], $diff['despues']);
    }

    public function testDiffEnmascaraDatosPersonalesYSecretos(): void
    {
        $diff = auditDiff(
            ['telefono' => '3318635185', 'contrasena' => 'vieja'],
            ['telefono' => '3311112222', 'contrasena' => 'nueva']
        );
        $this->assertSame('******5185', $diff['antes']['telefono']);
        $this->assertSame('******2222', $diff['despues']['telefono']);
        $this->assertSame('[oculto]', $diff['antes']['contrasena']);
        $this->assertSame('[oculto]', $diff['despues']['contrasena']);
    }

    public function testDiffSinListaBlancaIgnoraCamposQueSoloExistenEnUnLado(): void
    {
        $diff = auditDiff(['a' => 1], ['a' => 1, 'extra' => 'x']);
        $this->assertSame([], $diff['despues']);
    }

    public function testDiffConListaBlancaSoloMiraEsosCampos(): void
    {
        $diff = auditDiff(['a' => 1, 'b' => 1], ['a' => 2, 'b' => 2], ['b']);
        $this->assertSame(['b' => 1], $diff['antes']);
        $this->assertSame(['b' => 2], $diff['despues']);
    }

    public function testResumenDeCambiosEsLegible(): void
    {
        $resumen = auditResumenCambios(['precio_oferta' => null, 'estado' => 'activo'], ['precio_oferta' => '120', 'estado' => 'inactivo']);
        $this->assertSame('precio_oferta: (vacio) -> 120; estado: activo -> inactivo', $resumen);
        $this->assertLessThanOrEqual(53, mb_strlen(auditResumenCambios(['x' => 'a'], ['x' => str_repeat('b', 500)], 50)));
    }

    /* ---------- payload y JSON ---------- */

    public function testPayloadSanitizadoOcultaSecretosYLimitaTamano(): void
    {
        $datos = ['csrf_token' => 'abc', 'contrasena' => 'x', 'nota' => str_repeat('n', 1000), 'telefono' => '3318635185', 'items' => [1, 2]];
        $payload = auditSanitizarPayload($datos);

        $this->assertSame('[oculto]', $payload['csrf_token']);
        $this->assertSame('[oculto]', $payload['contrasena']);
        $this->assertSame('******5185', $payload['telefono']);
        $this->assertLessThanOrEqual(303, mb_strlen($payload['nota']));
        $this->assertSame([1, 2], array_values($payload['items']));

        $muchos = auditSanitizarPayload(array_fill(0, 100, 'v'), 10);
        $this->assertSame(90, $muchos['_omitidos']);
    }

    public function testJsonCompactoNuncaPierdeElEventoPorTamano(): void
    {
        $this->assertNull(auditJsonCompacto(null));
        $this->assertNull(auditJsonCompacto([]));
        $this->assertSame('{"a":"ñ"}', auditJsonCompacto(['a' => 'ñ']));

        $grande = auditJsonCompacto(['campo' => str_repeat('z', 20000)]);
        $this->assertNotNull($grande);
        $this->assertLessThanOrEqual(AUDIT_MAX_JSON_BYTES, strlen($grande));
        $this->assertStringContainsString('_truncado', $grande);
    }

    /* ---------- lecturas por POST y endpoints excluidos ---------- */

    public function testAccionesDeLecturaNoCuentanComoMovimiento(): void
    {
        foreach (['list', 'get_one', 'get_dependencies', 'blife_search', 'fetch_blife_info', 'buscar_cliente', 'fetch'] as $a) {
            $this->assertTrue(auditAccionEsLectura($a), $a);
        }
        foreach (['save', 'delete', 'bulk_assign_category', 'guardar', 'eliminar', 'transfer', ''] as $a) {
            $this->assertFalse(auditAccionEsLectura($a), $a);
        }
    }

    public function testEndpointsSinRegistroSeReconocenPorNombreDeArchivo(): void
    {
        $this->assertTrue(auditEndpointSinRegistro('/e-commerce-pro/api/log_activity.php'));
        $this->assertTrue(auditEndpointSinRegistro('/views/login.php?x=1'));
        $this->assertFalse(auditEndpointSinRegistro('/api/products_manager.php'));
        $this->assertFalse(auditEndpointSinRegistro('/api/ventas.php'));
    }

    /* ---------- severidad y etiquetas ---------- */

    public function testSeveridadPorDefecto(): void
    {
        $this->assertSame('alerta', auditSeveridadPorDefecto('CLIENTE_ELIMINADO'));
        $this->assertSame('alerta', auditSeveridadPorDefecto('LOGIN_FALLIDO'));
        $this->assertSame('alerta', auditSeveridadPorDefecto('ACCESO_DENEGADO'));
        $this->assertSame('alerta', auditSeveridadPorDefecto('EXPORTACION_DATOS'));
        $this->assertSame('aviso', auditSeveridadPorDefecto('PRODUCTO_EDITADO'));
        $this->assertSame('aviso', auditSeveridadPorDefecto('TRANSFERENCIA_STOCK'));
        $this->assertSame('info', auditSeveridadPorDefecto('LOGIN_EXITOSO'));
        $this->assertSame('info', auditSeveridadPorDefecto('PETICION_ESCRITURA'));
    }

    public function testSeveridadExplicitaGanaSiEsValida(): void
    {
        $this->assertSame('alerta', auditNormalizarSeveridad('alerta', 'LOGIN_EXITOSO'));
        $this->assertSame('alerta', auditNormalizarSeveridad('ALERTA', 'LOGIN_EXITOSO'));
        $this->assertSame('aviso', auditNormalizarSeveridad('invalida', 'PRODUCTO_EDITADO'));
        $this->assertSame('info', auditNormalizarSeveridad(null, 'LOGIN_EXITOSO'));
    }

    public function testEtiquetasConocidasYRespaldoHumanizado(): void
    {
        $this->assertSame('Inicio de sesión', auditEtiquetaAccion('LOGIN_EXITOSO'));
        $this->assertSame('Creación', auditEtiquetaAccion('crear'));
        $this->assertSame('Algo nuevo raro', auditEtiquetaAccion('ALGO_NUEVO_RARO'));
        $this->assertSame('Productos', auditEtiquetaTabla('productos'));
        $this->assertSame('tabla_desconocida', auditEtiquetaTabla('tabla_desconocida'));
    }

    /**
     * Guarda contra el olvido mas comun: agregar un logAudit('ACCION_NUEVA', ...) sin ponerle
     * nombre legible en auditMapaEtiquetasAccion(), y que en la vista salga "Accion nueva".
     */
    public function testTodaAccionRegistradaEnElCodigoTieneEtiqueta(): void
    {
        $raiz = dirname(__DIR__, 2);
        $faltantes = [];
        foreach (['api', 'views', 'core', 'scripts'] as $carpeta) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz . '/' . $carpeta, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $archivo) {
                if ($archivo->getExtension() !== 'php') {
                    continue;
                }
                $codigo = (string) file_get_contents($archivo->getPathname());
                if (preg_match_all("/logAudit(?:Cambios)?\\(\\s*'([A-Za-z_]+)'/", $codigo, $m)) {
                    foreach ($m[1] as $accion) {
                        if (!auditAccionTieneEtiqueta($accion)) {
                            $faltantes[$accion] = $archivo->getFilename();
                        }
                    }
                }
            }
        }

        $this->assertSame([], $faltantes, 'Acciones de logAudit() sin etiqueta en auditMapaEtiquetasAccion(): ' . json_encode($faltantes));
    }

    public function testUserAgentSeResumeParaLeerloDeUnVistazo(): void
    {
        $this->assertSame('Chrome · Android', auditResumirUserAgent('Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/120 Mobile Safari/537.36'));
        $this->assertSame('Safari · iPhone', auditResumirUserAgent('Mozilla/5.0 (iPhone; CPU iPhone OS 17) AppleWebKit/605 Version/17 Mobile Safari/604'));
        $this->assertSame('Edge · Windows', auditResumirUserAgent('Mozilla/5.0 (Windows NT 10.0) Chrome/120 Safari/537 Edg/120'));
        $this->assertSame('', auditResumirUserAgent(null));
    }

    /* ---------- contexto de la peticion ---------- */

    public function testContextoTomaActorDeLaSesionYNoGuardaLaQueryString(): void
    {
        $_SESSION['usuario'] = ['id_usuario' => 7, 'nombre' => 'Maria', 'rol' => 'encargado', 'id_almacen' => 2];
        $_SERVER['REQUEST_URI'] = '/api/products_manager.php?action=save&token=SECRETO';
        $_SERVER['HTTP_USER_AGENT'] = 'UA-de-prueba';

        $ctx = auditContextoActual();

        $this->assertSame(7, $ctx['id_usuario']);
        $this->assertSame('Maria', $ctx['usuario_nombre']);
        $this->assertSame('encargado', $ctx['usuario_rol']);
        $this->assertSame(2, $ctx['id_almacen']);
        $this->assertSame('/api/products_manager.php', $ctx['url']);
        $this->assertStringNotContainsString('SECRETO', (string) json_encode($ctx));
        $this->assertSame('CLI', $ctx['metodo']);
    }

    public function testContextoPermiteForzarElActorSinSesion(): void
    {
        unset($_SESSION['usuario']);
        $ctx = auditContextoActual(['id_usuario' => 9, 'usuario_nombre' => 'Ana', 'usuario_rol' => 'cliente']);
        $this->assertSame(9, $ctx['id_usuario']);
        $this->assertSame('Ana', $ctx['usuario_nombre']);

        $sin = auditContextoActual(['id_usuario' => null]);
        $this->assertNull($sin['id_usuario']);
    }

    /* ---------- fotos de registros (SQLite en memoria) ---------- */

    private function pdoConProductos(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('CREATE TABLE productos (id_producto INTEGER PRIMARY KEY, nombre TEXT, precio_venta REAL, precio_oferta REAL, estado TEXT, descripcion TEXT, secreto_interno TEXT)');
        $pdo->exec("INSERT INTO productos VALUES (1, 'Vitamina C', 250, NULL, 'activo', 'Un texto largo de descripcion', 'no-debe-salir')");
        return $pdo;
    }

    public function testSnapshotSoloTraeCamposDeLaListaBlancaYCompactaTextoLargo(): void
    {
        $foto = auditSnapshotProducto($this->pdoConProductos(), 1);

        $this->assertSame('Vitamina C', $foto['nombre']);
        $this->assertArrayNotHasKey('secreto_interno', $foto);
        $this->assertArrayNotHasKey('sku', $foto, 'una columna que no existe en el entorno se omite');
        $this->assertMatchesRegularExpression('/^\d+ car\. #[0-9a-f]{6}$/', $foto['descripcion']);
    }

    public function testSnapshotDetectaEdicionDeTextoLargoSinVolcarloAlLog(): void
    {
        $pdo = $this->pdoConProductos();
        $antes = auditSnapshotProducto($pdo, 1);
        $pdo->exec("UPDATE productos SET descripcion = 'Otro texto distinto', precio_oferta = 199 WHERE id_producto = 1");
        $despues = auditSnapshotProducto($pdo, 1);

        $diff = auditDiff($antes, $despues);
        $campos = array_keys($diff['despues']);
        sort($campos);
        $this->assertSame(['descripcion', 'precio_oferta'], $campos);
        $this->assertStringNotContainsString('Otro texto', (string) json_encode($diff));
    }

    public function testSnapshotEsToleranteConTablaOIdInvalidos(): void
    {
        $pdo = $this->pdoConProductos();
        $this->assertSame([], auditSnapshotProducto($pdo, 999));
        $this->assertSame([], auditSnapshotProducto($pdo, 0));
        $this->assertSame([], auditSnapshotFila($pdo, 'tabla_inexistente', 'id', 1, ['x']));
        $this->assertSame([], auditSnapshotFila($pdo, 'productos; DROP TABLE productos', 'id_producto', 1, ['nombre']));
        $this->assertSame('', auditNombreRegistro($pdo, 'productos', 'id_producto', 'nombre', 999));
        $this->assertSame('Vitamina C', auditNombreRegistro($pdo, 'productos', 'id_producto', 'nombre', 1));
    }

    /* ---------- migracion ---------- */

    public function testMigracionDeAuditoriaUsaPatronCompatibleConPercona(): void
    {
        $archivos = glob(dirname(__DIR__, 2) . '/database/migrations/*amplia_logs_auditoria_para_trazabilidad.sql') ?: [];
        $this->assertCount(1, $archivos);

        $sql = (string) file_get_contents($archivos[0]);
        $this->assertStringNotContainsString('ADD COLUMN IF NOT EXISTS', $sql, 'MariaDB-only: revienta el deploy en Percona 8.4');
        $this->assertStringContainsString('information_schema.COLUMNS', $sql);
        foreach (['datos_antes', 'datos_despues', 'usuario_nombre', 'usuario_rol', 'sesion_hash', 'user_agent', 'severidad', 'idx_logs_auditoria_registro'] as $pieza) {
            $this->assertStringContainsString($pieza, $sql);
        }
    }
}
