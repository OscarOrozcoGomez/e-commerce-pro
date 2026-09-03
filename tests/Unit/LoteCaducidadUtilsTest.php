<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LoteCaducidadUtilsTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->createSchema();
    }

    /* --------------------------------------------------------------------- */

    public function testProductoSinVentasEsSinHistorico(): void
    {
        $this->seedProducto(1, 'Sin ventas');
        $this->seedLote(1, 'L1', $this->enDias(120), 100);

        $lotes = loteFetchProyecciones($this->pdo)['lotes'];
        $this->assertCount(1, $lotes);
        $this->assertSame('sin_historico', $lotes[0]['severidad']);
        $this->assertNull($lotes[0]['excedente_proyectado']);
    }

    public function testLoteQueSeVendeATiempoEsOk(): void
    {
        $this->seedProducto(1, 'Rotacion alta');
        $this->seedVentaHistorica(1, 90);          // ~1 pieza/dia
        $this->seedLote(1, 'L1', $this->enDias(60), 5);

        $lotes = loteFetchProyecciones($this->pdo)['lotes'];
        $this->assertSame('ok', $lotes[0]['severidad']);
        $this->assertSame(0, $lotes[0]['excedente_proyectado']);
        $this->assertEqualsWithDelta(1.0, $lotes[0]['vel_diaria'], 0.05);
    }

    public function testLoteConExcedenteYCaducidadLejana(): void
    {
        $this->seedProducto(1, 'Lento');
        $this->seedVentaHistorica(1, 90);          // ~1 pieza/dia
        $this->seedLote(1, 'L1', $this->enDias(150), 500);

        $lote = loteFetchProyecciones($this->pdo)['lotes'][0];
        $this->assertSame('planificar', $lote['severidad']);
        $this->assertGreaterThan(300, $lote['excedente_proyectado']);
        $this->assertGreaterThan(0, $lote['descuento_sugerido_pct']);

        // Mas de 180 dias de anticipacion -> vigilar
        $this->pdo->exec("UPDATE lotes_inventario SET fecha_caducidad = '" . $this->enDias(220) . "'");
        $lote = loteFetchProyecciones($this->pdo)['lotes'][0];
        $this->assertSame('vigilar', $lote['severidad']);
    }

    public function testLoteCriticoPorCaducidadCercana(): void
    {
        $this->seedProducto(1, 'Critico');
        $this->seedVentaHistorica(1, 90);
        $this->seedLote(1, 'L1', $this->enDias(10), 40);

        $lote = loteFetchProyecciones($this->pdo)['lotes'][0];
        $this->assertSame('critico', $lote['severidad']);
        $this->assertGreaterThan(0, $lote['excedente_proyectado']);
    }

    public function testLoteCaducado(): void
    {
        $this->seedProducto(1, 'Vencido');
        $this->seedVentaHistorica(1, 90);
        $this->seedLote(1, 'L1', $this->enDias(-5), 12);

        $lote = loteFetchProyecciones($this->pdo)['lotes'][0];
        $this->assertSame('caducado', $lote['severidad']);
        $this->assertSame(12, $lote['excedente_proyectado']);
    }

    public function testSinRotacionCuandoNoHayVentasEnVentana(): void
    {
        $this->seedProducto(1, 'Frenado');
        // Ultima venta hace 200 dias: hay historico pero nada en la ventana de 90.
        $this->seedVenta(1, 10, 200);
        $this->seedLote(1, 'L1', $this->enDias(120), 30);

        $lote = loteFetchProyecciones($this->pdo)['lotes'][0];
        $this->assertSame('sin_rotacion', $lote['severidad']);
        $this->assertSame(30, $lote['excedente_proyectado']);
    }

    public function testFefoElPrimerLoteAbsorbeLaDemanda(): void
    {
        $this->seedProducto(1, 'Dos lotes');
        $this->seedVentaHistorica(1, 90);          // ~1 pieza/dia
        $this->seedLote(1, 'A', $this->enDias(30), 40);
        $this->seedLote(1, 'B', $this->enDias(60), 40);

        $lotes = loteFetchProyecciones($this->pdo)['lotes'];
        $porCodigo = [];
        foreach ($lotes as $l) {
            $porCodigo[$l['codigo_lote']] = $l;
        }

        // Sin FEFO, B (caducidad a 60d, vel 1/dia) tendria excedente 0.
        // Con FEFO hereda que A ya consumio ~40 piezas de demanda.
        $this->assertGreaterThan(0, $porCodigo['B']['excedente_proyectado']);
        $this->assertGreaterThanOrEqual(
            $porCodigo['A']['excedente_proyectado'],
            $porCodigo['B']['excedente_proyectado']
        );
    }

    public function testExcluyeCanceladosMuestrasYRechazados(): void
    {
        $this->seedProducto(1, 'Filtros');
        $this->seedVenta(1, 10, 10);                                   // valida
        $this->seedVenta(1, 500, 10, 'cancelado');                     // cancelada
        $this->seedVenta(1, 500, 10, 'pagado', 0);                     // no afecta inventario
        $this->seedVenta(1, 500, 10, 'pagado', 1, 'rechazado');        // linea rechazada
        $this->seedLote(1, 'L1', $this->enDias(60), 5);

        $vel = loteVelocidadVentas($this->pdo, [1])[1]['vel_diaria'];
        $this->assertEqualsWithDelta(1.0, $vel, 0.2); // ~10 piezas / 10 dias
    }

    public function testDescuentoSugeridoEnRango(): void
    {
        $this->assertSame(0, loteDescuentoSugerido(0, 100, 30));
        $peq = loteDescuentoSugerido(10, 100, 170);
        $grande = loteDescuentoSugerido(90, 100, 10);
        foreach ([$peq, $grande] as $d) {
            $this->assertGreaterThanOrEqual(5, $d);
            $this->assertLessThanOrEqual(50, $d);
            $this->assertSame(0, $d % 5);
        }
        $this->assertGreaterThanOrEqual($peq, $grande);
    }

    public function testDescuadreEntreLotesYStockDelSistema(): void
    {
        $this->seedProducto(1, 'Descuadre');
        $this->seedInventario(1, 1, 7);
        $this->seedLote(1, 'L1', $this->enDias(90), 20);

        $lote = loteFetchProyecciones($this->pdo)['lotes'][0];
        $this->assertSame(7, $lote['stock_sistema']);
        $this->assertSame(20, $lote['stock_lotes']);
        $this->assertTrue($lote['descuadre']);
    }

    public function testVentanaEfectivaCuandoHistorialCorto(): void
    {
        $this->seedProducto(1, 'Nuevo');
        // 20 piezas repartidas en los ultimos 20 dias, primera venta hace 20 dias.
        for ($i = 1; $i <= 20; $i++) {
            $this->seedVenta(1, 1, $i);
        }
        $this->seedLote(1, 'L1', $this->enDias(40), 5);

        $vel = loteVelocidadVentas($this->pdo, [1])[1];
        $this->assertSame(20, $vel['dias_efectivos']);
        $this->assertEqualsWithDelta(1.0, $vel['vel_diaria'], 0.05);
    }

    public function testCrudGuardarAjustarYContar(): void
    {
        $this->seedProducto(1, 'CRUD');
        $this->seedVentaHistorica(1, 90);

        $id = loteGuardar($this->pdo, [
            'id_producto' => 1,
            'codigo_lote' => 'LOT6758',
            'fecha_caducidad' => $this->enDias(20),
            'cantidad' => 700,
        ], 42);
        $this->assertGreaterThan(0, $id);

        $row = $this->pdo->query('SELECT * FROM lotes_inventario WHERE id_lote = ' . $id)->fetch();
        $this->assertSame(700, (int) $row['cantidad_restante']);
        $this->assertSame(42, (int) $row['creado_por']);

        loteAjustarCantidad($this->pdo, $id, 0, 42);
        $row = $this->pdo->query('SELECT * FROM lotes_inventario WHERE id_lote = ' . $id)->fetch();
        $this->assertSame('agotado', $row['estado']);

        // agotado deja de ser visible
        $this->assertCount(0, loteFetchProyecciones($this->pdo)['lotes']);
    }

    public function testDiasTratamientoPorEnvase(): void
    {
        $this->assertSame(90, loteDiasTratamiento(90, 1));
        $this->assertSame(45, loteDiasTratamiento(90, 2));
        $this->assertSame(30, loteDiasTratamiento(90, 3));
        $this->assertSame(90, loteDiasTratamiento(90, null)); // porción por defecto = 1
        $this->assertNull(loteDiasTratamiento(null, 1));
        $this->assertNull(loteDiasTratamiento(0, 1));
    }

    public function testNoVendibleCuandoElEnvaseNoSeAlcanzaAConsumir(): void
    {
        // Omega: 90 cápsulas, 1 por día => rinde 90 días. Caduca en 40 días:
        // quien lo compre hoy no lo termina a tiempo.
        $this->seedProducto(1, 'Omega 3', 90, 1);
        $this->seedVentaHistorica(1, 90);
        $this->seedLote(1, 'L1', $this->enDias(40), 20);

        $lote = loteFetchProyecciones($this->pdo)['lotes'][0];
        $this->assertTrue($lote['no_vendible']);
        $this->assertSame(90, $lote['dias_tratamiento_envase']);
        $this->assertSame(-50, $lote['margen_consumo_dias']);
        $this->assertSame('critico', $lote['severidad']);
        $this->assertSame(20, $lote['excedente_proyectado']);
        $this->assertGreaterThanOrEqual(30, $lote['descuento_sugerido_pct']);
    }

    public function testMargenDeConsumoPositivoNoEscalaSeveridad(): void
    {
        $this->seedProducto(1, 'Omega 3', 90, 1);
        $this->seedVentaHistorica(1, 90);
        $this->seedLote(1, 'L1', $this->enDias(200), 5);

        $lote = loteFetchProyecciones($this->pdo)['lotes'][0];
        $this->assertFalse($lote['no_vendible']);
        $this->assertSame(110, $lote['margen_consumo_dias']);
        $this->assertSame('ok', $lote['severidad']);
    }

    public function testRegistrarEntradaSumaAlLoteExistente(): void
    {
        $this->seedProducto(1, 'Entrada');
        loteRegistrarEntrada($this->pdo, [
            'id_producto' => 1, 'codigo_lote' => 'L9', 'fecha_caducidad' => $this->enDias(90), 'cantidad' => 100,
        ], 1);
        loteRegistrarEntrada($this->pdo, [
            'id_producto' => 1, 'codigo_lote' => 'L9', 'fecha_caducidad' => $this->enDias(90), 'cantidad' => 50,
        ], 1);

        $row = $this->pdo->query("SELECT * FROM lotes_inventario WHERE codigo_lote = 'L9'")->fetch();
        $this->assertSame(150, (int) $row['cantidad_restante']);
        $this->assertSame(150, (int) $row['cantidad_inicial']);
    }

    /* --------------------------------------------------------------------- */

    private function enDias(int $dias): string
    {
        return (new DateTimeImmutable('today'))->modify(($dias >= 0 ? '+' : '') . $dias . ' days')->format('Y-m-d');
    }

    private function createSchema(): void
    {
        $this->pdo->exec("CREATE TABLE almacenes (id_almacen INTEGER PRIMARY KEY, nombre TEXT NOT NULL)");
        $this->pdo->exec("CREATE TABLE productos (
            id_producto INTEGER PRIMARY KEY, nombre TEXT NOT NULL, sku TEXT NULL,
            codigo_barras TEXT NULL, categoria TEXT NULL, unidad TEXT NULL,
            capsulas_por_envase INTEGER NULL, porcion_capsulas INTEGER NULL,
            estado TEXT NOT NULL DEFAULT 'activo'
        )");
        $this->pdo->exec("CREATE TABLE inventario_almacen (
            id_inventario INTEGER PRIMARY KEY AUTOINCREMENT, id_producto INTEGER NOT NULL,
            id_almacen INTEGER NOT NULL, cantidad_actual INTEGER NOT NULL DEFAULT 0
        )");
        $this->pdo->exec("CREATE TABLE pedidos (
            id_pedido INTEGER PRIMARY KEY AUTOINCREMENT, estado TEXT NOT NULL DEFAULT 'pagado',
            afecta_inventario INTEGER NOT NULL DEFAULT 1, fecha_creacion TEXT NOT NULL
        )");
        $this->pdo->exec("CREATE TABLE detalle_pedidos (
            id_detalle INTEGER PRIMARY KEY AUTOINCREMENT, id_pedido INTEGER NOT NULL,
            id_producto INTEGER NOT NULL, cantidad INTEGER NOT NULL DEFAULT 1,
            estado_entrega TEXT NOT NULL DEFAULT 'entregado'
        )");
        $this->pdo->exec("CREATE TABLE lotes_inventario (
            id_lote INTEGER PRIMARY KEY AUTOINCREMENT,
            id_producto INTEGER NOT NULL, id_almacen INTEGER NULL,
            codigo_lote TEXT NOT NULL, fecha_caducidad TEXT NOT NULL,
            caducidad_aproximada INTEGER NOT NULL DEFAULT 0, fecha_ingreso TEXT NOT NULL,
            cantidad_inicial INTEGER NOT NULL, cantidad_restante INTEGER NOT NULL,
            costo_unitario REAL NULL, estado TEXT NOT NULL DEFAULT 'activo',
            en_oferta INTEGER NOT NULL DEFAULT 0, alerta_atendida INTEGER NOT NULL DEFAULT 0,
            foto_evidencia TEXT NULL, id_usuario_seguimiento INTEGER NULL,
            notas_seguimiento TEXT NULL, creado_por INTEGER NULL,
            creado_en TEXT DEFAULT CURRENT_TIMESTAMP, actualizado_en TEXT DEFAULT CURRENT_TIMESTAMP
        )");
        $this->seedAlmacen(1, 'Matriz');
    }

    private function seedAlmacen(int $id, string $nombre): void
    {
        $this->pdo->prepare('INSERT INTO almacenes (id_almacen, nombre) VALUES (?, ?)')->execute([$id, $nombre]);
    }

    private function seedProducto(int $id, string $nombre, ?int $capsEnvase = null, ?int $porcion = null): void
    {
        $this->pdo->prepare("INSERT INTO productos (id_producto, nombre, sku, categoria, estado, capsulas_por_envase, porcion_capsulas) VALUES (?, ?, ?, 'Salud', 'activo', ?, ?)")
            ->execute([$id, $nombre, 'SKU-' . $id, $capsEnvase, $porcion]);
    }

    private function seedInventario(int $idProducto, int $idAlmacen, int $cantidad): void
    {
        $this->pdo->prepare('INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual) VALUES (?, ?, ?)')
            ->execute([$idProducto, $idAlmacen, $cantidad]);
    }

    private function seedVenta(
        int $idProducto,
        int $cantidad,
        int $diasAtras,
        string $estado = 'pagado',
        int $afecta = 1,
        string $estadoEntrega = 'entregado'
    ): void {
        $fecha = (new DateTimeImmutable('today'))->modify('-' . $diasAtras . ' days')->format('Y-m-d H:i:s');
        $this->pdo->prepare('INSERT INTO pedidos (estado, afecta_inventario, fecha_creacion) VALUES (?, ?, ?)')
            ->execute([$estado, $afecta, $fecha]);
        $idPedido = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO detalle_pedidos (id_pedido, id_producto, cantidad, estado_entrega) VALUES (?, ?, ?, ?)')
            ->execute([$idPedido, $idProducto, $cantidad, $estadoEntrega]);
    }

    /**
     * Una venta de 1 pieza por dia durante los ultimos $dias dias => velocidad
     * de exactamente 1 pieza/dia (primera venta hace $dias dias).
     */
    private function seedVentaHistorica(int $idProducto, int $dias): void
    {
        for ($d = 1; $d <= $dias; $d++) {
            $this->seedVenta($idProducto, 1, $d);
        }
    }

    private function seedLote(int $idProducto, string $codigo, string $fechaCaducidad, int $cantidad, ?int $idAlmacen = null): void
    {
        $this->pdo->prepare(
            'INSERT INTO lotes_inventario
                (id_producto, id_almacen, codigo_lote, fecha_caducidad, fecha_ingreso, cantidad_inicial, cantidad_restante)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $idProducto,
            $idAlmacen,
            $codigo,
            $fechaCaducidad,
            (new DateTimeImmutable('today'))->modify('-30 days')->format('Y-m-d'),
            $cantidad,
            $cantidad,
        ]);
    }
}
