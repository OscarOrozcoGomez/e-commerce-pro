<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cubre la logica de merge de permisos efectivos (rol + overrides por usuario).
 * mergeEffectivePermissions() es pura, no toca BD.
 */
final class EffectivePermissionsTest extends TestCase
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

    public function testSoloRolCuandoNoHayOverrides(): void
    {
        $resultado = mergeEffectivePermissions(['venta', 'inventario'], []);

        $this->assertSame(['inventario', 'venta'], $resultado, 'Devuelve las claves del rol, ordenadas y unicas.');
    }

    public function testConcederAgregaUnPermisoQueElRolNoDa(): void
    {
        $resultado = mergeEffectivePermissions(
            ['venta'],
            [['clave' => 'ver_entregas', 'efecto' => 'conceder']]
        );

        $this->assertSame(['venta', 'ver_entregas'], $resultado);
    }

    public function testDenegarQuitaUnPermisoQueElRolSiDa(): void
    {
        $resultado = mergeEffectivePermissions(
            ['venta', 'transferir_stock'],
            [['clave' => 'transferir_stock', 'efecto' => 'denegar']]
        );

        $this->assertSame(['venta'], $resultado);
    }

    public function testDenegarSobreUnPermisoQueElRolNoTieneEsInocuo(): void
    {
        $resultado = mergeEffectivePermissions(
            ['venta'],
            [['clave' => 'ver_reportes', 'efecto' => 'denegar']]
        );

        $this->assertSame(['venta'], $resultado);
    }

    public function testEfectoPorDefectoEsConceder(): void
    {
        $resultado = mergeEffectivePermissions(
            [],
            [['clave' => 'ver_reportes']]
        );

        $this->assertSame(['ver_reportes'], $resultado);
    }

    public function testClavesVaciasSeIgnoran(): void
    {
        $resultado = mergeEffectivePermissions(
            ['', 'venta'],
            [['clave' => '', 'efecto' => 'conceder'], ['efecto' => 'conceder']]
        );

        $this->assertSame(['venta'], $resultado);
    }

    public function testNoHayDuplicadosSiElOverrideRepiteUnaClaveDelRol(): void
    {
        $resultado = mergeEffectivePermissions(
            ['ver_reportes'],
            [['clave' => 'ver_reportes', 'efecto' => 'conceder']]
        );

        $this->assertSame(['ver_reportes'], $resultado);
    }

    public function testResultadoDelMergeFuncionaConHasPermission(): void
    {
        $efectivos = mergeEffectivePermissions(
            ['venta'],
            [
                ['clave' => 'ver_entregas', 'efecto' => 'conceder'],
                ['clave' => 'venta', 'efecto' => 'denegar'],
            ]
        );

        $_SESSION['usuario'] = [
            'id_usuario' => 42,
            'rol' => 'encargado',
            'permisos' => $efectivos,
        ];

        $this->assertTrue(hasPermission('ver_entregas'));
        $this->assertFalse(hasPermission('venta'), 'denegar gana sobre el permiso del rol');
        $this->assertFalse(hasPermission('gestionar_usuarios'));
    }

    public function testAdminIgnoraElMergeYConservaTodosLosPermisos(): void
    {
        $_SESSION['usuario'] = [
            'id_usuario' => 1,
            'rol' => 'admin',
            'permisos' => mergeEffectivePermissions([], [['clave' => 'lo_que_sea', 'efecto' => 'denegar']]),
        ];

        $this->assertTrue(hasPermission('cualquier_cosa'), 'admin sigue con short-circuit en hasPermission()');
    }

    public function testPermisosEnUsoListaLasClavesRealmenteControladas(): void
    {
        $this->assertContains('gestionar_usuarios', PERMISOS_EN_USO);
        $this->assertContains('ver_entregas', PERMISOS_EN_USO);
        $this->assertNotContains('inventario', PERMISOS_EN_USO, 'inventario aun no se comprueba en codigo (Fase 4)');
    }
}
