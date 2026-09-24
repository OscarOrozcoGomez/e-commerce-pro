<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Boton "Con foto" del modal Compartir de Ventas (views/sales.php): abre el menu de compartir del
 * celular con la foto del producto adjunta (JPG) y el mensaje como texto.
 *
 * Las partes puras viven en assets/js/compartir_producto.js y se ejecutan de verdad con Node (si
 * no hay Node en la maquina, esas pruebas se saltan; los runners de GitHub lo traen). El cableado
 * en sales.php (boton oculto por defecto, JPG, turno contra fotos viejas) se revisa sobre la fuente.
 */
final class CompartirProductoConFotoTest extends TestCase
{
    private const JS = __DIR__ . '/../../assets/js/compartir_producto.js';
    private const VISTA = __DIR__ . '/../../views/sales.php';

    /** Evalua una expresion JS con el modulo cargado en `m` y regresa su valor (via JSON). */
    private function js(string $expresion): mixed
    {
        $node = $this->nodeBinario();
        $script = 'const m = require(' . json_encode(realpath(self::JS)) . ');'
            . 'process.stdout.write(JSON.stringify(' . $expresion . '));';
        $proc = proc_open([$node, '-e', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $this->assertIsResource($proc, 'No se pudo lanzar Node.');
        $salida = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $codigo = proc_close($proc);
        $this->assertSame(0, $codigo, 'Node fallo: ' . $error);

        return json_decode((string) $salida, true, 512, JSON_THROW_ON_ERROR);
    }

    private function nodeBinario(): string
    {
        static $binario = null;
        if ($binario === null) {
            $binario = '';
            foreach (['node', 'node.exe', 'C:\\Program Files\\nodejs\\node.exe'] as $candidato) {
                $proc = @proc_open([$candidato, '--version'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                if (is_resource($proc)) {
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    if (proc_close($proc) === 0) {
                        $binario = $candidato;
                        break;
                    }
                }
            }
        }
        if ($binario === '') {
            $this->markTestSkipped('Node no esta instalado en esta maquina.');
        }

        return $binario;
    }

    // ------------------------------------------------------------------
    // compartirPuedeArchivos: solo si el navegador tiene canShare Y share
    // ------------------------------------------------------------------

    public function testNavegadorConCanShareYShareSiPuedeCompartirArchivos(): void
    {
        $this->assertTrue($this->js('m.compartirPuedeArchivos({ canShare: () => true, share: () => Promise.resolve() })'));
    }

    /** @return array<string, array{string}> */
    public static function navegadoresSinSoporte(): array
    {
        return [
            'sin navigator (null)'         => ['null'],
            'sin navigator (undefined)'    => ['undefined'],
            'objeto vacio (compu tipica)'  => ['{}'],
            'solo share, sin canShare'     => ['{ share: () => Promise.resolve() }'],
            'solo canShare, sin share'     => ['{ canShare: () => true }'],
            'canShare no es funcion'       => ['{ canShare: true, share: () => Promise.resolve() }'],
            'share no es funcion'          => ['{ canShare: () => true, share: "si" }'],
        ];
    }

    #[DataProvider('navegadoresSinSoporte')]
    public function testNavegadorSinSoporteNoMuestraElBoton(string $navegador): void
    {
        $this->assertFalse($this->js('m.compartirPuedeArchivos(' . $navegador . ')'));
    }

    // ------------------------------------------------------------------
    // compartirImagenUsable: una foto real, nunca el placeholder
    // ------------------------------------------------------------------

    /** @return array<string, array{string}> */
    public static function imagenesReales(): array
    {
        return [
            'ruta relativa de productos' => ['../assets/img/products/omega-3-platinum-201/foto.webp'],
            'url absoluta del CDN'       => ['https://cdn.shopify.com/s/files/1/omega.png'],
            'ruta absoluta'              => ['/assets/img/products/x/foto.jpg'],
            'data uri de imagen raster'  => ['data:image/png;base64,iVBORw0KGgo='],
        ];
    }

    #[DataProvider('imagenesReales')]
    public function testImagenRealEsUsable(string $src): void
    {
        $this->assertTrue($this->js('m.compartirImagenUsable(' . json_encode($src) . ')'));
    }

    /** @return array<string, array{string}> */
    public static function imagenesNoUsables(): array
    {
        return [
            'null'                    => ['null'],
            'undefined'               => ['undefined'],
            'cadena vacia'            => ['""'],
            'solo espacios'           => ['"   "'],
            'placeholder no-product'  => ['"../assets/img/no-product.png"'],
            'placeholder no-image'    => ['"https://cdn.example.com/no-image.jpg"'],
            'svg en linea'            => ['"data:image/svg+xml;utf8,<svg></svg>"'],
        ];
    }

    #[DataProvider('imagenesNoUsables')]
    public function testSinFotoRealNoSeOfreceCompartirConFoto(string $src): void
    {
        $this->assertFalse($this->js('m.compartirImagenUsable(' . $src . ')'));
    }

    // ------------------------------------------------------------------
    // compartirNombreArchivo: nombre limpio, .jpg, maximo 60 caracteres
    // ------------------------------------------------------------------

    /** @return array<string, array{string, string}> */
    public static function nombresDeArchivo(): array
    {
        return [
            'nombre con variante'      => ['Omega 3 Platinum - 180 Caps | 1000 mg', 'Omega-3-Platinum-180-Caps-1000-mg.jpg'],
            'acentos se conservan sin tilde' => ['14 Day cleanse - 28 cápsulas', '14-Day-cleanse-28-capsulas.jpg'],
            'ene'                      => ['Ñame Silvestre', 'Name-Silvestre.jpg'],
            'signos y mas'             => ['Coconut Oil D3 + K2', 'Coconut-Oil-D3-K2.jpg'],
            'ampersand y barra'        => ['Relax & Calm / Mascotas', 'Relax-Calm-Mascotas.jpg'],
            'espacios de sobra'        => ['   Mag   Kids   ', 'Mag-Kids.jpg'],
        ];
    }

    #[DataProvider('nombresDeArchivo')]
    public function testNombreDeArchivoLimpio(string $nombre, string $esperado): void
    {
        $this->assertSame($esperado, $this->js('m.compartirNombreArchivo(' . json_encode($nombre) . ')'));
    }

    /** @return array<string, array{string}> */
    public static function nombresSinLetras(): array
    {
        return [
            'null'          => ['null'],
            'undefined'     => ['undefined'],
            'vacio'         => ['""'],
            'solo signos'   => ['"| - + & !"'],
            'solo emoji'    => ['"🌻💊"'],
        ];
    }

    #[DataProvider('nombresSinLetras')]
    public function testNombreSinLetrasUsaProductoJpg(string $nombre): void
    {
        $this->assertSame('producto.jpg', $this->js('m.compartirNombreArchivo(' . $nombre . ')'));
    }

    public function testNombreLargoSeCortaA60SinGuionColgando(): void
    {
        $largo = 'Myo & D-Chiro Inositol en cápsulas | Relación Ideal 40:1 de Alta Pureza | Fórmula Platinum | 2000 mg por porción';
        $archivo = $this->js('m.compartirNombreArchivo(' . json_encode($largo) . ')');

        $this->assertStringEndsWith('.jpg', $archivo);
        $base = substr($archivo, 0, -4);
        $this->assertLessThanOrEqual(60, strlen($base));
        $this->assertStringEndsNotWith('-', $base);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9-]+$/', $base);
    }

    public function testNombreDeExactamente60CaracteresNoSeCorta(): void
    {
        $nombre = str_repeat('a', 60);
        $this->assertSame($nombre . '.jpg', $this->js('m.compartirNombreArchivo(' . json_encode($nombre) . ')'));
    }

    // ------------------------------------------------------------------
    // Cableado en views/sales.php
    // ------------------------------------------------------------------

    private function vista(): string
    {
        return (string) file_get_contents(self::VISTA);
    }

    public function testLaVistaCargaElScriptDeCompartir(): void
    {
        $this->assertStringContainsString('assets/js/compartir_producto.js', $this->vista());
    }

    public function testElBotonConFotoArrancaOculto(): void
    {
        $this->assertMatchesRegularExpression(
            '/<button[^>]*class="[^"]*compartir-con-foto[^"]*"[^>]*style="display: none;"/',
            $this->vista(),
            'En compus sin soporte el boton nunca debe verse.'
        );
    }

    public function testSoloSeMuestraSiHaySoporteYFotoReal(): void
    {
        $vista = $this->vista();
        $this->assertStringContainsString('if (!compartirPuedeArchivos(navigator) || !compartirImagenUsable(imgSrc)) return;', $vista);
        $this->assertStringContainsString('navigator.canShare({ files: [archivo] })', $vista);
    }

    public function testLaFotoSeMandaComoJpgNuncaComoWebp(): void
    {
        $vista = $this->vista();
        // WhatsApp manda los .webp como sticker: se convierte a JPG antes de compartir.
        $this->assertStringContainsString("canvas.toBlob(", $vista);
        $this->assertStringContainsString("'image/jpeg', 0.9)", $vista);
        $this->assertStringContainsString("{ type: 'image/jpeg' }", $vista);
        $this->assertStringNotContainsString("type: 'image/webp'", $vista);
    }

    public function testComparteFotoYMensajeJuntos(): void
    {
        $this->assertStringContainsString('navigator.share({ files: [compartirConFotoArchivo], text: mensaje })', $this->vista());
    }

    public function testUnaFotoViejaNoPisaAlProductoNuevo(): void
    {
        $vista = $this->vista();
        $this->assertStringContainsString('const turno = ++compartirConFotoTurno;', $vista);
        $this->assertSame(2, substr_count($vista, 'turno !== compartirConFotoTurno'), 'Se revisa al cargar la imagen y al tener el JPG.');
    }

    public function testCancelarElMenuNoMuestraError(): void
    {
        $this->assertStringContainsString("err.name !== 'AbortError'", $this->vista());
    }
}
