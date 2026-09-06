<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * sessionRoleIsInternal() (core/site_behavior.php) decide si una fila de
 * logs_actividad se sella como es_interno = 1 al registrarla. La usa
 * api/log_activity.php con $_SESSION['usuario']['rol'] (o null si la sesion es
 * anonima). Regla: interno = cualquier rol de staff; NO interno = anonimo o cliente.
 */
final class SessionRoleIsInternalTest extends TestCase
{
    /** @return array<string, array{0: ?string}> */
    public static function staffRoles(): array
    {
        return [
            'admin' => ['admin'],
            'encargado' => ['encargado'],
            'vendedor' => ['vendedor'],
            'repartidor' => ['repartidor'],
            'rol desconocido de staff' => ['soporte'],
        ];
    }

    /**
     * @dataProvider staffRoles
     */
    public function testStaffRolesCountAsInternal(?string $rol): void
    {
        $this->assertTrue(sessionRoleIsInternal($rol));
    }

    public function testClienteIsNotInternal(): void
    {
        $this->assertFalse(sessionRoleIsInternal('cliente'));
    }

    public function testAnonymousSessionIsNotInternal(): void
    {
        $this->assertFalse(sessionRoleIsInternal(null));
        $this->assertFalse(sessionRoleIsInternal(''));
        $this->assertFalse(sessionRoleIsInternal('   '));
    }

    public function testRoleMatchingIsCaseAndWhitespaceInsensitive(): void
    {
        $this->assertFalse(sessionRoleIsInternal(' Cliente '));
        $this->assertFalse(sessionRoleIsInternal('CLIENTE'));
        $this->assertTrue(sessionRoleIsInternal(' Admin '));
    }
}
