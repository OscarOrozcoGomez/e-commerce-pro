<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Matriz de variaciones de permisos POR USUARIO: rol base + conceder/denegar
 * individuales, en distintas combinaciones (0, 1, 2 y 4 overrides a la vez).
 *
 * mergeEffectivePermissions() es pura (sin BD), así que se puede probar cada
 * combinación de forma barata y exhaustiva. El foco es NEGATIVO a propósito:
 * no solo "el permiso concedido aparece", sino "ningún otro permiso aparece
 * de rebote" -- que es el tipo de bug que un guard mal escrito (hasPermission
 * de la clave equivocada, un && que debía ser ||, etc.) sí dejaría pasar.
 *
 * hasPermission() añade el short-circuit de admin y lee de $_SESSION; se cubre
 * aparte en la segunda mitad del archivo.
 */
final class PermissionGrantRevokeMatrixTest extends TestCase
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

    private static function conceder(string ...$claves): array
    {
        return array_map(static fn ($c) => ['clave' => $c, 'efecto' => 'conceder'], $claves);
    }

    private static function denegar(string ...$claves): array
    {
        return array_map(static fn ($c) => ['clave' => $c, 'efecto' => 'denegar'], $claves);
    }

    // ---------------------------------------------------------------
    // 0 overrides: el usuario solo tiene lo que le da el rol.
    // ---------------------------------------------------------------

    public function testSinOverridesSoloTieneExactamenteLoDelRolNiUnoMas(): void
    {
        $rol = ['ver_reportes', 'gestionar_productos'];
        $efectivos = mergeEffectivePermissions($rol, []);

        $this->assertSame(['gestionar_productos', 'ver_reportes'], $efectivos);
        // Negativo: nada fuera del rol se cuela.
        $this->assertNotContains('gestionar_usuarios', $efectivos);
        $this->assertNotContains('inventario', $efectivos);
        $this->assertNotContains('ver_entregas', $efectivos);
    }

    public function testRolVacioSinOverridesNoTieneNingunPermiso(): void
    {
        $this->assertSame([], mergeEffectivePermissions([], []));
    }

    // ---------------------------------------------------------------
    // 1 override: agregar UNO no debe tocar los demas (ni de mas ni de menos).
    // ---------------------------------------------------------------

    public function testConcederUnPermisoAgregaSoloEseUno(): void
    {
        $rol = ['venta'];
        $efectivos = mergeEffectivePermissions($rol, self::conceder('ver_entregas'));

        $this->assertContains('ver_entregas', $efectivos, 'el concedido debe aparecer');
        $this->assertContains('venta', $efectivos, 'lo del rol no debe desaparecer');
        $this->assertCount(2, $efectivos, 'no debe aparecer ningun tercer permiso');
    }

    public function testDenegarUnPermisoDelRolLoQuitaYDejaElRestoIntacto(): void
    {
        $rol = ['venta', 'inventario', 'ver_reportes'];
        $efectivos = mergeEffectivePermissions($rol, self::denegar('inventario'));

        $this->assertNotContains('inventario', $efectivos, 'el denegado debe desaparecer');
        $this->assertContains('venta', $efectivos);
        $this->assertContains('ver_reportes', $efectivos);
        $this->assertCount(2, $efectivos, 'denegar uno no debe tocar los otros dos');
    }

    public function testDenegarUnPermisoQueElRolNoTeniaNoHaceNadaYNoRompe(): void
    {
        $rol = ['venta'];
        $efectivos = mergeEffectivePermissions($rol, self::denegar('gestionar_usuarios'));

        $this->assertSame(['venta'], $efectivos);
        $this->assertNotContains('gestionar_usuarios', $efectivos);
    }

    public function testConcederUnPermisoQueElRolYaTeniaEsIdempotente(): void
    {
        $rol = ['venta', 'ver_reportes'];
        $efectivos = mergeEffectivePermissions($rol, self::conceder('venta'));

        $this->assertSame(['venta', 'ver_reportes'], $efectivos, 'sin duplicados ni cambios');
    }

    // ---------------------------------------------------------------
    // 2 overrides: 1 conceder + 1 denegar a la vez.
    // ---------------------------------------------------------------

    public function testDosOverridesConcederYDenegarSimultaneos(): void
    {
        // Encargado tipico: le quitamos transferir_stock, le damos ver_entregas.
        $rol = ['inventario', 'transferir_stock', 'gestionar_clientes'];
        $overrides = array_merge(self::conceder('ver_entregas'), self::denegar('transferir_stock'));
        $efectivos = mergeEffectivePermissions($rol, $overrides);

        sort($efectivos);
        $this->assertSame(['gestionar_clientes', 'inventario', 'ver_entregas'], $efectivos);
        // Negativos explicitos:
        $this->assertNotContains('transferir_stock', $efectivos, 'el denegado no debe sobrevivir');
        $this->assertNotContains('gestionar_usuarios', $efectivos, 'nada ajeno a los overrides aparece');
        $this->assertNotContains('asignar_entregas', $efectivos, 'conceder uno no concede otro parecido');
    }

    public function testDosDenegaresQuitanSoloEsosDosDelRolDeCuatro(): void
    {
        $rol = ['venta', 'inventario', 'ver_reportes', 'gestionar_clientes'];
        $efectivos = mergeEffectivePermissions($rol, self::denegar('inventario', 'ver_reportes'));

        sort($efectivos);
        $this->assertSame(['gestionar_clientes', 'venta'], $efectivos);
        $this->assertNotContains('inventario', $efectivos);
        $this->assertNotContains('ver_reportes', $efectivos);
    }

    public function testDosConcederesAgreganSoloEsosDosSinTocarElRolDeCuatro(): void
    {
        $rol = ['venta', 'inventario', 'ver_reportes', 'gestionar_clientes'];
        $efectivos = mergeEffectivePermissions($rol, self::conceder('ver_entregas', 'gestionar_sucursales'));

        sort($efectivos);
        $this->assertSame(
            ['gestionar_clientes', 'gestionar_sucursales', 'inventario', 'venta', 'ver_entregas', 'ver_reportes'],
            $efectivos
        );
    }

    // ---------------------------------------------------------------
    // 4 overrides mixtos: el caso "alguien le tocaron varias cosas a mano".
    // ---------------------------------------------------------------

    public function testCuatroOverridesMixtosDosConcederesDosDenegares(): void
    {
        // Rol base "encargado" tipico (5 claves). Le tocan 4 cosas a mano:
        // +2 concedidos que NO trae el rol, -2 denegados que SI trae el rol.
        $rol = ['inventario', 'transferir_stock', 'gestionar_clientes', 'ver_reportes', 'venta'];
        $overrides = array_merge(
            self::conceder('ver_entregas', 'gestionar_sucursales'),
            self::denegar('transferir_stock', 'ver_reportes')
        );
        $efectivos = mergeEffectivePermissions($rol, $overrides);
        sort($efectivos);

        $this->assertSame(
            ['gestionar_clientes', 'gestionar_sucursales', 'inventario', 'venta', 'ver_entregas'],
            $efectivos,
            'exactamente: 3 del rol sin tocar + 2 concedidos, sin los 2 denegados'
        );
        // Negativos uno por uno (no basta con el conteo total: podria "cuadrar" con la
        // combinacion equivocada de claves).
        $this->assertNotContains('transferir_stock', $efectivos);
        $this->assertNotContains('ver_reportes', $efectivos);
        $this->assertNotContains('gestionar_usuarios', $efectivos, 'ninguna clave ajena a rol+overrides aparece');
        $this->assertNotContains('asignar_entregas', $efectivos, 'ver_entregas concedido no arrastra asignar_entregas');
        $this->assertCount(5, $efectivos);
    }

    public function testCuatroDenegaresSobreRolDeCuatroDejaCeroPermisos(): void
    {
        $rol = ['venta', 'inventario', 'ver_reportes', 'gestionar_clientes'];
        $efectivos = mergeEffectivePermissions($rol, self::denegar(...$rol));

        $this->assertSame([], $efectivos, 'denegar las 4 claves del rol deja al usuario sin nada');
    }

    public function testCuatroConcederesSobreRolVacioSonExactamenteEsosCuatro(): void
    {
        $claves = ['ver_entregas', 'gestionar_sucursales', 'gestionar_cancelaciones', 'ver_insights_ia'];
        $efectivos = mergeEffectivePermissions([], self::conceder(...$claves));
        sort($efectivos);
        $esperado = $claves;
        sort($esperado);

        $this->assertSame($esperado, $efectivos);
        $this->assertCount(4, $efectivos, 'ni uno de mas (rol vacio no aporta nada)');
    }

    // ---------------------------------------------------------------
    // Caso limite: la misma clave con conceder Y denegar en el arreglo de
    // overrides (no puede pasar via BD -- PK unica en usuario_permisos -- pero
    // la funcion pura debe tener un comportamiento predecible: gana el ultimo).
    // ---------------------------------------------------------------

    public function testMismaClaveConcederLuegoDenegarGanaElUltimo(): void
    {
        $overrides = array_merge(self::conceder('ver_entregas'), self::denegar('ver_entregas'));
        $efectivos = mergeEffectivePermissions([], $overrides);

        $this->assertSame([], $efectivos, 'el denegar posterior gana');
    }

    public function testMismaClaveDenegarLuegoConcederGanaElUltimo(): void
    {
        $overrides = array_merge(self::denegar('ver_entregas'), self::conceder('ver_entregas'));
        $efectivos = mergeEffectivePermissions(['ver_entregas'], $overrides);

        $this->assertSame(['ver_entregas'], $efectivos, 'el conceder posterior gana');
    }

    // =================================================================
    // hasPermission(): mismas variaciones pero a traves de la sesion real,
    // con foco en que un permiso NO listado responda false.
    // =================================================================

    public function testHasPermissionConDosPermisosSoloEsosDosSonTrue(): void
    {
        $_SESSION['usuario'] = ['rol' => 'encargado', 'permisos' => ['ver_entregas', 'gestionar_clientes']];

        $this->assertTrue(hasPermission('ver_entregas'));
        $this->assertTrue(hasPermission('gestionar_clientes'));

        // Negativos: cualquier otra clave real del catalogo debe ser false.
        foreach (['gestionar_usuarios', 'inventario', 'transferir_stock', 'asignar_entregas', 'ver_reportes'] as $clave) {
            $this->assertFalse(hasPermission($clave), "hasPermission('$clave') deberia ser false con solo 2 permisos concedidos");
        }
    }

    public function testHasPermissionConCuatroPermisosSoloEsosCuatroSonTrue(): void
    {
        $concedidos = ['ver_entregas', 'gestionar_clientes', 'inventario', 'ver_reportes'];
        $_SESSION['usuario'] = ['rol' => 'encargado', 'permisos' => $concedidos];

        foreach ($concedidos as $clave) {
            $this->assertTrue(hasPermission($clave), "hasPermission('$clave') deberia ser true");
        }

        foreach (['gestionar_usuarios', 'transferir_stock', 'asignar_entregas', 'gestionar_sucursales', 'ver_insights_ia'] as $clave) {
            $this->assertFalse(hasPermission($clave), "hasPermission('$clave') deberia ser false con solo 4 permisos concedidos");
        }
    }

    public function testHasPermissionSinSesionEsSiempreFalse(): void
    {
        $_SESSION = [];

        foreach (['gestionar_usuarios', 'ver_reportes', 'venta', 'cualquier_cosa'] as $clave) {
            $this->assertFalse(hasPermission($clave), "sin sesion, hasPermission('$clave') nunca debe ser true");
        }
    }

    public function testHasPermissionConArrayDePermisosVacioEsSiempreFalse(): void
    {
        $_SESSION['usuario'] = ['rol' => 'vendedor', 'permisos' => []];

        foreach (['venta', 'ver_reportes', 'gestionar_usuarios'] as $clave) {
            $this->assertFalse(hasPermission($clave));
        }
    }

    public function testAdminConCuatroDenegaresSigueTeniendoTodoPorShortCircuit(): void
    {
        // Aunque a un admin "le denieguen" 4 claves via overrides, hasPermission()
        // ignora por completo el array de permisos para el rol admin.
        $rolAdminSinNada = mergeEffectivePermissions(
            [],
            self::denegar('gestionar_usuarios', 'ver_reportes', 'venta', 'inventario')
        );
        $_SESSION['usuario'] = ['rol' => 'admin', 'permisos' => $rolAdminSinNada];

        $this->assertSame([], $rolAdminSinNada, 'el merge en si respeta los denegares...');
        $this->assertTrue(hasPermission('gestionar_usuarios'), '...pero admin entra por rol, no por el array');
        $this->assertTrue(hasPermission('venta'));
        $this->assertTrue(hasPermission('lo_que_sea_incluso_inventado'));
    }

    public function testNoAdminConMismoArrayVacioNoTieneNadaNegativo(): void
    {
        // Mismo array vacio que el test anterior, pero sin ser admin: aqui SI debe
        // negar todo (confirma que el short-circuit es exclusivo de isAdmin()).
        $_SESSION['usuario'] = ['rol' => 'encargado', 'permisos' => []];

        $this->assertFalse(hasPermission('gestionar_usuarios'));
        $this->assertFalse(hasPermission('venta'));
    }
}
