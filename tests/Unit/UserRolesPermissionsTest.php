<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class UserRolesPermissionsTest extends TestCase
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

    public function testIsAuthenticatedReturnsFalseWhenSessionIsEmpty(): void
    {
        $this->assertFalse(isAuthenticated());
    }

    public function testIsAuthenticatedReturnsTrueWhenUsuarioExists(): void
    {
        $_SESSION['usuario'] = [
            'id_usuario' => 10,
            'rol' => 'cliente',
            'permisos' => [],
        ];

        $this->assertTrue(isAuthenticated());
    }

    public function testRoleCheckersReturnFalseWithoutSession(): void
    {
        $this->assertFalse(isAdmin());
        $this->assertFalse(isEncargado());
        $this->assertFalse(isVendedor());
        $this->assertFalse(isRepartidor());
        $this->assertFalse(isCliente());
    }

    #[DataProvider('roleMatrixProvider')]
    public function testRoleCheckersMatrix(string $role, bool $isAdminExpected, bool $isEncargadoExpected, bool $isVendedorExpected, bool $isRepartidorExpected, bool $isClienteExpected): void
    {
        $_SESSION['usuario'] = ['rol' => $role];

        $this->assertSame($isAdminExpected, isAdmin());
        $this->assertSame($isEncargadoExpected, isEncargado());
        $this->assertSame($isVendedorExpected, isVendedor());
        $this->assertSame($isRepartidorExpected, isRepartidor());
        $this->assertSame($isClienteExpected, isCliente());
    }

    public static function roleMatrixProvider(): array
    {
        return [
            'admin' => ['admin', true, false, false, false, false],
            'encargado' => ['encargado', false, true, false, false, false],
            'vendedor' => ['vendedor', false, false, true, false, false],
            'repartidor' => ['repartidor', false, false, false, true, false],
            'cliente' => ['cliente', false, false, false, false, true],
            'rol desconocido' => ['invitado', false, false, false, false, false],
        ];
    }

    public function testRoleCheckersMatchExactRole(): void
    {
        $_SESSION['usuario'] = ['rol' => 'admin'];
        $this->assertTrue(isAdmin());
        $this->assertFalse(isEncargado());

        $_SESSION['usuario'] = ['rol' => 'encargado'];
        $this->assertTrue(isEncargado());
        $this->assertFalse(isAdmin());

        $_SESSION['usuario'] = ['rol' => 'vendedor'];
        $this->assertTrue(isVendedor());

        $_SESSION['usuario'] = ['rol' => 'repartidor'];
        $this->assertTrue(isRepartidor());

        $_SESSION['usuario'] = ['rol' => 'cliente'];
        $this->assertTrue(isCliente());
    }

    public function testRoleCheckersAreCaseSensitiveEdgeCase(): void
    {
        $_SESSION['usuario'] = ['rol' => 'Admin'];

        $this->assertFalse(isAdmin());
    }

    public function testHasPermissionReturnsFalseWhenNotAuthenticated(): void
    {
        $this->assertFalse(hasPermission('venta'));
    }

    public function testHasPermissionReturnsTrueForAdminWithoutPermissionArray(): void
    {
        $_SESSION['usuario'] = [
            'id_usuario' => 1,
            'rol' => 'admin',
        ];

        $this->assertTrue(hasPermission('cualquier_permiso'));
    }

    public function testHasPermissionReturnsTrueForExplicitPermissionInArray(): void
    {
        $_SESSION['usuario'] = [
            'id_usuario' => 20,
            'rol' => 'vendedor',
            'permisos' => ['venta', 'ver_reportes'],
        ];

        $this->assertTrue(hasPermission('venta'));
        $this->assertTrue(hasPermission('ver_reportes'));
    }

    public function testHasPermissionReturnsFalseWhenPermissionIsMissing(): void
    {
        $_SESSION['usuario'] = [
            'id_usuario' => 21,
            'rol' => 'vendedor',
            'permisos' => ['venta'],
        ];

        $this->assertFalse(hasPermission('gestionar_usuarios'));
    }

    public function testHasPermissionUsesStrictComparisonEdgeCase(): void
    {
        $_SESSION['usuario'] = [
            'id_usuario' => 21,
            'rol' => 'vendedor',
            'permisos' => ['1'],
        ];

        $this->assertTrue(hasPermission('1'));
        $this->assertFalse(hasPermission('01'));
    }

    public function testHasPermissionReturnsFalseWhenPermisosIsNotArrayEdgeCase(): void
    {
        $_SESSION['usuario'] = [
            'id_usuario' => 22,
            'rol' => 'encargado',
            'permisos' => 'venta,ver_reportes',
        ];

        $this->assertFalse(hasPermission('venta'));
    }

    public function testHasPermissionReturnsFalseWhenPermisosArrayIsEmpty(): void
    {
        $_SESSION['usuario'] = [
            'id_usuario' => 23,
            'rol' => 'encargado',
            'permisos' => [],
        ];

        $this->assertFalse(hasPermission('venta'));
    }

    public function testHasPermissionWorksForAnyNonAdminRoleWithAssignedPermission(): void
    {
        $roles = ['encargado', 'vendedor', 'repartidor', 'cliente'];

        foreach ($roles as $role) {
            $_SESSION['usuario'] = [
                'id_usuario' => 50,
                'rol' => $role,
                'permisos' => ['permiso_especifico'],
            ];

            $this->assertTrue(hasPermission('permiso_especifico'));
            $this->assertFalse(hasPermission('permiso_inexistente'));
        }
    }

    public function testGetCurrentAlmacenIdReturnsValueFromSession(): void
    {
        $_SESSION['usuario'] = [
            'id_usuario' => 30,
            'rol' => 'vendedor',
            'id_almacen' => 2,
            'permisos' => ['venta'],
        ];

        $this->assertSame(2, getCurrentAlmacenId());
    }

    public function testGetCurrentAlmacenIdReturnsNullWhenMissing(): void
    {
        $_SESSION['usuario'] = [
            'id_usuario' => 31,
            'rol' => 'cliente',
            'permisos' => [],
        ];

        $this->assertNull(getCurrentAlmacenId());
    }

    public function testGetCsrfTokenGeneratesAndReusesToken(): void
    {
        $token1 = getCsrfToken();
        $token2 = getCsrfToken();

        $this->assertNotSame('', $token1);
        $this->assertSame($token1, $token2);
    }

    public function testCsrfInputContainsHiddenFieldAndSessionToken(): void
    {
        $token = getCsrfToken();
        $input = csrfInput();

        $this->assertStringContainsString('type="hidden"', $input);
        $this->assertStringContainsString('name="csrf_token"', $input);
        $this->assertStringContainsString($token, $input);
    }

    public function testCsrfInputEscapesSpecialCharactersEdgeCase(): void
    {
        $_SESSION['csrf_token'] = 'abc"def';
        $input = csrfInput();

        $this->assertStringContainsString('abc&quot;def', $input);
        $this->assertStringNotContainsString('abc"def', $input);
    }

    public function testValidateCsrfTokenReturnsFalseForMissingToken(): void
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        $this->assertFalse(validateCsrfToken(''));
    }

    public function testValidateCsrfTokenReturnsFalseWhenSessionTokenMissing(): void
    {
        $this->assertFalse(validateCsrfToken('abc'));
    }

    public function testValidateCsrfTokenReturnsFalseWhenTokenDoesNotMatch(): void
    {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        $this->assertFalse(validateCsrfToken('not_the_same'));
    }

    public function testValidateCsrfTokenReturnsTrueWhenTokenMatches(): void
    {
        $token = getCsrfToken();

        $this->assertTrue(validateCsrfToken($token));
    }
}
