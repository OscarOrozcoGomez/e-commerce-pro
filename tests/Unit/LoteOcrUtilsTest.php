<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LoteOcrUtilsTest extends TestCase
{
    /* -------------------------- loteOcrParsearCaducidad -------------------------- */

    public function testCadPegadoSinDiaUsaUltimoDiaDeMes(): void
    {
        // Ejemplo real del usuario: CAD022029 -> 28-feb-2029 (2029 no es bisiesto).
        $r = loteOcrParsearCaducidad('LOTE AB1234 CAD022029');
        $this->assertNotNull($r);
        $this->assertSame('2029-02-28', $r['fecha']);
        $this->assertTrue($r['aproximada']);
    }

    public function testCadPegadoSinDiaEnAnioBisiesto(): void
    {
        $r = loteOcrParsearCaducidad('CAD022028'); // 2028 sí es bisiesto
        $this->assertNotNull($r);
        $this->assertSame('2028-02-29', $r['fecha']);
        $this->assertTrue($r['aproximada']);
    }

    public function testCadPegadoConDiaCompleto(): void
    {
        $r = loteOcrParsearCaducidad('EXP27042030');
        $this->assertNotNull($r);
        $this->assertSame('2030-04-27', $r['fecha']);
        $this->assertFalse($r['aproximada']);
    }

    public function testFechaConSeparadorDiaMesAnio(): void
    {
        // Ejemplo real del usuario: "27-04-2030".
        $r = loteOcrParsearCaducidad('VENCE: 27-04-2030');
        $this->assertNotNull($r);
        $this->assertSame('2030-04-27', $r['fecha']);
        $this->assertFalse($r['aproximada']);
    }

    public function testFechaConSeparadorYBarras(): void
    {
        $r = loteOcrParsearCaducidad('CADUCIDAD 05/12/2027');
        $this->assertNotNull($r);
        $this->assertSame('2027-12-05', $r['fecha']);
        $this->assertFalse($r['aproximada']);
    }

    public function testFechaConSeparadorFormatoMesDiaSeReordena(): void
    {
        // 13 no puede ser mes -> el primer numero es el dia aunque venga como MM-DD-YYYY.
        $r = loteOcrParsearCaducidad('04-13-2030');
        $this->assertNotNull($r);
        $this->assertSame('2030-04-13', $r['fecha']);
    }

    public function testMesAnioSinDiaUsaUltimoDiaDeMes(): void
    {
        $r = loteOcrParsearCaducidad('CAD 04-2030');
        $this->assertNotNull($r);
        $this->assertSame('2030-04-30', $r['fecha']);
        $this->assertTrue($r['aproximada']);
    }

    public function testSinFechaRegresaNull(): void
    {
        $this->assertNull(loteOcrParsearCaducidad('LOTE AB1234 CONTIENE 60 CAPSULAS'));
    }

    public function testMesEnLetrasSinDiaUsaUltimoDiaDeMes(): void
    {
        // Ejemplo real del usuario: "Jul/2028".
        $r = loteOcrParsearCaducidad('Jul/2028');
        $this->assertNotNull($r);
        $this->assertSame('2028-07-31', $r['fecha']);
        $this->assertTrue($r['aproximada']);
    }

    public function testMesEnLetrasMayusculasConEspacio(): void
    {
        $r = loteOcrParsearCaducidad('CAD JUL 2028');
        $this->assertNotNull($r);
        $this->assertSame('2028-07-31', $r['fecha']);
        $this->assertTrue($r['aproximada']);
    }

    public function testMesEnLetrasNombreCompleto(): void
    {
        $r = loteOcrParsearCaducidad('Julio-2028');
        $this->assertNotNull($r);
        $this->assertSame('2028-07-31', $r['fecha']);
    }

    public function testDiaMesEnLetrasYAnio(): void
    {
        $r = loteOcrParsearCaducidad('15-Jul-2028');
        $this->assertNotNull($r);
        $this->assertSame('2028-07-15', $r['fecha']);
        $this->assertFalse($r['aproximada']);
    }

    public function testPalabraComunNoSeConfundeConMes(): void
    {
        // "marca" y "mayor" empiezan como "mar"/"may" pero no SON esos meses.
        $this->assertNull(loteOcrParsearCaducidad('LOTE MARCA: 2028'));
        $this->assertNull(loteOcrParsearCaducidad('Cantidad mayor a 2028'));
    }

    /* -------------------------- loteOcrParsearCodigoLote -------------------------- */

    public function testLoteConEtiquetaLote(): void
    {
        $this->assertSame('AB1234', loteOcrParsearCodigoLote('LOTE: AB1234 CAD022029'));
    }

    public function testLoteConEtiquetaLotSinDosPuntos(): void
    {
        $this->assertSame('XZ99-7', loteOcrParsearCodigoLote('LOT XZ99-7'));
    }

    public function testLoteConEtiquetaLCorta(): void
    {
        $this->assertSame('45KP2', loteOcrParsearCodigoLote('L: 45KP2 EXP022029'));
    }

    public function testSinLoteRegresaNull(): void
    {
        $this->assertNull(loteOcrParsearCodigoLote('CAD022029'));
    }

    /* -------------------------- loteOcrUltimoDiaDeMes -------------------------- */

    public function testUltimoDiaDeMesFebreroNoBisiesto(): void
    {
        $this->assertSame(28, loteOcrUltimoDiaDeMes(2, 2029));
    }

    public function testUltimoDiaDeMesFebreroBisiesto(): void
    {
        $this->assertSame(29, loteOcrUltimoDiaDeMes(2, 2028));
    }

    public function testUltimoDiaDeMesAbril(): void
    {
        $this->assertSame(30, loteOcrUltimoDiaDeMes(4, 2030));
    }

    /* -------------------------- huecos por confianza (costura del bote) -------------------------- */

    /** Arma un fullTextAnnotation mínimo a partir de una lista de [texto, confianzas[]] por palabra. */
    private function fakeFullTextAnnotation(array $palabras): array
    {
        $words = [];
        foreach ($palabras as [$texto, $confianzas]) {
            $symbols = [];
            foreach (str_split($texto) as $i => $char) {
                $symbols[] = ['text' => $char, 'confidence' => $confianzas[$i] ?? 1.0];
            }
            $words[] = ['symbols' => $symbols];
        }
        return ['pages' => [['blocks' => [['paragraphs' => [['words' => $words]]]]]]];
    }

    public function testPalabrasConConfianzaAplanaLaEstructura(): void
    {
        $fta = $this->fakeFullTextAnnotation([
            ['LOTE', [1, 1, 1, 1]],
            ['AB1234', [1, 1, 1, 0.3, 1, 1]],
        ]);
        $palabras = loteOcrPalabrasConConfianza($fta);
        $this->assertCount(2, $palabras);
        $this->assertSame('LOTE', $palabras[0]['texto']);
        $this->assertSame('AB1234', $palabras[1]['texto']);
        $this->assertSame(0.3, $palabras[1]['confianzas'][3]);
    }

    public function testEnmascararPalabraMarcaCaracteresDeBajaConfianza(): void
    {
        $palabra = ['texto' => 'AB1234', 'confianzas' => [1, 1, 1, 0.3, 1, 1]];
        $this->assertSame('AB1_34', loteOcrEnmascararPalabra($palabra, 0.65));
    }

    public function testCodigoLoteConHuecosMarcaCaracterIlegible(): void
    {
        // Simula "LOTE AB1234" donde el "3" quedó tapado por la costura del bote.
        $fta = $this->fakeFullTextAnnotation([
            ['LOTE', [1, 1, 1, 1]],
            ['AB1234', [1, 1, 1, 0.2, 1, 1]],
            ['CAD022029', array_fill(0, 9, 1)],
        ]);
        $palabras = loteOcrPalabrasConConfianza($fta);
        $this->assertSame('AB1_34', loteOcrCodigoLoteConHuecos($palabras));
    }

    public function testCodigoLoteConHuecosSaltaDosPuntosSueltos(): void
    {
        // Vision a veces separa "LOTE:" del código en dos/tres palabras.
        $fta = $this->fakeFullTextAnnotation([
            ['LOTE', [1, 1, 1, 1]],
            [':', [1]],
            ['XZ99', [1, 1, 1, 1]],
        ]);
        $palabras = loteOcrPalabrasConConfianza($fta);
        $this->assertSame('XZ99', loteOcrCodigoLoteConHuecos($palabras));
    }

    public function testCodigoLoteConHuecosRegresaNullSinPalabraClave(): void
    {
        $fta = $this->fakeFullTextAnnotation([
            ['CONTIENE', array_fill(0, 8, 1)],
            ['60', [1, 1]],
        ]);
        $palabras = loteOcrPalabrasConConfianza($fta);
        $this->assertNull(loteOcrCodigoLoteConHuecos($palabras));
    }
}
