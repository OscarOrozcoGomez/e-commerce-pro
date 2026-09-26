<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * "Articulo libre": vender en el POS algo fuera de catalogo (otras marcas) con marca,
 * descripcion, costo y precio capturados por linea. Ver core/articulo_libre_utils.php.
 */
final class ArticuloLibreTest extends TestCase
{
    private PDO $pdo;
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = dirname(__DIR__, 2);
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec("CREATE TABLE productos (id_producto INTEGER PRIMARY KEY, nombre TEXT NOT NULL, nombre_variante TEXT NULL, codigo_barras TEXT NOT NULL, estado TEXT NOT NULL DEFAULT 'activo')");
        $this->pdo->exec('CREATE TABLE usuarios (id_usuario INTEGER PRIMARY KEY, nombre TEXT NOT NULL)');
        $this->pdo->exec('CREATE TABLE pedidos (id_pedido INTEGER PRIMARY KEY, numero_pedido TEXT NULL, id_usuario INTEGER NULL, id_repartidor INTEGER NULL, id_almacen INTEGER NOT NULL, estado TEXT NOT NULL, subtotal REAL NOT NULL DEFAULT 0, descuento_total REAL NOT NULL DEFAULT 0, total REAL NOT NULL DEFAULT 0, observaciones TEXT NULL, fecha_creacion TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)');
        $this->pdo->exec("CREATE TABLE detalle_pedidos (id_detalle INTEGER PRIMARY KEY, id_pedido INTEGER NOT NULL, id_producto INTEGER NOT NULL, cantidad INTEGER NOT NULL, precio_unitario REAL NOT NULL DEFAULT 0, costo_unitario REAL NULL, subtotal REAL NOT NULL DEFAULT 0, monto_descuento REAL NOT NULL DEFAULT 0, estado_entrega TEXT NOT NULL DEFAULT 'entregado', motivo_rechazo TEXT NULL, marca_libre TEXT NULL, descripcion_libre TEXT NULL)");
        $this->pdo->exec('CREATE TABLE inventario_almacen (id_producto INTEGER NOT NULL, id_almacen INTEGER NOT NULL, cantidad_actual INTEGER NOT NULL DEFAULT 0, stock_minimo INTEGER NOT NULL DEFAULT 0, stock_maximo INTEGER NOT NULL DEFAULT 0)');
        $this->pdo->exec('CREATE TABLE movimientos_inventario (id_movimiento INTEGER PRIMARY KEY AUTOINCREMENT, id_producto INTEGER NOT NULL, tipo_movimiento TEXT NOT NULL, id_almacen_destino INTEGER NULL, cantidad INTEGER NOT NULL, id_usuario INTEGER NULL, observacion TEXT NULL)');

        $this->pdo->exec("INSERT INTO productos (id_producto, nombre, codigo_barras, estado) VALUES (10, 'Omega 3', '750100', 'activo')");
        $this->pdo->prepare("INSERT INTO productos (id_producto, nombre, codigo_barras, estado) VALUES (99, 'Artículo libre (otras marcas)', ?, 'archivado')")
            ->execute([ARTICULO_LIBRE_CODIGO]);
        $this->pdo->exec("INSERT INTO usuarios (id_usuario, nombre) VALUES (1, 'Oscar'), (2, 'Vendedor')");
    }

    // ---- Validacion de la linea ---------------------------------------------------------------

    public function testNormalizaLineaValidaYCalculaGanancia(): void
    {
        $r = articuloLibreNormalizarLinea([
            'marca' => '  Nutrilite ', 'descripcion' => "Colágeno   hidrolizado\n500g",
            'costo' => '180.50', 'precio' => '320', 'cantidad' => '2', 'descuento' => '40',
        ]);

        $this->assertTrue($r['ok']);
        $l = $r['linea'];
        $this->assertSame('Nutrilite', $l['marca']);
        $this->assertSame('Colágeno hidrolizado 500g', $l['descripcion']);
        $this->assertSame(180.5, $l['costo']);
        $this->assertSame(320.0, $l['precio']);
        $this->assertSame(2, $l['cantidad']);
        $this->assertSame(640.0, $l['subtotal_base']);
        $this->assertSame(600.0, $l['subtotal']);
        $this->assertSame(239.0, $l['ganancia']); // 600 - 2*180.50
        $this->assertFalse($l['bajo_costo']);
    }

    public function testCostoCeroEsValidoYDescuentoOpcional(): void
    {
        $r = articuloLibreNormalizarLinea(['marca' => 'X', 'descripcion' => 'Regalo', 'costo' => '0', 'precio' => '50', 'cantidad' => 1]);
        $this->assertTrue($r['ok']);
        $this->assertSame(0.0, $r['linea']['descuento']);
        $this->assertSame(50.0, $r['linea']['ganancia']);
    }

    public function testMarcaVentaAbajoDelCosto(): void
    {
        $r = articuloLibreNormalizarLinea(['marca' => 'X', 'descripcion' => 'Remate', 'costo' => '100', 'precio' => '80', 'cantidad' => '1']);
        $this->assertTrue($r['ok'], 'vender abajo del costo se permite (se audita como alerta)');
        $this->assertTrue($r['linea']['bajo_costo']);
        $this->assertSame(-20.0, $r['linea']['ganancia']);
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function lineasInvalidas(): array
    {
        $base = ['marca' => 'Marca', 'descripcion' => 'Algo', 'costo' => '10', 'precio' => '20', 'cantidad' => '1'];
        return [
            'sin marca' => [array_merge($base, ['marca' => '   ']), 'marca'],
            'sin descripcion' => [array_merge($base, ['descripcion' => '']), 'descripción'],
            'costo vacio' => [array_merge($base, ['costo' => '']), 'costo'],
            'costo negativo' => [array_merge($base, ['costo' => '-1']), 'negativo'],
            'precio cero' => [array_merge($base, ['precio' => '0']), 'mayor a 0'],
            'precio texto' => [array_merge($base, ['precio' => 'abc']), 'precio'],
            'cantidad cero' => [array_merge($base, ['cantidad' => '0']), 'cantidad'],
            'cantidad fraccion' => [array_merge($base, ['cantidad' => '1.5']), 'entero'],
            'precio absurdo' => [array_merge($base, ['precio' => '5000000']), 'demasiado alto'],
            'descuento mayor' => [array_merge($base, ['descuento' => '25']), 'descuento'],
        ];
    }

    /** @dataProvider lineasInvalidas */
    public function testRechazaLineasInvalidas(array $raw, string $fragmento): void
    {
        $r = articuloLibreNormalizarLinea($raw);
        $this->assertFalse($r['ok']);
        $this->assertNull($r['linea']);
        $this->assertStringContainsStringIgnoringCase($fragmento, (string) $r['error']);
    }

    public function testRecortaTextosLargos(): void
    {
        $r = articuloLibreNormalizarLinea([
            'marca' => str_repeat('m', 300), 'descripcion' => str_repeat('ñ', 400),
            'costo' => '1', 'precio' => '2', 'cantidad' => '1',
        ]);
        $this->assertTrue($r['ok']);
        $this->assertSame(ARTICULO_LIBRE_MARCA_MAX, mb_strlen($r['linea']['marca']));
        $this->assertSame(ARTICULO_LIBRE_DESCRIPCION_MAX, mb_strlen($r['linea']['descripcion']));
    }

    public function testEtiqueta(): void
    {
        $this->assertSame('Colágeno (Nutrilite)', articuloLibreEtiqueta('Nutrilite', 'Colágeno'));
        $this->assertSame('Colágeno', articuloLibreEtiqueta('', 'Colágeno'));
        $this->assertSame('Artículo libre', articuloLibreEtiqueta(null, null));
    }

    // ---- Producto interno ----------------------------------------------------------------------

    public function testIdentificaElProductoInterno(): void
    {
        $this->assertSame(99, articuloLibreIdProducto($this->pdo));
        $this->assertTrue(articuloLibreEsProducto($this->pdo, 99));
        $this->assertFalse(articuloLibreEsProducto($this->pdo, 10));
        $this->assertFalse(articuloLibreEsProducto($this->pdo, 0));
    }

    public function testSinMigracionNoHayProductoInterno(): void
    {
        $vacio = new PDO('sqlite::memory:');
        $vacio->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->assertNull(articuloLibreIdProducto($vacio));
        $this->assertFalse(articuloLibreEsProducto($vacio, 99));
        $this->assertSame([], articuloLibreFetchLineas($vacio, '2026-09-01 00:00:00', '2026-10-01 00:00:00'));
        $this->assertSame([], articuloLibreMarcasUsadas($vacio));
        $this->assertFalse(articuloLibreColumnasListas($vacio));
        $this->assertSame('p.nombre', articuloLibreSqlNombreLinea($vacio, 'dp', 'p'), 'sin columnas la consulta no debe tronar');
    }

    public function testNombreDeLineaUsaLaDescripcionLibre(): void
    {
        $this->seedPedido(1, 'MOS-1', 1, 1, 'pagado', '2026-09-10 12:00:00');
        $this->seedDetalle(1, 1, 99, 1, 300, 150, 300, 'Marca X', 'Colágeno');
        $this->seedDetalle(2, 1, 10, 1, 200, 100, 200, null, null);

        $sql = 'SELECT dp.id_detalle, ' . articuloLibreSqlNombreLinea($this->pdo, 'dp', 'p') . ' AS nombre, '
            . articuloLibreSqlVarianteLinea($this->pdo, 'dp', 'p') . ' AS variante
                FROM detalle_pedidos dp JOIN productos p ON p.id_producto = dp.id_producto ORDER BY dp.id_detalle';
        $rows = $this->pdo->query($sql)->fetchAll();

        $this->assertSame('Colágeno', $rows[0]['nombre']);
        $this->assertSame('Marca X', $rows[0]['variante']);
        $this->assertSame('Omega 3', $rows[1]['nombre']);
        $this->assertNull($rows[1]['variante']);
    }

    // ---- Reporte ------------------------------------------------------------------------------

    public function testReporteDelMesSumaVentasCostoYGananciaPorMarca(): void
    {
        $this->seedPedido(1, 'MOS-1', 1, 1, 'pagado', '2026-09-10 12:00:00');
        $this->seedPedido(2, 'MOS-2', 2, 1, 'pagado', '2026-09-11 12:00:00');
        $this->seedPedido(3, 'MOS-3', 1, 1, 'cancelado', '2026-09-12 12:00:00');
        $this->seedPedido(4, 'MOS-4', 1, 1, 'pagado', '2026-08-30 12:00:00');
        $this->seedPedido(5, 'MOS-5', 1, 2, 'pagado', '2026-09-13 12:00:00');

        $this->seedDetalle(1, 1, 99, 2, 300, 150, 600, 'Nutrilite', 'Colágeno');      // gana 300
        $this->seedDetalle(2, 1, 10, 1, 200, 100, 200, null, null);                    // catalogo: fuera
        $this->seedDetalle(3, 2, 99, 1, 100, 120, 100, 'Herbalife', 'Batido');         // pierde 20
        $this->seedDetalle(4, 2, 99, 1, 90, 50, 90, 'nutrilite', 'Vitamina C');        // gana 40 (misma marca)
        $this->seedDetalle(5, 3, 99, 5, 100, 10, 500, 'Nutrilite', 'Cancelado');      // pedido cancelado
        $this->seedDetalle(6, 4, 99, 5, 100, 10, 500, 'Nutrilite', 'Mes pasado');     // otro mes
        $this->seedDetalle(7, 5, 99, 1, 100, 60, 100, 'Otra', 'Otra sucursal');        // almacen 2
        $this->pdo->exec("INSERT INTO detalle_pedidos (id_detalle, id_pedido, id_producto, cantidad, precio_unitario, costo_unitario, subtotal, estado_entrega, marca_libre, descripcion_libre) VALUES (8, 1, 99, 1, 100, 10, 100, 'rechazado', 'Nutrilite', 'Rechazado')");

        $rango = articuloLibreRangoMes('2026-09');
        $lineas = articuloLibreFetchLineas($this->pdo, $rango['inicio'], $rango['fin'], 1);
        $this->assertSame([4, 3, 1], array_map(static fn(array $l): int => (int) $l['id_detalle'], $lineas));
        $this->assertSame('Vendedor', $lineas[0]['vendedor']);

        $resumen = articuloLibreResumir($lineas);
        $this->assertSame(['piezas' => 4, 'ventas' => 790.0, 'costo' => 470.0, 'ganancia' => 320.0, 'lineas' => 3], $resumen['totales']);
        $this->assertCount(2, $resumen['por_marca'], 'Nutrilite y nutrilite son la misma marca');
        $this->assertSame('nutrilite', mb_strtolower($resumen['por_marca'][0]['marca']));
        $this->assertSame(340.0, $resumen['por_marca'][0]['ganancia']);
        $this->assertSame(-20.0, $resumen['por_marca'][1]['ganancia']);
        $this->assertSame(-20.0, $resumen['por_marca'][1]['margen_pct']);

        $soloVendedor = articuloLibreFetchLineas($this->pdo, $rango['inicio'], $rango['fin'], null, 2);
        $this->assertSame([4, 3], array_map(static fn(array $l): int => (int) $l['id_detalle'], $soloVendedor));

        $this->assertSame(['Nutrilite', 'Otra', 'nutrilite', 'Herbalife'], articuloLibreMarcasUsadas($this->pdo));
    }

    public function testRangoDeMes(): void
    {
        $hoy = new DateTimeImmutable('2026-09-26 15:00:00');
        $this->assertSame(['mes' => '2026-12', 'inicio' => '2026-12-01 00:00:00', 'fin' => '2027-01-01 00:00:00'], articuloLibreRangoMes('2026-12', $hoy));
        $this->assertSame('2026-09', articuloLibreRangoMes('basura', $hoy)['mes']);
        $this->assertSame('2026-09', articuloLibreRangoMes('2026-13', $hoy)['mes']);
        $this->assertSame('2026-09', articuloLibreRangoMes(null, $hoy)['mes']);
    }

    // ---- Entregas: no regresa inventario de algo que no tiene -----------------------------------

    public function testNoEntregarUnArticuloLibreNoCreaInventario(): void
    {
        $this->seedPedido(100, 'PED-1', 1, 1, 'en_reparto', '2026-09-10 12:00:00', 5);
        $this->seedDetalle(1, 100, 99, 2, 150, 80, 300, 'Marca X', 'Colágeno');
        $this->seedDetalle(2, 100, 10, 1, 200, 100, 200, null, null);

        $r = dbMarkProductoNoEntregado($this->pdo, 100, 1, 5, 'Ya no lo quiso', 5);

        $this->assertTrue($r['success'], (string) ($r['message'] ?? ''));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM inventario_almacen WHERE id_producto = 99')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM movimientos_inventario WHERE id_producto = 99')->fetchColumn());
        $this->assertSame('rechazado', $this->pdo->query('SELECT estado_entrega FROM detalle_pedidos WHERE id_detalle = 1')->fetchColumn());
    }

    public function testCancelarPedidoConArticuloLibreSoloRegresaLoDeCatalogo(): void
    {
        $this->seedPedido(101, 'PED-2', 1, 1, 'en_reparto', '2026-09-10 12:00:00', 5);
        $this->seedDetalle(3, 101, 99, 2, 150, 80, 300, 'Marca X', 'Colágeno');
        $this->seedDetalle(4, 101, 10, 1, 200, 100, 200, null, null);
        $this->pdo->exec('INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual) VALUES (10, 1, 3)');

        $r = dbCancelarPedidoCompleto($this->pdo, 101, 5, 'Cliente ausente', 5);

        $this->assertTrue($r['success'], (string) ($r['message'] ?? ''));
        $this->assertSame(4, (int) $this->pdo->query('SELECT cantidad_actual FROM inventario_almacen WHERE id_producto = 10')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM inventario_almacen WHERE id_producto = 99')->fetchColumn());
    }

    // ---- Contratos de archivos -----------------------------------------------------------------

    public function testMigracionCreaColumnasProductoInternoYPermiso(): void
    {
        $sql = (string) file_get_contents($this->root . '/database/migrations/20260926_000001_articulo_libre_ventas_otras_marcas.sql');
        $this->assertStringContainsString('ADD COLUMN marca_libre', $sql);
        $this->assertStringContainsString('ADD COLUMN descripcion_libre', $sql);
        $this->assertStringContainsString("'" . ARTICULO_LIBRE_CODIGO . "'", $sql);
        $this->assertStringContainsString("'archivado' AS estado", $sql, 'el producto interno nace archivado (fuera de catalogo)');
        $this->assertStringContainsString("'vender_articulo_libre'", $sql);
        $this->assertStringNotContainsStringIgnoringCase('DELETE ', $sql);
        $this->assertContains('vender_articulo_libre', PERMISOS_EN_USO);
    }

    public function testVentasExigePermisoYNoTocaInventarioDeLineasLibres(): void
    {
        $src = (string) file_get_contents($this->root . '/api/ventas.php');
        $this->assertMatchesRegularExpression("/if \\(!hasPermission\\('vender_articulo_libre'\\)\\)/", $src);
        $this->assertStringContainsString('articuloLibreNormalizarLinea(', $src);
        $this->assertStringContainsString("'VENTA_ARTICULO_LIBRE'", $src);
        // Las lineas libres no entran al plan de lotes.
        $this->assertStringContainsString('loteFetchPlanVentaFEFO($pdo, $productosDeCatalogo', $src);
        // La rama libre inserta y hace continue antes del descuento de inventario.
        $ramaLibre = substr($src, (int) strpos($src, "if (isset(\$producto['libre']))"));
        $ramaLibre = substr($ramaLibre, 0, (int) strpos($ramaLibre, 'continue;'));
        $this->assertStringContainsString('marca_libre', $ramaLibre);
        $this->assertStringNotContainsString('inventario_almacen', $ramaLibre);
        $this->assertArrayHasKey('VENTA_ARTICULO_LIBRE', auditMapaEtiquetasAccion());
    }

    private function seedPedido(int $id, string $folio, int $idUsuario, int $idAlmacen, string $estado, string $fecha, ?int $idRepartidor = null): void
    {
        $this->pdo->prepare('INSERT INTO pedidos (id_pedido, numero_pedido, id_usuario, id_repartidor, id_almacen, estado, fecha_creacion) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $folio, $idUsuario, $idRepartidor, $idAlmacen, $estado, $fecha]);
    }

    private function seedDetalle(int $id, int $idPedido, int $idProducto, int $cantidad, float $precio, float $costo, float $subtotal, ?string $marca, ?string $descripcion): void
    {
        $this->pdo->prepare('INSERT INTO detalle_pedidos (id_detalle, id_pedido, id_producto, cantidad, precio_unitario, costo_unitario, subtotal, marca_libre, descripcion_libre) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$id, $idPedido, $idProducto, $cantidad, $precio, $costo, $subtotal, $marca, $descripcion]);
    }
}
