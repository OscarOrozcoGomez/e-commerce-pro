<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Contrato de seguridad de api/: lo que NO debe volver a salir sin proteccion.
 *
 *  1. Todo endpoint que ESCRIBE con sesion lleva CSRF (o un token de cabecera servidor-a-servidor).
 *     Un endpoint nuevo que no lo cumpla hace fallar esta prueba, salvo que se justifique en
 *     EXCEPCIONES_CSRF.
 *  2. Los endpoints publicos llevan limite de peticiones por IP.
 *  3. Las pantallas que llaman a los endpoints protegidos SI mandan el token (si no, la pantalla
 *     se rompe en silencio con un 403).
 */
final class ApiSecurityContractTest extends TestCase
{
    /** Endpoints con escritura y sesion que NO llevan CSRF, y por que. */
    private const EXCEPCIONES_CSRF = [
        'log_activity.php' => 'telemetria publica y anonima de visitas: no hay sesion que falsificar; se protege con limite de peticiones',
    ];

    private function leer(string $ruta): string
    {
        $contenido = file_get_contents(dirname(__DIR__, 2) . '/' . $ruta);
        $this->assertNotFalse($contenido, "No se pudo leer {$ruta}");

        return (string) $contenido;
    }

    /** @return list<string> nombres de archivo de api/ */
    private function endpoints(): array
    {
        $archivos = glob(dirname(__DIR__, 2) . '/api/*.php') ?: [];
        $this->assertNotEmpty($archivos);

        return array_map('basename', $archivos);
    }

    private function escribe(string $codigo): bool
    {
        return preg_match('/INSERT\s+INTO|UPDATE\s+[`\w]+\s+SET|DELETE\s+FROM/i', $codigo) === 1;
    }

    private function usaSesion(string $codigo): bool
    {
        return preg_match('/requireAuth\(|isAuthenticated\(|requirePermission\(|isAdmin\(|isCliente\(|hasPermission\(/', $codigo) === 1;
    }

    private function tieneProteccionCsrf(string $codigo): bool
    {
        return preg_match('/validateCsrfToken|apiRequerirCsrf\(|csrf_token|X-Webhook-Token|X-Migrations-Token/', $codigo) === 1;
    }

    // ------------------------------------------------------------ 1. CSRF en escrituras con sesion

    public function testTodoEndpointQueEscribeConSesionTieneCsrfOEstaJustificado(): void
    {
        $sinProteccion = [];
        foreach ($this->endpoints() as $archivo) {
            $codigo = $this->leer('api/' . $archivo);
            if ($this->usaSesion($codigo) && $this->escribe($codigo) && !$this->tieneProteccionCsrf($codigo) && !isset(self::EXCEPCIONES_CSRF[$archivo])) {
                $sinProteccion[] = $archivo;
            }
        }

        $this->assertSame(
            [],
            $sinProteccion,
            "Estos endpoints escriben con sesion y NO validan CSRF. Agrega apiRequerirCsrf() (core/api_security_utils.php)\n"
            . "y manda el token desde la pantalla (csrf_token o cabecera X-CSRF-Token), o justificalo en EXCEPCIONES_CSRF."
        );
    }

    public function testNingunEndpointPublicoEscribeSinSesionNiToken(): void
    {
        $publicosQueEscriben = [];
        foreach ($this->endpoints() as $archivo) {
            $codigo = $this->leer('api/' . $archivo);
            if (!$this->usaSesion($codigo) && !$this->tieneProteccionCsrf($codigo) && $this->escribe($codigo)) {
                $publicosQueEscriben[] = $archivo;
            }
        }

        $this->assertSame([], $publicosQueEscriben, 'Escribe en la BD sin sesion ni token de cabecera');
    }

    public function testLasExcepcionesSiguenExistiendoYSiguenSiendoNecesarias(): void
    {
        foreach (self::EXCEPCIONES_CSRF as $archivo => $motivo) {
            $codigo = $this->leer('api/' . $archivo);
            $this->assertNotSame('', $motivo);
            $this->assertFalse($this->tieneProteccionCsrf($codigo), "{$archivo} ya tiene CSRF: quitalo de EXCEPCIONES_CSRF");
        }
    }

    public function testLosEndpointsArreglados(): void
    {
        foreach (['favorites', 'process_inbound', 'cleanup_reservations', 'products', 'create_po', 'public_orders'] as $endpoint) {
            $codigo = $this->leer("api/{$endpoint}.php");
            $this->assertStringContainsString('apiRequerirCsrf(', $codigo, $endpoint);
            $this->assertStringContainsString("core/api_security_utils.php", $codigo, $endpoint);
        }
    }

    public function testCreatePoYaNoCreaOrdenesConUnGet(): void
    {
        $codigo = $this->leer('api/create_po.php');

        $this->assertStringContainsString("apiRequerirMetodo('POST')", $codigo);
        $this->assertLessThan(strpos($codigo, 'INSERT INTO ordenes_compra'), strpos($codigo, "apiRequerirMetodo('POST')"), 'El metodo se valida ANTES de escribir');
        $this->assertLessThan(strpos($codigo, 'INSERT INTO ordenes_compra'), strpos($codigo, 'apiRequerirCsrf('), 'El CSRF se valida ANTES de escribir');
    }

    public function testElCsrfSeValidaDespuesDeLaSesionYAntesDeEscribir(): void
    {
        foreach (['favorites' => 'INSERT', 'process_inbound' => 'beginTransaction', 'products' => 'INSERT', 'public_orders' => 'dbCreatePublicOrder('] as $endpoint => $primeraEscritura) {
            $codigo = $this->leer("api/{$endpoint}.php");
            $posCsrf = strpos($codigo, 'apiRequerirCsrf(');
            $posEscritura = strpos($codigo, $primeraEscritura, (int) $posCsrf);
            $this->assertNotFalse($posCsrf, $endpoint);
            $this->assertNotFalse($posEscritura, "{$endpoint}: hay una escritura despues del chequeo CSRF");
        }
    }

    public function testElCronLocalDeCleanupReservationsYaNoSeDecidePorLaIpSola(): void
    {
        $codigo = $this->leer('api/cleanup_reservations.php');

        $this->assertMatchesRegularExpression('/\$isLocalCron = apiEsCronLocal\(\$_SERVER, isAuthenticated\(\)\);/', $codigo, 'La decision del cron local se toma con apiEsCronLocal(), no en linea');
        $this->assertStringNotContainsString("=== '127.0.0.1'", $codigo, 'REMOTE_ADDR por si solo ya no basta para saltarse la sesion');
        $this->assertMatchesRegularExpression('/if \(!\$isLocalCron\) \{\s*apiRequerirCsrf\(\$payload\);/', $codigo, 'El cron local no lleva token; toda llamada con sesion si');
    }

    public function testPublicOrdersExigeCsrfSoloConSesionYNoBloqueaInvitados(): void
    {
        $codigo = $this->leer('api/public_orders.php');

        $this->assertMatchesRegularExpression('/if \(isAuthenticated\(\)\) \{\s*apiRequerirCsrf\(\$data\);/', $codigo);
    }

    public function testLosTokensDeAdministracionNoSeAceptanPorLaUrl(): void
    {
        $enUrl = [];
        foreach ($this->endpoints() as $archivo) {
            if (str_contains($this->leer('api/' . $archivo), "\$_GET['token']")) {
                $enUrl[] = $archivo;
            }
        }

        $this->assertSame([], $enUrl, 'Un token en ?token= queda en logs de acceso, historial y Referer: solo por cabecera');
    }

    public function testLosEndpointsDeAdministracionExigenPostYTokenPorCabecera(): void
    {
        foreach (['clear_secrets_cache', 'optimize_existing_images'] as $endpoint) {
            $codigo = $this->leer("api/{$endpoint}.php");
            $this->assertStringContainsString("'POST'", $codigo, $endpoint);
            $this->assertStringContainsString('HTTP_X_MIGRATIONS_TOKEN', $codigo, $endpoint);
            $this->assertStringContainsString('hash_equals(', $codigo, $endpoint);
        }
        $this->assertStringContainsString('hash_equals(', $this->leer('api/run_migrations.php'));
    }

    // ------------------------------------------------------------ 2. limite en endpoints publicos

    public function testLosEndpointsPublicosTienenLimiteDePeticiones(): void
    {
        $esperados = [
            'product_detail' => 240,
            'pickup_stock_check' => 120,
            'delivery_zone_quote' => 60,
            'log_activity' => 600,
            'public_orders' => 30,
        ];

        foreach ($esperados as $endpoint => $maximo) {
            $codigo = $this->leer("api/{$endpoint}.php");
            $this->assertMatchesRegularExpression("/apiLimitarPeticiones\('{$endpoint}', {$maximo}\)/", $codigo, $endpoint);
        }
    }

    public function testElLimiteSeAplicaAntesDeTocarLaBaseDeDatos(): void
    {
        $primeraLecturaDeDatos = [
            'product_detail' => 'getPDO()',
            'pickup_stock_check' => 'getPDO()',
            'delivery_zone_quote' => "json_decode(file_get_contents('php://input')",
        ];
        foreach ($primeraLecturaDeDatos as $endpoint => $marca) {
            $codigo = $this->leer("api/{$endpoint}.php");
            $this->assertNotFalse(strpos($codigo, $marca), "{$endpoint}: no aparece la marca {$marca}");
            $this->assertLessThan(strpos($codigo, $marca), strpos($codigo, 'apiLimitarPeticiones('), "{$endpoint}: el limite va antes de procesar la peticion");
        }
    }

    // ------------------------------------------------------------ 3. las pantallas mandan el token

    public function testLasPantallasQueLlamanALosEndpointsProtegidosMandanElToken(): void
    {
        $header = $this->leer('views/includes/header.php');
        $this->assertStringContainsString('FAVORITES_CSRF_TOKEN', $header);
        $this->assertStringContainsString("'X-CSRF-Token': FAVORITES_CSRF_TOKEN", $header);

        $this->assertStringContainsString('csrfInput()', $this->leer('views/process_inbound.php'), 'el formulario manda csrf_token');
        $this->assertStringContainsString('payload.csrf_token', $this->leer('views/cleanup_reservations.php'));
        // Las paginas de la raiz que tambien escriben en favorites.php deben mandar la cabecera.
        foreach (['favoritos.php', 'product_detail.php'] as $pagina) {
            $this->assertStringContainsString("'X-CSRF-Token': FAVORITES_CSRF_TOKEN", $this->leer($pagina), $pagina);
        }        $this->assertStringContainsString('csrf_token: <?php echo json_encode(getCsrfToken()); ?>', $this->leer('views/cart.php'));
    }

    public function testElFormularioDeProcessInboundLlevaElTokenDentroDelForm(): void
    {
        $vista = $this->leer('views/process_inbound.php');
        $form = strpos($vista, '<form id="form-process-inbound">');
        $token = strpos($vista, 'csrfInput()', (int) $form);
        $cierre = strpos($vista, '</form>', (int) $form);

        $this->assertNotFalse($form);
        $this->assertNotFalse($token);
        $this->assertLessThan($cierre, $token, 'FormData solo recoge los campos que estan DENTRO del form');
    }
}
