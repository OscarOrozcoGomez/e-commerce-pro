<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Prueba NEGATIVA de contrato: una peticion NO autorizada a los endpoints JSON
 * tocados en esta sesion debe responder SIEMPRE JSON valido -- nunca HTML.
 *
 * Regresion directa del bug "Unexpected token '<', ..." que se veia cuando un
 * fatal (\Error/\TypeError) se escapaba del catch (Exception) y el
 * set_exception_handler global respondia con un redirect HTML a views/error.php.
 *
 * Se ejecuta el endpoint con el binario PHP en un proceso aparte, sin sesion.
 * Al no haber usuario autenticado cada endpoint entra por su guardia de auth y
 * emite su JSON de "no autorizado" -- lo que confirma: (a) el archivo compila,
 * (b) la guardia responde JSON, (c) el cuerpo no empieza con '<'.
 */
final class ApiJsonContractNegativeTest extends TestCase
{
    /** @var array<string,string> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['REQUEST_METHOD', 'HTTP_HOST', 'REMOTE_ADDR', 'REQUEST_URI'] as $k) {
            $this->savedEnv[$k] = getenv($k) === false ? '' : (string) getenv($k);
        }
        putenv('REQUEST_METHOD=GET');
        putenv('HTTP_HOST=localhost');
        putenv('REMOTE_ADDR=203.0.113.9'); // IP publica -> NO activa el bypass $isLocalCron
        putenv('REQUEST_URI=/api/test');
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $k => $v) {
            if ($v === '') {
                putenv($k);
            } else {
                putenv("$k=$v");
            }
        }
        parent::tearDown();
    }

    /**
     * @dataProvider jsonEndpoints
     */
    public function testUnauthenticatedRequestReturnsJsonNeverHtml(string $relPath): void
    {
        if (!function_exists('shell_exec') || in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            $this->markTestSkipped('shell_exec no disponible');
        }

        $file = dirname(__DIR__, 2) . '/' . $relPath;
        $this->assertFileExists($file);

        $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=0 ' . escapeshellarg($file) . ' 2>&1';
        $out = shell_exec($cmd);

        $this->assertNotNull($out, "sin salida de $relPath");
        $trimmed = ltrim((string) $out);
        $this->assertNotSame('', $trimmed, "$relPath devolvio cuerpo vacio");

        // Lo esencial: nunca HTML.
        $this->assertStringStartsNotWith('<', $trimmed, "$relPath devolvio HTML: " . substr($trimmed, 0, 120));
        $this->assertStringNotContainsString('<!DOCTYPE', $trimmed, "$relPath contiene HTML");
        $this->assertStringNotContainsString('<br', $trimmed, "$relPath contiene warning HTML de PHP");

        // Debe ser JSON parseable con success=false.
        $decoded = json_decode($trimmed, true);
        $this->assertIsArray($decoded, "$relPath no devolvio JSON: " . substr($trimmed, 0, 200));
        $this->assertArrayHasKey('success', $decoded);
        $this->assertFalse($decoded['success'], "$relPath deberia negar acceso sin sesion");
    }

    /**
     * Todo endpoint bajo api/ que decide con hasPermission() (grep -l "hasPermission("
     * api/*.php) debe estar aqui. Antes de agregar uno confirma que su guardia usa
     * isAuthenticated()/hasPermission() -- no requireAuth(), que sin sesion redirige a
     * login.php en vez de devolver JSON (bug real que este test encontro y se corrigio
     * en api/ventas.php, api/create_customer.php, api/update_customer_phone.php y
     * api/cleanup_reservations.php).
     *
     * @return array<string,array{0:string}>
     */
    public static function jsonEndpoints(): array
    {
        return [
            'purchase_orders_data'   => ['api/purchase_orders_data.php'],
            'analytics_data'         => ['api/analytics_data.php'],
            'batch_inbound'          => ['api/batch_inbound.php'],
            'update_thresholds'      => ['api/update_thresholds.php'],
            'inventory_handler'      => ['api/inventory_handler.php'],
            'transfer_stock'         => ['api/transfer_stock.php'],
            'ventas'                 => ['api/ventas.php'],
            'postpone_purchase_items'=> ['api/postpone_purchase_items.php'],
            'purchase_order_create'  => ['api/purchase_order_create.php'],
            'purchase_order_receive' => ['api/purchase_order_receive.php'],
            'purchase_order_cancel'  => ['api/purchase_order_cancel.php'],
            'purchase_orders_open'   => ['api/purchase_orders_open.php'],
            'postponed_items_data'   => ['api/postponed_items_data.php'],
            'postpone_reactivate'    => ['api/postpone_reactivate.php'],
            'products_manager'       => ['api/products_manager.php'],
            'ai_assistant_admin'     => ['api/ai_assistant_admin.php'],
            'create_customer'        => ['api/create_customer.php'],
            'update_customer_phone'  => ['api/update_customer_phone.php'],
            'cleanup_reservations'   => ['api/cleanup_reservations.php'],
            'process_inbound'        => ['api/process_inbound.php'],
            'entrega_publicacion'    => ['api/entrega_publicacion.php'],
            'optimize_delivery_route'=> ['api/optimize_delivery_route.php'],
        ];
    }
}
