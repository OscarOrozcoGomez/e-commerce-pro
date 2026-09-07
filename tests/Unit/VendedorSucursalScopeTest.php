<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * El alcance por sucursal debe aplicar IGUAL a 'vendedor' que a 'encargado':
 *   - solo ve/atiende clientes de SU sucursal (no los de otras, no los "sin sucursal");
 *   - solo vende contra el inventario de SU sucursal: resolveSalesWarehouseId nunca
 *     le da la sucursal "por defecto" que si recibe un admin sin sucursal asignada.
 *
 * Se prueba a nivel de las funciones que deciden esto (core/cliente_scope_utils.php y
 * core/auth.php::resolveSalesWarehouseId), montando la sesion como lo hace el resto de
 * la suite (UserRolesPermissionsTest).
 */
final class VendedorSucursalScopeTest extends TestCase
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

    private function pdoConAlmacenes(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE almacenes (id_almacen INTEGER PRIMARY KEY, nombre TEXT, estado TEXT)");
        $pdo->exec("INSERT INTO almacenes (id_almacen, nombre, estado) VALUES (3,'Central','activo'),(4,'Norte','activo')");
        return $pdo;
    }

    // ------------------------------------------------------------------
    // Clientes: el vendedor queda restringido a su sucursal
    // ------------------------------------------------------------------

    public function testVendedorSoloVeClientesDeSuSucursal(): void
    {
        $_SESSION['usuario'] = ['rol' => 'vendedor', 'id_almacen' => 2];

        $this->assertTrue(isVendedor());
        $this->assertFalse(isAdmin());
        $this->assertSame(2, getCurrentAlmacenId());

        $filtro = clienteScopeSqlFilter(getCurrentAlmacenId(), isAdmin(), 'c');
        $this->assertSame('c.id_almacen = :cli_scope_almacen', $filtro['sql']);
        $this->assertSame([':cli_scope_almacen' => 2], $filtro['params']);

        $this->assertTrue(clienteScopeAllows(2, getCurrentAlmacenId(), isAdmin()), 'cliente de su sucursal');
        $this->assertFalse(clienteScopeAllows(9, getCurrentAlmacenId(), isAdmin()), 'cliente de otra sucursal');
        $this->assertFalse(clienteScopeAllows(null, getCurrentAlmacenId(), isAdmin()), 'cliente web sin sucursal');
    }

    public function testVendedorYEncargadoDeLaMismaSucursalTienenElMismoAlcance(): void
    {
        $_SESSION['usuario'] = ['rol' => 'vendedor', 'id_almacen' => 5];
        $filtroVendedor = clienteScopeSqlFilter(getCurrentAlmacenId(), isAdmin());

        $_SESSION['usuario'] = ['rol' => 'encargado', 'id_almacen' => 5];
        $filtroEncargado = clienteScopeSqlFilter(getCurrentAlmacenId(), isAdmin());

        $this->assertSame($filtroEncargado, $filtroVendedor);
        $this->assertSame('c.id_almacen = :cli_scope_almacen', $filtroVendedor['sql']);
    }

    public function testClienteQueRegistraElVendedorQuedaParaSuSucursal(): void
    {
        // "Si empiezan a registrar clientes de ahora en adelante, esos quedan para ellos."
        $_SESSION['usuario'] = ['rol' => 'vendedor', 'id_almacen' => 7];

        $almacenNuevo = clienteScopeAlmacenParaNuevo(getCurrentAlmacenId());
        $this->assertSame(7, $almacenNuevo);
        // ...y el propio vendedor lo puede ver inmediatamente.
        $this->assertTrue(clienteScopeAllows($almacenNuevo, getCurrentAlmacenId(), isAdmin()));
        // ...pero un vendedor de otra sucursal NO.
        $this->assertFalse(clienteScopeAllows($almacenNuevo, 8, false));
    }

    public function testVendedorSinSucursalAsignadaNoVeNingunCliente(): void
    {
        $_SESSION['usuario'] = ['rol' => 'vendedor']; // sin id_almacen

        $this->assertNull(getCurrentAlmacenId());
        $filtro = clienteScopeSqlFilter(getCurrentAlmacenId(), isAdmin());
        $this->assertSame('1=0', $filtro['sql']);
        $this->assertFalse(clienteScopeAllows(2, getCurrentAlmacenId(), isAdmin()));
    }

    public function testAdminSigueViendoTodosLosClientes(): void
    {
        $_SESSION['usuario'] = ['rol' => 'admin', 'id_almacen' => null];

        $filtro = clienteScopeSqlFilter(getCurrentAlmacenId(), isAdmin());
        $this->assertSame('1=1', $filtro['sql']);
        $this->assertTrue(clienteScopeAllows(null, getCurrentAlmacenId(), isAdmin()));
        $this->assertTrue(clienteScopeAllows(99, getCurrentAlmacenId(), isAdmin()));
    }

    // ------------------------------------------------------------------
    // Inventario: el vendedor vende SOLO contra la sucursal que tiene asignada
    // ------------------------------------------------------------------

    public function testVendedorVendeContraSuPropiaSucursal(): void
    {
        $_SESSION['usuario'] = ['rol' => 'vendedor', 'id_almacen' => 2];

        // No hace fallback: devuelve exactamente su sucursal (de ahi se descuenta stock).
        $this->assertSame(2, resolveSalesWarehouseId($this->pdoConAlmacenes()));
    }

    public function testVendedorSinSucursalNoPuedeVender(): void
    {
        $_SESSION['usuario'] = ['rol' => 'vendedor']; // sin id_almacen

        // 0 => api/ventas.php lanza "No tienes una sucursal asignada para registrar el pedido".
        $this->assertSame(0, resolveSalesWarehouseId($this->pdoConAlmacenes()));
    }

    public function testEncargadoTambienVendeContraSuPropiaSucursal(): void
    {
        $_SESSION['usuario'] = ['rol' => 'encargado', 'id_almacen' => 4];
        $this->assertSame(4, resolveSalesWarehouseId($this->pdoConAlmacenes()));
    }

    public function testSoloElAdminSinSucursalCaeALaSucursalPorDefecto(): void
    {
        $_SESSION['usuario'] = ['rol' => 'admin']; // sin id_almacen
        // Toma la primera sucursal activa (id 3) -- este fallback es exclusivo de admin.
        $this->assertSame(3, resolveSalesWarehouseId($this->pdoConAlmacenes()));

        $_SESSION['usuario'] = ['rol' => 'admin', 'id_almacen' => 4];
        $this->assertSame(4, resolveSalesWarehouseId($this->pdoConAlmacenes()));
    }

    public function testVendedorNuncaHeredaLaSucursalPorDefectoDeAdmin(): void
    {
        // Aunque exista una sucursal activa "por defecto", un vendedor sin asignacion
        // no la recibe: 0, no 3.
        $_SESSION['usuario'] = ['rol' => 'vendedor'];
        $this->assertNotSame(3, resolveSalesWarehouseId($this->pdoConAlmacenes()));
        $this->assertSame(0, resolveSalesWarehouseId($this->pdoConAlmacenes()));
    }

    // ------------------------------------------------------------------
    // sales.php: agendar a domicilio exige permiso 'asignar_entregas'
    // (rol encargado/admin como respaldo). Sin eso, solo venta en sucursal.
    // ------------------------------------------------------------------

    /** Igual que views/sales.php ($puedeAgendarDomicilio) y api/ventas.php. */
    private function puedeAgendarDomicilio(): bool
    {
        return hasPermission('asignar_entregas') || canManageDeliveryOrders();
    }

    public function testVendedorSinPermisoNoPuedeAgendarADomicilio(): void
    {
        $_SESSION['usuario'] = ['rol' => 'vendedor', 'id_almacen' => 2, 'permisos' => []];

        $this->assertFalse($this->puedeAgendarDomicilio());
        $this->assertFalse(saleDeliveryModeIsAllowedForUser('Domicilio', $this->puedeAgendarDomicilio()));
        $this->assertTrue(saleDeliveryModeIsAllowedForUser('Sucursal', $this->puedeAgendarDomicilio()));
        // Aunque no mande nada (default = Domicilio), tampoco pasa.
        $this->assertFalse(saleDeliveryModeIsAllowedForUser(null, $this->puedeAgendarDomicilio()));
    }

    public function testVendedorConPermisoAsignarEntregasSiPuedeAgendarADomicilio(): void
    {
        // Lo "configurable": si desde el panel de Roles le conceden 'asignar_entregas'
        // a un vendedor, este ya puede agendar a domicilio.
        $_SESSION['usuario'] = ['rol' => 'vendedor', 'id_almacen' => 2, 'permisos' => ['asignar_entregas']];

        $this->assertTrue($this->puedeAgendarDomicilio());
        $this->assertTrue(saleDeliveryModeIsAllowedForUser('Domicilio', $this->puedeAgendarDomicilio()));
        $this->assertTrue(saleDeliveryModeIsAllowedForUser('Sucursal', $this->puedeAgendarDomicilio()));
    }

    public function testEncargadoYAdminSiPuedenAgendarADomicilio(): void
    {
        // Encargado: por el respaldo de rol aunque no tenga la clave en 'permisos'.
        $_SESSION['usuario'] = ['rol' => 'encargado', 'id_almacen' => 2, 'permisos' => []];
        $this->assertTrue($this->puedeAgendarDomicilio());
        $this->assertTrue(saleDeliveryModeIsAllowedForUser('Domicilio', $this->puedeAgendarDomicilio()));

        // Admin: hasPermission() hace short-circuit.
        $_SESSION['usuario'] = ['rol' => 'admin'];
        $this->assertTrue($this->puedeAgendarDomicilio());
        $this->assertTrue(saleDeliveryModeIsAllowedForUser('Domicilio', $this->puedeAgendarDomicilio()));
    }
}
