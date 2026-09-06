<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Verifica que la lista blanca PERMISOS_EN_USO (core/auth.php) y las llamadas reales
 * a hasPermission()/requirePermission() en views/ y api/ no se desincronicen:
 *
 *  - toda clave usada en un guard debe estar en PERMISOS_EN_USO (si no, el panel la
 *    marcaria "sin efecto" cuando en realidad si controla algo, o es un typo);
 *  - toda clave de PERMISOS_EN_USO debe usarse en al menos un guard (si no, sobra
 *    en la lista y engana al panel diciendo que ya controla algo).
 *
 * Es una prueba de sistema de archivos (no toca BD).
 */
final class PermissionGuardsConsistencyTest extends TestCase
{
    /** @return string[] rutas absolutas de todos los .php bajo views/ y api/ */
    private function sourceFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = [];
        foreach (['views', 'api'] as $dir) {
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($it as $file) {
                if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        sort($files);
        return $files;
    }

    /** @return array<string,string[]> clave => lista de archivos (relativos) que la usan */
    private function keysUsedInGuards(): array
    {
        $root = dirname(__DIR__, 2);
        $used = [];
        foreach ($this->sourceFiles() as $path) {
            $code = (string) file_get_contents($path);
            if (!preg_match_all(
                "/\b(?:hasPermission|requirePermission)\(\s*'([a-z0-9_]+)'/",
                $code,
                $m
            )) {
                continue;
            }
            $rel = str_replace('\\', '/', substr($path, strlen($root) + 1));
            foreach ($m[1] as $clave) {
                $used[$clave][] = $rel;
            }
        }
        ksort($used);
        return $used;
    }

    public function testTodaClaveUsadaEnUnGuardEstaEnPermisosEnUso(): void
    {
        $enUso = PERMISOS_EN_USO;
        $huerfanas = [];
        foreach ($this->keysUsedInGuards() as $clave => $archivos) {
            if (!in_array($clave, $enUso, true)) {
                $huerfanas[$clave] = $archivos;
            }
        }

        $this->assertSame(
            [],
            $huerfanas,
            "Estas claves se comprueban en un guard pero faltan en PERMISOS_EN_USO:\n" .
            print_r($huerfanas, true)
        );
    }

    public function testTodaClaveDePermisosEnUsoSeUsaEnAlgunGuard(): void
    {
        $usadas = array_keys($this->keysUsedInGuards());
        $sinConsumidor = array_values(array_diff(PERMISOS_EN_USO, $usadas));

        $this->assertSame(
            [],
            $sinConsumidor,
            'Estas claves estan en PERMISOS_EN_USO pero ningun guard de views/ o api/ las usa: '
            . implode(', ', $sinConsumidor)
        );
    }

    public function testPermisosEnUsoNoTieneDuplicados(): void
    {
        $this->assertSame(
            array_values(array_unique(PERMISOS_EN_USO)),
            array_values(PERMISOS_EN_USO),
            'PERMISOS_EN_USO tiene claves repetidas.'
        );
    }

    public function testGuardsDeMetricasYCampanasSiguenPresentes(): void
    {
        // Regresion: estas vistas nuevas deben quedar detras de su permiso (no volver a
        // ser "isAdmin() a secas" en un merge de main).
        $usadas = $this->keysUsedInGuards();
        foreach ([
            'ver_analitica_negocio',
            'ver_trafico_campanas',
            'ver_comportamiento_sitio',
            'gestionar_campanas',
            'configurar_iniciativas_ventas',
            'ver_auditoria',
            'gestionar_sucursales',
            'gestionar_cancelaciones',
            'gestionar_asistente_ia',
            'ver_insights_ia',
        ] as $clave) {
            $this->assertArrayHasKey(
                $clave,
                $usadas,
                "Ningun guard usa '{$clave}' -- ¿se perdio en un merge?"
            );
        }
    }
}
