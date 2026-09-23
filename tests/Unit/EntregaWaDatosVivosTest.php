<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Datos del aviso de WhatsApp de la ruta en entregas.php: solo productos vigentes y total actual.
 */
final class EntregaWaDatosVivosTest extends TestCase
{
    public function testExcluyeProductosRechazadosYConservaLosEntregados(): void
    {
        $productos = entregaWaProductosVigentes([
            ['nombre' => 'Omega 3', 'nombre_variante' => '60 caps', 'cantidad' => 2, 'estado_entrega' => 'entregado'],
            ['nombre' => 'Colageno', 'nombre_variante' => null, 'cantidad' => 1, 'estado_entrega' => 'rechazado'],
            ['nombre' => 'Magnesio', 'nombre_variante' => '', 'cantidad' => '3', 'estado_entrega' => 'entregado'],
        ]);

        $this->assertSame([
            ['nombre' => 'Omega 3 - 60 caps', 'cantidad' => 2],
            ['nombre' => 'Magnesio', 'cantidad' => 3],
        ], $productos);
    }

    public function testSinColumnaOEstadoNuloCuentaComoEntregado(): void
    {
        $productos = entregaWaProductosVigentes([
            ['nombre' => 'A', 'cantidad' => 1],
            ['nombre' => 'B', 'cantidad' => 1, 'estado_entrega' => null],
        ]);

        $this->assertCount(2, $productos);
    }

    public function testCualquierEstadoDistintoDeEntregadoSeExcluye(): void
    {
        // Estados desconocidos/futuros o con otra capitalizacion no se le anuncian al cliente.
        $productos = entregaWaProductosVigentes([
            ['nombre' => 'A', 'cantidad' => 1, 'estado_entrega' => 'no_entregado'],
            ['nombre' => 'B', 'cantidad' => 1, 'estado_entrega' => 'Entregado'],
            ['nombre' => 'C', 'cantidad' => 1, 'estado_entrega' => ''],
        ]);

        $this->assertSame([], $productos);
    }

    public function testVarianteSoloEspaciosNoAgregaGuion(): void
    {
        $productos = entregaWaProductosVigentes([
            ['nombre' => '  Zinc ', 'nombre_variante' => '   ', 'cantidad' => 1, 'estado_entrega' => 'entregado'],
        ]);

        $this->assertSame('Zinc', $productos[0]['nombre']);
    }

    public function testTodosRechazadosDaListaVacia(): void
    {
        $this->assertSame([], entregaWaProductosVigentes([
            ['nombre' => 'A', 'cantidad' => 1, 'estado_entrega' => 'rechazado'],
        ]));
        $this->assertSame([], entregaWaProductosVigentes([]));
    }

    public function testDatosVivosPorPedidoConTotalYCostoEnvioActuales(): void
    {
        $datos = entregaWaDatosVivos(
            [
                ['id_pedido' => '10', 'total' => '450.50', 'costo_envio' => '50.00'],
                ['id_pedido' => 11, 'total' => 200, 'costo_envio' => null],
            ],
            [
                10 => [
                    ['nombre' => 'A', 'cantidad' => 1, 'estado_entrega' => 'entregado'],
                    ['nombre' => 'B', 'cantidad' => 1, 'estado_entrega' => 'rechazado'],
                ],
            ]
        );

        $this->assertSame([10, 11], array_keys($datos));
        $this->assertSame([['nombre' => 'A', 'cantidad' => 1]], $datos[10]['productos']);
        $this->assertSame(450.5, $datos[10]['total']);
        $this->assertSame(50.0, $datos[10]['costo_envio']);
        // Pedido sin detalle cargado: lista vacia (el JS cae al numero de pedido), sin envio.
        $this->assertSame([], $datos[11]['productos']);
        $this->assertSame(0.0, $datos[11]['costo_envio']);
    }

    public function testIgnoraPedidosSinIdYCostoEnvioNegativo(): void
    {
        $datos = entregaWaDatosVivos(
            [
                ['id_pedido' => 0, 'total' => 100],
                ['total' => 100],
                ['id_pedido' => 5, 'total' => 100, 'costo_envio' => -30],
            ],
            []
        );

        $this->assertSame([5], array_keys($datos));
        $this->assertSame(0.0, $datos[5]['costo_envio']);
    }

    public function testJsonVacioEsObjetoNoArreglo(): void
    {
        // En JS routeDatosVivos["10"] debe funcionar; un "[]" seria un arreglo.
        $this->assertSame('{}', entregaWaDatosVivosJson([]));
    }

    public function testJsonConIdsConsecutivosDesdeCeroSigueSiendoObjeto(): void
    {
        $json = entregaWaDatosVivosJson([0 => ['total' => 1.0], 1 => ['total' => 2.0]]);

        $this->assertStringStartsWith('{', $json);
    }

    public function testJsonNoPuedeCerrarLaEtiquetaScript(): void
    {
        $json = entregaWaDatosVivosJson(entregaWaDatosVivos(
            [['id_pedido' => 1, 'total' => 10, 'costo_envio' => 0]],
            [1 => [['nombre' => '</script><script>alert("x")</script>', 'nombre_variante' => "O'Brien & <b>", 'cantidad' => 1, 'estado_entrega' => 'entregado']]]
        ));

        $this->assertStringNotContainsString('<', $json);
        $this->assertStringNotContainsString('>', $json);
        $this->assertStringNotContainsString("'", $json);
        $this->assertStringNotContainsString('&', $json);
        // Pero al decodificarlo el nombre queda intacto para el mensaje.
        $decoded = json_decode($json, true);
        $this->assertSame('</script><script>alert("x")</script> - O\'Brien & <b>', $decoded['1']['productos'][0]['nombre']);
    }

    public function testJsonConUtf8InvalidoNoRompeElScript(): void
    {
        $json = entregaWaDatosVivosJson(entregaWaDatosVivos(
            [['id_pedido' => 1, 'total' => 10]],
            [1 => [['nombre' => "Caf\xE9 roto", 'cantidad' => 1]]]
        ));

        $this->assertNotSame('', $json);
        $this->assertIsArray(json_decode($json, true));
    }

    public function testJsonConservaAcentos(): void
    {
        $json = entregaWaDatosVivosJson(entregaWaDatosVivos(
            [['id_pedido' => 1, 'total' => 10]],
            [1 => [['nombre' => 'Vitamina C con acerola ñ', 'cantidad' => 1]]]
        ));

        $this->assertSame('Vitamina C con acerola ñ', json_decode($json, true)['1']['productos'][0]['nombre']);
    }
}
