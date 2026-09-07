<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * views/alex_insights.php: el candado es
 *   hasPermission('ver_insights_ia') || isAdmin()
 * -- el encargado YA NO entra por rol (antes tenia el fallback !isEncargado(),
 * heredado del guard original de la vista, inconsistente con ai_assistant_settings.php
 * y ai_diagnostics.php, que son 'gestionar_asistente_ia' || isAdmin()).
 *
 * Regresion: que nadie vuelva a meter !isEncargado() aqui. Prueba de la
 * expresion booleana (no renderiza la vista); mismo enfoque que
 * PermisoCaducidadesPorRolTest.
 */
final class PermisoAlexInsightsPorRolTest extends TestCase
{
    private const CLAVE = 'ver_insights_ia';

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
        $_SESSION['usuario'] = ['id_usuario' => 7, 'rol' => $rol, 'permisos' => $permisos];
    }

    /** Candado de views/alex_insights.php (requireAuth() ya paso). */
    private function puedeEntrar(): bool
    {
        return hasPermission(self::CLAVE) || isAdmin();
    }

    public function testAdminEntraSiempre(): void
    {
        $this->sesionComo('admin', []);
        $this->assertTrue($this->puedeEntrar());
    }

    public function testEncargadoSinElPermisoNoEntra(): void
    {
        $this->sesionComo('encargado', []);
        $this->assertFalse($this->puedeEntrar(), 'el encargado ya no entra a Alex Insights solo por su rol');
    }

    public function testEncargadoConElPermisoConcedidoSiEntra(): void
    {
        $this->sesionComo('encargado', [self::CLAVE]);
        $this->assertTrue($this->puedeEntrar(), 'concederle ver_insights_ia al encargado sí lo deja entrar');

        // y al revocarlo, vuelve a quedar fuera
        $_SESSION['usuario']['permisos'] = [];
        $this->assertFalse($this->puedeEntrar());
    }

    /**
     * @dataProvider rolesOperativos
     */
    public function testRolesOperativosNoEntranSinElPermiso(string $rol): void
    {
        $this->sesionComo($rol, ['realizar_ventas', 'inventario', 'ver_entregas']);
        $this->assertFalse($this->puedeEntrar(), "$rol con otros permisos pero sin ver_insights_ia no entra");
    }

    public function testElGuardDeLaVistaNoVuelveAUsarIsEncargado(): void
    {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/views/alex_insights.php');
        // Aisla la condicion del candado (primer if tras requireAuth()).
        $this->assertMatchesRegularExpression(
            "/requireAuth\(\);.*?if \(!hasPermission\('ver_insights_ia'\) && !isAdmin\(\)\) \{/s",
            $src,
            'El candado de alex_insights.php debe ser exactamente ver_insights_ia || isAdmin() (sin isEncargado()).'
        );
        // Y explicitamente: no aparece isEncargado en el archivo.
        $this->assertStringNotContainsString('isEncargado', $src,
            'alex_insights.php no debe volver a referirse a isEncargado()');
    }

    public static function rolesOperativos(): array
    {
        return ['vendedor' => ['vendedor'], 'repartidor' => ['repartidor'], 'cliente' => ['cliente']];
    }
}
