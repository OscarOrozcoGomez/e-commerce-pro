<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Prueba de INTEGRACION del alcance de clientes por sucursal: arma un esquema sqlite
 * con clientes de varias sucursales (y algunos sin sucursal) y ejecuta la consulta
 * real que usan views/manage_customers.php y views/sales.php
 * (clienteScopeSqlFilter()), para verificar que:
 *   - un encargado/vendedor NO recibe ni una fila de otra sucursal (fuga de PII), y
 *   - un admin sigue viendo todo, incluidos los "sin sucursal" (registros del sitio).
 *
 * Incluye casos para "hacer tronar" el contrato: params mal pasados, alias hostil,
 * tipos equivocados y la logica de backfill de la migracion.
 */
final class ClienteScopeQueryTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec("CREATE TABLE clientes (
            id_cliente INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre TEXT NOT NULL,
            id_almacen INTEGER NULL,
            estado TEXT NOT NULL DEFAULT 'activo'
        )");
        $this->pdo->exec("CREATE TABLE pedidos (
            id_pedido INTEGER PRIMARY KEY AUTOINCREMENT,
            id_cliente INTEGER NULL,
            id_almacen INTEGER NULL
        )");

        // 3 clientes de la sucursal 1, 2 de la 2, 4 sin sucursal (tipo sitio web).
        $this->seedCliente('Ana (S1)', 1);
        $this->seedCliente('Beto (S1)', 1);
        $this->seedCliente('Caro (S1)', 1);
        $this->seedCliente('Dan (S2)', 2);
        $this->seedCliente('Eva (S2)', 2);
        $this->seedCliente('Fer (web)', null);
        $this->seedCliente('Gil (web)', null);
        $this->seedCliente('Hugo (web)', null);
        $this->seedCliente('Ivy (web/inactivo)', null, 'inactivo');
    }

    private function seedCliente(string $nombre, ?int $idAlmacen, string $estado = 'activo'): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO clientes (nombre, id_almacen, estado) VALUES (?, ?, ?)');
        $stmt->execute([$nombre, $idAlmacen, $estado]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Reproduce la consulta de sales.php / manage_customers.php. */
    private function nombresVisibles(?int $almacenId, bool $isAdmin, bool $soloActivos = true): array
    {
        $f = clienteScopeSqlFilter($almacenId, $isAdmin, 'c');
        $sql = "SELECT c.nombre FROM clientes c WHERE " . ($soloActivos ? "c.estado = 'activo' AND " : '') . $f['sql'] . " ORDER BY c.nombre ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($f['params']);
        return array_column($stmt->fetchAll(), 'nombre');
    }

    // ------------------------------------------------------------------
    // POSITIVO
    // ------------------------------------------------------------------

    public function testEncargadoSucursal1SoloVeSusClientes(): void
    {
        $this->assertSame(['Ana (S1)', 'Beto (S1)', 'Caro (S1)'], $this->nombresVisibles(1, false));
    }

    public function testEncargadoSucursal2SoloVeSusClientes(): void
    {
        $this->assertSame(['Dan (S2)', 'Eva (S2)'], $this->nombresVisibles(2, false));
    }

    public function testAdminVeTodosLosClientesActivosIncluidosLosSinSucursal(): void
    {
        $this->assertSame(
            ['Ana (S1)', 'Beto (S1)', 'Caro (S1)', 'Dan (S2)', 'Eva (S2)', 'Fer (web)', 'Gil (web)', 'Hugo (web)'],
            $this->nombresVisibles(null, true)
        );
    }

    // ------------------------------------------------------------------
    // NEGATIVO: nada se filtra hacia otra sucursal
    // ------------------------------------------------------------------

    public function testEncargadoNoRecibeNiUnaFilaDeOtraSucursalNiDeLosSinSucursal(): void
    {
        $vistos = $this->nombresVisibles(1, false);
        foreach (['Dan (S2)', 'Eva (S2)', 'Fer (web)', 'Gil (web)', 'Hugo (web)'] as $ajeno) {
            $this->assertNotContains($ajeno, $vistos, "FUGA: $ajeno no deberia ser visible para la sucursal 1");
        }
    }

    public function testNoAdminSinSucursalNoVeAbsolutamenteNada(): void
    {
        foreach ([null, 0, -1] as $sinSucursal) {
            $this->assertSame([], $this->nombresVisibles($sinSucursal, false), 'almacen=' . var_export($sinSucursal, true));
        }
    }

    public function testEncargadoDeUnaSucursalSinClientesRecibeListaVacia(): void
    {
        $this->assertSame([], $this->nombresVisibles(99, false));
    }

    public function testClientesInactivosNoAparecenNiParaAdmin(): void
    {
        $this->assertNotContains('Ivy (web/inactivo)', $this->nombresVisibles(null, true));
        // ...pero si se pide sin el filtro de estado, el admin si lo ve (y el encargado no, por sucursal).
        $this->assertContains('Ivy (web/inactivo)', $this->nombresVisibles(null, true, false));
        $this->assertNotContains('Ivy (web/inactivo)', $this->nombresVisibles(1, false, false));
    }

    // ------------------------------------------------------------------
    // "HACER TRONAR": el contrato de la funcion
    // ------------------------------------------------------------------

    public function testFiltroAdminNoLlevaParamsYElExecuteNoRevienta(): void
    {
        // Regresion: si clienteScopeSqlFilter devolviera un placeholder para admin pero
        // params vacio (o al reves), PDO tiraria "number of bound variables".
        $f = clienteScopeSqlFilter(null, true);
        $this->assertSame([], $f['params']);
        $stmt = $this->pdo->prepare("SELECT COUNT(*) c FROM clientes c WHERE {$f['sql']}");
        $stmt->execute($f['params']); // no debe lanzar
        $this->assertSame(9, (int) $stmt->fetch()['c']);
    }

    public function testOlvidarLosParamsHaceQueElFiltroFalleCerrado(): void
    {
        // Si alguien ejecuta el filtro con placeholder pero sin pasar $f['params'],
        // el placeholder queda NULL y la consulta no matchea NADA (falla "cerrado":
        // molesto, pero nunca es una fuga hacia otra sucursal).
        $f = clienteScopeSqlFilter(2, false);
        $this->assertStringContainsString(':cli_scope_almacen', $f['sql']);
        $stmt = $this->pdo->prepare("SELECT nombre FROM clientes c WHERE c.estado='activo' AND {$f['sql']} ORDER BY c.nombre");

        $stmt->execute(); // sin params
        $this->assertSame([], array_column($stmt->fetchAll(), 'nombre'), 'sin params: 0 filas (no fuga)');

        $stmt->execute($f['params']); // con params: ahora si trae la sucursal 2
        $this->assertSame(['Dan (S2)', 'Eva (S2)'], array_column($stmt->fetchAll(), 'nombre'));
    }

    public function testAliasHostilNoSeSanitiza_esResponsabilidadDeQuienLlama(): void
    {
        // clienteScopeSqlFilter NO escapa el alias ni el placeholder: son identificadores
        // de confianza (hardcodeados). Si alguien mete basura, sale SQL invalido -> PDO
        // lo rechaza al preparar. La prueba fija ese contrato para que nadie asuma que
        // la funcion sanitiza.
        $f = clienteScopeSqlFilter(2, false, 'c; DROP TABLE clientes; --', ':x');
        $this->assertStringContainsString('DROP TABLE', $f['sql']);

        $this->expectException(PDOException::class);
        $this->pdo->prepare("SELECT * FROM clientes c WHERE {$f['sql']}");
    }

    public function testClienteScopeAllowsExigeEnterosBajoStrictTypes(): void
    {
        // Los callers hacen (int)$row['id_almacen']; si alguien pasa el string crudo
        // que devuelve la BD, strict_types lo revienta -> mejor fallar fuerte que
        // "empatar" por juggling.
        $this->expectException(\TypeError::class);
        /** @phpstan-ignore-next-line */
        clienteScopeAllows('2', 2, false);
    }

    // ------------------------------------------------------------------
    // Logica del backfill de la migracion (sucursal del PRIMER pedido)
    // ------------------------------------------------------------------

    public function testBackfillTomaLaSucursalDelPrimerPedido(): void
    {
        // Cliente 100: primero compro en sucursal 3, luego en la 1 -> debe quedar en 3.
        $this->pdo->exec("INSERT INTO clientes (id_cliente, nombre, id_almacen) VALUES (100, 'Multi', NULL)");
        $this->pdo->exec("INSERT INTO pedidos (id_cliente, id_almacen) VALUES (100, 3), (100, 1), (100, NULL)");
        // Cliente 101: solo tiene un pedido sin sucursal -> queda NULL.
        $this->pdo->exec("INSERT INTO clientes (id_cliente, nombre, id_almacen) VALUES (101, 'SoloNull', NULL)");
        $this->pdo->exec("INSERT INTO pedidos (id_cliente, id_almacen) VALUES (101, NULL)");
        // Cliente 102: sin pedidos -> queda NULL.
        $this->pdo->exec("INSERT INTO clientes (id_cliente, nombre, id_almacen) VALUES (102, 'SinPedidos', NULL)");

        // Mismo SELECT correlacionado que la migracion 20260907_120000.
        $this->pdo->exec("
            UPDATE clientes
            SET id_almacen = (
                SELECT p.id_almacen FROM pedidos p
                WHERE p.id_cliente = clientes.id_cliente AND p.id_almacen IS NOT NULL
                ORDER BY p.id_pedido ASC LIMIT 1
            )
            WHERE id_almacen IS NULL AND id_cliente IN (100, 101, 102)
        ");

        $get = fn(int $id) => $this->pdo->query("SELECT id_almacen FROM clientes WHERE id_cliente = $id")->fetchColumn();
        $this->assertSame('3', (string) $get(100), 'debe heredar la sucursal del primer pedido');
        $this->assertNull($get(101), 'pedido sin sucursal -> sigue NULL');
        $this->assertNull($get(102), 'sin pedidos -> sigue NULL');
    }
}
