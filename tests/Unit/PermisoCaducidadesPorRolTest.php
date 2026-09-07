<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Simula el permiso 'gestionar_caducidades' (Control de Caducidades por Lote)
 * para TODOS los tipos de usuario: se le concede y se verifica que entra, se le
 * revoca y se verifica que deja de entrar.
 *
 * El candado real vive en dos archivos y es la MISMA expresion en ambos:
 *   - views/caducidades.php :  requireAuth();
 *                              if (!hasPermission('gestionar_caducidades')
 *                                  && !isAdmin() && !isEncargado()) { fuera }
 *   - api/lotes_manager.php  :  if (!isAuthenticated()
 *                                  || (!hasPermission('gestionar_caducidades')
 *                                      && !isAdmin() && !isEncargado())) { 'No autorizado' }
 *
 * requireAuth() hace header()+exit y no corre en un test; por eso aqui se
 * reproduce la expresion booleana exacta (leyendo de la sesion real via las
 * mismas funciones de core/auth.php) en vez de incluir la vista. Es el mismo
 * enfoque que PermissionGrantRevokeMatrixTest.
 */
final class PermisoCaducidadesPorRolTest extends TestCase
{
    private const CLAVE = 'gestionar_caducidades';

    /** Roles a los que el candado NO les da acceso por rol: dependen del permiso. */
    private const ROLES_SIN_ACCESO_POR_ROL = ['vendedor', 'repartidor', 'cliente'];

    /** Roles que entran siempre por su rol, tengan o no el permiso en el array. */
    private const ROLES_CON_ACCESO_POR_ROL = ['admin', 'encargado'];

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

    /**
     * Deja la sesion como quedaria tras el login + refreshSessionPermissions():
     * un rol y el array plano de permisos efectivos que lee hasPermission().
     *
     * @param string[] $permisos
     */
    private function sesionComo(string $rol, array $permisos = []): void
    {
        $_SESSION['usuario'] = [
            'id_usuario' => 42,
            'rol' => $rol,
            'permisos' => $permisos,
        ];
    }

    /** Candado de la vista (views/caducidades.php), asumiendo requireAuth() ya paso. */
    private function puedeEntrarVista(): bool
    {
        return hasPermission(self::CLAVE) || isAdmin() || isEncargado();
    }

    /** Candado del endpoint (api/lotes_manager.php), incluye el check de sesion. */
    private function endpointAutoriza(): bool
    {
        return isAuthenticated()
            && (hasPermission(self::CLAVE) || isAdmin() || isEncargado());
    }

    // -----------------------------------------------------------------
    // Sin sesion: conceder no significa nada, siempre fuera.
    // -----------------------------------------------------------------

    public function testSinSesionElEndpointNoAutoriza(): void
    {
        $_SESSION = [];
        $this->assertFalse($this->endpointAutoriza(), 'sin sesion no se autoriza el endpoint');
        $this->assertFalse(hasPermission(self::CLAVE), 'sin sesion hasPermission siempre es false');
    }

    // -----------------------------------------------------------------
    // admin y encargado: entran por ROL, con o sin el permiso.
    // -----------------------------------------------------------------

    /**
     * @dataProvider rolesConAccesoPorRol
     */
    public function testRolConAccesoEntraSinTenerElPermisoEnElArray(string $rol): void
    {
        $this->sesionComo($rol, []); // el array NO trae la clave
        $this->assertTrue($this->puedeEntrarVista(), "$rol debe entrar a la vista por su rol");
        $this->assertTrue($this->endpointAutoriza(), "$rol debe pasar el candado del endpoint por su rol");
    }

    /**
     * @dataProvider rolesConAccesoPorRol
     */
    public function testRolConAccesoSigueEntrandoAunqueSeLeRevoqueLaClave(string $rol): void
    {
        // "Revocar" a un admin/encargado el override no le quita el acceso: el
        // respaldo por rol manda. permisos=[] simula que no tiene el override.
        $this->sesionComo($rol, []);
        $this->assertTrue($this->puedeEntrarVista(), "revocar la clave no saca a $rol (respaldo por rol)");
        $this->assertTrue($this->endpointAutoriza());
    }

    /**
     * @dataProvider rolesConAccesoPorRol
     */
    public function testRolConAccesoTambienEntraSiSeLeConcedeLaClave(string $rol): void
    {
        $this->sesionComo($rol, [self::CLAVE]);
        $this->assertTrue($this->puedeEntrarVista());
        $this->assertTrue($this->endpointAutoriza());
    }

    // -----------------------------------------------------------------
    // vendedor, repartidor, cliente: NO entran por rol. El acceso lo
    // decide EXCLUSIVAMENTE el permiso 'gestionar_caducidades'.
    // -----------------------------------------------------------------

    /**
     * @dataProvider rolesSinAccesoPorRol
     */
    public function testRolSinAccesoNoEntraSinElPermiso(string $rol): void
    {
        $this->sesionComo($rol, []);
        $this->assertFalse($this->puedeEntrarVista(), "$rol sin el permiso NO debe entrar a la vista");
        $this->assertFalse($this->endpointAutoriza(), "$rol sin el permiso NO debe pasar el endpoint");
    }

    /**
     * @dataProvider rolesSinAccesoPorRol
     */
    public function testRolSinAccesoEntraJustoAlConcederleElPermiso(string $rol): void
    {
        $this->sesionComo($rol, [self::CLAVE]);
        $this->assertTrue($this->puedeEntrarVista(), "al concederle '" . self::CLAVE . "', $rol entra a la vista");
        $this->assertTrue($this->endpointAutoriza(), "al concederle '" . self::CLAVE . "', $rol pasa el endpoint");
    }

    /**
     * @dataProvider rolesSinAccesoPorRol
     */
    public function testRolSinAccesoVuelveAQuedarFueraAlRevocarElPermiso(string $rol): void
    {
        // Ciclo completo conceder -> revocar (el "y viceversa").
        $this->sesionComo($rol, [self::CLAVE]);
        $this->assertTrue($this->puedeEntrarVista(), "precondicion: con el permiso concedido, $rol entra");

        // Revocar = el permiso deja de estar en el array efectivo de la sesion.
        $_SESSION['usuario']['permisos'] = [];
        $this->assertFalse($this->puedeEntrarVista(), "tras revocar, $rol deja de entrar a la vista");
        $this->assertFalse($this->endpointAutoriza(), "tras revocar, $rol deja de pasar el endpoint");
    }

    /**
     * @dataProvider rolesSinAccesoPorRol
     */
    public function testConcederOtroPermisoNoAbreCaducidades(string $rol): void
    {
        // Negativo: tener OTROS permisos (aunque sean muchos) no debe colar el acceso.
        $this->sesionComo($rol, ['inventario', 'transferir_stock', 'ver_reportes', 'realizar_ventas']);
        $this->assertFalse($this->puedeEntrarVista(), "$rol con otros permisos pero sin '" . self::CLAVE . "' no entra");
        $this->assertFalse($this->endpointAutoriza());
    }

    // -----------------------------------------------------------------
    // El "conceder/revocar" a nivel del merge de permisos efectivos:
    // agregar el override mete EXACTAMENTE la clave y nada mas; quitarlo
    // (o denegarlo) la saca sin tocar el resto.
    // -----------------------------------------------------------------

    public function testConcederElOverrideAgregaSoloEsaClaveAlRolDelVendedor(): void
    {
        $rolBase = ['realizar_ventas', 'apartar_productos'];
        $efectivos = mergeEffectivePermissions(
            $rolBase,
            [['clave' => self::CLAVE, 'efecto' => 'conceder']]
        );

        $this->assertContains(self::CLAVE, $efectivos, 'el override concedido aparece');
        $this->assertContains('realizar_ventas', $efectivos, 'lo del rol no se pierde');
        $this->assertContains('apartar_productos', $efectivos);
        $this->assertCount(3, $efectivos, 'ni un permiso de mas de rebote');
    }

    public function testDenegarElOverrideQuitaLaClaveAunSiElRolLaTrajera(): void
    {
        // Rol hipotetico que si trae la clave -> el override 'denegar' la retira.
        $rolBase = [self::CLAVE, 'inventario'];
        $efectivos = mergeEffectivePermissions(
            $rolBase,
            [['clave' => self::CLAVE, 'efecto' => 'denegar']]
        );

        $this->assertNotContains(self::CLAVE, $efectivos, 'el override denegar retira la clave');
        $this->assertSame(['inventario'], $efectivos, 'lo demas queda intacto');
    }

    public function testCicloConcederLuegoQuitarElOverrideDejaElRolComoEstaba(): void
    {
        $rolBase = ['realizar_ventas'];

        $conOverride = mergeEffectivePermissions(
            $rolBase,
            [['clave' => self::CLAVE, 'efecto' => 'conceder']]
        );
        $this->assertSame(['gestionar_caducidades', 'realizar_ventas'], $conOverride);

        // Quitar el override (ya no hay filas en usuario_permisos para esa clave).
        $sinOverride = mergeEffectivePermissions($rolBase, []);
        $this->assertSame(['realizar_ventas'], $sinOverride, 'al quitar el override vuelve al rol puro');
        $this->assertNotContains(self::CLAVE, $sinOverride);
    }

    // -----------------------------------------------------------------
    // La clave esta declarada como "en uso" (dispara el badge del panel y
    // la consistencia con los call sites).
    // -----------------------------------------------------------------

    public function testLaClaveEstaEnPermisosEnUso(): void
    {
        $this->assertContains(
            self::CLAVE,
            PERMISOS_EN_USO,
            "'" . self::CLAVE . "' debe estar en PERMISOS_EN_USO: el codigo la comprueba en views/caducidades.php y api/lotes_manager.php"
        );
    }

    // -----------------------------------------------------------------
    // Data providers
    // -----------------------------------------------------------------

    public static function rolesConAccesoPorRol(): array
    {
        return array_combine(
            self::ROLES_CON_ACCESO_POR_ROL,
            array_map(static fn ($r) => [$r], self::ROLES_CON_ACCESO_POR_ROL)
        );
    }

    public static function rolesSinAccesoPorRol(): array
    {
        return array_combine(
            self::ROLES_SIN_ACCESO_POR_ROL,
            array_map(static fn ($r) => [$r], self::ROLES_SIN_ACCESO_POR_ROL)
        );
    }
}
