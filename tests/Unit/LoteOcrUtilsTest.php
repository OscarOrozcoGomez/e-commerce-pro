<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class LoteOcrUtilsTest extends TestCase
{
    /**
     * @dataProvider fechasProvider
     */
    public function testNormalizarFecha(string $texto, ?string $esperada, bool $aprox): void
    {
        $r = loteOcrNormalizarFecha($texto);
        $this->assertSame($esperada, $r['fecha'], "texto: {$texto}");
        if ($esperada !== null) {
            $this->assertSame($aprox, $r['aproximada'], "texto: {$texto}");
        }
    }

    public static function fechasProvider(): array
    {
        return [
            'iso completa'        => ['CAD 2027-07-31', '2027-07-31', false],
            'iso mes/anio'        => ['EXP 2027/07', '2027-07-01', true],
            'mes espanol y anio'  => ['Caduca: JUL 2027', '2027-07-01', true],
            'mes ingles dia anio' => ['BEST BEFORE JUL 15 2027', '2027-07-15', false],
            'dia mes espanol'     => ['15 AGO 2027', '2027-08-15', false],
            'ddmmyyyy mx'         => ['VENCE 31/12/2027', '2027-12-31', false],
            'mm/yyyy'             => ['EXP 07/2027', '2027-07-01', true],
            'mm/yy'               => ['12/27', '2027-12-01', true],
            'mmyyyy pegado'       => ['L25 072027', '2027-07-01', true],
            'sin fecha'           => ['LOTE ABC123', null, false],
        ];
    }

    /**
     * @dataProvider codigoProvider
     */
    public function testExtraerCodigoLote(string $texto, ?string $esperado): void
    {
        $this->assertSame($esperado, loteOcrExtraerCodigoLote($texto));
    }

    public static function codigoProvider(): array
    {
        return [
            ['LOTE: LOT6758\nCAD JUL 2027', 'LOT6758'],
            ['Lot L2507A', 'L2507A'],
            ["Batch #  AB12CD\n", 'AB12CD'],
            ['sin nada relevante aqui', null],
        ];
    }

    public function testExtraerCapsulas(): void
    {
        $texto = "Datos de la porcion\nTamano de la porcion: 2 capsulas\nContenido: 180 capsulas por envase";
        $r = loteOcrExtraerCapsulas($texto);
        $this->assertSame(180, $r['capsulas_por_envase']);
        $this->assertSame(2, $r['porcion_capsulas']);
    }

    public function testExtraerCapsulasConServingsYForma(): void
    {
        $texto = "Suplemento en softgels\nServing size: 1 softgel\nServings per container: 60";
        $r = loteOcrExtraerCapsulas($texto);
        $this->assertSame(60, $r['servings_por_envase']);
        $this->assertSame(1, $r['porcion_capsulas']);
        $this->assertSame(60, $r['capsulas_por_envase']); // derivado: 60 servings * 1
        $this->assertSame('Softgels', $r['forma']);
    }

    public function testExtraerContenidoNeto(): void
    {
        $this->assertSame('90 capsulas', loteOcrExtraerContenidoNeto('CONTENIDO NETO: 90 capsulas'));
        $this->assertSame('500 mg', loteOcrExtraerContenidoNeto('Contenido neto 500 mg por tableta'));
        $this->assertNull(loteOcrExtraerContenidoNeto('sin datos de contenido'));
    }

    public function testInterpretarUsaHeuristicaSinLlm(): void
    {
        $texto = "Salmon Omega 3\nLOTE LOT6758\nCAD JUL 2027";
        $r = loteOcrInterpretar($texto, null, null);
        $this->assertSame('LOT6758', $r['codigo_lote']);
        $this->assertSame('2027-07-01', $r['fecha_caducidad']);
        $this->assertTrue($r['caducidad_aproximada']);
        $this->assertSame('heuristica', $r['fuente']);
    }

    public function testInterpretarPrefiereRespuestaDelLlm(): void
    {
        $texto = "texto ocr borroso";
        $llm = static function (string $prompt): string {
            return '```json
{"codigo_lote":"L2507A","fecha_caducidad":"2028-01-31","caducidad_aproximada":false,
 "capsulas_por_envase":120,"porcion_capsulas":3,"servings_por_envase":40,
 "forma":"Cápsulas","nombre_detectado":"Magnesio Bisglicinato","marca":"AcmeVit",
 "contenido_neto":"120 cápsulas","fecha_fabricacion":"2026-01-15","confianza":0.9}
```';
        };
        $r = loteOcrInterpretar($texto, null, $llm);
        $this->assertSame('L2507A', $r['codigo_lote']);
        $this->assertSame('2028-01-31', $r['fecha_caducidad']);
        $this->assertFalse($r['caducidad_aproximada']);
        $this->assertSame(120, $r['capsulas_por_envase']);
        $this->assertSame(3, $r['porcion_capsulas']);
        $this->assertSame(40, $r['servings_por_envase']);
        $this->assertSame('Cápsulas', $r['forma']);
        $this->assertSame('Magnesio Bisglicinato', $r['nombre_detectado']);
        $this->assertSame('AcmeVit', $r['marca']);
        $this->assertSame('120 cápsulas', $r['contenido_neto']);
        $this->assertSame('2026-01-15', $r['fecha_fabricacion']);
        $this->assertSame(0.9, $r['confianza']);
        $this->assertSame('llm', $r['fuente']);
    }

    public function testInterpretarCaeAHeuristicaSiElLlmFalla(): void
    {
        $texto = "LOTE ABC999 EXP 2027-05-10";
        $llm = static function (string $prompt): string {
            throw new RuntimeException('timeout');
        };
        $r = loteOcrInterpretar($texto, null, $llm);
        $this->assertSame('ABC999', $r['codigo_lote']);
        $this->assertSame('2027-05-10', $r['fecha_caducidad']);
        $this->assertSame('heuristica', $r['fuente']);
    }

    public function testParsearRespuestaVision(): void
    {
        $raw = json_encode(['responses' => [['fullTextAnnotation' => ['text' => "LOTE X1\nCAD 2027-07"]]]]);
        $this->assertSame("LOTE X1\nCAD 2027-07", loteOcrParsearRespuestaVision(200, $raw));
    }

    public function testParsearRespuestaVisionConError(): void
    {
        $this->expectException(RuntimeException::class);
        loteOcrParsearRespuestaVision(200, json_encode(['responses' => [['error' => ['message' => 'PERMISSION_DENIED']]]]));
    }

    public function testProcesarInyectandoOcrYLlm(): void
    {
        $ocr = static fn(string $ruta): string => "LOTE LT-42\nCONSUMIR ANTES DE 09/2027";
        $r = loteOcrProcesar(['lote' => '/fake/path.jpg'], $ocr, null);
        $this->assertSame('LT-42', $r['codigo_lote']);
        $this->assertSame('2027-09-01', $r['fecha_caducidad']);
        $this->assertArrayHasKey('texto_ocr', $r);
    }
}
