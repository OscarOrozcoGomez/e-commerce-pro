<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CaducidadNotificacionesUtilsTest extends TestCase
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

    public function testPrimeraCorridaDetectaTodosLosLotesVisiblesComoCambio(): void
    {
        $this->seedProducto(1, 'Nuevo');
        $this->seedVentaHistorica(1, 90);
        $this->seedLote(1, 'L1', $this->enDias(20), 40); // sin ultima_severidad_notificada -> NULL

        $cambios = loteDetectarCambiosDeSeveridad($this->pdo);
        $this->assertCount(1, $cambios);
        $this->assertNull($cambios[0]['severidad_anterior']);
        $this->assertSame('critico', $cambios[0]['severidad']);
    }

    public function testSegundaCorridaNoDetectaNadaSiNoHuboCambioReal(): void
    {
        $this->seedProducto(1, 'Estable');
        $this->seedVentaHistorica(1, 90);
        $this->seedLote(1, 'L1', $this->enDias(60), 5); // se vende a tiempo -> 'ok'

        $cambios = loteDetectarCambiosDeSeveridad($this->pdo);
        loteMarcarSeveridadesNotificadas($this->pdo, $cambios);

        $cambios2 = loteDetectarCambiosDeSeveridad($this->pdo);
        $this->assertSame([], $cambios2);
    }

    public function testDetectaCambioDeSeveridadRealEntreCorridas(): void
    {
        $this->seedProducto(1, 'Escalando');
        $this->seedVentaHistorica(1, 90);
        $id = $this->seedLoteId(1, 'L1', $this->enDias(150), 500); // 'planificar'

        $cambios = loteDetectarCambiosDeSeveridad($this->pdo);
        $this->assertSame('planificar', $cambios[0]['severidad']);
        loteMarcarSeveridadesNotificadas($this->pdo, $cambios);

        // El tiempo avanza: ahora caduca en 10 dias -> 'critico'.
        $this->pdo->exec("UPDATE lotes_inventario SET fecha_caducidad = '{$this->enDias(10)}' WHERE id_lote = $id");

        $cambios2 = loteDetectarCambiosDeSeveridad($this->pdo);
        $this->assertCount(1, $cambios2);
        $this->assertSame('planificar', $cambios2[0]['severidad_anterior']);
        $this->assertSame('critico', $cambios2[0]['severidad']);
    }

    public function testMarcarSeveridadesNotificadasActualizaLaColumna(): void
    {
        $this->seedProducto(1, 'Marcar');
        $this->seedVentaHistorica(1, 90);
        $id = $this->seedLoteId(1, 'L1', $this->enDias(10), 40);

        $cambios = loteDetectarCambiosDeSeveridad($this->pdo);
        loteMarcarSeveridadesNotificadas($this->pdo, $cambios);

        $row = $this->pdo->query("SELECT ultima_severidad_notificada FROM lotes_inventario WHERE id_lote = $id")->fetch();
        $this->assertSame('critico', $row['ultima_severidad_notificada']);
    }

    public function testMarcarSeveridadesNotificadasConListaVaciaNoHaceNada(): void
    {
        $this->seedProducto(1, 'Vacio');
        $this->seedLote(1, 'L1', $this->enDias(10), 40);

        // No debe lanzar excepcion ni tocar nada.
        loteMarcarSeveridadesNotificadas($this->pdo, []);
        $row = $this->pdo->query("SELECT ultima_severidad_notificada FROM lotes_inventario LIMIT 1")->fetch();
        $this->assertNull($row['ultima_severidad_notificada']);
    }

    public function testBuildNotificacionHtmlIncluyeDatosClave(): void
    {
        $this->seedProducto(1, 'Omega 3', 'SKU-OMEGA');
        $this->seedVentaHistorica(1, 90);
        $this->seedLote(1, 'LOT6758', $this->enDias(10), 700);

        $cambios = loteDetectarCambiosDeSeveridad($this->pdo);
        $html = loteBuildNotificacionHtml($cambios);

        $this->assertStringContainsString('Omega 3', $html);
        $this->assertStringContainsString('SKU-OMEGA', $html);
        $this->assertStringContainsString('LOT6758', $html);
        $this->assertStringContainsString('Crítico', $html);
        $this->assertStringContainsString('id_producto=1', $html);
        $this->assertStringContainsString('no se venderían a tiempo', $html);
    }

    public function testBuildNotificacionHtmlEscapaNombreDeProductoMalicioso(): void
    {
        $this->seedProducto(1, '<script>alert(1)</script>');
        $this->seedVentaHistorica(1, 90);
        $this->seedLote(1, 'L1', $this->enDias(10), 40);

        $cambios = loteDetectarCambiosDeSeveridad($this->pdo);
        $html = loteBuildNotificacionHtml($cambios);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testBuildNotificacionHtmlMarcaNoVendible(): void
    {
        $this->seedProducto(1, 'Omega 3', null, 90, 1);
        $this->seedVentaHistorica(1, 90);
        $this->seedLote(1, 'L1', $this->enDias(40), 20); // rinde 90d, caduca en 40d -> no_vendible

        $cambios = loteDetectarCambiosDeSeveridad($this->pdo);
        $this->assertTrue($cambios[0]['no_vendible']);

        $html = loteBuildNotificacionHtml($cambios);
        $this->assertStringContainsString('NO VENDIBLE A TIEMPO', $html);
    }

    public function testEnviarNotificacionesSinCambiosNoLlamaAlMailer(): void
    {
        $this->seedProducto(1, 'Sin cambios');
        $this->seedVentaHistorica(1, 90);
        $id = $this->seedLoteId(1, 'L1', $this->enDias(60), 5);
        loteMarcarSeveridadesNotificadas($this->pdo, loteDetectarCambiosDeSeveridad($this->pdo));
        $this->seedCorreo('activo@correo.com', true);

        $llamadas = 0;
        $mailer = function () use (&$llamadas) { $llamadas++; return true; };

        $res = loteEnviarNotificacionesDeCambios($this->pdo, $mailer);
        $this->assertSame(0, $res['cambios']);
        $this->assertSame(0, $res['correos_enviados']);
        $this->assertSame(0, $llamadas);
    }

    public function testEnviarNotificacionesLlamaMailerSoloParaCorreosActivos(): void
    {
        $this->seedProducto(1, 'Con cambio');
        $this->seedVentaHistorica(1, 90);
        $this->seedLote(1, 'L1', $this->enDias(10), 40);
        $this->seedCorreo('activo1@correo.com', true);
        $this->seedCorreo('activo2@correo.com', true);
        $this->seedCorreo('inactivo@correo.com', false);

        $llamadosA = [];
        $mailer = function (string $correo, string $asunto, string $html) use (&$llamadosA) {
            $llamadosA[] = $correo;
            return true;
        };

        $res = loteEnviarNotificacionesDeCambios($this->pdo, $mailer);
        $this->assertSame(1, $res['cambios']);
        $this->assertSame(2, $res['correos_enviados']);
        sort($llamadosA);
        $this->assertSame(['activo1@correo.com', 'activo2@correo.com'], $llamadosA);
    }

    public function testEnviarNotificacionesMarcaComoNotificadoDespuesDeEnviar(): void
    {
        $this->seedProducto(1, 'Marca al enviar');
        $this->seedVentaHistorica(1, 90);
        $this->seedLote(1, 'L1', $this->enDias(10), 40);
        $this->seedCorreo('a@correo.com', true);

        loteEnviarNotificacionesDeCambios($this->pdo, fn() => true);

        $this->assertSame([], loteDetectarCambiosDeSeveridad($this->pdo));
    }

    public function testEnviarNotificacionesDryRunNoMarcaComoNotificado(): void
    {
        $this->seedProducto(1, 'Dry run');
        $this->seedVentaHistorica(1, 90);
        $this->seedLote(1, 'L1', $this->enDias(10), 40);
        $this->seedCorreo('a@correo.com', true);

        $res = loteEnviarNotificacionesDeCambios($this->pdo, fn() => true, true);
        $this->assertSame(1, $res['cambios']);

        // Como fue dry-run, el mismo cambio se sigue detectando despues.
        $this->assertCount(1, loteDetectarCambiosDeSeveridad($this->pdo));
    }

    public function testEnviarNotificacionesIgnoraCorreoConFormatoInvalido(): void
    {
        $this->seedProducto(1, 'Correo raro');
        $this->seedVentaHistorica(1, 90);
        $this->seedLote(1, 'L1', $this->enDias(10), 40);
        $this->seedCorreo('no-es-un-correo', true);

        $llamadas = 0;
        $res = loteEnviarNotificacionesDeCambios($this->pdo, function () use (&$llamadas) { $llamadas++; return true; });

        $this->assertSame(0, $llamadas);
        $this->assertSame(0, $res['correos_enviados']);
    }

    public function testEnviarNotificacionesNoRompeSiElMailerLanzaExcepcion(): void
    {
        $this->seedProducto(1, 'Mailer roto');
        $this->seedVentaHistorica(1, 90);
        $this->seedLote(1, 'L1', $this->enDias(10), 40);
        $this->seedCorreo('a@correo.com', true);

        $mailer = function () {
            throw new RuntimeException('SMTP caido');
        };

        // No debe propagar la excepcion (mismo contrato que sendNewOrderNotificationEmails()).
        $res = loteEnviarNotificacionesDeCambios($this->pdo, $mailer);
        $this->assertSame(1, $res['cambios']);
        $this->assertSame(0, $res['correos_enviados']);

        // Como el envio fallo, NO se marca como notificado: el siguiente cron debe reintentar.
        $this->assertCount(1, loteDetectarCambiosDeSeveridad($this->pdo));
    }

    public function testEnviarNotificacionesSinDestinatariosSiCuentaCambiosPeroNoEnviaNada(): void
    {
        $this->seedProducto(1, 'Sin destinatarios');
        $this->seedVentaHistorica(1, 90);
        $this->seedLote(1, 'L1', $this->enDias(10), 40);
        // Ningun correo configurado.

        $llamadas = 0;
        $res = loteEnviarNotificacionesDeCambios($this->pdo, function () use (&$llamadas) { $llamadas++; return true; });

        $this->assertSame(1, $res['cambios']);
        $this->assertSame(0, $res['correos_enviados']);
        $this->assertSame(0, $llamadas);
        // Sin destinatarios activos igual se marca como notificado (ya se "proceso" el cambio).
        $this->assertSame([], loteDetectarCambiosDeSeveridad($this->pdo));
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
            ultima_severidad_notificada TEXT NULL,
            foto_evidencia TEXT NULL, id_usuario_seguimiento INTEGER NULL,
            notas_seguimiento TEXT NULL, creado_por INTEGER NULL,
            creado_en TEXT DEFAULT CURRENT_TIMESTAMP, actualizado_en TEXT DEFAULT CURRENT_TIMESTAMP,
            UNIQUE (id_producto, codigo_lote)
        )");
        $this->pdo->exec("CREATE TABLE caducidad_notificacion_correos (
            id_correo INTEGER PRIMARY KEY AUTOINCREMENT, correo TEXT NOT NULL,
            activo INTEGER NOT NULL DEFAULT 1, creado_en TEXT DEFAULT CURRENT_TIMESTAMP
        )");
        $this->seedAlmacen(1, 'Matriz');
    }

    private function seedAlmacen(int $id, string $nombre): void
    {
        $this->pdo->prepare('INSERT INTO almacenes (id_almacen, nombre) VALUES (?, ?)')->execute([$id, $nombre]);
    }

    private function seedProducto(int $id, string $nombre, ?string $sku = null, ?int $capsEnvase = null, ?int $porcion = null): void
    {
        $this->pdo->prepare("INSERT INTO productos (id_producto, nombre, sku, categoria, estado, capsulas_por_envase, porcion_capsulas) VALUES (?, ?, ?, 'Salud', 'activo', ?, ?)")
            ->execute([$id, $nombre, $sku ?? ('SKU-' . $id), $capsEnvase, $porcion]);
    }

    private function seedCorreo(string $correo, bool $activo): void
    {
        $this->pdo->prepare('INSERT INTO caducidad_notificacion_correos (correo, activo) VALUES (?, ?)')
            ->execute([$correo, $activo ? 1 : 0]);
    }

    private function seedVenta(int $idProducto, int $cantidad, int $diasAtras): void
    {
        $fecha = (new DateTimeImmutable('today'))->modify('-' . $diasAtras . ' days')->format('Y-m-d H:i:s');
        $this->pdo->prepare('INSERT INTO pedidos (fecha_creacion) VALUES (?)')->execute([$fecha]);
        $idPedido = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO detalle_pedidos (id_pedido, id_producto, cantidad) VALUES (?, ?, ?)')
            ->execute([$idPedido, $idProducto, $cantidad]);
    }

    private function seedVentaHistorica(int $idProducto, int $dias): void
    {
        for ($d = 1; $d <= $dias; $d++) {
            $this->seedVenta($idProducto, 1, $d);
        }
    }

    private function seedLote(int $idProducto, string $codigo, string $fechaCaducidad, int $cantidad): void
    {
        $this->seedLoteId($idProducto, $codigo, $fechaCaducidad, $cantidad);
    }

    private function seedLoteId(int $idProducto, string $codigo, string $fechaCaducidad, int $cantidad): int
    {
        $this->pdo->prepare(
            'INSERT INTO lotes_inventario
                (id_producto, codigo_lote, fecha_caducidad, fecha_ingreso, cantidad_inicial, cantidad_restante)
             VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([
            $idProducto,
            $codigo,
            $fechaCaducidad,
            (new DateTimeImmutable('today'))->modify('-30 days')->format('Y-m-d'),
            $cantidad,
            $cantidad,
        ]);

        return (int) $this->pdo->lastInsertId();
    }
}
