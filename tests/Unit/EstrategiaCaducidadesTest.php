<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Estrategia de venta de productos por caducar, con base de datos (SQLite en memoria):
 *  - "Poner en oferta" con escalera de precio y la gestion automatica (ofertaCadReconciliar).
 *  - Contexto que ve Alex (urgencia, motivo honesto, paquete) y precio de paquete al agendar.
 *  - Bitacora de eventos y metricas.
 *  - Gancho de oferta en el seguimiento de 24h y recompra proactiva.
 * Las reglas puras estan en OfertaCaducidadPricingTest.
 */
final class EstrategiaCaducidadesTest extends TestCase
{
    private PDO $pdo;
    private int $idCatOferta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->createSchema();
        $this->pdo->exec("INSERT INTO categorias (nombre, estado) VALUES ('Oferta', 'activo')");
        $this->idCatOferta = (int) $this->pdo->lastInsertId();
    }

    /* ------------------------------------------------------------------
     * lotePonerProductoEnOferta: escalera + gestion
     * ---------------------------------------------------------------- */

    public function testPonerEnOfertaLoteUrgenteUsaElEscalonDel30PorCiento(): void
    {
        $this->seedProducto(1, 'Omega 3', 500.0, 200.0);
        $this->seedLote(1, 'L1', $this->enDias(100), 5); // runway 100 -> urgente

        $res = lotePonerProductoEnOferta($this->pdo, 1, 0, 1);

        $this->assertSame(350.0, $res['precio_oferta']);
        $this->assertSame('urgente', $res['severidad']);
        $this->assertTrue($res['gestionada']);
        $this->assertTrue($res['precio_fijado']);
        $this->assertTrue(ofertaProductoEnOferta($this->pdo, 1));
        $this->assertSame(350.0, (float) $this->pdo->query('SELECT precio_oferta FROM productos WHERE id_producto = 1')->fetchColumn());

        $g = $this->gestion(1);
        $this->assertSame(1, (int) $g['gestiona_precio']);
        $this->assertSame(350.0, (float) $g['precio_aplicado']);
        $this->assertSame('urgente', $g['severidad']);
    }

    public function testPonerEnOfertaLoteCriticoVaDirectoAlPisoCostoMas50(): void
    {
        $this->seedProducto(2, 'Zinc', 500.0, 200.0);
        $this->seedLote(2, 'L2', $this->enDias(20), 5); // runway 20 -> critico

        $res = lotePonerProductoEnOferta($this->pdo, 2, 0, 1);

        $this->assertSame(250.0, $res['precio_oferta']);
        $this->assertSame('critico', $res['severidad']);
    }

    public function testPonerEnOfertaMarcaElLoteComoAtendido(): void
    {
        $this->seedProducto(3, 'Magnesio', 500.0, 200.0);
        $this->seedLote(3, 'L3', $this->enDias(200), 5);
        $idLote = (int) $this->pdo->lastInsertId();

        lotePonerProductoEnOferta($this->pdo, 3, $idLote, 7);

        $fila = $this->pdo->query("SELECT alerta_atendida, en_oferta FROM lotes_inventario WHERE id_lote = {$idLote}")->fetch();
        $this->assertSame([1, 1], [(int) $fila['alerta_atendida'], (int) $fila['en_oferta']]);
    }

    public function testProductoCuradoAManoConservaElComportamientoDeSiempre(): void
    {
        // Ya estaba en Ofertas (lo metio el equipo): costo+50 como siempre y SIN gestion automatica.
        $this->seedProducto(4, 'Colageno', 500.0, 200.0);
        $this->ponerEnCategoria(4);
        $this->seedLote(4, 'L4', $this->enDias(100), 5); // urgente, pero no se le aplica la escalera

        $res = lotePonerProductoEnOferta($this->pdo, 4, 0, 1);

        $this->assertTrue($res['ya_estaba']);
        $this->assertFalse($res['gestionada']);
        $this->assertSame(250.0, $res['precio_oferta']);
        $this->assertNull($this->gestion(4));
    }

    public function testPrecioManualSeRespetaYSoloSeGestionaLaPermanencia(): void
    {
        $this->seedProducto(5, 'Vitamina C', 500.0, 200.0, 180.0);
        $this->seedLote(5, 'L5', $this->enDias(100), 5);

        $res = lotePonerProductoEnOferta($this->pdo, 5, 0, 1);

        $this->assertSame(180.0, $res['precio_oferta']);
        $this->assertFalse($res['precio_fijado']);
        $this->assertTrue($res['gestionada']);
        $this->assertSame(0, (int) $this->gestion(5)['gestiona_precio']);
    }

    public function testSegundaPulsacionNuncaSubeUnPrecioYaBajado(): void
    {
        $this->seedProducto(6, 'Omega', 500.0, 200.0);
        $this->seedLote(6, 'L6', $this->enDias(20), 5);
        lotePonerProductoEnOferta($this->pdo, 6, 0, 1); // critico -> 250

        // El lote "mejora" (mas margen): un segundo clic no debe subir el precio.
        $this->pdo->exec("UPDATE lotes_inventario SET fecha_caducidad = '" . $this->enDias(200) . "' WHERE id_producto = 6");
        $res = lotePonerProductoEnOferta($this->pdo, 6, 0, 1);

        $this->assertSame(250.0, $res['precio_oferta']);
    }

    /* ------------------------------------------------------------------
     * ofertaCadReconciliar
     * ---------------------------------------------------------------- */

    public function testReconciliarRetiraDeOfertasCuandoYaNoQuedanLotesEnRiesgo(): void
    {
        $this->seedProducto(10, 'Omega', 500.0, 200.0);
        $this->seedLote(10, 'L10', $this->enDias(100), 5);
        lotePonerProductoEnOferta($this->pdo, 10, 0, 1);

        // Se acabo el lote en riesgo; solo queda uno fresco.
        $this->pdo->exec("UPDATE lotes_inventario SET estado = 'agotado', cantidad_restante = 0 WHERE id_producto = 10");
        $this->seedLote(10, 'L10-FRESCO', $this->enDias(600), 30);

        $acciones = ofertaCadReconciliar($this->pdo);

        $this->assertSame(['retirado'], array_column($acciones, 'accion'));
        $this->assertFalse(ofertaProductoEnOferta($this->pdo, 10));
        $this->assertNull($this->pdo->query('SELECT precio_oferta FROM productos WHERE id_producto = 10')->fetchColumn());
        $this->assertNull($this->gestion(10));
    }

    public function testReconciliarBajaElPrecioAlSiguienteEscalonConformeSeAcercaLaFecha(): void
    {
        $this->seedProducto(11, 'Omega', 500.0, 200.0);
        $this->seedLote(11, 'L11', $this->enDias(200), 5); // planificar -> 425
        $this->assertSame(425.0, lotePonerProductoEnOferta($this->pdo, 11, 0, 1)['precio_oferta']);

        $this->pdo->exec("UPDATE lotes_inventario SET fecha_caducidad = '" . $this->enDias(20) . "' WHERE id_producto = 11");
        $acciones = ofertaCadReconciliar($this->pdo);

        $this->assertSame(['precio_bajado'], array_column($acciones, 'accion'));
        $this->assertSame(425.0, $acciones[0]['precio_anterior']);
        $this->assertSame(250.0, $acciones[0]['precio_nuevo']);
        $this->assertSame(250.0, (float) $this->pdo->query('SELECT precio_oferta FROM productos WHERE id_producto = 11')->fetchColumn());
        $this->assertSame(250.0, (float) $this->gestion(11)['precio_aplicado']);
        $this->assertTrue(ofertaProductoEnOferta($this->pdo, 11));
    }

    public function testReconciliarNoRetiraUnLoteQueYaNoSeAlcanzaAConsumirLoBajaAlPiso(): void
    {
        // Caso visto en la copia de produccion: el envase rinde 90 dias y al lote le quedan 10 -> no_vendible.
        $this->seedProducto(19, 'Coenzima', 499.0, 200.0, null, 180, 2);
        $this->seedLote(19, 'L19', $this->enDias(200), 5); // runway 200-90=110 -> urgente
        $this->assertEqualsWithDelta(349.30, lotePonerProductoEnOferta($this->pdo, 19, 0, 1)['precio_oferta'], 0.001);

        $this->pdo->exec("UPDATE lotes_inventario SET fecha_caducidad = '" . $this->enDias(10) . "' WHERE id_producto = 19");
        $acciones = ofertaCadReconciliar($this->pdo);

        $this->assertSame(['precio_bajado'], array_column($acciones, 'accion'));
        $this->assertEqualsWithDelta(250.00, (float) $this->pdo->query('SELECT precio_oferta FROM productos WHERE id_producto = 19')->fetchColumn(), 0.001); // piso costo+50
        $this->assertTrue(ofertaProductoEnOferta($this->pdo, 19));
        // Alex, en cambio, sigue sin ofrecerlo: no se alcanza a consumir.
        $this->assertSame([], array_column(aiListarOfertasVigentes($this->pdo), 'id_producto'));
    }

    public function testReconciliarSiRetiraCuandoElUnicoLoteYaCaduco(): void
    {
        $this->seedProducto(28, 'Vencido', 500.0, 200.0);
        $this->seedLote(28, 'L28', $this->enDias(100), 5);
        lotePonerProductoEnOferta($this->pdo, 28, 0, 1);
        $this->pdo->exec("UPDATE lotes_inventario SET fecha_caducidad = '" . $this->enDias(-3) . "' WHERE id_producto = 28");

        $this->assertSame(['retirado'], array_column(ofertaCadReconciliar($this->pdo), 'accion'));
    }

    public function testReconciliarSinCambioDeUrgenciaNoTocaNada(): void
    {
        $this->seedProducto(12, 'Omega', 500.0, 200.0);
        $this->seedLote(12, 'L12', $this->enDias(200), 5);
        lotePonerProductoEnOferta($this->pdo, 12, 0, 1);

        $this->assertSame([], ofertaCadReconciliar($this->pdo));
    }

    public function testReconciliarNuncaSubeElPrecio(): void
    {
        $this->seedProducto(13, 'Omega', 500.0, 200.0);
        $this->seedLote(13, 'L13', $this->enDias(20), 5);
        lotePonerProductoEnOferta($this->pdo, 13, 0, 1); // critico -> 250

        $this->pdo->exec("UPDATE lotes_inventario SET fecha_caducidad = '" . $this->enDias(200) . "' WHERE id_producto = 13");
        ofertaCadReconciliar($this->pdo);

        $this->assertSame(250.0, (float) $this->pdo->query('SELECT precio_oferta FROM productos WHERE id_producto = 13')->fetchColumn());
    }

    public function testReconciliarRespetaUnPrecioCambiadoAMano(): void
    {
        $this->seedProducto(14, 'Omega', 500.0, 200.0);
        $this->seedLote(14, 'L14', $this->enDias(200), 5);
        lotePonerProductoEnOferta($this->pdo, 14, 0, 1);

        $this->pdo->exec('UPDATE productos SET precio_oferta = 300 WHERE id_producto = 14'); // alguien lo cambio en la ficha
        $this->pdo->exec("UPDATE lotes_inventario SET fecha_caducidad = '" . $this->enDias(20) . "' WHERE id_producto = 14");
        $acciones = ofertaCadReconciliar($this->pdo);

        $this->assertSame(['precio_manual'], array_column($acciones, 'accion'));
        $this->assertSame(300.0, (float) $this->pdo->query('SELECT precio_oferta FROM productos WHERE id_producto = 14')->fetchColumn());
        $this->assertSame(0, (int) $this->gestion(14)['gestiona_precio']);
    }

    public function testReconciliarAlRetirarConservaUnPrecioManual(): void
    {
        $this->seedProducto(15, 'Omega', 500.0, 200.0, 180.0); // override manual desde el inicio
        $this->seedLote(15, 'L15', $this->enDias(100), 5);
        lotePonerProductoEnOferta($this->pdo, 15, 0, 1);

        $this->pdo->exec("UPDATE lotes_inventario SET fecha_caducidad = '" . $this->enDias(600) . "' WHERE id_producto = 15");
        $acciones = ofertaCadReconciliar($this->pdo);

        $this->assertSame(['retirado'], array_column($acciones, 'accion'));
        $this->assertFalse(ofertaProductoEnOferta($this->pdo, 15));
        $this->assertSame(180.0, (float) $this->pdo->query('SELECT precio_oferta FROM productos WHERE id_producto = 15')->fetchColumn());
    }

    public function testReconciliarLiberaAlQueSeSacoDeOfertasAMano(): void
    {
        $this->seedProducto(16, 'Omega', 500.0, 200.0);
        $this->seedLote(16, 'L16', $this->enDias(100), 5);
        lotePonerProductoEnOferta($this->pdo, 16, 0, 1);
        $this->pdo->exec('DELETE FROM producto_categorias WHERE id_producto = 16');

        $acciones = ofertaCadReconciliar($this->pdo);

        $this->assertSame(['liberado'], array_column($acciones, 'accion'));
        $this->assertNull($this->gestion(16));
    }

    public function testReconciliarNuncaTocaLoQueElEquipoMetioAMano(): void
    {
        $this->seedProducto(17, 'Curado', 500.0, 200.0, 300.0);
        $this->ponerEnCategoria(17);
        $this->seedLote(17, 'L17', $this->enDias(600), 5); // sin riesgo, pero no esta gestionado

        $this->assertSame([], ofertaCadReconciliar($this->pdo));
        $this->assertTrue(ofertaProductoEnOferta($this->pdo, 17));
    }

    public function testReconciliarEnDryRunSoloReportaSinEscribir(): void
    {
        $this->seedProducto(18, 'Omega', 500.0, 200.0);
        $this->seedLote(18, 'L18', $this->enDias(100), 5);
        lotePonerProductoEnOferta($this->pdo, 18, 0, 1);
        $this->pdo->exec("UPDATE lotes_inventario SET fecha_caducidad = '" . $this->enDias(600) . "' WHERE id_producto = 18");

        $acciones = ofertaCadReconciliar($this->pdo, true);

        $this->assertSame(['retirado'], array_column($acciones, 'accion'));
        $this->assertTrue(ofertaProductoEnOferta($this->pdo, 18));
        $this->assertNotNull($this->gestion(18));
    }

    public function testReconciliarSinLaTablaNoHaceNada(): void
    {
        $this->pdo->exec('DROP TABLE oferta_caducidad_gestion');

        $this->assertSame([], ofertaCadReconciliar($this->pdo));
    }

    /* ------------------------------------------------------------------
     * Lo que ve Alex
     * ---------------------------------------------------------------- */

    public function testConsultarOfertasOrdenaPorUrgenciaYExplicaElMotivo(): void
    {
        $this->seedProducto(20, 'Alfa', 500.0, 200.0, 350.0);   // urgente
        $this->seedProducto(21, 'Beta', 500.0, 200.0);          // critico
        $this->seedProducto(22, 'Gamma', 500.0, 200.0);         // sin lotes: sin urgencia
        foreach ([20, 21, 22] as $id) {
            $this->ponerEnCategoria($id);
            $this->seedInventario($id, 10);
        }
        $this->seedLote(20, 'A', $this->enDias(100), 5);
        $this->seedLote(21, 'B', $this->enDias(20), 5);

        $ofertas = aiListarOfertasVigentes($this->pdo);

        $this->assertSame([21, 20, 22], array_column($ofertas, 'id_producto'));
        $this->assertSame('alta', $ofertas[0]['urgencia']);
        $this->assertSame('media', $ofertas[1]['urgencia']);
        $this->assertArrayNotHasKey('urgencia', $ofertas[2]);
        $this->assertArrayNotHasKey('motivo', $ofertas[2]);
        $this->assertStringContainsString('caducan el ' . ofertaCadFechaLegible($this->enDias(20)), $ofertas[0]['motivo']);
        $this->assertSame($this->enDias(20), $ofertas[0]['caduca_el']);
        $this->assertSame(5, $ofertas[0]['piezas_con_fecha_corta']);
    }

    public function testDuracionInventadaSeQuitaCuandoNoHayDatoCapturado(): void
    {
        $resultados = '{"ok":true,"productos":[{"nombre":"Calcium 180 Caps","duracion_envase":"NO CAPTURADA: no la estimes"}]}';
        $resp = "El Calcium esta en oferta a \$287. Un envase rinde aproximadamente 90 dias con la dosis sugerida. Caduca el 30 de marzo de 2027. ¿Te lo aparto?";

        $r = aiQuitarDuracionInventada($resp, $resultados);

        $this->assertStringNotContainsString('90 dias', $r);
        $this->assertStringContainsString('esta en oferta a $287', $r);
        $this->assertStringContainsString('Caduca el 30 de marzo de 2027', $r);
        $this->assertStringContainsString('un asesor te lo confirma', $r);
    }

    public function testDuracionSeConservaSiAlgunaHerramientaTrajoElDatoReal(): void
    {
        $resultados = '{"productos":[{"nombre":"A","duracion_envase":"NO CAPTURADA"},{"nombre":"B","rendimiento_estimado":"90 dias"}]}';
        $resp = 'El B rinde aproximadamente 90 dias con la dosis sugerida.';

        $this->assertSame($resp, aiQuitarDuracionInventada($resp, $resultados));
    }

    public function testDuracionNoSeTocaSiNoHuboProductosSinDosis(): void
    {
        $resp = 'Un envase rinde unos 60 dias con la dosis sugerida.';

        $this->assertSame($resp, aiQuitarDuracionInventada($resp, '{"ok":true}'));
    }

    public function testDuracionNoConfundeFechasDeCaducidadNiPlazosDeEntrega(): void
    {
        $resultados = '{"motivo":"No tenemos capturada la duracion de un envase de este producto"}';
        $resp = 'Caduca el 11 de octubre de 2026 (en 20 dias). La entrega es el miercoles. Quedan 4 piezas.';

        $this->assertSame($resp, aiQuitarDuracionInventada($resp, $resultados));
    }

    public function testAvisoDePagoSeAgregaCuandoPreguntanPorTarjetaYAlexNoLoAclara(): void
    {
        $r = aiAsegurarAvisoDePago('¿Puedo pagar con tarjeta de crédito? Quiero 1 Collagen', '¡Claro que sí! Aquí está el producto: $349.');

        $this->assertStringContainsString('solo manejamos efectivo o transferencia', $r);
        $this->assertStringStartsWith('¡Claro que sí!', $r);
    }

    public function testAvisoDePagoNoSeDuplicaSiAlexYaLoAclaro(): void
    {
        $resp = 'Por ahora solo manejamos efectivo o transferencia contra entrega.';

        $this->assertSame($resp, aiAsegurarAvisoDePago('¿aceptan tarjeta?', $resp));
    }

    public function testAvisoDePagoNoTocaConversacionesQueNoHablanDeOtrosMetodos(): void
    {
        $this->assertSame('Hola', aiAsegurarAvisoDePago('¿Tienen ofertas?', 'Hola'));
        $this->assertSame('Hola', aiAsegurarAvisoDePago('Pago en efectivo el miércoles', 'Hola'));
    }

    public function testUnaOfertaSinDescuentoRealNoSeAdelantaNiLlevaMotivo(): void
    {
        // Caso real de la copia de produccion: precio_oferta (424) por ENCIMA del precio normal (399).
        $this->seedProducto(25, 'Aloe', 399.0, 374.0, 424.0);
        $this->seedProducto(26, 'Bueno', 500.0, 200.0);
        foreach ([25, 26] as $id) {
            $this->ponerEnCategoria($id);
            $this->seedInventario($id, 10);
        }
        $this->seedLote(25, 'A', $this->enDias(20), 5); // critico
        $this->seedLote(26, 'B', $this->enDias(100), 5); // urgente

        $ofertas = aiListarOfertasVigentes($this->pdo);

        $this->assertSame([26, 25], array_column($ofertas, 'id_producto'));
        $this->assertSame(0.0, $ofertas[1]['ahorro']);
        $this->assertArrayNotHasKey('urgencia', $ofertas[1]);
        $this->assertArrayNotHasKey('motivo', $ofertas[1]);
        $this->assertArrayNotHasKey('paquete', $ofertas[1]);
    }

    public function testConsultarOfertasEmpataPorOrdenAlfabetico(): void
    {
        $this->seedProducto(23, 'Zeta', 500.0, 200.0);
        $this->seedProducto(24, 'Alfa', 500.0, 200.0);
        foreach ([23, 24] as $id) {
            $this->ponerEnCategoria($id);
            $this->seedInventario($id, 10);
        }

        $this->assertSame([24, 23], array_column(aiListarOfertasVigentes($this->pdo), 'id_producto'));
    }

    public function testPaqueteSoloApareceSiSobraProductoPorCaducarYHayMargen(): void
    {
        $this->seedProducto(30, 'ConMargen', 500.0, 200.0, 350.0);
        $this->seedProducto(31, 'EnElPiso', 500.0, 200.0);  // critico: oferta = piso, sin paquete
        $this->seedProducto(32, 'UnaPieza', 500.0, 200.0, 350.0);
        foreach ([30, 31, 32] as $id) {
            $this->ponerEnCategoria($id);
            $this->seedInventario($id, 10);
        }
        $this->seedLote(30, 'A', $this->enDias(100), 5);
        $this->seedLote(31, 'B', $this->enDias(20), 5);
        $this->seedLote(32, 'C', $this->enDias(100), 1);

        $porId = array_column(aiListarOfertasVigentes($this->pdo), null, 'id_producto');

        $this->assertSame(
            ['cantidad_minima' => 2, 'cantidad_maxima' => 5, 'precio_unitario' => 300.0, 'ahorro_por_pieza' => 50.0, 'ahorro_vs_precio_normal_por_pieza' => 200.0],
            $porId[30]['paquete']
        );
        $this->assertArrayNotHasKey('paquete', $porId[31]);
        $this->assertArrayNotHasKey('paquete', $porId[32]);
    }

    public function testConsultarInventarioTraeMotivoYPaqueteDeLosProductosEnOferta(): void
    {
        $this->seedProducto(33, 'Omega Especial', 500.0, 200.0, 350.0);
        $this->ponerEnCategoria(33);
        $this->seedInventario(33, 10);
        $this->seedLote(33, 'A', $this->enDias(100), 5);

        $producto = aiSearchInventory($this->pdo, 'Omega Especial')[0];

        $this->assertTrue($producto['en_oferta']);
        $this->assertSame('media', $producto['urgencia_oferta']);
        $this->assertStringContainsString('fecha de caducidad corta', $producto['motivo_oferta']);
        $this->assertSame(300.0, $producto['paquete']['precio_unitario']);
    }

    public function testOfertaSinDosisCapturadaLeDiceAAlexQueNoEstimeLaDuracion(): void
    {
        $this->seedProducto(36, 'Sin Dosis 180 Caps', 500.0, 200.0, 350.0);          // sin capsulas/porcion capturadas
        $this->seedProducto(37, 'Con Dosis', 500.0, 200.0, 350.0, 180, 2);
        foreach ([36, 37] as $id) {
            $this->ponerEnCategoria($id);
            $this->seedInventario($id, 5);
        }

        $sinDosis = aiSearchInventory($this->pdo, 'Sin Dosis')[0];
        $conDosis = aiSearchInventory($this->pdo, 'Con Dosis')[0];

        $this->assertStringContainsString('NO CAPTURADA', $sinDosis['duracion_envase']);
        $this->assertArrayNotHasKey('rendimiento_estimado', $sinDosis);
        $this->assertArrayNotHasKey('duracion_envase', $conDosis);
        $this->assertArrayHasKey('rendimiento_estimado', $conDosis);
    }

    public function testAgendarVentaAplicaElPrecioDePaqueteSoloDentroDelRangoAutorizado(): void
    {
        $this->seedProducto(34, 'Omega', 500.0, 200.0, 350.0);
        $this->ponerEnCategoria(34);
        $this->seedInventario(34, 15);
        $this->seedLote(34, 'A', $this->enDias(100), 5);     // 5 en riesgo
        $this->seedLote(34, 'B', $this->enDias(600), 10);    // fresco

        $una = aiResolveOrderItems($this->pdo, [['id_producto' => 34, 'cantidad' => 1]])['items'][0];
        $dos = aiResolveOrderItems($this->pdo, [['id_producto' => 34, 'cantidad' => 2]])['items'][0];
        $cinco = aiResolveOrderItems($this->pdo, [['id_producto' => 34, 'cantidad' => 5]])['items'][0];
        $seis = aiResolveOrderItems($this->pdo, [['id_producto' => 34, 'cantidad' => 6]])['items'][0];

        $this->assertSame(350.0, $una['precio']);
        $this->assertFalse($una['paquete']);
        $this->assertSame(300.0, $dos['precio']);
        $this->assertTrue($dos['paquete']);
        $this->assertSame(300.0, $cinco['precio']);
        // Mas de lo que hay por caducar: el precio de paquete NO se extiende a piezas frescas.
        $this->assertSame(350.0, $seis['precio']);
        $this->assertFalse($seis['paquete']);
        $this->assertTrue($seis['en_oferta']);
        $this->assertSame(500.0, $seis['precio_normal']);
    }

    public function testAgendarVentaSinOfertaCobraElPrecioNormalSinPaquete(): void
    {
        $this->seedProducto(35, 'Normal', 500.0, 200.0);
        $this->seedInventario(35, 10);

        $item = aiResolveOrderItems($this->pdo, [['id_producto' => 35, 'cantidad' => 3]])['items'][0];

        $this->assertSame(500.0, $item['precio']);
        $this->assertFalse($item['paquete']);
        $this->assertFalse($item['en_oferta']);
    }

    public function testListaDelPosPrecargaElPrecioDeOfertaSoloSiEsDescuentoReal(): void
    {
        $this->seedProducto(70, 'Myo', 899.0, 577.32);            // oferta costo+50 = 627.32
        $this->seedProducto(71, 'Manual', 599.0, 339.32, 400.0);   // override manual
        $this->seedProducto(72, 'Delgado', 240.0, 200.0);          // costo+50 supera la venta: sin descuento
        $this->seedProducto(73, 'Normal', 100.0, 40.0);            // no esta en Ofertas
        foreach ([70, 71, 72] as $id) {
            $this->ponerEnCategoria($id);
        }
        $filas = array_map(
            static fn(int $id): array => ['id_producto' => $id, 'nombre' => 'x'],
            [70, 71, 72, 73]
        );
        $filas = array_map(function (array $f): array {
            $r = $this->pdo->query('SELECT precio_venta, precio_costo, precio_oferta FROM productos WHERE id_producto = ' . $f['id_producto'])->fetch();
            return $f + $r;
        }, $filas);

        $lista = array_column(ofertaAplicarPrecioEfectivoALista($this->pdo, $filas), null, 'id_producto');

        $this->assertEqualsWithDelta(627.32, (float) $lista[70]['precio_venta'], 0.001);
        $this->assertTrue($lista[70]['en_oferta']);
        $this->assertEqualsWithDelta(899.0, (float) $lista[70]['precio_normal'], 0.001);
        $this->assertEqualsWithDelta(400.0, (float) $lista[71]['precio_venta'], 0.001);
        $this->assertEqualsWithDelta(240.0, (float) $lista[72]['precio_venta'], 0.001);
        $this->assertArrayNotHasKey('en_oferta', $lista[72]);
        $this->assertEqualsWithDelta(100.0, (float) $lista[73]['precio_venta'], 0.001);
        $this->assertArrayNotHasKey('en_oferta', $lista[73]);
    }

    public function testListaDelPosSinTablasDeCategoriasNoRompe(): void
    {
        $this->pdo->exec('DROP TABLE producto_categorias');
        $filas = [['id_producto' => 1, 'precio_venta' => 100.0, 'precio_costo' => 40.0, 'precio_oferta' => null]];

        $this->assertSame($filas, ofertaAplicarPrecioEfectivoALista($this->pdo, $filas));
    }

    /* ------------------------------------------------------------------
     * Bitacora y metricas
     * ---------------------------------------------------------------- */

    public function testConsultarOfertasRegistraUnEventoPorProductoSinDuplicarNiFiltrarDatosInternos(): void
    {
        $this->seedProducto(40, 'Omega', 500.0, 200.0);
        $this->ponerEnCategoria(40);
        $this->seedInventario(40, 10);
        $this->seedLote(40, 'A', $this->enDias(20), 5);

        $r1 = aiToolConsultarOfertas($this->pdo, [], ['id_conversacion' => 9, 'id_cliente' => 3]);
        aiToolConsultarOfertas($this->pdo, [], ['id_conversacion' => 9, 'id_cliente' => 3]); // segunda vez, mismo dia

        $this->assertArrayNotHasKey('_severidad', $r1['ofertas'][0]);
        $eventos = $this->pdo->query("SELECT * FROM alex_oferta_eventos WHERE tipo = 'consultada'")->fetchAll();
        $this->assertCount(1, $eventos);
        $this->assertSame([9, 3, 40, 'critico'], [(int) $eventos[0]['id_conversacion'], (int) $eventos[0]['id_cliente'], (int) $eventos[0]['id_producto'], $eventos[0]['severidad']]);
    }

    public function testRegistrarEventoSinLaTablaNoRompeNada(): void
    {
        $this->pdo->exec('DROP TABLE alex_oferta_eventos');

        alexOfertaRegistrarEvento($this->pdo, ALEX_OFERTA_EVENTO_VENDIDA, 1, ['cantidad' => 1]);

        $this->assertSame(0, alexOfertaContarHoy($this->pdo, ALEX_OFERTA_EVENTO_VENDIDA));
        $this->assertFalse(alexOfertaMetricas($this->pdo)['disponible']);
    }

    public function testMetricasCuentanVentasConversionPaqueteYRecompra(): void
    {
        $this->seedProducto(41, 'Omega', 500.0, 200.0);
        $this->seedProducto(42, 'Zinc', 300.0, 100.0);

        // Conversacion 1: vio la oferta y compro. Conversacion 2: solo la vio.
        alexOfertaRegistrarEvento($this->pdo, ALEX_OFERTA_EVENTO_CONSULTADA, 41, ['id_conversacion' => 1, 'id_cliente' => 5]);
        alexOfertaRegistrarEvento($this->pdo, ALEX_OFERTA_EVENTO_CONSULTADA, 41, ['id_conversacion' => 2, 'id_cliente' => 6]);
        alexOfertaRegistrarEvento($this->pdo, ALEX_OFERTA_EVENTO_VENDIDA, 41, [
            'id_conversacion' => 1, 'id_cliente' => 5, 'id_pedido' => 100, 'cantidad' => 2,
            'precio_unitario' => 300.0, 'precio_normal' => 500.0, 'severidad' => 'critico', 'paquete' => true,
        ]);
        alexOfertaRegistrarEvento($this->pdo, ALEX_OFERTA_EVENTO_VENDIDA, 42, [
            'id_conversacion' => 1, 'id_cliente' => 5, 'id_pedido' => 100, 'cantidad' => 1,
            'precio_unitario' => 200.0, 'precio_normal' => 300.0, 'severidad' => 'planificar',
        ]);
        // Recompra enviada al cliente 7 por el producto 41, y ese cliente compro despues.
        $this->pdo->exec("INSERT INTO alex_oferta_eventos (tipo, id_cliente, id_conversacion, id_producto, creado_en) VALUES ('recompra_enviada', 7, 3, 41, '" . date('Y-m-d H:i:s', strtotime('-2 days')) . "')");
        alexOfertaRegistrarEvento($this->pdo, ALEX_OFERTA_EVENTO_VENDIDA, 41, [
            'id_conversacion' => 3, 'id_cliente' => 7, 'id_pedido' => 101, 'cantidad' => 1,
            'precio_unitario' => 300.0, 'precio_normal' => 500.0, 'severidad' => 'urgente',
        ]);

        $m = alexOfertaMetricas($this->pdo, 30);

        $this->assertTrue($m['disponible']);
        $this->assertSame(['conversaciones' => 2, 'productos' => 1], $m['consultas']);
        $this->assertSame(2, $m['ventas']['pedidos']);
        $this->assertSame(4, $m['ventas']['unidades']);
        $this->assertSame(1100.0, $m['ventas']['ingreso']);          // 2x300 + 1x200 + 1x300
        $this->assertSame(700.0, $m['ventas']['ahorro_clientes']);   // 2x200 + 1x100 + 1x200
        $this->assertSame(2, $m['ventas']['unidades_paquete']);
        $this->assertSame(3, $m['ventas']['unidades_urgentes']);     // critico x2 + urgente x1
        $this->assertSame(50.0, $m['conversion_pct']);               // 1 de 2 conversaciones que vieron oferta
        $this->assertSame(['enviadas' => 1, 'convertidas' => 1], $m['recompra']);
        $this->assertSame(41, $m['top_productos'][0]['id_producto']);
        $this->assertSame(3, $m['top_productos'][0]['unidades']);
    }

    /* ------------------------------------------------------------------
     * Seguimiento de 24h con gancho de oferta
     * ---------------------------------------------------------------- */

    public function testProductosMencionadosSalenDeLosResultadosDeHerramientas(): void
    {
        $idConv = $this->seedConversacion('5213312345678');
        $this->seedMensaje($idConv, 'tool', json_encode(['ok' => true, 'productos' => [['id_producto' => 50], ['id_producto' => 51]]]), '-3 days', 'consultar_inventario');
        $this->seedMensaje($idConv, 'tool', json_encode(['ok' => true, 'ofertas' => [['id_producto' => 52]]]), '-2 days', 'consultar_ofertas');
        $this->seedMensaje($idConv, 'tool', json_encode(['ok' => true]), '-2 days', 'enviar_catalogo');

        $this->assertEqualsCanonicalizing([50, 51, 52], aiProductosMencionadosEnConversacion($this->pdo, $idConv));
    }

    public function testOfertaRelevanteSoloSiElClienteYaVioElProducto(): void
    {
        $this->seedProducto(53, 'Omega', 500.0, 200.0);
        $this->seedProducto(54, 'Otro', 500.0, 200.0);
        foreach ([53, 54] as $id) {
            $this->ponerEnCategoria($id);
            $this->seedInventario($id, 10);
        }
        $this->seedLote(53, 'A', $this->enDias(20), 5);

        $vio = $this->seedConversacion('5213312345001');
        $this->seedMensaje($vio, 'tool', json_encode(['productos' => [['id_producto' => 53]]]), '-2 days', 'consultar_inventario');
        $noVio = $this->seedConversacion('5213312345002');
        $this->seedMensaje($noVio, 'tool', json_encode(['productos' => [['id_producto' => 99]]]), '-2 days', 'consultar_inventario');

        $this->assertSame(53, aiOfertaRelevanteParaConversacion($this->pdo, $vio)['id_producto']);
        $this->assertNull(aiOfertaRelevanteParaConversacion($this->pdo, $noVio));
        $this->assertNull(aiOfertaRelevanteParaConversacion($this->pdo, $this->seedConversacion('5213312345003')));
    }

    public function testDatoDeOfertaParaMensajeSoloTraeHechosDelSistema(): void
    {
        $dato = aiBuildDatoOfertaParaMensaje([
            'nombre' => 'Omega 3', 'precio_oferta' => 350.0, 'precio_normal' => 500.0,
            'motivo' => 'Es producto de fecha de caducidad corta.',
        ]);

        $this->assertStringContainsString('"Omega 3"', $dato);
        $this->assertStringContainsString('$350.00', $dato);
        $this->assertStringContainsString('$500.00', $dato);
        $this->assertStringContainsString('fecha de caducidad corta', $dato);
        $this->assertStringContainsString('UNA sola vez', $dato);
    }

    public function testSeguimientoDe24hNoSeMandaSiYaSeLeHizoUnaRecompraSinContestar(): void
    {
        $idConv = $this->seedConversacion('5213312345010');
        $this->seedMensaje($idConv, 'user', 'hola', '-10 days');
        $this->seedMensaje($idConv, 'assistant', 'recompra', '-2 days', null, 1);
        $this->pdo->exec("INSERT INTO alex_oferta_eventos (tipo, id_conversacion, id_cliente, id_producto, creado_en) VALUES ('recompra_enviada', {$idConv}, 1, 1, '" . date('Y-m-d H:i:s', strtotime('-2 days')) . "')");

        $this->assertSame([], aiFindConversationsNeedingFollowup($this->pdo));

        // El cliente contesta y despues Alex le responde: la exclusion se levanta sola.
        $this->seedMensaje($idConv, 'user', 'si, apartamelo', '-40 hours');
        $this->seedMensaje($idConv, 'assistant', 'listo', '-30 hours', null, 1);

        $this->assertCount(1, aiFindConversationsNeedingFollowup($this->pdo));
    }

    /* ------------------------------------------------------------------
     * Recompra proactiva
     * ---------------------------------------------------------------- */

    public function testFechaFinDeTratamientoYVentanaDeRecompra(): void
    {
        $this->assertSame('2026-09-30', aiRecompraFechaFinTratamiento('2026-08-31 10:00:00', 1, 30));
        $this->assertSame('2026-10-30', aiRecompraFechaFinTratamiento('2026-08-31', 2, 30));

        $fin = '2026-09-30';
        $this->assertFalse(aiRecompraEnVentana($fin, new DateTimeImmutable('2026-09-19')));      // faltan 11 dias
        $this->assertTrue(aiRecompraEnVentana($fin, new DateTimeImmutable('2026-09-20')));       // faltan 10
        $this->assertTrue(aiRecompraEnVentana($fin, new DateTimeImmutable('2026-10-30 12:00'))); // 30 dias despues
        $this->assertFalse(aiRecompraEnVentana($fin, new DateTimeImmutable('2026-10-31')));
    }

    public function testRecompraSoloEnHorarioDiurno(): void
    {
        $this->assertFalse(aiRecompraEnHorario(new DateTimeImmutable('2026-09-20 08:59')));
        $this->assertTrue(aiRecompraEnHorario(new DateTimeImmutable('2026-09-20 09:00')));
        $this->assertTrue(aiRecompraEnHorario(new DateTimeImmutable('2026-09-20 19:59')));
        $this->assertFalse(aiRecompraEnHorario(new DateTimeImmutable('2026-09-20 20:00')));
    }

    public function testRecompraEncuentraAlClienteAQuienSeLeTerminaElEnvase(): void
    {
        $idConv = $this->escenarioRecompra();

        $candidatos = aiFindRecompraCandidatos($this->pdo);

        $this->assertCount(1, $candidatos);
        $this->assertSame($idConv, $candidatos[0]['id_conversacion']);
        $this->assertSame(10, $candidatos[0]['id_cliente']);
        $this->assertSame(60, $candidatos[0]['oferta']['id_producto']);
        $this->assertSame('media', $candidatos[0]['oferta']['urgencia']);
    }

    public function testRecompraNoLeEscribeAQuienSeLeHabloHaceMuyPoco(): void
    {
        $idConv = $this->escenarioRecompra();
        $this->seedMensaje($idConv, 'user', 'gracias', '-2 days');

        $this->assertSame([], aiFindRecompraCandidatos($this->pdo));
    }

    public function testRecompraNoSeRepiteEnLosSiguientes45Dias(): void
    {
        $this->escenarioRecompra();
        $this->pdo->exec("INSERT INTO alex_oferta_eventos (tipo, id_cliente, id_conversacion, id_producto, creado_en) VALUES ('recompra_enviada', 10, 1, 60, '" . date('Y-m-d H:i:s', strtotime('-10 days')) . "')");

        $this->assertSame([], aiFindRecompraCandidatos($this->pdo));
    }

    public function testRecompraExcluyeFueraDeCoberturaYForaneos(): void
    {
        $idConv = $this->escenarioRecompra();
        $this->pdo->exec("INSERT INTO whatsapp_etiquetas (nombre) VALUES ('" . AI_TAG_FUERA_COBERTURA . "')");
        $this->pdo->exec("INSERT INTO whatsapp_conversacion_etiquetas (id_conversacion, id_etiqueta) VALUES ({$idConv}, " . (int) $this->pdo->lastInsertId() . ')');
        $this->assertSame([], aiFindRecompraCandidatos($this->pdo));

        $this->pdo->exec('DELETE FROM whatsapp_conversacion_etiquetas');
        $this->pdo->exec("UPDATE whatsapp_conversaciones SET wa_id = '5215512345678' WHERE id_conversacion = {$idConv}"); // lada 55
        $this->assertSame([], aiFindRecompraCandidatos($this->pdo));
    }

    public function testRecompraIgnoraProductosSinDosisCapturada(): void
    {
        $this->escenarioRecompra();
        $this->pdo->exec('UPDATE productos SET porcion_capsulas = NULL WHERE id_producto = 60');

        $this->assertSame([], aiFindRecompraCandidatos($this->pdo));
    }

    public function testRecompraIgnoraOfertasSinMotivoDeCaducidad(): void
    {
        $this->escenarioRecompra();
        $this->pdo->exec("UPDATE lotes_inventario SET fecha_caducidad = '" . $this->enDias(600) . "' WHERE id_producto = 60"); // ya sin riesgo

        $this->assertSame([], aiFindRecompraCandidatos($this->pdo));
    }

    public function testRecompraIgnoraAQuienNoHaCompradoEsteProducto(): void
    {
        $this->escenarioRecompra();
        $this->pdo->exec('DELETE FROM detalle_pedidos');

        $this->assertSame([], aiFindRecompraCandidatos($this->pdo));
    }

    /* ------------------------------------------------------------------ */

    /**
     * Producto 60 en oferta con un lote urgente; el cliente 10 lo compro hace 35 dias (1 envase de
     * 30 dias: se le acabo hace 5) y su conversacion lleva 20 dias en silencio. Regresa el id de
     * la conversacion.
     */
    private function escenarioRecompra(): int
    {
        $this->seedProducto(60, 'Omega 3', 500.0, 200.0, 350.0, 60, 2);
        $this->ponerEnCategoria(60);
        $this->seedInventario(60, 10);
        $this->seedLote(60, 'A', $this->enDias(100), 5);

        $this->pdo->exec("INSERT INTO pedidos (id_pedido, id_cliente, estado, fecha_creacion) VALUES (1, 10, 'pagado', '" . date('Y-m-d H:i:s', strtotime('-35 days')) . "')");
        $this->pdo->exec("INSERT INTO detalle_pedidos (id_pedido, id_producto, cantidad, estado_entrega) VALUES (1, 60, 1, 'entregado')");

        $idConv = $this->seedConversacion('5213312345678', 10);
        $this->seedMensaje($idConv, 'user', 'hola quiero omega', '-36 days');
        $this->seedMensaje($idConv, 'assistant', 'claro', '-20 days', null, 1);

        return $idConv;
    }

    private function seedConversacion(string $waId, ?int $idCliente = null): int
    {
        $this->pdo->prepare('INSERT INTO whatsapp_conversaciones (wa_id, id_cliente) VALUES (?, ?)')->execute([$waId, $idCliente]);

        return (int) $this->pdo->lastInsertId();
    }

    private function seedMensaje(int $idConv, string $rol, ?string $contenido, string $hace, ?string $toolName = null, int $enviado = 0): void
    {
        $this->pdo->prepare(
            'INSERT INTO whatsapp_mensajes (id_conversacion, rol, contenido, tool_name, enviado_whatsapp, creado_en) VALUES (?, ?, ?, ?, ?, ?)'
        )->execute([$idConv, $rol, $contenido, $toolName, $enviado, date('Y-m-d H:i:s', strtotime($hace))]);
    }

    private function gestion(int $idProducto): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM oferta_caducidad_gestion WHERE id_producto = ?');
        $stmt->execute([$idProducto]);

        return $stmt->fetch() ?: null;
    }

    private function enDias(int $dias): string
    {
        return (new DateTimeImmutable('today'))->modify(($dias >= 0 ? '+' : '') . $dias . ' days')->format('Y-m-d');
    }

    private function ponerEnCategoria(int $idProducto): void
    {
        $this->pdo->prepare('INSERT INTO producto_categorias (id_producto, id_categoria) VALUES (?, ?)')
            ->execute([$idProducto, $this->idCatOferta]);
    }

    private function seedProducto(int $id, string $nombre, float $venta, float $costo, ?float $oferta = null, ?int $capsulas = null, ?int $porcion = null): void
    {
        $this->pdo->prepare(
            'INSERT INTO productos (id_producto, nombre, precio_venta, precio_costo, precio_oferta, capsulas_por_envase, porcion_capsulas)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([$id, $nombre, $venta, $costo, $oferta, $capsulas, $porcion]);
    }

    private function seedInventario(int $idProducto, int $cantidad): void
    {
        $this->pdo->prepare('INSERT INTO inventario_almacen (id_producto, id_almacen, cantidad_actual) VALUES (?, 1, ?)')
            ->execute([$idProducto, $cantidad]);
    }

    private function seedLote(int $idProducto, string $codigo, string $fechaCaducidad, int $cantidad): void
    {
        $this->pdo->prepare(
            'INSERT INTO lotes_inventario (id_producto, id_almacen, codigo_lote, fecha_caducidad, fecha_ingreso, cantidad_inicial, cantidad_restante)
             VALUES (?, 1, ?, ?, ?, ?, ?)'
        )->execute([$idProducto, $codigo, $fechaCaducidad, (new DateTimeImmutable('today'))->modify('-30 days')->format('Y-m-d'), $cantidad, $cantidad]);
    }

    private function createSchema(): void
    {
        $this->pdo->exec('CREATE TABLE almacenes (id_almacen INTEGER PRIMARY KEY, nombre TEXT NOT NULL)');
        $this->pdo->exec("CREATE TABLE productos (
            id_producto INTEGER PRIMARY KEY, nombre TEXT NOT NULL, nombre_variante TEXT NULL,
            nombre_corto TEXT NULL,
            codigo_barras TEXT NOT NULL DEFAULT '', descripcion TEXT NULL,
            ingredientes TEXT NULL, beneficios TEXT NULL, perfil_recomendado TEXT NULL, modo_uso TEXT NULL, tabla_nutrimental TEXT NULL,
            precio_venta REAL NOT NULL DEFAULT 0, precio_costo REAL NOT NULL DEFAULT 0,
            precio_oferta REAL NULL, categoria TEXT NULL,
            capsulas_por_envase INTEGER NULL, porcion_capsulas INTEGER NULL,
            estado TEXT NOT NULL DEFAULT 'activo'
        )");
        $this->pdo->exec('CREATE TABLE inventario_almacen (id_producto INTEGER NOT NULL, id_almacen INTEGER NOT NULL, cantidad_actual INTEGER NOT NULL DEFAULT 0)');
        $this->pdo->exec('CREATE TABLE producto_relacionados (id_producto INTEGER NOT NULL, id_producto_relacionado INTEGER NOT NULL, nota TEXT NULL)');
        $this->pdo->exec("CREATE TABLE categorias (id_categoria INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT, estado TEXT DEFAULT 'activo')");
        $this->pdo->exec('CREATE TABLE producto_categorias (id_producto INTEGER, id_categoria INTEGER, PRIMARY KEY (id_producto, id_categoria))');
        $this->pdo->exec("CREATE TABLE lotes_inventario (
            id_lote INTEGER PRIMARY KEY AUTOINCREMENT,
            id_producto INTEGER NOT NULL, id_almacen INTEGER NULL,
            codigo_lote TEXT NOT NULL, fecha_caducidad TEXT NOT NULL, fecha_ingreso TEXT NOT NULL,
            cantidad_inicial INTEGER NOT NULL, cantidad_restante INTEGER NOT NULL,
            estado TEXT NOT NULL DEFAULT 'activo',
            alerta_atendida INTEGER NOT NULL DEFAULT 0, en_oferta INTEGER NOT NULL DEFAULT 0,
            notas_seguimiento TEXT NULL, id_usuario_seguimiento INTEGER NULL
        )");
        $this->pdo->exec("CREATE TABLE pedidos (
            id_pedido INTEGER PRIMARY KEY AUTOINCREMENT, id_cliente INTEGER NULL, estado TEXT NOT NULL DEFAULT 'pagado',
            afecta_inventario INTEGER NOT NULL DEFAULT 1, fecha_creacion TEXT NOT NULL
        )");
        $this->pdo->exec("CREATE TABLE detalle_pedidos (
            id_detalle INTEGER PRIMARY KEY AUTOINCREMENT, id_pedido INTEGER NOT NULL,
            id_producto INTEGER NOT NULL, cantidad INTEGER NOT NULL DEFAULT 1,
            estado_entrega TEXT NOT NULL DEFAULT 'entregado'
        )");
        $this->pdo->exec("CREATE TABLE oferta_caducidad_gestion (
            id_producto INTEGER PRIMARY KEY, severidad TEXT NOT NULL DEFAULT '',
            gestiona_precio INTEGER NOT NULL DEFAULT 1, precio_aplicado REAL NULL,
            creado_en TEXT DEFAULT CURRENT_TIMESTAMP, actualizado_en TEXT DEFAULT CURRENT_TIMESTAMP
        )");
        $this->pdo->exec('CREATE TABLE alex_oferta_eventos (
            id_evento INTEGER PRIMARY KEY AUTOINCREMENT, tipo TEXT NOT NULL,
            id_conversacion INTEGER NULL, id_cliente INTEGER NULL, id_producto INTEGER NOT NULL, id_pedido INTEGER NULL,
            cantidad INTEGER NULL, precio_unitario REAL NULL, precio_normal REAL NULL, severidad TEXT NULL,
            paquete INTEGER NOT NULL DEFAULT 0, creado_en TEXT DEFAULT CURRENT_TIMESTAMP
        )');
        $this->pdo->exec('CREATE TABLE whatsapp_conversaciones (
            id_conversacion INTEGER PRIMARY KEY AUTOINCREMENT, wa_id TEXT NOT NULL UNIQUE, id_cliente INTEGER NULL,
            nombre_perfil TEXT NULL, estado_bot TEXT NOT NULL DEFAULT "activo", motivo_transferencia TEXT NULL,
            ultimo_mensaje_en TEXT NULL, seguimiento_enviado_en TEXT NULL, creado_en TEXT DEFAULT CURRENT_TIMESTAMP
        )');
        $this->pdo->exec('CREATE TABLE whatsapp_mensajes (
            id_mensaje INTEGER PRIMARY KEY AUTOINCREMENT, id_conversacion INTEGER NOT NULL, wa_message_id TEXT NULL UNIQUE,
            rol TEXT NOT NULL, contenido TEXT NULL, tool_calls_json TEXT NULL, tool_call_id TEXT NULL, tool_name TEXT NULL,
            enviado_whatsapp INTEGER NOT NULL DEFAULT 0, creado_en TEXT DEFAULT CURRENT_TIMESTAMP
        )');
        $this->pdo->exec('CREATE TABLE whatsapp_etiquetas (
            id_etiqueta INTEGER PRIMARY KEY AUTOINCREMENT, id_etiqueta_wa TEXT NULL UNIQUE, nombre TEXT NOT NULL UNIQUE, color TEXT NOT NULL DEFAULT "grey"
        )');
        $this->pdo->exec('CREATE TABLE whatsapp_conversacion_etiquetas (
            id_conversacion INTEGER NOT NULL, id_etiqueta INTEGER NOT NULL, asignado_en TEXT DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id_conversacion, id_etiqueta)
        )');
        $this->pdo->exec("INSERT INTO almacenes (id_almacen, nombre) VALUES (1, 'Matriz')");
    }
}
