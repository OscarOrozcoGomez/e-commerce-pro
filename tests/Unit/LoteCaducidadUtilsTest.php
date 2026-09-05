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

    public function testFetchProyeccionesFiltraPorProducto(): void
    {
        $this->seedProducto(1, 'Omega 3');
        $this->seedProducto(2, 'Magnesio');
        $this->seedVentaHistorica(1, 90);
        $this->seedVentaHistorica(2, 90);
        $this->seedLote(1, 'L1', $this->enDias(60), 5);
        $this->seedLote(2, 'L2', $this->enDias(60), 5);

        $lotes = loteFetchProyecciones($this->pdo, ['id_producto' => 1])['lotes'];
        $this->assertCount(1, $lotes);
        $this->assertSame(1, (int) $lotes[0]['id_producto']);
    }

    /* ------------------- Pruebas adversariales / edge cases ------------------- */

    /**
     * Bug real encontrado y corregido: un lote que YA caduco con sobrante NO debe
     * tratarse como si hubiera "cubierto" demanda futura para el siguiente lote en
     * la fila FEFO -- lo que en realidad paso es que esas unidades se convirtieron
     * en merma sin venderse, asi que la demanda de esos dias sigue disponible para
     * el lote que sigue. Antes del fix, este caso marcaba al lote B con excedente
     * inflado (50/50) cuando en realidad se vende completo.
     */
    public function testFefoNoTrataMermaDeUnLoteCaducadoComoVentaCubierta(): void
    {
        $this->seedProducto(1, 'Con merma');
        $this->seedVentaHistorica(1, 90); // ~1 pieza/dia

        // Lote A: 100 piezas, caduca en 5 dias -> solo 5 se alcanzan a vender, 95 merma.
        $this->seedLote(1, 'A', $this->enDias(5), 100);
        // Lote B: 50 piezas, caduca en 100 dias -> a 1 pieza/dia, sobran 95 dias para
        // venderlas TODAS despues de que A ya no este (dia 5 a dia 100). Excedente real = 0.
        $this->seedLote(1, 'B', $this->enDias(100), 50);

        $lotes = loteFetchProyecciones($this->pdo)['lotes'];
        $porCodigo = [];
        foreach ($lotes as $l) {
            $porCodigo[$l['codigo_lote']] = $l;
        }

        $this->assertSame(95, $porCodigo['A']['excedente_proyectado']);
        $this->assertSame(0, $porCodigo['B']['excedente_proyectado'], 'B deberia venderse completo una vez que A ya no compite por la demanda');
        $this->assertSame('ok', $porCodigo['B']['severidad']);
    }

    /**
     * Cadena de 3 lotes con destinos distintos (uno ya caduco con merma, uno que se
     * vende justo a tiempo, uno con margen de sobra) para verificar que la posicion
     * FEFO acumulada no se "descarrila" con mas de dos eslabones.
     */
    public function testFefoCadenaDeTresLotesConDestinosMixtos(): void
    {
        $this->seedProducto(1, 'Cadena');
        $this->seedVentaHistorica(1, 90); // ~1 pieza/dia

        $this->seedLote(1, 'A', $this->enDias(-2), 30);   // ya caduco, sin poder venderse mas
        $this->seedLote(1, 'B', $this->enDias(30), 30);   // debe agotarse justo a tiempo
        $this->seedLote(1, 'C', $this->enDias(60), 30);   // deberia venderse de sobra

        $lotes = loteFetchProyecciones($this->pdo)['lotes'];
        $porCodigo = [];
        foreach ($lotes as $l) {
            $porCodigo[$l['codigo_lote']] = $l;
        }

        $this->assertSame('caducado', $porCodigo['A']['severidad']);
        $this->assertSame(30, $porCodigo['A']['excedente_proyectado']);

        // B: caduca en 30 dias, A no aporta nada a la posicion (ya esta fuera) -> se
        // vende completo (30 piezas / 30 dias a 1/dia).
        $this->assertSame(0, $porCodigo['B']['excedente_proyectado']);

        // C: caduca en 60 dias; para cuando le toca, B ya consumio 30 dias de demanda
        // (posicion=30), quedan 30 dias de demanda para C (60-30) que cubren sus 30 piezas.
        $this->assertSame(0, $porCodigo['C']['excedente_proyectado']);
    }

    public function testCeroDiasParaCaducarEsCriticoNoCaducado(): void
    {
        $this->seedProducto(1, 'Hoy caduca');
        $this->seedVentaHistorica(1, 90);
        $this->seedLote(1, 'L1', $this->enDias(0), 40);

        $lote = loteFetchProyecciones($this->pdo)['lotes'][0];
        $this->assertSame(0, $lote['dias_hasta_caducar']);
        $this->assertSame('critico', $lote['severidad']);
    }

    public function testUnDiaDespuesDeCaducarEsCaducado(): void
    {
        $this->seedProducto(1, 'Ayer caduco');
        $this->seedVentaHistorica(1, 90);
        $this->seedLote(1, 'L1', $this->enDias(-1), 40);

        $lote = loteFetchProyecciones($this->pdo)['lotes'][0];
        $this->assertSame(-1, $lote['dias_hasta_caducar']);
        $this->assertSame('caducado', $lote['severidad']);
    }

    /**
     * loteSeveridad() clasifica exclusivamente por fecha_caducidad, no por la
     * columna `estado`. Si alguien marca un lote como 'caducado' a mano (ej. se
     * daño/se contamino aunque la fecha impresa siga vigente), la SEVERIDAD que se
     * muestra sigue reflejando la fecha real, no la marca manual -- documentamos
     * este comportamiento (potencialmente confuso) explicitamente.
     */
    public function testEstadoManualCaducadoConFechaFuturaNoCambiaLaSeveridadCalculada(): void
    {
        $this->seedProducto(1, 'Marcado a mano');
        $this->seedVentaHistorica(1, 90);
        $this->seedLote(1, 'L1', $this->enDias(150), 5); // vende a tiempo -> 'ok' por fecha

        $lote = loteFetchProyecciones($this->pdo)['lotes'][0];
        $this->pdo->exec("UPDATE lotes_inventario SET estado = 'caducado' WHERE id_lote = {$lote['id_lote']}");

        $lote2 = loteFetchProyecciones($this->pdo)['lotes'][0];
        $this->assertSame('activo', $lote['estado'], 'precondicion: arranca activo');
        $this->assertSame('caducado', $lote2['estado'], 'la columna estado si cambio');
        $this->assertSame('ok', $lote2['severidad'], 'pero la severidad calculada sigue basandose en la fecha real, no en estado');
    }

    public function testAjustarCantidadReactivaAgotadoPeroNoRetiradoNiCaducado(): void
    {
        $this->seedProducto(1, 'Reactivar');
        $id = loteGuardar($this->pdo, [
            'id_producto' => 1, 'codigo_lote' => 'R1', 'fecha_caducidad' => $this->enDias(90), 'cantidad' => 10,
        ], 1);

        loteAjustarCantidad($this->pdo, $id, 0, 1);
        $row = $this->pdo->query("SELECT estado FROM lotes_inventario WHERE id_lote = $id")->fetch();
        $this->assertSame('agotado', $row['estado']);

        // Restockear un lote agotado si debe reactivarlo.
        loteAjustarCantidad($this->pdo, $id, 20, 1);
        $row = $this->pdo->query("SELECT estado, cantidad_restante FROM lotes_inventario WHERE id_lote = $id")->fetch();
        $this->assertSame('activo', $row['estado']);
        $this->assertSame(20, (int) $row['cantidad_restante']);

        // Pero un lote retirado o caducado NO se reactiva solo por ajustar cantidad
        // (son estados que reflejan una decision/hecho, no el nivel de stock).
        loteCambiarEstado($this->pdo, $id, 'retirado', 1);
        loteAjustarCantidad($this->pdo, $id, 5, 1);
        $row = $this->pdo->query("SELECT estado FROM lotes_inventario WHERE id_lote = $id")->fetch();
        $this->assertSame('retirado', $row['estado']);

        loteCambiarEstado($this->pdo, $id, 'caducado', 1);
        loteAjustarCantidad($this->pdo, $id, 5, 1);
        $row = $this->pdo->query("SELECT estado FROM lotes_inventario WHERE id_lote = $id")->fetch();
        $this->assertSame('caducado', $row['estado']);
    }

    public function testGuardarRechazaLoteDuplicadoParaElMismoProducto(): void
    {
        $this->seedProducto(1, 'Duplicado');
        loteGuardar($this->pdo, [
            'id_producto' => 1, 'codigo_lote' => 'DUP1', 'fecha_caducidad' => $this->enDias(90), 'cantidad' => 10,
        ], 1);

        $this->expectException(PDOException::class);
        loteGuardar($this->pdo, [
            'id_producto' => 1, 'codigo_lote' => 'DUP1', 'fecha_caducidad' => $this->enDias(60), 'cantidad' => 5,
        ], 1);
    }

    /**
     * @dataProvider fechasInvalidasProvider
     */
    public function testNormalizarDatosRechazaFechasDeCalendarioImposibles(string $fechaInvalida): void
    {
        $this->expectException(InvalidArgumentException::class);
        loteNormalizarDatos([
            'id_producto' => 1, 'codigo_lote' => 'X', 'fecha_caducidad' => $fechaInvalida, 'cantidad' => 1,
        ]);
    }

    public static function fechasInvalidasProvider(): array
    {
        return [
            'febrero 30 (no existe)'        => ['2029-02-30'],
            'mes 13'                        => ['2029-13-01'],
            'mes 00'                        => ['2029-00-15'],
            'cero absoluto'                 => ['0000-00-00'],
            'expresion relativa'            => ['tomorrow'],
            'vacio'                         => [''],
            'formato dd-mm-yyyy'            => ['30-02-2029'],
            'sin ceros de relleno'          => ['2029-2-3'],
            'texto arbitrario'              => ['la proxima semana'],
            'fecha con hora pegada'         => ['2029-05-10 12:00:00'],
        ];
    }

    public function testNormalizarDatosAceptaFechaValidaBienFormateada(): void
    {
        $datos = loteNormalizarDatos([
            'id_producto' => 1, 'codigo_lote' => 'X', 'fecha_caducidad' => '2029-02-28', 'cantidad' => 1,
        ]);
        $this->assertSame('2029-02-28', $datos['fecha_caducidad']);
    }

    public function testNormalizarDatosRechazaCantidadNegativa(): void
    {
        $this->expectException(InvalidArgumentException::class);
        loteNormalizarDatos([
            'id_producto' => 1, 'codigo_lote' => 'X', 'fecha_caducidad' => $this->enDias(10), 'cantidad' => -5,
        ]);
    }

    public function testLotesAgotadosYRetiradosNoAparecenEnLaLista(): void
    {
        $this->seedProducto(1, 'Oculto');
        $this->seedVentaHistorica(1, 90);
        $idAgotado = $this->seedLoteId(1, 'AG', $this->enDias(90), 0);
        $this->pdo->exec("UPDATE lotes_inventario SET estado = 'agotado' WHERE id_lote = $idAgotado");
        $idRetirado = $this->seedLoteId(1, 'RE', $this->enDias(90), 10);
        $this->pdo->exec("UPDATE lotes_inventario SET estado = 'retirado' WHERE id_lote = $idRetirado");
        $this->seedLote(1, 'OK', $this->enDias(90), 10);

        $lotes = loteFetchProyecciones($this->pdo)['lotes'];
        $this->assertCount(1, $lotes);
        $this->assertSame('OK', $lotes[0]['codigo_lote']);
    }

    public function testOrdenFefoEsDeterministaConFechasIdenticas(): void
    {
        $this->seedProducto(1, 'Empate');
        $this->seedVentaHistorica(1, 90);
        $misma = $this->enDias(60);
        $idPrimero = $this->seedLoteId(1, 'PRIMERO', $misma, 10);
        $idSegundo = $this->seedLoteId(1, 'SEGUNDO', $misma, 10);
        // Mismas fechas de caducidad e ingreso -> el desempate es por id_lote (orden de creacion).
        $this->pdo->exec("UPDATE lotes_inventario SET fecha_ingreso = '{$this->enDias(-30)}' WHERE id_lote IN ($idPrimero, $idSegundo)");

        $lotes = loteFetchProyecciones($this->pdo)['lotes'];
        $this->assertSame('PRIMERO', $lotes[0]['codigo_lote']);
        $this->assertSame('SEGUNDO', $lotes[1]['codigo_lote']);
    }

    /**
     * Footgun documentado: id_producto = 0 (o cualquier valor "falsy" de PHP) NO
     * aplica el filtro porque el codigo usa !empty(). api/lotes_manager.php ya se
     * protege validando id_producto > 0 antes de llamar aqui, pero si en el futuro
     * otro caller invoca loteFetchProyecciones() directo con id_producto=0
     * esperando "nada", en realidad recibe TODOS los lotes.
     */
    public function testFiltroIdProductoCeroNoFiltraNada(): void
    {
        $this->seedProducto(1, 'Uno');
        $this->seedProducto(2, 'Dos');
        $this->seedVentaHistorica(1, 90);
        $this->seedVentaHistorica(2, 90);
        $this->seedLote(1, 'L1', $this->enDias(60), 5);
        $this->seedLote(2, 'L2', $this->enDias(60), 5);

        $lotes = loteFetchProyecciones($this->pdo, ['id_producto' => 0])['lotes'];
        $this->assertCount(2, $lotes, 'id_producto=0 deberia idealmente no devolver nada, pero !empty() lo trata como "sin filtro"');
    }

    public function testNoVendibleAportaSoloLoRealmenteConsumidoAlSiguienteLote(): void
    {
        // Producto con porcion tal que un envase rinde 90 dias.
        $this->seedProducto(1, 'No vendible en cadena', 90, 1);
        $this->seedVentaHistorica(1, 90); // ~1 pieza/dia

        // Lote A: caduca en 40 dias -> "no_vendible" (rinde 90 > 40 dias de vida),
        // pero de cualquier forma solo se alcanzan a vender ~40 de sus piezas antes
        // de esa fecha.
        $this->seedLote(1, 'A', $this->enDias(40), 60);
        // Lote B: caduca en 95 dias (>= los 90 que rinde el envase, o sea B NO es
        // no_vendible por si mismo). Si A "contaminara" la posicion con las 60
        // piezas completas (por el flag no_vendible de A), B se veria con excedente
        // inflado. Debe seguir usando lo realmente consumido por A (~40) para la posicion.
        $this->seedLote(1, 'B', $this->enDias(95), 20);

        $lotes = loteFetchProyecciones($this->pdo)['lotes'];
        $porCodigo = [];
        foreach ($lotes as $l) {
            $porCodigo[$l['codigo_lote']] = $l;
        }

        $this->assertTrue($porCodigo['A']['no_vendible']);
        $this->assertFalse($porCodigo['B']['no_vendible']);
        // B: demanda disponible = 95 dias; posicion real consumida por A = min(60, 40) = 40;
        // quedan 55 dias de demanda para B, mas que suficiente para sus 20 piezas.
        $this->assertSame(0, $porCodigo['B']['excedente_proyectado']);
    }

    public function testVelocidadIncluyeVentaExactamenteEnElBordeDeLaVentana(): void
    {
        $this->seedProducto(1, 'Borde ventana');
        // Primera y unica venta hace exactamente LOTE_VENTANA_DIAS (90) dias.
        $this->seedVenta(1, 90, LOTE_VENTANA_DIAS);

        $vel = loteVelocidadVentas($this->pdo, [1])[1];
        $this->assertFalse($vel['sin_rotacion'], 'una venta justo en el borde de los 90 dias debe contar dentro de la ventana');
        $this->assertGreaterThan(0, $vel['vel_diaria']);
    }

    public function testLoteConCantidadCeroNoProduceExcedenteNiRompe(): void
    {
        $this->seedProducto(1, 'Cantidad cero');
        $this->seedVentaHistorica(1, 90);
        $this->seedLote(1, 'L0', $this->enDias(30), 0);

        $lote = loteFetchProyecciones($this->pdo)['lotes'][0];
        $this->assertSame(0, $lote['cantidad_restante']);
        $this->assertSame(0, $lote['excedente_proyectado']);
        $this->assertSame('ok', $lote['severidad']);
    }

    public function testExcedenteProyectadoRedondeaHaciaArriba(): void
    {
        // vel = 1/3 por dia (1 pieza cada 3 dias), lote de 10 piezas, caduca en 10 dias.
        // demanda cubierta = 10 * (1/3) = 3.33 -> vendidas = 3.33 -> excedente = ceil(10-3.33) = ceil(6.67) = 7.
        $lotes = [[
            'id_lote' => 1, 'fecha_caducidad' => $this->enDias(10), 'fecha_ingreso' => $this->enDias(-30),
            'cantidad_restante' => 10, 'capsulas_por_envase' => null, 'porcion_capsulas' => null,
        ]];
        $vel = ['vel_diaria' => 1 / 3, 'sin_historico' => false, 'sin_rotacion' => false];
        $r = loteComputeProyeccionProducto($lotes, $vel, (new DateTimeImmutable('today'))->format('Y-m-d'));
        $this->assertSame(7, $r[0]['excedente_proyectado']);
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
            creado_en TEXT DEFAULT CURRENT_TIMESTAMP, actualizado_en TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (id_producto, codigo_lote)
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

    private function seedLoteId(int $idProducto, string $codigo, string $fechaCaducidad, int $cantidad, ?int $idAlmacen = null): int
    {
        $this->seedLote($idProducto, $codigo, $fechaCaducidad, $cantidad, $idAlmacen);
        return (int) $this->pdo->lastInsertId();
    }
}
