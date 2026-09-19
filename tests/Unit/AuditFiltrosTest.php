<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * views/activity_logs.php lee sus filtros de la URL. Estas pruebas cubren lo que NO debe pasar
 * con una URL hostil o mal formada: consulta rota, filtro que "se pasa de listo", OFFSET
 * desbordado, o una advertencia de PHP ("Array to string conversion") en pantalla.
 */
final class AuditFiltrosTest extends TestCase
{
    public function testSinParametrosDevuelveLosValoresPorDefecto(): void
    {
        $f = auditFiltrosDesdeGet([]);

        $this->assertSame('movimientos', $f['vista']);
        $this->assertSame(0, $f['usuario']);
        $this->assertSame(1, $f['pagina']);
        $this->assertSame(0, $f['registro']);
        foreach (['fecha_inicio', 'fecha_fin', 'accion', 'modulo', 'severidad', 'q', 'tabla', 'tipo', 'origen', 'plataforma'] as $clave) {
            $this->assertSame('', $f[$clave], $clave);
        }
    }

    public function testFiltrosValidosPasanTalCual(): void
    {
        $f = auditFiltrosDesdeGet([
            'vista' => 'navegacion', 'usuario' => '7', 'fecha_inicio' => '2026-09-01', 'fecha_fin' => '2026-09-19',
            'accion' => 'PRODUCTO_EDITADO', 'modulo' => 'productos', 'severidad' => 'alerta', 'q' => 'vitamina',
            'tabla' => 'productos', 'registro' => '27', 'pagina' => '3', 'tipo' => 'click', 'origen' => 'interno',
            'plataforma' => 'Facebook',
        ]);

        $this->assertSame('navegacion', $f['vista']);
        $this->assertSame(7, $f['usuario']);
        $this->assertSame('2026-09-01', $f['fecha_inicio']);
        $this->assertSame('PRODUCTO_EDITADO', $f['accion']);
        $this->assertSame('alerta', $f['severidad']);
        $this->assertSame(27, $f['registro']);
        $this->assertSame(3, $f['pagina']);
        $this->assertSame('click', $f['tipo']);
        $this->assertSame('Facebook', $f['plataforma']);
    }

    /** ?accion[]=x, ?q[a]=b ... PHP los entrega como arreglos: nunca deben llegar a la consulta. */
    #[DataProvider('parametrosComoArreglo')]
    public function testUnArregloEnLaUrlNoTruenaNiGeneraAdvertencias(string $clave): void
    {
        set_error_handler(static function (int $nivel, string $mensaje): bool {
            throw new ErrorException($mensaje, 0, $nivel);
        });
        try {
            $f = auditFiltrosDesdeGet([$clave => ['x', 'y'], 'pagina' => [1], 'usuario' => [5]]);
        } finally {
            restore_error_handler();
        }

        $valorPorDefecto = is_int($f[$clave] ?? null) ? 0 : '';
        if ($clave === 'vista') {
            $valorPorDefecto = 'movimientos';
        }
        $this->assertSame($valorPorDefecto, $f[$clave]);
        $this->assertSame(1, $f['pagina']);
        $this->assertSame(0, $f['usuario']);
    }

    /** @return array<string,array{0:string}> */
    public static function parametrosComoArreglo(): array
    {
        $claves = ['vista', 'accion', 'modulo', 'severidad', 'q', 'tabla', 'registro', 'fecha_inicio', 'fecha_fin', 'tipo', 'origen', 'plataforma'];
        return array_combine($claves, array_map(static fn(string $c): array => [$c], $claves));
    }

    /** Intentos de inyeccion: el valor se descarta o se conserva como texto, nunca se interpreta. */
    #[DataProvider('inyecciones')]
    public function testInyeccionEnIdentificadoresSeDescarta(string $entrada): void
    {
        $f = auditFiltrosDesdeGet(['accion' => $entrada, 'modulo' => $entrada, 'tabla' => $entrada]);
        $this->assertSame('', $f['accion']);
        $this->assertSame('', $f['modulo']);
        $this->assertSame('', $f['tabla']);
    }

    /** @return array<string,array{0:string}> */
    public static function inyecciones(): array
    {
        return [
            'comilla' => ["x' OR '1'='1"],
            'comentario' => ['x--'],
            'punto y coma' => ['x; DROP TABLE logs_auditoria'],
            'espacio' => ['PRODUCTO EDITADO'],
            'nulo' => ["PRODUCTO\0_EDITADO"],
            'unicode' => ['PRODUCTÓ'],
            'demasiado largo' => [str_repeat('A', 51)],
            'guion' => ['PRODUCTO-EDITADO'],
            'punto' => ['a.b'],
        ];
    }

    public function testSaltoDeLineaFinalSeLimpiaPeroUnoAlMedioInvalidaElIdentificador(): void
    {
        $this->assertSame('PRODUCTO_EDITADO', auditFiltrosDesdeGet(['accion' => "PRODUCTO_EDITADO\n"])['accion']);
        $this->assertSame('', auditFiltrosDesdeGet(['accion' => "PRODUCTO\n_EDITADO"])['accion']);
        $this->assertSame('', auditFiltrosDesdeGet(['accion' => "\nPRODUCTO_EDITADO\nDROP"])['accion']);
    }

    public function testIdentificadorDeLongitudMaximaSiPasa(): void
    {
        $this->assertSame(str_repeat('A', 50), auditFiltrosDesdeGet(['accion' => str_repeat('A', 50)])['accion']);
    }

    public function testValoresFueraDeLaListaPermitidaSeDescartan(): void
    {
        $f = auditFiltrosDesdeGet(['severidad' => 'ALERTA', 'tipo' => 'hover', 'origen' => 'todos', 'vista' => 'otra']);
        $this->assertSame('', $f['severidad'], 'la severidad distingue mayusculas: una valida en minusculas');
        $this->assertSame('', $f['tipo']);
        $this->assertSame('', $f['origen']);
        $this->assertSame('movimientos', $f['vista']);
    }

    /* ---------------- fechas ---------------- */

    #[DataProvider('fechasInvalidas')]
    public function testFechasInvalidasSeDescartan(mixed $fecha): void
    {
        $f = auditFiltrosDesdeGet(['fecha_inicio' => $fecha, 'fecha_fin' => $fecha]);
        $this->assertSame('', $f['fecha_inicio']);
        $this->assertSame('', $f['fecha_fin']);
    }

    /** @return array<string,array{0:mixed}> */
    public static function fechasInvalidas(): array
    {
        return [
            'dia imposible' => ['2026-02-31'],
            'mes imposible' => ['2026-13-01'],
            'cero' => ['0000-00-00'],
            'anio cero' => ['0001-01-01'],
            'anio lejano' => ['2999-01-01'],
            'sin ceros' => ['2026-1-1'],
            'con hora' => ['2026-09-01 10:00:00'],
            'con espacios' => [' 2026-09-01'],
            'salto final' => ["2026-09-01\n"],
            'formato latino' => ['01/09/2026'],
            'texto' => ['ayer'],
            'inyeccion' => ["2026-09-01' OR '1'='1"],
            'entero' => [20260901],
            'nulo' => [null],
            'vacio' => [''],
        ];
    }

    public function testFechaBisiestaSoloValidaEnAnioBisiesto(): void
    {
        $this->assertSame('2028-02-29', auditFiltrosDesdeGet(['fecha_inicio' => '2028-02-29'])['fecha_inicio']);
        $this->assertSame('', auditFiltrosDesdeGet(['fecha_inicio' => '2027-02-29'])['fecha_inicio']);
    }

    public function testDesdePosteriorAHastaSeIntercambian(): void
    {
        $f = auditFiltrosDesdeGet(['fecha_inicio' => '2026-09-30', 'fecha_fin' => '2026-09-01']);
        $this->assertSame('2026-09-01', $f['fecha_inicio']);
        $this->assertSame('2026-09-30', $f['fecha_fin']);
    }

    public function testUnaSolaFechaValidaNoSeIntercambiaNiSeInventaLaOtra(): void
    {
        $f = auditFiltrosDesdeGet(['fecha_inicio' => '2026-09-30', 'fecha_fin' => 'basura']);
        $this->assertSame('2026-09-30', $f['fecha_inicio']);
        $this->assertSame('', $f['fecha_fin']);
    }

    /* ---------------- numeros ---------------- */

    #[DataProvider('paginas')]
    public function testPaginaSiempreQuedaEnUnRangoSeguroParaElOffset(mixed $entrada, int $esperada): void
    {
        $this->assertSame($esperada, auditFiltrosDesdeGet(['pagina' => $entrada])['pagina']);
    }

    /** @return array<string,array{0:mixed,1:int}> */
    public static function paginas(): array
    {
        return [
            'normal' => ['5', 5],
            'cero' => ['0', 1],
            'negativa' => ['-3', 1],
            'decimal' => ['2.5', 1],
            'texto' => ['abc', 1],
            'mezcla' => ['12abc', 1],
            'vacia' => ['', 1],
            'notacion cientifica' => ['1e3', 1],
            'limite' => ['100000', 100000],
            'sobre el limite' => ['100001', 100000],
            'desbordaria un int' => ['99999999999999999999999999', 100000],
            'booleano' => [true, 1],
            'nulo' => [null, 1],
        ];
    }

    public function testOffsetMaximoNoDesbordaEntero(): void
    {
        $pagina = auditFiltrosDesdeGet(['pagina' => str_repeat('9', 30)])['pagina'];
        $offset = ($pagina - 1) * 50;
        $this->assertIsInt($offset, 'PHP_INT_MAX * 50 se volveria float y romperia el SQL');
        $this->assertLessThan(PHP_INT_MAX, $offset);
    }

    #[DataProvider('usuarios')]
    public function testFiltroDeUsuario(mixed $entrada, int $esperado): void
    {
        $this->assertSame($esperado, auditFiltrosDesdeGet(['usuario' => $entrada])['usuario']);
    }

    /** @return array<string,array{0:mixed,1:int}> */
    public static function usuarios(): array
    {
        return [
            'un usuario' => ['7', 7],
            'sin sesion (-1)' => ['-1', -1],
            'otro negativo no es "sin sesion"' => ['-5', 0],
            'cero' => ['0', 0],
            'mezcla' => ['12abc', 0],
            'decimal' => ['1.5', 0],
            'demasiado grande para INT' => ['99999999999', 0],
            'texto' => ['todos', 0],
            'nulo' => [null, 0],
            'booleano' => [true, 0],
        ];
    }

    public function testRegistroInvalidoONoNumericoEsCero(): void
    {
        foreach (['-1', 'abc', '1.5', '99999999999', '', null, ['1']] as $entrada) {
            $this->assertSame(0, auditFiltrosDesdeGet(['registro' => $entrada])['registro'], json_encode($entrada));
        }
    }

    /* ---------------- texto libre ---------------- */

    public function testTextoLibreSeRecortaYSeLimpiaDeCaracteresDeControl(): void
    {
        $f = auditFiltrosDesdeGet(['q' => "  vita\0mina\r\nC\t  "]);
        $this->assertSame('vita mina C', $f['q']);

        $largo = auditFiltrosDesdeGet(['q' => str_repeat('ñ', 500)])['q'];
        $this->assertSame(100, mb_strlen($largo));
        $this->assertTrue(mb_check_encoding($largo, 'UTF-8'));
    }

    public function testTextoLibreSoloConEspaciosOControlEsVacio(): void
    {
        $this->assertSame('', auditFiltrosDesdeGet(['q' => "   \t\n  "])['q']);
        $this->assertSame('', auditFiltrosDesdeGet(['q' => "\0\0"])['q']);
    }

    public function testTextoLibreConUtf8InvalidoNoRompeElFiltro(): void
    {
        $f = auditFiltrosDesdeGet(['q' => "vita\xB1\x31mina", 'plataforma' => "\xC3\x28"]);
        $this->assertIsString($f['q']);
        $this->assertIsString($f['plataforma']);
    }

    public function testCaracteresComodinDeLikeSeConservanParaQueLaConsultaLosEscape(): void
    {
        // El saneado no los toca; los escapa la consulta con addcslashes (ver la vista).
        $this->assertSame('100%_off', auditFiltrosDesdeGet(['q' => '100%_off'])['q']);
    }

    public function testLaVistaEscapaLosComodinesDelLike(): void
    {
        $vista = (string) file_get_contents(dirname(__DIR__, 2) . '/views/activity_logs.php');
        $this->assertStringContainsString("addcslashes(\$filtro_q, '%_\\\\')", $vista);
    }

    public function testLaSalidaSiempreTieneTodasLasClaves(): void
    {
        $claves = ['vista', 'usuario', 'fecha_inicio', 'fecha_fin', 'accion', 'modulo', 'severidad', 'q', 'tabla', 'registro', 'pagina', 'tipo', 'origen', 'plataforma'];
        foreach ([[], ['x' => 1], ['q' => ['a']], array_fill(0, 50, 'x')] as $entrada) {
            $this->assertSame($claves, array_keys(auditFiltrosDesdeGet($entrada)));
        }
    }

    public function testParametrosDesconocidosSeIgnoran(): void
    {
        $f = auditFiltrosDesdeGet(['id_log' => '1 OR 1=1', 'orden' => 'DESC; DROP', '__proto__' => 'x']);
        $this->assertArrayNotHasKey('id_log', $f);
        $this->assertArrayNotHasKey('orden', $f);
    }
}
