<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pruebas de core/ai_foto_producto_utils.php: la pista que recibe Alex cuando el cliente manda la
 * foto de un frasco y el OCR del puente lee solo parte de la etiqueta. Incluye el caso real del
 * 2026-09-20: del frasco "WOMENS MULT MATUR3" el OCR solo leyo "Mento Alimentic" (= "SUPLEMENTO
 * ALIMENTICIO") y Alex ofrecio productos que no eran.
 */
final class AiFotoProductoTest extends TestCase
{
    /** Catalogo fabricado: nombres cortos reales de la tienda, mas nombres largos que chocan con los ingredientes. */
    private function catalogo(): array
    {
        // Relleno para que el peso de cada palabra (mientras menos nombres la comparten, mas pesa) se parezca
        // al de un catalogo real de ~90 nombres cortos y no al de uno de 6 entradas.
        $relleno = [];
        for ($n = 1; $n <= 30; $n++) {
            $relleno[] = ['id_producto' => 1000 + $n, 'nombre' => "Relleno {$n}", 'nombre_corto' => "Relleno Numero {$n}"];
        }

        return array_merge($relleno, [
            ['id_producto' => 187, 'nombre' => 'Womens Mult Matur3 - 180 Caps | 500 mg', 'nombre_corto' => 'Womens Mult Matur3'],
            ['id_producto' => 185, 'nombre' => 'Multivitaminico Mujer 40+ (inactivo)', 'nombre_corto' => 'Womens Mult Matur3'],
            ['id_producto' => 114, 'nombre' => 'Multivitaminico para Hombre 40+', 'nombre_corto' => 'Mens Mult Matur3'],
            ['id_producto' => 30, 'nombre' => '60 Billion Probiotics', 'nombre_corto' => '60 Billion Probiotics'],
            ['id_producto' => 44, 'nombre' => 'Ashwagandha Root Powder', 'nombre_corto' => 'Ashwagandha Root Powder'],
            ['id_producto' => 229, 'nombre' => 'Colageno Hidrolizado', 'nombre_corto' => null],   // sin nombre corto: no se reconoce por foto
            ['id_producto' => 34, 'nombre' => 'Vitamina A', 'nombre_corto' => ''],
            ['id_producto' => 55, 'nombre' => 'Vitamina D3', 'nombre_corto' => 'D3'],               // demasiado corto: se ignora
            ['id_producto' => 56, 'nombre' => 'Vitamina B', 'nombre_corto' => 'Threonate Mag'],
        ]);
    }

    private function mensajeFoto(string $caption, string $ocr): string
    {
        return '[El cliente envio una foto'
            . ($caption !== '' ? ' con el texto: "' . $caption . '".' : '.')
            . ' Texto detectado en la imagen (puede tener errores de OCR): ' . $ocr . ']';
    }

    // ---- extraccion y normalizacion ----------------------------------------------------------

    public function testExtraerPartesSeparaCaptionYOcr(): void
    {
        $partes = aiFotoExtraerPartes($this->mensajeFoto('Que precio tiene este?', "WOMENS\nMULT MATUR3"));

        $this->assertSame('Que precio tiene este?', $partes['caption']);
        $this->assertSame("WOMENS\nMULT MATUR3", $partes['ocr']);
    }

    public function testExtraerPartesSinCaption(): void
    {
        $partes = aiFotoExtraerPartes($this->mensajeFoto('', 'Ashwagandha* LIFE'));

        $this->assertSame('', $partes['caption']);
        $this->assertSame('Ashwagandha* LIFE', $partes['ocr']);
    }

    public function testExtraerPartesDevuelveNullSiNoEsFotoConOcr(): void
    {
        $this->assertNull(aiFotoExtraerPartes('Hola, que precio tiene el omega 3?'));
        $this->assertNull(aiFotoExtraerPartes('[El cliente envio una foto.]'));
    }

    public function testNormalizarQuitaAcentosSignosYMayusculas(): void
    {
        $this->assertSame('womens mult matur3', aiFotoNormalizar("  WOMENS   Mult-Matur3!! "));
        $this->assertSame('colageno hidrolizado', aiFotoNormalizar('Colágeno Hidrolizado'));
        $this->assertSame('nino', aiFotoNormalizar('NIÑO'));
    }

    // ---- leyendas genericas -------------------------------------------------------------------

    public function testLeyendasGenericasDetectaSuplementoAlimenticioMutilado(): void
    {
        $info = aiFotoLeyendasGenericas(aiFotoNormalizar('Mento Alimentic'));

        $this->assertContains('Suplemento alimenticio', $info['leyendas']);
    }

    public function testLeyendasGenericasYNumeroDeCapsulas(): void
    {
        $info = aiFotoLeyendasGenericas(aiFotoNormalizar("Capsulas a base de Colágeno Hidrolizado\ncontenido 180 cápsulas"));

        $this->assertSame(180, $info['capsulas']);
        $this->assertContains('Capsulas a base de...', $info['leyendas']);
        $this->assertContains('Contenido N capsulas', $info['leyendas']);
    }

    public function testLeyendasGenericasNoDisparaConUnComprobante(): void
    {
        $info = aiFotoLeyendasGenericas(aiFotoNormalizar('Scotiabank Transferencia exitosa Enviaste $1,028.00 a Perla Velazco'));

        $this->assertSame([], $info['leyendas']);
        $this->assertNull($info['capsulas']);
    }

    // ---- indice y coincidencias ---------------------------------------------------------------

    public function testIndiceSoloUsaNombresCortosYAgrupaLosRepetidos(): void
    {
        $indice = aiFotoConstruirIndice($this->catalogo());
        $etiquetas = array_column($indice, 'label');

        $this->assertContains('Womens Mult Matur3', $etiquetas);
        $this->assertNotContains('Colageno Hidrolizado', $etiquetas); // nombre largo: no entra
        $this->assertNotContains('D3', $etiquetas);                    // demasiado corto
        $mult = array_values(array_filter($indice, static fn(array $e): bool => $e['label'] === 'Womens Mult Matur3'))[0];
        $this->assertEqualsCanonicalizing([187, 185], $mult['ids']);
    }

    public function testCoincidenciaClaraCuandoElOcrLeeElNombre(): void
    {
        $indice = aiFotoConstruirIndice($this->catalogo());

        $c = aiFotoCoincidencias(aiFotoNormalizar("B LIFE\nWOMENS\nMULT MATUR3\nSUPLEMENTO ALIMENTICIO"), $indice);

        $this->assertSame('Womens Mult Matur3', $c[0]['label']);
        $this->assertSame(1.0, $c[0]['puntaje']);
    }

    public function testCoincidenciaToleraErroresDeLectura(): void
    {
        $indice = aiFotoConstruirIndice($this->catalogo());

        $c = aiFotoCoincidencias(aiFotoNormalizar('WOMENS MULT MATURS'), $indice); // "MATUR3" leido como "MATURS"

        $this->assertSame('Womens Mult Matur3', $c[0]['label']);
        $this->assertGreaterThanOrEqual(AI_FOTO_PUNTAJE_CLARO, $c[0]['puntaje']);
    }

    public function testUnaSolaPalabraSueltaNoBastaParaProponerUnProducto(): void
    {
        $indice = aiFotoConstruirIndice($this->catalogo());

        // "Probioticos" (ingrediente en espanol) se parece a "Probiotics" pero no es el producto "60 Billion Probiotics".
        $this->assertSame([], aiFotoCoincidencias(aiFotoNormalizar('Colageno, Vitaminas, Plantas y Probioticos'), $indice));
        // Ni "Ashwagandha" suelta, que tambien aparece en listas de ingredientes.
        $this->assertSame([], aiFotoCoincidencias(aiFotoNormalizar('Ashwagandha* LIFE'), $indice));
    }

    // ---- analisis y linea de contexto ---------------------------------------------------------

    /** Regresion del caso real: el OCR solo leyo leyendas; NO debe proponer ningun producto. */
    public function testCasoRealSoloLeyendasNoProponeProductoYPideNoBuscarLasLeyendas(): void
    {
        $indice = aiFotoConstruirIndice($this->catalogo());
        $mensaje = $this->mensajeFoto(
            'Que precio tiene este?',
            "Mento Alimentic\nCapsulas a base de Colágeno Hidrolizado,\nMinerales, Vitaminas, Plantas\ny Probióticos.\ncontenido 180 cápsulas\n(0.5 g c/u) Cont. Neto 90 g"
        );

        $a = aiFotoAnalizar($mensaje, $indice);
        $linea = aiFotoBuildContextLine($a);

        $this->assertTrue($a['es_foto_ocr']);
        $this->assertTrue($a['es_foto_de_etiqueta']);
        $this->assertSame([], $a['coincidencias']);
        $this->assertSame(180, $a['capsulas']);
        $this->assertStringStartsWith('FOTO DE PRODUCTO SIN NOMBRE LEGIBLE', $linea);
        $this->assertStringContainsString('Mento Alimentic', $linea);
        $this->assertStringContainsString('NO ofrezcas productos "similares"', $linea);
        $this->assertStringContainsString('transferir_a_humano', $linea);
        $this->assertStringContainsString('180 capsulas', $linea);
    }

    public function testSiElOcrLeyoElNombreLaLineaSugiereEseProducto(): void
    {
        $indice = aiFotoConstruirIndice($this->catalogo());

        $a = aiFotoAnalizar($this->mensajeFoto('Que precio tiene este?', "WOMENS\nMULT MATUR3\nSUPLEMENTO ALIMENTICIO\ncontenido 180 cápsulas"), $indice);
        $linea = aiFotoBuildContextLine($a);

        $this->assertTrue($a['nombre_claro']);
        $this->assertFalse($a['ambiguo']);
        $this->assertStringContainsString('«Womens Mult Matur3»', $linea);
        $this->assertStringContainsString('consultar_inventario', $linea);
        $this->assertStringContainsString('bastante seguridad', $linea);
        $this->assertStringNotContainsString('SIN NOMBRE LEGIBLE', $linea);
    }

    public function testNombreParcialEntreDosProductosParecidosEsAmbiguo(): void
    {
        $indice = aiFotoConstruirIndice($this->catalogo());

        $a = aiFotoAnalizar($this->mensajeFoto('', "MULT MATUR3\nSUPLEMENTO ALIMENTICIO"), $indice);
        $linea = aiFotoBuildContextLine($a);

        $this->assertTrue($a['ambiguo']);
        $this->assertFalse($a['nombre_claro']);
        $this->assertStringContainsString('«Mens Mult Matur3»', $linea);
        $this->assertStringContainsString('«Womens Mult Matur3»', $linea);
        $this->assertStringContainsString('confirme cual dice el frasco', $linea);
    }

    public function testElCaptionDelClienteTambienCuentaParaElEmparejamiento(): void
    {
        $indice = aiFotoConstruirIndice($this->catalogo());

        $a = aiFotoAnalizar($this->mensajeFoto('Quiero el Womens Mult Matur3, que precio tiene?', 'Mento Alimentic'), $indice);

        $this->assertSame('Womens Mult Matur3', $a['coincidencias'][0]['label']);
    }

    public function testUnComprobanteOUnMensajeNormalNoGeneranLinea(): void
    {
        $indice = aiFotoConstruirIndice($this->catalogo());

        $comprobante = aiFotoAnalizar($this->mensajeFoto('Te comparto el comprobante.', "Scotiabank\nTransferencia exitosa\nEnviaste \$1,028.00"), $indice);
        $this->assertSame('', aiFotoBuildContextLine($comprobante));

        $texto = aiFotoAnalizar('Hola, que precio tiene el omega 3?', $indice);
        $this->assertFalse($texto['es_foto_ocr']);
        $this->assertSame('', aiFotoBuildContextLine($texto));
    }

    public function testContextLineParaMensajeNoTocaLaBdSiNoHayFoto(): void
    {
        // Un PDO sin tablas: si intentara consultar el catalogo, lanzaria; con texto normal debe regresar '' sin tocarlo.
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->assertSame('', aiFotoBuildContextLineParaMensaje($pdo, 'Hola, buenas tardes'));
    }

    public function testContextLineParaMensajeUsaElCatalogoDeLaBd(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec("CREATE TABLE productos (id_producto INTEGER PRIMARY KEY, nombre TEXT, nombre_corto TEXT, estado TEXT)");
        $pdo->exec("INSERT INTO productos VALUES (187, 'Womens Mult Matur3 - 180 Caps', 'Womens Mult Matur3', 'activo'), (185, 'Womens Mult Matur3 viejo', 'Womens Mult Matur3', 'inactivo')");

        $linea = aiFotoBuildContextLineParaMensaje($pdo, $this->mensajeFoto('', "WOMENS MULT MATUR3\nSUPLEMENTO ALIMENTICIO"));

        $this->assertStringContainsString('«Womens Mult Matur3»', $linea);
    }

    public function testContextLineParaMensajeNoLanzaSiFallaLaBd(): void
    {
        $pdo = new PDO('sqlite::memory:'); // sin tabla productos
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $previo = ini_set('error_log', sys_get_temp_dir() . '/ai_foto_producto_test.log');
        try {
            $this->assertSame('', aiFotoBuildContextLineParaMensaje($pdo, $this->mensajeFoto('', 'Mento Alimentic')));
        } finally {
            ini_set('error_log', (string) $previo);
        }
    }

    // ---- integracion con el prompt ------------------------------------------------------------

    public function testElPromptIncluyeLaLineaDeFotoSoloCuandoSeLeDa(): void
    {
        $sin = aiBuildSystemPrompt([], null);
        $con = aiBuildSystemPrompt([], null, [], [], null, null, null, [], null, 'FOTO DE PRODUCTO SIN NOMBRE LEGIBLE: prueba');

        $this->assertStringNotContainsString('FOTO DE PRODUCTO SIN NOMBRE LEGIBLE: prueba', $sin);
        $this->assertStringContainsString('FOTO DE PRODUCTO SIN NOMBRE LEGIBLE: prueba', $con);
    }

    public function testElPromptSiempreExplicaQueLasLeyendasNoSonElNombreDelProducto(): void
    {
        $prompt = aiBuildSystemPrompt([], null);

        $this->assertStringContainsString('Fotos de frascos o etiquetas', $prompt);
        $this->assertStringContainsString('"Mento Alimentic" es "SUPLEMENTO ALIMENTICIO" mal leido', $prompt);
        $this->assertStringContainsString('no ofrezcas productos "similares"', $prompt);
    }
}
