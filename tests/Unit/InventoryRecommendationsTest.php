<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pruebas de core/inventory_recommendations.php: las reglas de "que reponer / poner en el
 * aparador / mover" de views/analytics.php. La clasificacion es pura; la reunion de datos se
 * prueba con SQLite en memoria y datos fabricados (nunca la BD real).
 */
final class InventoryRecommendationsTest extends TestCase
{
    /** @param array<string, mixed> $override */
    private function producto(array $override = []): array
    {
        return array_merge([
            'stock' => 10,
            'stock_minimo' => 0,
            'v30' => 0,
            'v90' => 0,
            'total_vendido' => 0,
            'dias_sin_venta' => null,
            'visitantes30' => 0,
            'dias_a_caducar' => null,
            'precio_venta' => 300.0,
            'precio_costo' => 200.0,
            'dias_en_catalogo' => 120,
        ], $override);
    }

    public function testProductoDePruebaSeDetectaPorNombre(): void
    {
        $this->assertTrue(analiticaEsProductoDePrueba('Playwright E2E Test Product'));
        $this->assertTrue(analiticaEsProductoDePrueba('algo e2e raro'));
        $this->assertFalse(analiticaEsProductoDePrueba('Omega 3 Platinum'));
    }

    public function testAgotadoConVentasRecientesSeRepone(): void
    {
        $r = analiticaClasificarProducto($this->producto(['stock' => 0, 'v90' => 4, 'total_vendido' => 4, 'dias_sin_venta' => 5]));
        $this->assertSame(INV_REC_ACCION_REPONER, $r['accion']);
        $this->assertSame('Agotado', $r['etiqueta']);
        $this->assertStringContainsString('4 pza', $r['motivo']);
    }

    public function testSeAcabaAntesDeQueLleguePedidoAlProveedor(): void
    {
        // 9 pza en 90 dias = 0.1/dia; con 1 pza alcanza ~10 dias < 14 + 7.
        $r = analiticaClasificarProducto($this->producto(['stock' => 1, 'v90' => 9, 'total_vendido' => 9, 'dias_sin_venta' => 2]));
        $this->assertSame(INV_REC_ACCION_REPONER, $r['accion']);
        $this->assertSame('Se acaba pronto', $r['etiqueta']);
        $this->assertSame(10, $r['cobertura_dias']);
    }

    public function testConStockSuficienteNoSeRepone(): void
    {
        // 9 pza en 90 dias = 0.1/dia; con 8 pza alcanza ~80 dias.
        $r = analiticaClasificarProducto($this->producto(['stock' => 8, 'v90' => 9, 'total_vendido' => 9, 'dias_sin_venta' => 2]));
        $this->assertNotSame(INV_REC_ACCION_REPONER, $r['accion']);
        $this->assertSame(80, $r['cobertura_dias']);
    }

    public function testEnSuMinimoSoloSiYaSeHaVendido(): void
    {
        $vendido = analiticaClasificarProducto($this->producto(['stock' => 2, 'stock_minimo' => 2, 'v90' => 1, 'total_vendido' => 1, 'dias_sin_venta' => 40]));
        $this->assertSame('En su minimo', $vendido['etiqueta']);

        // Mismo minimo y stock pero jamas vendido: la regla del minimo no dispara compra.
        $nunca = analiticaClasificarProducto($this->producto(['stock' => 2, 'stock_minimo' => 2, 'dias_en_catalogo' => 10]));
        $this->assertNotSame(INV_REC_ACCION_REPONER, $nunca['accion']);
    }

    public function testSinStockPeroVisitadoEsVentaPerdida(): void
    {
        $r = analiticaClasificarProducto($this->producto(['stock' => 0, 'visitantes30' => 5]));
        $this->assertSame(INV_REC_ACCION_VENTAS_PERDIDAS, $r['accion']);
    }

    public function testSinStockSinVentasNiVisitasNoGeneraAccion(): void
    {
        $r = analiticaClasificarProducto($this->producto(['stock' => 0]));
        $this->assertSame(INV_REC_ACCION_OK, $r['accion']);
    }

    public function testLoteProximoACaducarSeMueve(): void
    {
        $r = analiticaClasificarProducto($this->producto(['dias_a_caducar' => 45]));
        $this->assertSame(INV_REC_ACCION_MOVER, $r['accion']);
        $this->assertSame('Por caducar', $r['etiqueta']);
        $this->assertStringContainsString('45', $r['motivo']);
    }

    public function testLoteYaCaducadoSeMueveConMayorPrioridad(): void
    {
        $caducado = analiticaClasificarProducto($this->producto(['dias_a_caducar' => -3]));
        $proximo = analiticaClasificarProducto($this->producto(['dias_a_caducar' => 80]));
        $this->assertSame(INV_REC_ACCION_MOVER, $caducado['accion']);
        $this->assertStringContainsString('caducado', $caducado['motivo']);
        $this->assertGreaterThan($proximo['prioridad'], $caducado['prioridad']);
    }

    public function testProductoQueSeVendeVaAlAparador(): void
    {
        $r = analiticaClasificarProducto($this->producto(['stock' => 30, 'v90' => 3, 'total_vendido' => 3, 'dias_sin_venta' => 10]));
        $this->assertSame(INV_REC_ACCION_APARADOR, $r['accion']);
        $this->assertSame('Se vende', $r['etiqueta']);
    }

    public function testVisitadoYConMargenAltoVaAlAparadorAunSinVentas(): void
    {
        // margen (300-150)/300 = 50%
        $r = analiticaClasificarProducto($this->producto(['precio_costo' => 150.0, 'visitantes30' => 4]));
        $this->assertSame(INV_REC_ACCION_APARADOR, $r['accion']);
        $this->assertSame('Lo ven y deja margen', $r['etiqueta']);
        $this->assertSame(50.0, $r['margen_pct']);
    }

    public function testSinPrecioDeVentaNuncaVaAlAparador(): void
    {
        $r = analiticaClasificarProducto($this->producto(['stock' => 30, 'v90' => 3, 'total_vendido' => 3, 'dias_sin_venta' => 10, 'precio_venta' => 0.0]));
        $this->assertNotSame(INV_REC_ACCION_APARADOR, $r['accion']);
    }

    public function testNuncaVendidoYSinVisitasEsNoRecomprar(): void
    {
        $r = analiticaClasificarProducto($this->producto(['stock' => 5, 'dias_en_catalogo' => 139]));
        $this->assertSame(INV_REC_ACCION_MOVER, $r['accion']);
        $this->assertSame('No recomprar', $r['etiqueta']);
        $this->assertSame(1000.0, $r['capital']); // 5 pza x $200 de costo
        $this->assertStringContainsString('$1,000.00', $r['motivo']);
    }

    public function testProductoNuevoSinVentasTodaviaNoSeConsideraEstancado(): void
    {
        $r = analiticaClasificarProducto($this->producto(['stock' => 5, 'dias_en_catalogo' => 10]));
        $this->assertSame(INV_REC_ACCION_OK, $r['accion']);
    }

    public function testEstancadoConInteresSeSugiereOfertaNoDejarDeComprar(): void
    {
        $r = analiticaClasificarProducto($this->producto([
            'stock' => 5, 'v90' => 0, 'total_vendido' => 2, 'dias_sin_venta' => 100, 'visitantes30' => 1,
        ]));
        $this->assertSame(INV_REC_ACCION_MOVER, $r['accion']);
        $this->assertSame('Estancado', $r['etiqueta']);
    }

    public function testVentaRecienteNoSeConsideraEstancada(): void
    {
        $r = analiticaClasificarProducto($this->producto(['stock' => 5, 'v90' => 1, 'total_vendido' => 1, 'dias_sin_venta' => 20]));
        $this->assertNotSame(INV_REC_ACCION_MOVER, $r['accion']);
    }

    public function testDatosRaros_noTronan(): void
    {
        $r = analiticaClasificarProducto(['stock' => -5, 'v90' => -1, 'precio_venta' => 'abc']);
        $this->assertSame(INV_REC_ACCION_OK, $r['accion']);
        $this->assertNull($r['cobertura_dias']);
        $this->assertNull($r['margen_pct']);
    }

    public function testFaltaConfiguracionSeAvisaPorProducto(): void
    {
        $this->assertNull(analiticaClasificarProducto($this->producto())['falta_config']);
        $this->assertSame('costo', analiticaClasificarProducto($this->producto(['precio_costo' => 0.0]))['falta_config']);
        $this->assertSame('precio de venta', analiticaClasificarProducto($this->producto(['precio_venta' => 0.0]))['falta_config']);
        $this->assertSame('precio y costo', analiticaClasificarProducto($this->producto(['precio_venta' => 0.0, 'precio_costo' => 0.0]))['falta_config']);
    }

    public function testLoQueCaducaVaAntesQueUnEstancadoConMuchoCapital(): void
    {
        $porCaducar = analiticaClasificarProducto($this->producto(['stock' => 1, 'precio_costo' => 10.0, 'dias_a_caducar' => 85]));
        $estancadoCaro = analiticaClasificarProducto($this->producto(['stock' => 500, 'precio_costo' => 200.0, 'dias_en_catalogo' => 200]));
        $this->assertSame('Por caducar', $porCaducar['etiqueta']);
        $this->assertSame('No recomprar', $estancadoCaro['etiqueta']);
        $this->assertGreaterThan($estancadoCaro['prioridad'], $porCaducar['prioridad']);
    }

    public function testMoverMuestraSoloLosDeMayorCapitalPeroElResumenCuentaTodos(): void
    {
        $pdo = $this->bdDePrueba();
        $ahora = new DateTimeImmutable('2026-09-19 12:00:00');

        // 20 productos estancados (con stock, sin ventas, viejos) con capital creciente: el ultimo es el mayor.
        for ($i = 1; $i <= 20; $i++) {
            $pdo->exec("INSERT INTO productos VALUES ({$i}, 'Estancado {$i}', 300, " . (100 + $i) . ", 'activo', '2026-01-01 00:00:00')");
            $pdo->exec("INSERT INTO inventario_almacen VALUES ({$i}, 1, 3, 0)");
        }
        // Uno con lote que caduca en 30 dias y poco capital: debe salir primero aunque cueste poco.
        $pdo->exec("INSERT INTO productos VALUES (21, 'Por caducar barato', 300, 5, 'activo', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO inventario_almacen VALUES (21, 1, 1, 0)");
        $pdo->exec("INSERT INTO lotes_inventario VALUES (21, 'activo', 1, '2026-10-19')");

        $r = analiticaRecomendaciones($pdo, 40, $ahora);

        $this->assertSame(21, $r['resumen']['mover']);
        $this->assertCount(INV_REC_LIMITE_MOVER, $r['mover']);
        $this->assertSame(21, $r['mover'][0]['id_producto']);   // lo que caduca, primero
        $this->assertSame(20, $r['mover'][1]['id_producto']);   // luego el de mayor capital (3 x $120)
        $this->assertSame(19, $r['mover'][2]['id_producto']);
        // El capital parado del resumen suma todos, no solo los mostrados.
        $capitalTodos = 1 * 5.0;
        for ($i = 1; $i <= 20; $i++) {
            $capitalTodos += 3 * (100 + $i);
        }
        $this->assertSame(round($capitalTodos, 2), $r['resumen']['capital_parado']);
    }

    public function testProductosSinPrecioOCostoSeListanParaConfigurarlos(): void
    {
        $pdo = $this->bdDePrueba();
        $ahora = new DateTimeImmutable('2026-09-19 12:00:00');
        $pdo->exec("INSERT INTO productos VALUES
            (1, 'Sin costo pero vendido', 300, 0, 'activo', '2026-01-01 00:00:00'),
            (2, 'Sin precio ni costo', 0, 0, 'activo', '2026-01-01 00:00:00'),
            (3, 'Todo configurado', 300, 100, 'activo', '2026-01-01 00:00:00'),
            (4, 'Playwright sin costo', 300, 0, 'activo', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO inventario_almacen VALUES (1, 1, 5, 0), (2, 1, 9, 0), (3, 1, 5, 0), (4, 1, 5, 0)");
        $pdo->exec("INSERT INTO pedidos VALUES (1, 'entregado', '2026-09-10 10:00:00')");
        $pdo->exec("INSERT INTO detalle_pedidos VALUES (1, 1, 1, 'entregado')");

        $r = analiticaRecomendaciones($pdo, 40, $ahora);

        $this->assertSame(2, $r['resumen']['sin_configuracion']);
        $lista = array_column($r['sin_configuracion'], null, 'id_producto');
        $this->assertSame('costo', $lista[1]['falta']);
        $this->assertSame('precio y costo', $lista[2]['falta']);
        $this->assertArrayNotHasKey(3, $lista);
        $this->assertArrayNotHasKey(4, $lista); // los productos de prueba no cuentan
        // Primero el que ya se vendio.
        $this->assertSame(1, $r['sin_configuracion'][0]['id_producto']);
    }

    private function bdDePrueba(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE productos (id_producto INTEGER PRIMARY KEY, nombre TEXT, precio_venta REAL, precio_costo REAL, estado TEXT, fecha_creacion TEXT)");
        $pdo->exec("CREATE TABLE inventario_almacen (id_producto INTEGER, id_almacen INTEGER, cantidad_actual INTEGER, stock_minimo INTEGER)");
        $pdo->exec("CREATE TABLE pedidos (id_pedido INTEGER PRIMARY KEY, estado TEXT, fecha_creacion TEXT)");
        $pdo->exec("CREATE TABLE detalle_pedidos (id_pedido INTEGER, id_producto INTEGER, cantidad INTEGER, estado_entrega TEXT)");
        $pdo->exec("CREATE TABLE logs_actividad (id_producto INTEGER, visitor_id TEXT, es_interno INTEGER, fecha_creacion TEXT)");
        $pdo->exec("CREATE TABLE lotes_inventario (id_producto INTEGER, estado TEXT, cantidad_restante INTEGER, fecha_caducidad TEXT)");
        return $pdo;
    }

    public function testRecomendacionesUsanSoloVentasRealesYExcluyenPruebas(): void
    {
        $pdo = $this->bdDePrueba();
        $ahora = new DateTimeImmutable('2026-09-19 12:00:00');
        $hace = static fn(int $dias): string => $ahora->modify("-{$dias} days")->format('Y-m-d H:i:s');

        $pdo->exec("INSERT INTO productos VALUES
            (1, 'Omega 3 real', 300, 150, 'activo', '{$hace(200)}'),
            (2, 'Playwright E2E Test Product', 100, 50, 'activo', '{$hace(200)}'),
            (3, 'Producto sin ventas', 200, 100, 'activo', '{$hace(200)}'),
            (4, 'Producto inactivo', 200, 100, 'inactivo', '{$hace(200)}')");
        // El producto 1 tiene stock en DOS almacenes: debe sumarse, no duplicarse.
        $pdo->exec("INSERT INTO inventario_almacen VALUES (1, 1, 1, 0), (1, 2, 0, 0), (2, 1, 50, 0), (3, 1, 4, 0), (4, 1, 9, 0)");

        $pdo->exec("INSERT INTO pedidos VALUES
            (1, 'entregado', '{$hace(10)}'),
            (2, 'cancelado', '{$hace(5)}'),
            (3, 'entregado', '{$hace(3)}'),
            (4, 'entregado', '{$hace(400)}')");
        $pdo->exec("INSERT INTO detalle_pedidos VALUES
            (1, 1, 6, 'entregado'),
            (2, 1, 50, 'entregado'),
            (3, 1, 2, 'rechazado'),
            (3, 2, 100, 'entregado'),
            (4, 1, 20, 'entregado')");
        $pdo->exec("INSERT INTO logs_actividad VALUES (3, 'a', 0, '{$hace(2)}'), (3, 'b', 0, '{$hace(2)}'), (3, 'a', 1, '{$hace(2)}')");

        $r = analiticaRecomendaciones($pdo, 40, $ahora);

        // El producto 1: 6 pza vendidas en 90 dias (la cancelada, la rechazada y la de hace 400 dias no cuentan),
        // 0.067/dia y solo 1 pza en total -> ~15 dias, menos de los 21 del proveedor: hay que reponer.
        $comprar = array_column($r['comprar'], null, 'id_producto');
        $this->assertArrayHasKey(1, $comprar);
        $this->assertSame(1, $comprar[1]['stock']);
        $this->assertSame(6, $comprar[1]['v90']);
        $this->assertSame(6 + 20, $comprar[1]['total_vendido']);

        // El producto de pruebas y el inactivo no aparecen en ninguna lista.
        $todos = array_merge($r['comprar'], $r['aparador'], $r['mover']);
        $this->assertNotContains(2, array_column($todos, 'id_producto'));
        $this->assertNotContains(4, array_column($todos, 'id_producto'));

        // El 3 (4 pza, sin ventas, 200 dias en catalogo) tiene 2 visitantes externos: no llega al interes minimo
        // (3) pero tampoco es cero, asi que se sugiere oferta y no 'No recomprar'.
        $mover = array_column($r['mover'], null, 'id_producto');
        $this->assertArrayHasKey(3, $mover);
        $this->assertSame('Estancado', $mover[3]['etiqueta']);
        $this->assertSame(2, $mover[3]['visitantes30']); // el visitante interno no cuenta

        // El contexto de evidencia ignora cancelados, rechazados y productos de prueba: solo cuentan los
        // pedidos 1 (6 pza) y 4 (20 pza).
        $this->assertSame(2, $r['contexto']['pedidos_reales']);
        $this->assertSame(26, $r['contexto']['piezas_vendidas']);
        $this->assertSame(1, $r['contexto']['productos_vendidos']);
    }

    public function testFuncionaSinTablasOpcionales(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE productos (id_producto INTEGER PRIMARY KEY, nombre TEXT, precio_venta REAL, precio_costo REAL, estado TEXT, fecha_creacion TEXT)");
        $pdo->exec("CREATE TABLE inventario_almacen (id_producto INTEGER, id_almacen INTEGER, cantidad_actual INTEGER, stock_minimo INTEGER)");
        $pdo->exec("CREATE TABLE pedidos (id_pedido INTEGER PRIMARY KEY, estado TEXT, fecha_creacion TEXT)");
        $pdo->exec("CREATE TABLE detalle_pedidos (id_pedido INTEGER, id_producto INTEGER, cantidad INTEGER, estado_entrega TEXT)");
        // Sin logs_actividad ni lotes_inventario (entorno donde esas migraciones aun no corren).
        $pdo->exec("INSERT INTO productos VALUES (1, 'Solo uno', 100, 40, 'activo', '2026-01-01 00:00:00')");
        $pdo->exec("INSERT INTO inventario_almacen VALUES (1, 1, 3, 0)");

        $r = analiticaRecomendaciones($pdo, 10, new DateTimeImmutable('2026-09-19 12:00:00'));

        $this->assertSame(0, $r['resumen']['comprar']);
        $this->assertSame(1, $r['resumen']['mover']); // stock sin ventas en catalogo desde hace meses
        $this->assertSame(0, $r['contexto']['pedidos_reales']);
    }
}
