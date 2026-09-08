<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pruebas de core/cliente_scope_utils.php: quien puede ver/editar que cliente segun
 * la sucursal (`clientes.id_almacen`).
 *
 * El riesgo que cubren es de privacidad: un encargado NO debe ver los datos (telefono,
 * domicilio, correo) de clientes de otras sucursales. Un bug aqui = fuga de PII entre
 * sucursales, o el efecto contrario (un admin que deja de ver clientes sin sucursal).
 */
final class ClienteScopeUtilsTest extends TestCase
{
    // ---------------------------------------------------------------------
    // clienteScopeSqlFilter
    // ---------------------------------------------------------------------

    public function testFiltroAdminVeTodoSinParams(): void
    {
        $filtro = clienteScopeSqlFilter(7, true);
        $this->assertSame('1=1', $filtro['sql']);
        $this->assertSame([], $filtro['params']);
    }

    public function testFiltroAdminSinSucursalTambienVeTodo(): void
    {
        $filtro = clienteScopeSqlFilter(null, true);
        $this->assertSame('1=1', $filtro['sql']);
        $this->assertSame([], $filtro['params']);
    }

    public function testFiltroEncargadoLimitaASuSucursal(): void
    {
        $filtro = clienteScopeSqlFilter(4, false);
        $this->assertSame('c.id_almacen = :cli_scope_almacen', $filtro['sql']);
        $this->assertSame([':cli_scope_almacen' => 4], $filtro['params']);
    }

    public function testFiltroRespetaAliasYPlaceholderPersonalizados(): void
    {
        $filtro = clienteScopeSqlFilter(9, false, 'cli', ':scope2');
        $this->assertSame('cli.id_almacen = :scope2', $filtro['sql']);
        $this->assertSame([':scope2' => 9], $filtro['params']);
    }

    public function testFiltroNoAdminSinSucursalNoVeNada(): void
    {
        foreach ([null, 0, -1, -999] as $almacen) {
            $filtro = clienteScopeSqlFilter($almacen, false);
            $this->assertSame('1=0', $filtro['sql'], 'almacen=' . var_export($almacen, true));
            $this->assertSame([], $filtro['params']);
        }
    }

    public function testFiltroSiempreDevuelveSqlNoVacioYParamsArray(): void
    {
        foreach ([[5, false], [5, true], [null, false], [null, true], [0, false]] as [$alm, $adm]) {
            $filtro = clienteScopeSqlFilter($alm, $adm);
            $this->assertNotSame('', trim($filtro['sql']));
            $this->assertIsArray($filtro['params']);
        }
    }

    public function testFiltroParaEncargadoNuncaEmiteParamsSiElSqlNoLosUsa(): void
    {
        // 1=1 y 1=0 no llevan placeholder -> params vacio, para no romper el execute().
        $this->assertSame([], clienteScopeSqlFilter(3, true)['params']);
        $this->assertSame([], clienteScopeSqlFilter(0, false)['params']);
    }

    // ---------------------------------------------------------------------
    // clienteScopeAllows
    // ---------------------------------------------------------------------

    public function testAllowsAdminPuedeConCualquierCliente(): void
    {
        $this->assertTrue(clienteScopeAllows(3, 7, true));
        $this->assertTrue(clienteScopeAllows(null, 7, true));
        $this->assertTrue(clienteScopeAllows(null, null, true));
        $this->assertTrue(clienteScopeAllows(0, null, true));
    }

    public function testAllowsEncargadoSoloConClientesDeSuSucursal(): void
    {
        $this->assertTrue(clienteScopeAllows(5, 5, false));
        $this->assertFalse(clienteScopeAllows(3, 5, false), 'cliente de otra sucursal');
        $this->assertFalse(clienteScopeAllows(null, 5, false), 'cliente sin sucursal (web) -> solo admin');
    }

    public function testAllowsNoAdminSinSucursalNuncaPuede(): void
    {
        foreach ([null, 0, -1] as $sesion) {
            $this->assertFalse(clienteScopeAllows(5, $sesion, false));
            $this->assertFalse(clienteScopeAllows(null, $sesion, false));
        }
    }

    public function testAllowsTrataAlmacenCeroComoInvalido(): void
    {
        // id_almacen 0 no existe (AUTO_INCREMENT empieza en 1); no debe "empatar".
        $this->assertFalse(clienteScopeAllows(0, 0, false));
        $this->assertFalse(clienteScopeAllows(0, 5, false));
    }

    public function testAllowsEsConsistenteConElFiltroSql(): void
    {
        // Si el filtro SQL es 1=0 (no ve nada), allows() tampoco debe dejar pasar nada.
        $filtro = clienteScopeSqlFilter(0, false);
        $this->assertSame('1=0', $filtro['sql']);
        $this->assertFalse(clienteScopeAllows(1, 0, false));
        $this->assertFalse(clienteScopeAllows(null, 0, false));
    }

    // ---------------------------------------------------------------------
    // clienteScopeAlmacenParaNuevo
    // ---------------------------------------------------------------------

    public function testAlmacenParaNuevoUsaLaSucursalDeSesion(): void
    {
        $this->assertSame(6, clienteScopeAlmacenParaNuevo(6));
    }

    public function testAlmacenParaNuevoEsNullCuandoNoHaySucursal(): void
    {
        $this->assertNull(clienteScopeAlmacenParaNuevo(null));
        $this->assertNull(clienteScopeAlmacenParaNuevo(0));
        $this->assertNull(clienteScopeAlmacenParaNuevo(-3));
    }

    public function testClienteRecienCreadoQuedaDentroDeSuPropioAlcance(): void
    {
        // Un encargado de la sucursal 8 crea un cliente -> debe poder verlo enseguida.
        $almacenSesion = 8;
        $almacenNuevo = clienteScopeAlmacenParaNuevo($almacenSesion);
        $this->assertTrue(clienteScopeAllows($almacenNuevo, $almacenSesion, false));
    }
}
