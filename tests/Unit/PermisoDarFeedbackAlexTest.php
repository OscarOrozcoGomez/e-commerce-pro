<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * canGiveAlexFeedback() (core/auth.php): gatea el boton "Dar feedback" en el hilo de una
 * conversacion de WhatsApp (views/whatsapp_contactos.php) y la accion create_learning_rule
 * de api/ai_assistant_admin.php.
 *
 * Es exactamente hasPermission('gestionar_asistente_ia') || hasPermission('dar_feedback_asistente_ia') || isAdmin():
 *   - 'dar_feedback_asistente_ia' es una clave propia y mas angosta, para quien solo lee
 *     conversaciones de WhatsApp pero no debe administrar todo el asistente;
 *   - 'gestionar_asistente_ia' sigue abriendo el boton, para no quitarle acceso a quien ya
 *     administra el asistente completo.
 *
 * Mismo enfoque que PermisoConversacionesWhatsappPorRolTest / PermisoAlexInsightsPorRolTest.
 */
final class PermisoDarFeedbackAlexTest extends TestCase
{
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

    public function testAdminSiemprePuedeAunSinLaClaveEnElArray(): void
    {
        $this->sesionComo('admin', []);
        $this->assertTrue(canGiveAlexFeedback());
    }

    /**
     * @dataProvider rolesNoAdmin
     */
    public function testNingunRolNoAdminPuedeSinNingunaClave(string $rol): void
    {
        $this->sesionComo($rol, []);
        $this->assertFalse(canGiveAlexFeedback(), "$rol no debe poder dar feedback sin ningun permiso");
    }

    /**
     * @dataProvider rolesNoAdmin
     */
    public function testLaClavePropiaAlcanzaSola(string $rol): void
    {
        // Sin 'gestionar_asistente_ia': la clave angosta basta por si sola.
        $this->sesionComo($rol, ['dar_feedback_asistente_ia']);
        $this->assertTrue(canGiveAlexFeedback(), "$rol con 'dar_feedback_asistente_ia' ya deberia poder dar feedback");
    }

    /**
     * @dataProvider rolesNoAdmin
     */
    public function testGestionarAsistenteIaSigueAlcanzandoSolo(string $rol): void
    {
        // Quien ya administra todo el asistente no pierde acceso al boton de feedback.
        $this->sesionComo($rol, ['gestionar_asistente_ia']);
        $this->assertTrue(canGiveAlexFeedback(), "$rol con 'gestionar_asistente_ia' ya deberia poder dar feedback");
    }

    /**
     * @dataProvider rolesNoAdmin
     */
    public function testRevocarAmbasClavesVuelveAQuitarElAcceso(string $rol): void
    {
        $this->sesionComo($rol, ['dar_feedback_asistente_ia']);
        $this->assertTrue(canGiveAlexFeedback());

        $_SESSION['usuario']['permisos'] = [];
        $this->assertFalse(canGiveAlexFeedback(), "al revocarla, $rol vuelve a quedar fuera");
    }

    public function testLaClaveEstaEnPermisosEnUso(): void
    {
        $this->assertContains(
            'dar_feedback_asistente_ia',
            PERMISOS_EN_USO,
            "'dar_feedback_asistente_ia' debe estar en PERMISOS_EN_USO (core/auth.php) o el panel de roles la marcaria sin efecto."
        );
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
