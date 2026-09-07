<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * views/whatsapp_contactos.php: vista con PII de clientes (hilos de WhatsApp).
 * Candado = hasPermission('ver_conversaciones_whatsapp') || isAdmin(), y nada mas:
 *   - sin fallback de rol (!isEncargado(), etc.),
 *   - sin el fallback a 'gestionar_asistente_ia' que traia PR #144.
 * Como 'ver_conversaciones_whatsapp' no se asigna a ningun rol en la migracion,
 * la vista es admin-only por defecto y el admin decide a quien concedersela.
 *
 * Prueba de la expresion booleana (no renderiza la vista); mismo enfoque que
 * PermisoAlexInsightsPorRolTest / PermisoCaducidadesPorRolTest.
 */
final class PermisoConversacionesWhatsappPorRolTest extends TestCase
{
    private const CLAVE = 'ver_conversaciones_whatsapp';

    private array $originalSession = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalSession = $_SESSION ?? [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->originalSession;
        parent::tearDown();
    }

    private function sesionComo(string $rol, array $permisos = []): void
    {
        $_SESSION['usuario'] = ['id_usuario' => 9, 'rol' => $rol, 'permisos' => $permisos];
    }

    /** Candado de views/whatsapp_contactos.php (requireAuth() ya paso). */
    private function puedeEntrar(): bool
    {
        return hasPermission(self::CLAVE) || isAdmin();
    }

    public function testAdminEntraSiempreAunSinLaClaveEnElArray(): void
    {
        $this->sesionComo('admin', []);
        $this->assertTrue($this->puedeEntrar());
    }

    /**
     * @dataProvider rolesNoAdmin
     */
    public function testNingunRolNoAdminEntraSinLaClave(string $rol): void
    {
        $this->sesionComo($rol, []);
        $this->assertFalse($this->puedeEntrar(), "$rol no entra a Conversaciones WhatsApp sin 'ver_conversaciones_whatsapp'");
    }

    /**
     * @dataProvider rolesNoAdmin
     */
    public function testTenerGestionarAsistenteIaYaNoAbreLaVista(string $rol): void
    {
        // Regresion del cambio: antes 'gestionar_asistente_ia' era fallback y colaba aqui.
        $this->sesionComo($rol, ['gestionar_asistente_ia']);
        $this->assertFalse($this->puedeEntrar(), "'gestionar_asistente_ia' ya no debe abrir Conversaciones WhatsApp");
    }

    /**
     * @dataProvider rolesNoAdmin
     */
    public function testConcederLaClaveAbreYRevocarlaCierra(string $rol): void
    {
        $this->sesionComo($rol, [self::CLAVE]);
        $this->assertTrue($this->puedeEntrar(), "al concederle '" . self::CLAVE . "', $rol entra");

        $_SESSION['usuario']['permisos'] = [];
        $this->assertFalse($this->puedeEntrar(), "al revocarla, $rol vuelve a quedar fuera");
    }

    public function testElGuardDeLaVistaEsSoloClaveMasAdmin(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/views/whatsapp_contactos.php');
        $this->assertMatchesRegularExpression(
            "/requireAuth\(\);.*?if \(!hasPermission\('ver_conversaciones_whatsapp'\) && !isAdmin\(\)\) \{/s",
            $src,
            "El candado de whatsapp_contactos.php debe ser exactamente ver_conversaciones_whatsapp || isAdmin()."
        );
        $this->assertStringNotContainsString("hasPermission('gestionar_asistente_ia')", $src,
            'whatsapp_contactos.php no debe volver a usar gestionar_asistente_ia como fallback');
        $this->assertStringNotContainsString('isEncargado(', $src,
            'whatsapp_contactos.php no debe usar isEncargado() como fallback');
    }

    public static function rolesNoAdmin(): array
    {
        return [
            'encargado' => ['encargado'],
            'vendedor' => ['vendedor'],
            'repartidor' => ['repartidor'],
            'cliente' => ['cliente'],
        ];
    }
}
