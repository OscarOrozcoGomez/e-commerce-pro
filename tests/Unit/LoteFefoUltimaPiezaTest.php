<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Regresion: en MySQL/MariaDB las asignaciones del SET de un UPDATE se evaluan de izquierda a derecha y
 * ven el valor YA actualizado. loteDescontarVentaFEFO() restaba dos veces en el CASE del estado y marcaba
 * el lote 'agotado' con 1 pieza aun disponible, asi que la ultima pieza nunca se asignaba a un lote
 * (visto en una prueba de concurrencia sobre MariaDB). SQLite evalua con los valores originales y no
 * puede reproducirlo, por eso ademas de probar el comportamiento se revisa el ORDEN del SQL.
 */
final class LoteFefoUltimaPiezaTest extends TestCase
{
    public function testElEstadoSeEvaluaAntesQueLaCantidadEnElUpdateDeVenta(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../core/lote_caducidad_utils.php');
        $ini = strpos($src, 'function loteDescontarVentaFEFO');
        $this->assertNotFalse($ini);
        $cuerpo = substr($src, $ini, 2500);

        $posEstado = strpos($cuerpo, 'SET estado = CASE WHEN cantidad_restante');
        $posCantidad = strpos($cuerpo, 'cantidad_restante = cantidad_restante - :c');
        $this->assertNotFalse($posEstado, 'el estado debe ser la PRIMERA asignacion del SET');
        $this->assertNotFalse($posCantidad);
        $this->assertLessThan($posCantidad, $posEstado, 'el estado debe evaluarse ANTES de restar la cantidad (MySQL/MariaDB)');
    }

    public function testConsumirUnLoteHastaElFinalAsignaTodasLasPiezasYLoAgotaAlFinal(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("CREATE TABLE lotes_inventario (
            id_lote INTEGER PRIMARY KEY AUTOINCREMENT, id_producto INTEGER NOT NULL, id_almacen INTEGER NULL,
            codigo_lote TEXT NOT NULL, fecha_caducidad TEXT NOT NULL, fecha_ingreso TEXT NOT NULL,
            cantidad_inicial INTEGER NOT NULL, cantidad_restante INTEGER NOT NULL, estado TEXT NOT NULL DEFAULT 'activo'
        )");
        $pdo->exec("INSERT INTO lotes_inventario (id_producto, id_almacen, codigo_lote, fecha_caducidad, fecha_ingreso, cantidad_inicial, cantidad_restante)
                    VALUES (1, 1, 'L', '2027-12-31', '2026-09-01', 3, 3)");

        $asignadas = 0;
        for ($i = 1; $i <= 3; $i++) {
            $plan = loteDescontarVentaFEFO($pdo, 1, 1, 1);
            $asignadas += array_sum(array_column($plan['asignaciones'], 'cantidad'));
            $lote = $pdo->query('SELECT cantidad_restante, estado FROM lotes_inventario')->fetch();
            $this->assertSame(3 - $i, (int) $lote['cantidad_restante'], "tras la venta $i");
            // Solo la ULTIMA pieza agota el lote: antes (MySQL) se agotaba una pieza antes.
            $this->assertSame($i < 3 ? 'activo' : 'agotado', $lote['estado'], "estado tras la venta $i");
        }
        $this->assertSame(3, $asignadas, 'las 3 piezas deben quedar asignadas a un lote');
    }
}
