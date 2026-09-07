<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * El menu lateral movil (<ul class="sidenav" id="mobile-nav"> de
 * views/includes/header.php) debe ofrecer los mismos accesos de administracion
 * que el menu de perfil de escritorio (#user-dropdown), detras de los mismos
 * permisos.
 *
 * Regresion concreta: en movil no habia forma de entrar a "Usuarios" ni a
 * "Roles y Permisos" porque el dropdown de escritorio esta oculto y el sidenav
 * solo traia Dashboard / Pickup / Mensajes / Favoritos. Este test evita que
 * vuelva a pasar si alguien edita un menu y olvida el otro.
 *
 * Es una prueba de sistema de archivos (no toca BD ni renderiza el header).
 */
final class MobileNavAdminLinksTest extends TestCase
{
    private static function headerSource(): string
    {
        $path = dirname(__DIR__, 2) . '/views/includes/header.php';
        return (string) file_get_contents($path);
    }

    /** Extrae el contenido de un <ul ... id="$id" ...> ... </ul> (sin <ul> anidados). */
    private static function ulBlock(string $html, string $id): string
    {
        $needle = 'id="' . $id . '"';
        $pos = strpos($html, $needle);
        if ($pos === false) {
            return '';
        }
        $start = strpos($html, '>', $pos);
        $end = strpos($html, '</ul>', $start === false ? $pos : $start);
        if ($start === false || $end === false) {
            return '';
        }
        return substr($html, $start + 1, $end - $start - 1);
    }

    private string $sidenav = '';
    private string $dropdown = '';

    protected function setUp(): void
    {
        parent::setUp();
        $html = self::headerSource();
        $this->sidenav = self::ulBlock($html, 'mobile-nav');
        $this->dropdown = self::ulBlock($html, 'user-dropdown');
        $this->assertNotSame('', $this->sidenav, 'no se encontro el <ul id="mobile-nav">');
        $this->assertNotSame('', $this->dropdown, 'no se encontro el <ul id="user-dropdown">');
    }

    public function testElSidenavMovilEnlazaAUsuariosYRolesPermisos(): void
    {
        $this->assertStringContainsString('views/users.php', $this->sidenav,
            'el menu movil no enlaza a views/users.php');
        $this->assertStringContainsString('views/roles_permisos.php', $this->sidenav,
            'el menu movil no enlaza a views/roles_permisos.php');
    }

    public function testEsosEnlacesEstanDetrasDeGestionarUsuarios(): void
    {
        // El bloque de administracion del sidenav se abre con hasPermission('gestionar_usuarios')
        // (via la variable $mostrarUsuariosMovil) y ahi viven ambos enlaces.
        $this->assertMatchesRegularExpression(
            "/gestionar_usuarios.*views\/users\.php/s",
            $this->sidenav,
            "views/users.php en el menu movil debe quedar detras de un check de 'gestionar_usuarios'"
        );
        $this->assertMatchesRegularExpression(
            "/gestionar_usuarios.*views\/roles_permisos\.php/s",
            $this->sidenav,
            "views/roles_permisos.php en el menu movil debe quedar detras de un check de 'gestionar_usuarios'"
        );
    }

    public function testSaludDelSistemaEnMovilSoloParaAdmin(): void
    {
        $this->assertStringContainsString('views/salud_sistema.php', $this->sidenav,
            'el menu movil no enlaza a views/salud_sistema.php');
        $this->assertMatchesRegularExpression(
            "/isAdmin\(\).*views\/salud_sistema\.php/s",
            $this->sidenav,
            "salud_sistema.php en el menu movil debe quedar detras de isAdmin()"
        );
    }

    public function testGestionarBlogsEnMovilDetrasDeSuPermiso(): void
    {
        $this->assertMatchesRegularExpression(
            "/gestionar_blogs.*views\/manage_blogs\.php/s",
            $this->sidenav,
            "manage_blogs.php en el menu movil debe quedar detras de 'gestionar_blogs'"
        );
    }

    /**
     * Sincronizacion: todo destino views/*.php que el dropdown de escritorio
     * ofrece tras 'gestionar_usuarios' o isAdmin() debe existir tambien en el
     * sidenav movil.
     */
    public function testElMovilNoSeQuedaAtrasDelMenuDeEscritorio(): void
    {
        preg_match_all('/views\/([a-z0-9_]+\.php)/', $this->dropdown, $mDesktop);
        preg_match_all('/views\/([a-z0-9_]+\.php)/', $this->sidenav, $mMobile);

        $desktopAdminViews = array_unique(array_intersect(
            $mDesktop[1],
            ['users.php', 'roles_permisos.php', 'manage_blogs.php', 'salud_sistema.php']
        ));
        $mobileViews = array_unique($mMobile[1]);

        $faltan = array_values(array_diff($desktopAdminViews, $mobileViews));
        $this->assertSame([], $faltan,
            'El menu de escritorio ofrece estas vistas de administracion y el movil no: '
            . implode(', ', $faltan));
    }
}
