<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Consumo de lotes en FEFO al vender, reversa al cancelar/no entregar, y
 * mantenimiento automatico (agotado/caducado/purga). Ver core/lote_caducidad_utils.php.
 */
final class LoteConsumoFEFOTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->pdo->exec("CREATE TABLE lotes_inventario (
            id_lote INTEGER PRIMARY KEY AUTOINCREMENT,
            id_producto INTEGER NOT NULL, id_almacen INTEGER NULL,
            codigo_lote TEXT NOT NULL, fecha_caducidad TEXT NOT NULL,
            fecha_ingreso TEXT NOT NULL,
            cantidad_inicial INTEGER NOT NULL, cantidad_restante INTEGER NOT NULL,
            costo_unitario REAL NULL, estado TEXT NOT NULL DEFAULT 'activo',
            actualizado_en TEXT DEFAULT CURRENT_TIMESTAMP
        )");
        $this->pdo->exec("CREATE TABLE detalle_pedido_lotes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            id_detalle INTEGER NOT NULL, id_lote INTEGER NOT NULL,
            cantidad INTEGER NOT NULL, costo_unitario REAL NULL
        )");
        $this->pdo->exec("CREATE TABLE detalle_pedidos (
            id_detalle INTEGER PRIMARY KEY AUTOINCREMENT,
            id_pedido INTEGER NOT NULL, id_producto INTEGER NOT NULL,
            cantidad INTEGER NOT NULL
        )");
        $this->pdo->exec("CREATE TABLE productos (
            id_producto INTEGER PRIMARY KEY, nombre TEXT NOT NULL
        )");
        $this->pdo->prepare('INSERT INTO productos (id_producto, nombre) VALUES (1, ?)')->execute(['Omega 3']);
    }

    /* ---------------------------- loteConsumirFEFO (pura) -------------------------- */

    public function testConsumirFEFORepartaEntreLotesEnElOrdenDado(): void
    {
        $plan = loteConsumirFEFO([
            ['id_lote' => 1, 'cantidad_restante' => 5],
            ['id_lote' => 2, 'cantidad_restante' => 10],
        ], 8);

        $this->assertSame(0, $plan['sobrante']);
        $this->assertSame(
            [['id_lote' => 1, 'cantidad' => 5], ['id_lote' => 2, 'cantidad' => 3]],
            $plan['asignaciones']
        );
    }

    public function testConsumirFEFODejaSobranteCuandoLosLotesNoAlcanzan(): void
    {
        $plan = loteConsumirFEFO([['id_lote' => 1, 'cantidad_restante' => 3]], 10);

        $this->assertSame(7, $plan['sobrante']);
        $this->assertSame([['id_lote' => 1, 'cantidad' => 3]], $plan['asignaciones']);
    }

    public function testConsumirFEFOIgnoraLotesEnCero(): void
    {
        $plan = loteConsumirFEFO([
            ['id_lote' => 1, 'cantidad_restante' => 0],
            ['id_lote' => 2, 'cantidad_restante' => 4],
        ], 4);

        $this->assertSame(0, $plan['sobrante']);
        $this->assertSame([['id_lote' => 2, 'cantidad' => 4]], $plan['asignaciones']);
    }

    public function testConsumirFEFOSinLotesDejaTodoComoSobrante(): void
    {
        $plan = loteConsumirFEFO([], 5);
        $this->assertSame(5, $plan['sobrante']);
        $this->assertSame([], $plan['asignaciones']);
    }

    /* ------------------------------ loteDescontarVentaFEFO ------------------------- */

    public function testDescuentaElLoteQueCaducaPrimero(): void
    {
        $this->seedLote(1, 'VIEJO', $this->enDias(10), 20);
        $this->seedLote(1, 'NUEVO', $this->enDias(200), 50);

        $plan = loteDescontarVentaFEFO($this->pdo, 1, null, 5);

        $this->assertSame(0, $plan['sobrante']);
        $restantes = $this->restantesPorCodigo(1);
        $this->assertSame(15, $restantes['VIEJO']);
        $this->assertSame(50, $restantes['NUEVO'], 'no debe tocar el lote que caduca despues');
    }

    public function testRepartEntreVariosLotesYMarcaAgotadoElPrimeroQueSeVacia(): void
    {
        $idViejo = $this->seedLoteId(1, 'VIEJO', $this->enDias(10), 5);
        $idNuevo = $this->seedLoteId(1, 'NUEVO', $this->enDias(200), 50);

        $plan = loteDescontarVentaFEFO($this->pdo, 1, null, 8);

        $this->assertSame(0, $plan['sobrante']);
        $this->assertSame(
            [['id_lote' => $idViejo, 'cantidad' => 5], ['id_lote' => $idNuevo, 'cantidad' => 3]],
            $plan['asignaciones']
        );

        $viejo = $this->loteRow($idViejo);
        $this->assertSame(0, (int) $viejo['cantidad_restante']);
        $this->assertSame('agotado', $viejo['estado'], 'al llegar a 0 debe salir solo de la vista de Caducidades');

        $nuevo = $this->loteRow($idNuevo);
        $this->assertSame(47, (int) $nuevo['cantidad_restante']);
        $this->assertSame('activo', $nuevo['estado']);
    }

    public function testPrefiereLotesDelAlmacenDeLaVentaSobreLosDeAlmacenNull(): void
    {
        $idComun = $this->seedLoteId(1, 'COMUN', $this->enDias(5), 10, null);   // caduca antes, pero sin almacen
        $idSucursal = $this->seedLoteId(1, 'SUC', $this->enDias(300), 10, 2);   // del almacen de la venta

        loteDescontarVentaFEFO($this->pdo, 1, 2, 4);

        $this->assertSame(6, (int) $this->loteRow($idSucursal)['cantidad_restante'], 'primero el del almacen de la venta');
        $this->assertSame(10, (int) $this->loteRow($idComun)['cantidad_restante'], 'el de respaldo no se toca si el del almacen alcanza');
    }

    public function testUsaLotesDeAlmacenNullComoRespaldoSiElDelAlmacenNoAlcanza(): void
    {
        $idComun = $this->seedLoteId(1, 'COMUN', $this->enDias(300), 10, null);
        $idSucursal = $this->seedLoteId(1, 'SUC', $this->enDias(5), 3, 2);

        $plan = loteDescontarVentaFEFO($this->pdo, 1, 2, 7);

        $this->assertSame(0, $plan['sobrante']);
        $this->assertSame(0, (int) $this->loteRow($idSucursal)['cantidad_restante']);
        $this->assertSame(6, (int) $this->loteRow($idComun)['cantidad_restante']);
    }

    public function testEsBestEffortSinLotesRegistradosNoLanzaExcepcionYDejaTodoComoSobrante(): void
    {
        $plan = loteDescontarVentaFEFO($this->pdo, 999, 1, 5);

        $this->assertSame(5, $plan['sobrante']);
        $this->assertSame([], $plan['asignaciones']);
    }

    public function testRegistraElConsumoPorLoteConSuCostoParaTrazabilidadYCogs(): void
    {
        $id = $this->seedLoteId(1, 'CON-COSTO', $this->enDias(90), 10, null, 12.50);

        loteDescontarVentaFEFO($this->pdo, 1, null, 4, 777);

        $fila = $this->pdo->query('SELECT * FROM detalle_pedido_lotes WHERE id_detalle = 777')->fetch();
        $this->assertSame($id, (int) $fila['id_lote']);
        $this->assertSame(4, (int) $fila['cantidad']);
        $this->assertEqualsWithDelta(12.50, (float) $fila['costo_unitario'], 0.001);
    }

    public function testSinIdDetalleNoEscribeEnDetallePedidoLotes(): void
    {
        $this->seedLote(1, 'SIN-DETALLE', $this->enDias(90), 10);
        loteDescontarVentaFEFO($this->pdo, 1, null, 4);

        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM detalle_pedido_lotes')->fetchColumn();
        $this->assertSame(0, $total);
    }

    /* ------------------------------ loteFetchPlanVentaFEFO -------------------------- */

    public function testPlanVentaMuestraElLoteQueTocariaSinDescontarNada(): void
    {
        $idViejo = $this->seedLoteId(1, 'VIEJO', $this->enDias(10), 5);
        $idNuevo = $this->seedLoteId(1, 'NUEVO', $this->enDias(200), 50);

        $plan = loteFetchPlanVentaFEFO($this->pdo, [['id_producto' => 1, 'cantidad' => 8]], null);

        $this->assertCount(1, $plan);
        $this->assertSame(1, $plan[0]['id_producto']);
        $this->assertSame('Omega 3', $plan[0]['producto_nombre']);
        $this->assertSame(8, $plan[0]['cantidad_pedida']);
        $this->assertSame(0, $plan[0]['sobrante']);
        $this->assertSame(
            [
                ['id_lote' => $idViejo, 'codigo_lote' => 'VIEJO', 'fecha_caducidad' => $this->enDias(10), 'cantidad' => 5],
                ['id_lote' => $idNuevo, 'codigo_lote' => 'NUEVO', 'fecha_caducidad' => $this->enDias(200), 'cantidad' => 3],
            ],
            $plan[0]['asignaciones']
        );

        // Es solo vista previa: no debe haber tocado nada.
        $this->assertSame(5, (int) $this->loteRow($idViejo)['cantidad_restante']);
        $this->assertSame(50, (int) $this->loteRow($idNuevo)['cantidad_restante']);
    }

    public function testPlanVentaOmiteProductosSinLotes(): void
    {
        // producto 1 sin ningun lote registrado -- no hay nada que verificar.
        $plan = loteFetchPlanVentaFEFO($this->pdo, [['id_producto' => 1, 'cantidad' => 3]], null);
        $this->assertSame([], $plan);
    }

    public function testPlanVentaReportaSobranteCuandoLosLotesNoAlcanzan(): void
    {
        $this->seedLote(1, 'CORTO', $this->enDias(30), 2);

        $plan = loteFetchPlanVentaFEFO($this->pdo, [['id_producto' => 1, 'cantidad' => 5]], null);

        $this->assertCount(1, $plan);
        $this->assertSame(3, $plan[0]['sobrante']);
    }

    public function testFetchPlanPedidoLeeLosLotesQueYaFueronAsignados(): void
    {
        $idLote = $this->seedLoteId(1, 'PED-LOTE', $this->enDias(90), 10);
        $this->pdo->prepare('INSERT INTO detalle_pedidos (id_detalle, id_pedido, id_producto, cantidad) VALUES (100, 500, 1, 3)')
            ->execute();
        $this->pdo->prepare('INSERT INTO detalle_pedido_lotes (id_detalle, id_lote, cantidad, costo_unitario) VALUES (100, ?, 3, 12.50)')
            ->execute([$idLote]);

        $plan = loteFetchPlanPedido($this->pdo, 500);

        $this->assertCount(1, $plan);
        $this->assertSame('Omega 3', $plan[0]['producto_nombre']);
        $this->assertSame(3, $plan[0]['cantidad_pedida']);
        $this->assertSame('PED-LOTE', $plan[0]['asignaciones'][0]['codigo_lote']);
        $this->assertSame(10, (int)$this->loteRow($idLote)['cantidad_restante']);
    }

    /* --------------------------- loteRegresarDetalleALotes -------------------------- */

    public function testRegresarDetalleReactivaUnLoteQueQuedoAgotado(): void
    {
        $id = $this->seedLoteId(1, 'REACTIVAR', $this->enDias(90), 5);
        loteDescontarVentaFEFO($this->pdo, 1, null, 5, 100);
        $this->assertSame('agotado', $this->loteRow($id)['estado']);

        $regresado = loteRegresarDetalleALotes($this->pdo, 100);

        $this->assertSame(5, $regresado);
        $row = $this->loteRow($id);
        $this->assertSame(5, (int) $row['cantidad_restante']);
        $this->assertSame('activo', $row['estado']);
        $total = (int) $this->pdo->query('SELECT COUNT(*) FROM detalle_pedido_lotes WHERE id_detalle = 100')->fetchColumn();
        $this->assertSame(0, $total, 'al regresar todo, el registro de consumo se limpia');
    }

    public function testRegresarDetalleParcialDejaSaldoRegistrado(): void
    {
        $id = $this->seedLoteId(1, 'PARCIAL', $this->enDias(90), 10);
        loteDescontarVentaFEFO($this->pdo, 1, null, 6, 200);

        $regresado = loteRegresarDetalleALotes($this->pdo, 200, 2);

        $this->assertSame(2, $regresado);
        $this->assertSame(6, (int) $this->loteRow($id)['cantidad_restante']); // 10 - 6 + 2
        $saldo = (int) $this->pdo->query('SELECT cantidad FROM detalle_pedido_lotes WHERE id_detalle = 200')->fetchColumn();
        $this->assertSame(4, $saldo, 'queda registrado lo que sigue vendido (6 - 2)');
    }

    public function testRegresarDetalleSinRegistroPrevioNoHaceNadaNiLanzaExcepcion(): void
    {
        $regresado = loteRegresarDetalleALotes($this->pdo, 999999);
        $this->assertSame(0, $regresado);
    }

    /* ------------------------------ loteMantenimientoAutomatico --------------------- */

    public function testMantenimientoMarcaAgotadoYCaducadoAutomaticamente(): void
    {
        $idVacio = $this->seedLoteId(1, 'VACIO', $this->enDias(90), 0);
        $idVencido = $this->seedLoteId(1, 'VENCIDO', $this->enDias(-3), 8);
        $idOk = $this->seedLoteId(1, 'OK', $this->enDias(90), 8);

        $resultado = loteMantenimientoAutomatico($this->pdo);

        $this->assertSame(1, $resultado['agotados']);
        $this->assertSame(1, $resultado['caducados']);
        $this->assertSame('agotado', $this->loteRow($idVacio)['estado']);
        $this->assertSame('caducado', $this->loteRow($idVencido)['estado']);
        $this->assertSame('activo', $this->loteRow($idOk)['estado']);
    }

    public function testMantenimientoPurgaAgotadosYRetiradosViejosPeroNoLosRecientesNiLosCaducados(): void
    {
        $idViejoAgotado = $this->seedLoteId(1, 'VIEJO-AG', $this->enDias(90), 0);
        $this->marcarEstadoYFecha($idViejoAgotado, 'agotado', $this->hace(120));

        $idRecienAgotado = $this->seedLoteId(1, 'RECIEN-AG', $this->enDias(90), 0);
        $this->marcarEstadoYFecha($idRecienAgotado, 'agotado', $this->hace(5));

        $idCaducadoViejo = $this->seedLoteId(1, 'CAD-VIEJO', $this->enDias(-30), 3);
        $this->marcarEstadoYFecha($idCaducadoViejo, 'caducado', $this->hace(200));

        $resultado = loteMantenimientoAutomatico($this->pdo, 90);

        $this->assertSame(1, $resultado['purgados']);
        $this->assertNull($this->loteRowOrNull($idViejoAgotado), 'agotado con mas de 90 dias se purga');
        $this->assertNotNull($this->loteRowOrNull($idRecienAgotado), 'agotado reciente se conserva');
        $this->assertNotNull($this->loteRowOrNull($idCaducadoViejo), 'caducado no se purga aqui, se conserva para analisis de merma');
    }

    public function testMantenimientoDryRunSoloCuentaNoModificaNada(): void
    {
        $id = $this->seedLoteId(1, 'DRYRUN', $this->enDias(-1), 5);

        $resultado = loteMantenimientoAutomatico($this->pdo, 90, true);

        $this->assertSame(1, $resultado['caducados']);
        $this->assertSame('activo', $this->loteRow($id)['estado'], 'dry-run no debe escribir nada');
    }

    /* -------------------------------------------------------------------------- */

    private function enDias(int $dias): string
    {
        return (new DateTimeImmutable('today'))->modify(($dias >= 0 ? '+' : '') . $dias . ' days')->format('Y-m-d');
    }

    private function hace(int $dias): string
    {
        return (new DateTimeImmutable('now'))->modify('-' . $dias . ' days')->format('Y-m-d H:i:s');
    }

    private function seedLote(int $idProducto, string $codigo, string $fechaCaducidad, int $cantidad, ?int $idAlmacen = null, ?float $costo = null): void
    {
        $this->seedLoteId($idProducto, $codigo, $fechaCaducidad, $cantidad, $idAlmacen, $costo);
    }

    private function seedLoteId(int $idProducto, string $codigo, string $fechaCaducidad, int $cantidad, ?int $idAlmacen = null, ?float $costo = null): int
    {
        $this->pdo->prepare(
            'INSERT INTO lotes_inventario
                (id_producto, id_almacen, codigo_lote, fecha_caducidad, fecha_ingreso, cantidad_inicial, cantidad_restante, costo_unitario)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $idProducto, $idAlmacen, $codigo, $fechaCaducidad,
            (new DateTimeImmutable('today'))->modify('-30 days')->format('Y-m-d'),
            $cantidad, $cantidad, $costo,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function marcarEstadoYFecha(int $idLote, string $estado, string $actualizadoEn): void
    {
        $this->pdo->prepare('UPDATE lotes_inventario SET estado = ?, actualizado_en = ? WHERE id_lote = ?')
            ->execute([$estado, $actualizadoEn, $idLote]);
    }

    private function loteRow(int $idLote): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM lotes_inventario WHERE id_lote = ?');
        $stmt->execute([$idLote]);
        $row = $stmt->fetch();
        $this->assertIsArray($row, "lote $idLote deberia existir");
        return $row;
    }

    private function loteRowOrNull(int $idLote): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM lotes_inventario WHERE id_lote = ?');
        $stmt->execute([$idLote]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** @return array<string,int> cantidad_restante indexada por codigo_lote, para un producto */
    private function restantesPorCodigo(int $idProducto): array
    {
        $stmt = $this->pdo->prepare('SELECT codigo_lote, cantidad_restante FROM lotes_inventario WHERE id_producto = ?');
        $stmt->execute([$idProducto]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['codigo_lote']] = (int) $row['cantidad_restante'];
        }
        return $out;
    }
}
