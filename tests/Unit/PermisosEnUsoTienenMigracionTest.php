<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cada clave de PERMISOS_EN_USO (core/auth.php) tiene que existir de verdad en la
 * tabla `permisos`, o el panel de Roles y Permisos no la lista y ningun admin
 * puede concederla por rol ni por persona -- el guard queda dependiendo para
 * siempre del fallback de rol.
 *
 * Como no hay dump base en el repo (solo migraciones), la comprobacion es:
 *   - las claves "de siempre" (anteriores al panel, sembradas por el dump base)
 *     van en una allowlist fija y documentada aqui;
 *   - CUALQUIER otra clave de PERMISOS_EN_USO debe tener un `INSERT INTO permisos`
 *     en database/migrations/*.sql.
 *
 * Regresion concreta: 'gestionar_caducidades' (PR #140) y luego
 * 'ver_conversaciones_whatsapp' (PR #144) se agregaron al guard y a
 * PERMISOS_EN_USO pero SIN la migracion de la fila -- este test lo habria
 * atrapado. Es una prueba de sistema de archivos (no toca BD).
 */
final class PermisosEnUsoTienenMigracionTest extends TestCase
{
    /**
     * Claves sembradas por el dump base (fuera del sistema de migraciones del repo).
     * NO agregar aqui claves nuevas: una clave nueva se siembra con su migracion.
     */
    private const CLAVES_BASE_SIN_MIGRACION = [
        'gestionar_productos',
        'ver_reportes',
        'ver_entregas',
        'gestionar_blogs',
        'apartar_productos',
        'gestionar_usuarios',
        'realizar_ventas',
        'inventario',
        'gestionar_clientes',
    ];

    /** @return array<string,true> claves con INSERT INTO permisos en alguna migracion */
    private function clavesInsertadasEnMigraciones(): array
    {
        $root = dirname(__DIR__, 2);
        $blob = '';
        foreach (glob($root . '/database/migrations/*.sql') as $file) {
            $blob .= file_get_contents($file) . "\n";
        }

        // Cada bloque que arranca con INSERT INTO permisos hasta el siguiente ';'
        // o el siguiente INSERT. Dentro, la clave es el primer literal tras
        // SELECT '...' o VALUES ('...'.
        preg_match_all(
            '/INSERT\s+INTO\s+permisos\b(.*?)(?=;|INSERT\s+INTO|\Z)/is',
            $blob,
            $bloques
        );

        $claves = [];
        foreach ($bloques[1] as $bloque) {
            if (preg_match_all("/(?:SELECT|VALUES\s*\()\s*'([a-z_]+)'/i", $bloque, $m)) {
                foreach ($m[1] as $clave) {
                    $claves[$clave] = true;
                }
            }
        }
        return $claves;
    }

    public function testTodaClaveEnUsoExisteViaMigracionOEsBase(): void
    {
        $insertadas = $this->clavesInsertadasEnMigraciones();
        $permitidasBase = self::CLAVES_BASE_SIN_MIGRACION;

        $sinRespaldo = [];
        foreach (PERMISOS_EN_USO as $clave) {
            if (isset($insertadas[$clave]) || in_array($clave, $permitidasBase, true)) {
                continue;
            }
            $sinRespaldo[] = $clave;
        }

        $this->assertSame(
            [],
            $sinRespaldo,
            "Estas claves estan en PERMISOS_EN_USO y en un guard, pero ninguna migracion "
            . "hace INSERT INTO permisos de ellas (ni estan en la allowlist base). El panel "
            . "no las puede mostrar ni conceder. Falta crear la migracion:\n  "
            . implode("\n  ", $sinRespaldo)
        );
    }

    public function testLaAllowlistBaseNoSeLlenaDeClavesQueSiTienenMigracion(): void
    {
        // Si una clave "base" termina teniendo su propia migracion, sacala de la
        // allowlist para que esta no crezca sin control.
        $insertadas = $this->clavesInsertadasEnMigraciones();
        $redundantes = array_values(array_filter(
            self::CLAVES_BASE_SIN_MIGRACION,
            static fn ($c) => isset($insertadas[$c])
        ));

        $this->assertSame(
            [],
            $redundantes,
            'Estas claves estan en la allowlist base Y tienen migracion; quita de la allowlist: '
            . implode(', ', $redundantes)
        );
    }

    public function testLaAllowlistBaseNoTieneClavesFueraDePermisosEnUso(): void
    {
        $sobrantes = array_values(array_diff(self::CLAVES_BASE_SIN_MIGRACION, PERMISOS_EN_USO));

        $this->assertSame(
            [],
            $sobrantes,
            'La allowlist base menciona claves que ya no estan en PERMISOS_EN_USO: '
            . implode(', ', $sobrantes)
        );
    }

    public function testVerConversacionesWhatsappTieneSuMigracion(): void
    {
        // Regresion directa de PR #144 + esta revision.
        $this->assertArrayHasKey(
            'ver_conversaciones_whatsapp',
            $this->clavesInsertadasEnMigraciones(),
            "Falta la migracion que hace INSERT INTO permisos de 'ver_conversaciones_whatsapp'"
        );
    }
}
