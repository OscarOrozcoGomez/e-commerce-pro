<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * api/ai_assistant_admin.php: el guard es por accion desde que se agrego
 * 'dar_feedback_asistente_ia' -- create_learning_rule acepta esa clave angosta ademas de
 * 'gestionar_asistente_ia'/admin, pero el resto de las acciones (toggle_bot_global,
 * reactivate_bot, add_tag, remove_tag, resolve_diagnostic_error, toggle_learning_rule)
 * siguen exigiendo solo 'gestionar_asistente_ia'/admin, como antes.
 *
 * Prueba de codigo fuente (no ejecuta el endpoint ni toca BD/sesion), mismo enfoque que
 * PermisoConversacionesWhatsappPorRolTest::testElGuardDeLaVistaEsSoloClaveMasAdmin.
 */
final class ApiAiAssistantAdminFeedbackGuardTest extends TestCase
{
    private function fuente(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/api/ai_assistant_admin.php');
    }

    public function testCreateLearningRuleAceptaLaClaveAngostaAdemasDeGestionarAsistente(): void
    {
        $src = $this->fuente();

        $this->assertMatchesRegularExpression(
            "/\\\$action === 'create_learning_rule'\s*\n\s*\? \(hasPermission\('gestionar_asistente_ia'\) \|\| hasPermission\('dar_feedback_asistente_ia'\) \|\| isAdmin\(\)\)/",
            $src,
            "create_learning_rule debe autorizar con gestionar_asistente_ia O dar_feedback_asistente_ia O admin."
        );
    }

    public function testLasDemasAccionesSiguenExigiendoSoloGestionarAsistente(): void
    {
        $src = $this->fuente();

        $this->assertMatchesRegularExpression(
            "/: \(hasPermission\('gestionar_asistente_ia'\) \|\| isAdmin\(\)\);/",
            $src,
            "Las acciones distintas de create_learning_rule deben seguir exigiendo solo gestionar_asistente_ia/admin."
        );
    }
}
