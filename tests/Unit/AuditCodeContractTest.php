<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pruebas "de contrato": escanean el codigo fuente para atrapar regresiones que ninguna prueba de
 * comportamiento veria (nadie las ejecuta hasta que ya es tarde):
 *
 *  - un logAudit() al que alguien le pasa una contrasena,
 *  - una accion nueva sin etiqueta o demasiado larga para la columna,
 *  - una columna en el INSERT que la migracion no crea (el deploy tronaria),
 *  - una migracion que borre datos,
 *  - un endpoint que modifique datos antes de verificar la sesion,
 *  - una accion de escritura clasificada por error como "solo lectura" (saldria del registro).
 */
final class AuditCodeContractTest extends TestCase
{
    private static string $raiz;

    public static function setUpBeforeClass(): void
    {
        self::$raiz = dirname(__DIR__, 2);
    }

    /** @return string[] rutas absolutas de todos los .php de las carpetas dadas */
    private function archivosPhp(array $carpetas): array
    {
        $archivos = [];
        foreach ($carpetas as $carpeta) {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::$raiz . '/' . $carpeta, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $archivo) {
                if ($archivo->getExtension() === 'php') {
                    $archivos[] = str_replace('\\', '/', $archivo->getPathname());
                }
            }
        }
        sort($archivos);
        return $archivos;
    }

    private function leer(string $relativa): string
    {
        return (string) file_get_contents(self::$raiz . '/' . $relativa);
    }

    /** Quita comentarios de linea y de bloque para no dar falsos positivos con texto explicativo. */
    private function sinComentarios(string $codigo): string
    {
        $limpio = '';
        foreach (token_get_all($codigo) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                $limpio .= str_repeat("\n", substr_count($token[1], "\n"));
                continue;
            }
            $limpio .= is_array($token) ? $token[1] : $token;
        }
        return $limpio;
    }

    /* ================= secretos ================= */

    public function testNingunLogAuditRecibeContrasenasTokensNiHashes(): void
    {
        $prohibidas = '/\$(?:_POST\[\s*[\'"](?:password|contrasena|nueva_contrasena)[\'"]\s*\]|password\w*|contrasena\w*|temppassword|temphash|passwordhash|tokenhash|hash|otp\w*|code|codigo_hash)\b/i';
        $hallazgos = [];

        foreach ($this->archivosPhp(['api', 'views', 'core', 'scripts']) as $archivo) {
            $codigo = $this->sinComentarios((string) file_get_contents($archivo));
            if (!preg_match_all('/logAudit(?:Cambios)?\((.*?)\);/s', $codigo, $llamadas)) {
                continue;
            }
            foreach ($llamadas[1] as $argumentos) {
                // Solo interesa el CODIGO de los argumentos, no el texto de las cadenas ('Reset manual de contraseña').
                $sinCadenas = preg_replace('/\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*"/s', "''", $argumentos);
                if (preg_match($prohibidas, (string) $sinCadenas, $m)) {
                    $hallazgos[] = basename($archivo) . ': ' . $m[0];
                }
            }
        }

        $this->assertSame([], $hallazgos, 'logAudit() recibe una variable que parece secreto: ' . implode(' | ', $hallazgos));
    }

    /* ================= acciones ================= */

    /** @return array<string,string> accion => archivo */
    private function accionesRegistradas(): array
    {
        $acciones = [];
        foreach ($this->archivosPhp(['api', 'views', 'core', 'scripts']) as $archivo) {
            $codigo = $this->sinComentarios((string) file_get_contents($archivo));
            if (preg_match_all("/logAudit(?:Cambios)?\\(\\s*'([A-Za-z_]+)'/", $codigo, $m)) {
                foreach ($m[1] as $accion) {
                    $acciones[$accion] = basename($archivo);
                }
            }
        }
        return $acciones;
    }

    public function testSeEncontraronAccionesYTodasCabenEnLaColumna(): void
    {
        $acciones = $this->accionesRegistradas();
        $this->assertGreaterThan(40, count($acciones), 'el escaneo deberia encontrar decenas de acciones (si no, la regex se rompio)');

        $largas = array_filter(array_keys($acciones), static fn(string $a): bool => strlen($a) > AUDIT_MAX_ACCION);
        $this->assertSame([], array_values($largas), 'acciones mas largas que VARCHAR(50): se truncarian');
    }

    public function testCadaAccionRegistradaEnElCodigoTieneEtiquetaEnMayusculas(): void
    {
        foreach ($this->accionesRegistradas() as $accion => $archivo) {
            $this->assertTrue(auditAccionTieneEtiqueta($accion), "$accion ($archivo) sin etiqueta");
        }
    }

    public function testAccionesNuevasSiguenLaConvencionEnMayusculas(): void
    {
        $legadas = ['crear', 'cancelar', 'actualizar', 'importar', 'recibir']; // anteriores a la auditoria; siguen validas
        foreach ($this->accionesRegistradas() as $accion => $archivo) {
            if (in_array($accion, $legadas, true)) {
                continue;
            }
            $this->assertMatchesRegularExpression('/^[A-Z][A-Z0-9_]+$/', $accion, "$accion ($archivo) debe ir en MAYUSCULAS_CON_GUION_BAJO");
        }
    }

    public function testLosNombresDeTablaDeLogAuditCabenEnLaColumna(): void
    {
        $largas = [];
        foreach ($this->archivosPhp(['api', 'views', 'core', 'scripts']) as $archivo) {
            $codigo = $this->sinComentarios((string) file_get_contents($archivo));
            if (preg_match_all("/logAudit(?:Cambios)?\\(\\s*(?:'[A-Za-z_]+'|\\\$\\w+)\\s*,\\s*'([A-Za-z_]+)'/", $codigo, $m)) {
                foreach ($m[1] as $tabla) {
                    if (strlen($tabla) > AUDIT_MAX_TABLA) {
                        $largas[] = $tabla;
                    }
                }
            }
        }
        $this->assertSame([], $largas);
    }

    /* ================= endpoints excluidos ================= */

    public function testTodoEndpointExcluidoDelRegistroExisteDeVerdad(): void
    {
        $existentes = [];
        foreach ($this->archivosPhp(['api', 'views']) as $archivo) {
            $existentes[strtolower(basename($archivo))] = true;
        }

        $fantasmas = array_values(array_filter(AUDIT_ENDPOINTS_SIN_REGISTRO, static fn(string $e): bool => !isset($existentes[$e])));
        $this->assertSame([], $fantasmas, 'entradas de AUDIT_ENDPOINTS_SIN_REGISTRO que no corresponden a ningun archivo (typo o archivo borrado): ' . implode(', ', $fantasmas));
    }

    public function testLosEndpointsExcluidosNoTienenDuplicados(): void
    {
        $this->assertSame(count(AUDIT_ENDPOINTS_SIN_REGISTRO), count(array_unique(AUDIT_ENDPOINTS_SIN_REGISTRO)));
        foreach (AUDIT_ENDPOINTS_SIN_REGISTRO as $endpoint) {
            $this->assertSame(strtolower($endpoint), $endpoint, 'en minusculas: la comparacion usa strtolower');
        }
    }

    public function testEndpointsQueEditanProductosOClientesNoEstanExcluidos(): void
    {
        foreach (['products_manager.php', 'products.php', 'ventas.php', 'lotes_manager.php', 'users_handler.php', 'transfer_stock.php', 'update_thresholds.php', 'create_customer.php'] as $critico) {
            $this->assertFalse(auditEndpointSinRegistro('/api/' . $critico), "$critico jamas debe escapar del registro");
        }
    }

    /* ================= acciones de lectura vs escritura ================= */

    public function testNingunaAccionDeEscrituraDelSitioSeClasificaComoLectura(): void
    {
        $verbosDeEscritura = '/(save|guardar|delete|eliminar|borrar|crear|create|update|actualizar|cambiar|asignar|marcar|cancel|transfer|send|enviar|reset|toggle|set_|add|agregar|remove|quitar|import|commit|confirm|liberar|poner|ajustar|procesar|activar|desactivar|bloquear|desbloquear|publicar|aprobar|rechazar|recibir|reiniciar|start|close|merge|apply|aplicar|insert|register|registrar|login|logout)/i';

        $acciones = [];
        foreach ($this->archivosPhp(['api', 'views']) as $archivo) {
            $codigo = $this->sinComentarios((string) file_get_contents($archivo));
            if (preg_match_all("/(?:\\\$accion|\\\$action|\\['accion'\\]|\\['action'\\])[^=!<>]{0,40}={2,3}\\s*'([a-z0-9_]+)'/i", $codigo, $m)) {
                foreach ($m[1] as $accion) {
                    $acciones[$accion] = basename($archivo);
                }
            }
            if (preg_match_all("/case\\s+'([a-z0-9_]+)'\\s*:/i", $codigo, $m)) {
                foreach ($m[1] as $accion) {
                    $acciones[$accion] = basename($archivo);
                }
            }
        }

        $this->assertGreaterThan(30, count($acciones), 'el escaneo de acciones del sitio se rompio');

        $mal = [];
        foreach ($acciones as $accion => $archivo) {
            if (auditAccionEsLectura($accion) && preg_match($verbosDeEscritura, $accion)) {
                $mal[] = "$accion ($archivo)";
            }
        }
        $this->assertSame([], $mal, 'acciones que ESCRIBEN pero se clasifican como lectura (saldrian del registro): ' . implode(', ', $mal));
    }

    /* ================= columnas y migracion ================= */

    /** SQL de la migracion SIN comentarios (el texto explicativo menciona ADD COLUMN, DROP, etc.). */
    private function migracion(): string
    {
        $archivos = glob(self::$raiz . '/database/migrations/*amplia_logs_auditoria_para_trazabilidad.sql') ?: [];
        $this->assertCount(1, $archivos);
        return (string) preg_replace('/^\s*--.*$/m', '', (string) file_get_contents($archivos[0]));
    }

    /** @return string[] */
    private function columnasQueCreaLaMigracion(): array
    {
        preg_match_all('/ADD COLUMN (\w+)/', $this->migracion(), $m);
        return $m[1];
    }

    public function testLasColumnasDelInsertDeLogAuditExistenEnLaMigracion(): void
    {
        $auth = $this->leer('core/auth.php');
        $this->assertSame(1, preg_match('/INSERT INTO logs_auditoria\s*\(([^)]+)\)\s*VALUES \(([^)]+)\)/', $auth, $m));

        $columnas = array_map('trim', explode(',', $m[1]));
        $marcadores = array_map('trim', explode(',', $m[2]));
        $this->assertCount(count($columnas), $marcadores, 'una columna sin su ? (o al reves): el INSERT fallaria');

        $base = ['id_usuario', 'accion', 'tabla_afectada', 'id_registro', 'detalles', 'ip_address'];
        $creadas = $this->columnasQueCreaLaMigracion();
        foreach (array_diff($columnas, $base) as $columna) {
            $this->assertContains($columna, $creadas, "logAudit inserta '$columna' pero la migracion no la crea: el deploy tronaria");
        }
    }

    public function testLaVistaSoloPideColumnasQueLaMigracionCrea(): void
    {
        $vista = $this->leer('views/activity_logs.php');
        $this->assertSame(1, preg_match("/foreach \(\[([^\]]+)\] as \\\$c\)/", $vista, $m));
        preg_match_all("/'(\w+)'/", $m[1], $cols);

        $creadas = $this->columnasQueCreaLaMigracion();
        foreach ($cols[1] as $columna) {
            $this->assertContains($columna, $creadas, "la vista pide '$columna' que la migracion no crea");
        }
    }

    public function testLaMigracionNoBorraNiRenombraNiCambiaTiposDeNada(): void
    {
        $sql = $this->migracion();

        foreach (['DROP ', 'TRUNCATE', 'DELETE FROM', 'RENAME', 'MODIFY', 'CHANGE COLUMN', 'ALTER TABLE logs_actividad'] as $peligroso) {
            $this->assertStringNotContainsStringIgnoringCase($peligroso, (string) preg_replace('/DEALLOCATE PREPARE/i', '', (string) $sql), "la migracion no debe contener $peligroso");
        }
    }

    public function testLosUnicosUpdateDeLaMigracionSoloTocanSeveridad(): void
    {
        $sql = $this->migracion();
        $this->assertGreaterThan(0, preg_match_all('/UPDATE\s+\w+\s+SET\s+(\w+)/i', $sql, $m));
        foreach ($m[1] as $columna) {
            $this->assertSame('severidad', strtolower($columna), 'un UPDATE de la migracion toca ' . $columna);
        }
        // Y siempre acotados a filas que siguen en 'info' (idempotentes: una 2a corrida no pisa nada).
        $this->assertSame(substr_count(strtoupper($sql), 'UPDATE LOGS_AUDITORIA'), substr_count($sql, "WHERE severidad = 'info'"));
    }

    public function testCadaAlterDeLaMigracionEstaProtegidoPorSuConsultaAInformationSchema(): void
    {
        $sql = $this->migracion();

        $this->assertSame(substr_count($sql, 'ADD COLUMN'), substr_count($sql, 'INTO @has_col'), 'todo ADD COLUMN va guardado');
        $this->assertSame(substr_count($sql, 'ADD INDEX'), substr_count($sql, 'INTO @has_idx'), 'todo ADD INDEX va guardado');
        $this->assertSame(substr_count($sql, 'PREPARE stmt FROM'), substr_count($sql, 'EXECUTE stmt;'));
        $this->assertSame(substr_count($sql, 'PREPARE stmt FROM'), substr_count($sql, 'DEALLOCATE PREPARE stmt;'), 'sentencia preparada sin liberar');
    }

    public function testLasColumnasNuevasSonNulasOTienenDefaultParaNoRomperInsertsAnteriores(): void
    {
        $sql = $this->migracion();
        preg_match_all('/ADD COLUMN (\w+) ([^\']+?)[\',]/', $sql, $m, PREG_SET_ORDER);
        $this->assertNotEmpty($m);

        foreach ($m as [$_, $columna, $definicion]) {
            $this->assertMatchesRegularExpression('/(NULL|DEFAULT)/i', $definicion, "$columna: sin NULL ni DEFAULT, los INSERT antiguos (formato legado) fallarian");
        }
    }

    public function testLaMigracionCubreLosIndicesQueUsaLaVista(): void
    {
        $sql = $this->migracion();
        foreach (['(id_usuario, fecha)', '(accion, fecha)', '(tabla_afectada, id_registro)', '(fecha)'] as $indice) {
            $this->assertStringContainsString($indice, $sql, "falta el indice $indice");
        }
    }

    /* ================= orden de las operaciones ================= */

    public function testProductsPhpVerificaSesionAntesDeTocarArchivosOBaseDeDatos(): void
    {
        $codigo = $this->leer('api/products.php');
        $auth = strpos($codigo, 'isAuthenticated()');
        $this->assertNotFalse($auth, 'api/products.php debe verificar sesion');

        foreach (['move_uploaded_file', 'beginTransaction', 'getPDO()', 'UPDATE productos'] as $operacion) {
            $pos = strpos($codigo, $operacion);
            $this->assertNotFalse($pos, $operacion);
            $this->assertLessThan($pos, $auth, "la sesion debe verificarse ANTES de $operacion");
        }
        $this->assertStringContainsString("hasPermission('gestionar_productos')", $codigo);
        $this->assertStringContainsString('403', $codigo);
    }

    public function testLogoutRegistraAntesDeVaciarLaSesion(): void
    {
        $auth = $this->leer('core/auth.php');
        $inicio = strpos($auth, 'function logout(): void');
        $this->assertNotFalse($inicio);
        $cuerpo = substr($auth, $inicio, 1500);

        $this->assertNotFalse(strpos($cuerpo, "logAudit('LOGOUT'"));
        $this->assertLessThan(strpos($cuerpo, '$_SESSION = [];'), strpos($cuerpo, "logAudit('LOGOUT'"), 'con la sesion ya vacia el log quedaria anonimo');
    }

    public function testRequirePermissionRegistraElAccesoDenegadoAntesDeRedirigir(): void
    {
        $auth = $this->leer('core/auth.php');
        $inicio = strpos($auth, 'function requirePermission(');
        $this->assertNotFalse($inicio);
        $cuerpo = substr($auth, $inicio, 1200);

        $this->assertNotFalse(strpos($cuerpo, 'auditAccesoDenegado('));
        $this->assertLessThan(strpos($cuerpo, 'exit;'), strpos($cuerpo, 'auditAccesoDenegado('), 'exit corta la ejecucion: el log debe ir antes');
        $this->assertNotFalse(strpos($cuerpo, 'isAuthenticated()'), 'un visitante sin sesion no es un "acceso denegado"');
    }

    public function testAuthenticateRegistraTodasLasRamasDeFallo(): void
    {
        $auth = $this->leer('core/auth.php');
        $inicio = strpos($auth, 'function authenticate(');
        $fin = strpos($auth, "\nfunction logout(", $inicio);
        $cuerpo = substr($auth, $inicio, $fin - $inicio);

        // cuenta inexistente/inactiva, cuenta bloqueada y contrasena incorrecta.
        $this->assertGreaterThanOrEqual(3, preg_match_all("/logAudit\(\s*'LOGIN_FALLIDO'/", $cuerpo));
        $this->assertStringContainsString("'LOGIN_EXITOSO'", $cuerpo);
    }

    public function testElRegistroDeFalloDeLoginNoGuardaLaContrasenaIntentada(): void
    {
        $auth = $this->leer('core/auth.php');
        $inicio = strpos($auth, 'function authenticate(');
        $fin = strpos($auth, "\nfunction logout(", $inicio);
        $cuerpo = $this->sinComentarios(substr($auth, $inicio, $fin - $inicio));

        preg_match_all("/logAudit\\('LOGIN_FALLIDO'.*?\\]\\);/s", $cuerpo, $m);
        $this->assertNotEmpty($m[0]);
        foreach ($m[0] as $llamada) {
            $this->assertStringNotContainsString('$password', $llamada);
        }
    }

    /* ================= la vista ================= */

    public function testLaVistaNoLeeGetCrudoParaLosFiltros(): void
    {
        $vista = $this->sinComentarios($this->leer('views/activity_logs.php'));

        $this->assertSame(1, substr_count($vista, '$_GET'), '$_GET solo debe aparecer en la llamada a auditFiltrosDesdeGet()');
        $this->assertStringContainsString('auditFiltrosDesdeGet($_GET)', $vista);
    }

    public function testLaVistaNoInterpolaFiltrosCrudosEnElSql(): void
    {
        $vista = $this->sinComentarios($this->leer('views/activity_logs.php'));

        // Los filtros textuales entran siempre por parametros enlazados (:nombre), nunca dentro del SQL.
        foreach (['filtro_q', 'filtro_accion', 'filtro_modulo', 'filtro_tabla', 'filtro_severidad'] as $filtro) {
            $this->assertDoesNotMatchRegularExpression('/\$where\s*\.?=\s*"[^"]*\{\$' . $filtro . '\}/', $vista, "$filtro interpolado en el SQL");
            $this->assertDoesNotMatchRegularExpression('/\$where\s*\.?=\s*\'[^\']*\'\s*\.\s*\$' . $filtro . '\b/', $vista, "$filtro concatenado al SQL");
        }
        // Lo unico que se interpola son enteros ya casteados (LIMIT/OFFSET).
        $this->assertStringContainsString('LIMIT {$porPagina} OFFSET {$offset}', $vista);
        $this->assertStringContainsString('$offset = ($pagina - 1) * $porPagina;', $vista);
    }

    public function testLaVistaNuncaImprimeSinEscaparUnaFilaDeLaBaseDeDatos(): void
    {
        $vista = $this->leer('views/activity_logs.php');

        // Toda impresion directa de una fila de la BD ($m = movimiento, $log = visita, $ru = resumen, $u = usuario)
        // debe pasar por esc(): un detalle con <script> o un nombre con comillas no debe ejecutarse ni romper el HTML.
        preg_match_all('/<\?php echo (\$(?:m|log|ru|u)\[[^;]*?);? \?>/', $vista, $m);

        $sinEscape = [];
        foreach ($m[1] as $expresion) {
            $esCondicionalDeClaseCss = str_contains($expresion, '===') || str_contains($expresion, '?');
            $esIdNumerico = str_contains($expresion, "['id_usuario']");
            if (!$esCondicionalDeClaseCss && !$esIdNumerico) {
                $sinEscape[] = $expresion;
            }
        }

        $this->assertSame([], $sinEscape, 'impresiones de datos de la BD sin esc(): ' . implode(' | ', $sinEscape));
        $this->assertGreaterThan(10, substr_count($vista, 'esc('), 'la vista debe escapar a menudo (si no, el escaneo no esta viendo nada)');
    }
}
