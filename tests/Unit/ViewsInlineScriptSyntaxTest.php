<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * El JavaScript inline de las vistas no lo cubre ningun otro test: un salto de linea dentro de una
 * cadena JS (que un parche mal escapado ya metio en views/caducidades.php, dejando sin funcionar el
 * boton "Poner en oferta" y toda la pantalla) rompe TODO el <script> de la pagina en silencio -- PHP no
 * se entera. Aqui se extrae cada <script> inline de TODAS las vistas y paginas raiz, los bloques
 * <?php ... ?> se sustituyen por un literal valido y se revisa la sintaxis con `node --check`.
 * Se omite si node no esta instalado.
 */
final class ViewsInlineScriptSyntaxTest extends TestCase
{
    /** @return array<string,array{string}> */
    public static function vistas(): array
    {
        $raiz = dirname(__DIR__, 2);
        $out = [];
        foreach (array_merge(glob($raiz . '/views/*.php') ?: [], glob($raiz . '/*.php') ?: []) as $ruta) {
            $out[substr($ruta, strlen($raiz) + 1)] = [$ruta];
        }

        return $out;
    }

    #[DataProvider('vistas')]
    public function testElJavaScriptInlineTieneSintaxisValida(string $ruta): void
    {
        $node = trim((string) (PHP_OS_FAMILY === 'Windows' ? shell_exec('where node 2>NUL') : shell_exec('command -v node 2>/dev/null')));
        if ($node === '') {
            $this->markTestSkipped('node no esta instalado');
        }
        $node = strtok($node, "\r\n");

        $html = (string) file_get_contents($ruta);
        // Scripts inline de JavaScript (se saltan los src externos y los bloques JSON / JSON-LD).
        preg_match_all('#<script(?![^>]*\bsrc=)(?![^>]*type="application/(?:ld\+)?json")[^>]*>(.*?)</script>#s', $html, $m);

        foreach ($m[1] as $i => $js) {
            $js = preg_replace('#<\?php.*?\?>#s', '0', $js);
            if (trim((string) $js) === '') {
                continue;
            }
            $archivo = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'inline_' . md5($ruta) . "_{$i}.js";
            file_put_contents($archivo, $js);
            $salida = [];
            exec('"' . $node . '" --check "' . $archivo . '" 2>&1', $salida, $codigo);
            @unlink($archivo);
            $this->assertSame(0, $codigo, 'Error de sintaxis JS en ' . basename($ruta) . " (script #{$i}): " . implode(' | ', array_slice($salida, 0, 5)));
        }
        $this->addToAssertionCount(1); // vistas sin scripts inline: nada que revisar
    }
}
