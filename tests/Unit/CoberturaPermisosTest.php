<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Contrato de cobertura: TODA vista y TODO endpoint queda bajo un permiso, salvo los que estan
 * en la lista de excepciones de abajo (y cada excepcion tiene su razon).
 *
 * Es una prueba de sistema de archivos (no toca BD). Sirve de red para que una vista o un
 * endpoint nuevo no se publique "solo por rol" o sin ninguna comprobacion: si no llama a
 * hasPermission()/requirePermission() y no esta justificado aqui, este test falla.
 *
 * Tambien verifica que ya no exista el "respaldo por rol" (permiso O rol): quitarle una clave
 * a un encargado/vendedor/repartidor desde el panel tiene que cerrarle el acceso de verdad.
 * La semilla que conserva el acceso actual de cada rol vive en la migracion
 * 20260920_000001_permisos_cobertura_total_y_semilla_por_rol.sql.
 */
final class CoberturaPermisosTest extends TestCase
{
    /**
     * Archivos SIN comprobacion de permiso a proposito. Clave = ruta relativa a la raiz.
     * Regla: solo entran (a) paginas/endpoints publicos, (b) autoservicio del cliente (su propia
     * informacion, se decide por identidad), (c) webhooks/mantenimiento con token propio,
     * (d) telemetria/landing que cada rol ve acotada a lo suyo.
     *
     * @var array<string,string>
     */
    private const SIN_PERMISO_A_PROPOSITO = [
        // (a) publicos
        'views/blog.php' => 'publico',
        'views/blog_detail.php' => 'publico',
        'views/cart.php' => 'publico (carrito del catalogo)',
        'views/catalogo.php' => 'publico',
        'views/complete_account.php' => 'flujo de registro',
        'views/error.php' => 'publico',
        'views/forgot_password.php' => 'publico',
        'views/gracias.php' => 'publico',
        'views/index.php' => 'publico',
        'views/login.php' => 'publico',
        'views/producto_publico.php' => 'publico',
        'views/register.php' => 'publico',
        'views/terminos.php' => 'publico',
        'api/catalog_products.php' => 'publico (catalogo)',
        'api/delivery_zone_quote.php' => 'cotizacion publica sin sesion, a proposito',
        'api/pickup_stock_check.php' => 'publico (carrito)',
        'api/product_detail.php' => 'publico',
        'api/product_feed.php' => 'feed publico de productos',
        'product_detail.php' => 'publico',
        'wa.php' => 'redireccion publica a WhatsApp',
        // (b) autoservicio del cliente: solo ve/edita lo suyo (se filtra por id_usuario)
        'views/detalle_compra.php' => 'cliente: solo su propio pedido',
        'views/mi_perfil.php' => 'cliente: su propio perfil',
        'views/mis_compras.php' => 'cliente: sus propias compras',
        'views/mis_direcciones.php' => 'cliente: sus propias direcciones',
        'favoritos.php' => 'cliente: sus favoritos',
        'api/cancel_order.php' => 'cliente: cancela su propio pedido',
        'api/favorites.php' => 'cliente: sus favoritos',
        'api/public_orders.php' => 'checkout del cliente',
        // (c) token propio (no usan sesion)
        'api/clear_secrets_cache.php' => 'token de mantenimiento',
        'api/optimize_existing_images.php' => 'token de mantenimiento',
        'api/run_migrations.php' => 'token X-Migrations-Token del deploy',
        'api/whatsapp_confirmar_envio.php' => 'token del puente de WhatsApp',
        'api/whatsapp_webhook.php' => 'token del webhook',
        'api/alex_ocr_imagen.php' => 'token del webhook (puente de WhatsApp): lee el texto de una foto con Google Vision',
        // (d) telemetria / landing acotada por rol dentro del propio codigo
        'api/dashboard_data.php' => 'landing del dashboard: cada rol recibe solo su bloque (admin/encargado/repartidor/vendedor), un cliente no recibe nada',
        'api/log_activity.php' => 'telemetria de navegacion (marca trafico interno)',
    ];

    private string $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 2);
    }

    /** @return string[] rutas relativas de vistas, endpoints y paginas sueltas de la raiz */
    private function archivosDeAcceso(): array
    {
        $out = [];
        foreach (['views', 'api'] as $dir) {
            foreach (glob($this->root . "/{$dir}/*.php") ?: [] as $f) {
                $out[] = "{$dir}/" . basename($f);
            }
        }
        foreach (['favoritos.php', 'product_detail.php', 'wa.php', 'logout.php', 'index.php'] as $f) {
            if (is_file($this->root . '/' . $f)) {
                $out[] = $f;
            }
        }
        sort($out);
        return $out;
    }

    private function tienePermiso(string $rel): bool
    {
        $src = (string) file_get_contents($this->root . '/' . $rel);
        return (bool) preg_match(
            '/\b(hasPermission|requirePermission|canBulkAssignCategories|canUseSupportChat|isSupportChatStaff)\s*\(/',
            $src
        );
    }

    public function testTodaVistaYEndpointTienePermisoOEstaJustificado(): void
    {
        $sinPermiso = [];
        foreach ($this->archivosDeAcceso() as $rel) {
            // logout.php / index.php no son pantallas de negocio.
            if (in_array($rel, ['logout.php', 'index.php'], true)) {
                continue;
            }
            if (!$this->tienePermiso($rel) && !isset(self::SIN_PERMISO_A_PROPOSITO[$rel])) {
                $sinPermiso[] = $rel;
            }
        }

        $this->assertSame(
            [],
            $sinPermiso,
            "Estos archivos no comprueban ningun permiso y no estan justificados en SIN_PERMISO_A_PROPOSITO:\n"
            . implode("\n", $sinPermiso)
            . "\nAgrega hasPermission()/requirePermission() con una clave (y su migracion + PERMISOS_EN_USO)."
        );
    }

    public function testLaListaDeExcepcionesNoTieneEntradasViejas(): void
    {
        $viejas = [];
        foreach (self::SIN_PERMISO_A_PROPOSITO as $rel => $razon) {
            if (!is_file($this->root . '/' . $rel)) {
                $viejas[] = "{$rel} (ya no existe)";
            } elseif ($this->tienePermiso($rel)) {
                $viejas[] = "{$rel} (ya comprueba un permiso: quitalo de la lista)";
            }
        }
        $this->assertSame([], $viejas, "Excepciones obsoletas:\n" . implode("\n", $viejas));
    }

    public function testNingunaGuardaUsaElRolComoRespaldoDeUnPermiso(): void
    {
        $patron = '/hasPermission\([^)]*\)\s*(?:&&|\|\|)\s*!?\s*(?:isEncargado|isVendedor|isRepartidor|isCliente|canManageDeliveryOrders|canScheduleSalesOrders)\s*\(/';
        $patron2 = '/!\s*isAdmin\(\)\s*&&\s*!\s*(?:isEncargado|isVendedor|isRepartidor)\s*\(/';
        $culpables = [];
        foreach ($this->archivosDeAcceso() as $rel) {
            $lineas = preg_split('/\R/', (string) file_get_contents($this->root . '/' . $rel)) ?: [];
            foreach ($lineas as $i => $linea) {
                $t = ltrim($linea);
                if (str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '#')) {
                    continue;
                }
                if (preg_match($patron, $linea) || preg_match($patron2, $linea)) {
                    $culpables[] = $rel . ':' . ($i + 1) . '  ' . trim($linea);
                }
            }
        }
        $this->assertSame(
            [],
            $culpables,
            "Estas guardas mezclan permiso con rol (el permiso no cierra el acceso):\n" . implode("\n", $culpables)
        );
    }

    public function testLosHelpersDeRolQueDabanAccesoYaNoExisten(): void
    {
        require_once $this->root . '/tests/bootstrap.php';
        $this->assertFalse(function_exists('canManageDeliveryOrders'), 'canManageDeliveryOrders() debe usar hasPermission(asignar_entregas)');
        $this->assertFalse(function_exists('canScheduleSalesOrders'), 'canScheduleSalesOrders() debe usar hasPermission(realizar_ventas)');
    }

    // --------------------------------------------------------------------------------------
    // Efecto real: quitar el permiso cierra el acceso, poner el permiso lo abre.
    // --------------------------------------------------------------------------------------

    /** @return array<string, array{0:string,1:string}> [rol, clave] */
    public static function rolYClaveProvider(): array
    {
        return [
            'encargado / inventario' => ['encargado', 'inventario'],
            'encargado / gestionar_clientes' => ['encargado', 'gestionar_clientes'],
            'encargado / gestionar_cancelaciones' => ['encargado', 'gestionar_cancelaciones'],
            'encargado / gestionar_caducidades' => ['encargado', 'gestionar_caducidades'],
            'encargado / asignar_entregas' => ['encargado', 'asignar_entregas'],
            'encargado / vender_sin_inventario' => ['encargado', 'vender_sin_inventario'],
            'vendedor / realizar_ventas' => ['vendedor', 'realizar_ventas'],
            'vendedor / ver_notificaciones_pickup' => ['vendedor', 'ver_notificaciones_pickup'],
            'vendedor / declarar_liquidacion' => ['vendedor', 'declarar_liquidacion'],
            'repartidor / ver_entregas' => ['repartidor', 'ver_entregas'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rolYClaveProvider')]
    public function testSinLaClaveElRolYaNoPasa(string $rol, string $clave): void
    {
        require_once $this->root . '/tests/bootstrap.php';
        $_SESSION['usuario'] = ['id_usuario' => 7, 'rol' => $rol, 'permisos' => []];
        $this->assertFalse(hasPermission($clave), "{$rol} sin '{$clave}' no debe pasar");

        $_SESSION['usuario']['permisos'] = [$clave];
        $this->assertTrue(hasPermission($clave), "{$rol} con '{$clave}' debe pasar");
        unset($_SESSION['usuario']);
    }

    public function testElChatDeSoporteExigeElPermisoAlPersonalPeroNoAlCliente(): void
    {
        require_once $this->root . '/tests/bootstrap.php';

        $_SESSION['usuario'] = ['id_usuario' => 1, 'rol' => 'cliente', 'permisos' => []];
        $this->assertTrue(canUseSupportChat(), 'el cliente siempre puede usar su chat');
        $this->assertFalse(isSupportChatStaff(), 'un cliente nunca es personal del chat');

        $_SESSION['usuario'] = ['id_usuario' => 2, 'rol' => 'repartidor', 'permisos' => []];
        $this->assertFalse(canUseSupportChat());
        $this->assertFalse(isSupportChatStaff());

        $_SESSION['usuario']['permisos'] = ['atender_chat'];
        $this->assertTrue(canUseSupportChat());
        $this->assertTrue(isSupportChatStaff());

        $_SESSION['usuario'] = ['id_usuario' => 3, 'rol' => 'admin'];
        $this->assertTrue(isSupportChatStaff(), 'admin pasa por el short-circuit de hasPermission()');
        unset($_SESSION['usuario']);
    }

    // --------------------------------------------------------------------------------------
    // Dashboard: las tarjetas del encargado y del vendedor solo enlazan a lo que pueden abrir.
    // --------------------------------------------------------------------------------------

    /** @return array<string,string> vista => clave que debe envolver su enlace */
    private const ENLACES_DEL_DASHBOARD = [
        'products.php' => 'gestionar_productos',
        'asignar_entregas.php' => 'asignar_entregas',
        'pickup_notifications.php' => 'ver_notificaciones_pickup',
        'sales.php' => 'realizar_ventas',
        'reportes.php' => 'ver_reportes',
        'manage_customers.php' => 'gestionar_clientes',
        'cancelaciones_pedidos.php' => 'gestionar_cancelaciones',
        'purchase_orders.php' => 'inventario',
        'inventario_entradas.php' => 'inventario',
        'cleanup_reservations.php' => 'inventario',
        'manage_blogs.php' => 'gestionar_blogs',
    ];

    public function testLasTarjetasDelDashboardDePersonalEstanDetrasDeSuPermiso(): void
    {
        $lineas = preg_split('/\R/', (string) file_get_contents($this->root . '/views/dashboard.php')) ?: [];
        $bloque = null;
        $sinPermiso = [];
        foreach ($lineas as $i => $linea) {
            if (str_contains($linea, 'elseif (isEncargado())') || str_contains($linea, 'elseif (isVendedor())')) {
                $bloque = true;
            } elseif (str_contains($linea, 'elseif (isRepartidor())') || str_contains($linea, 'if (isAdmin()):')) {
                $bloque = null;
            }
            if ($bloque === null || !preg_match('#BASE_URL; \?>views/([a-z_]+\.php)"#', $linea, $m)) {
                continue;
            }
            $clave = self::ENLACES_DEL_DASHBOARD[$m[1]] ?? null;
            if ($clave === null) {
                continue; // catalogo, insights (ya con su if), transferir (ya con su if), etc.
            }
            // El if de permiso tiene que abrirse poco antes del enlace (mismo bloque de tarjeta).
            $ventana = implode("\n", array_slice($lineas, max(0, $i - 14), 15));
            if (!str_contains($ventana, "hasPermission('{$clave}')")) {
                $sinPermiso[] = 'dashboard.php:' . ($i + 1) . " -> {$m[1]} (esperaba hasPermission('{$clave}'))";
            }
        }
        $this->assertSame([], $sinPermiso, "Tarjetas del dashboard sin su permiso:\n" . implode("\n", $sinPermiso));
    }

    // --------------------------------------------------------------------------------------
    // Migracion: claves nuevas + semilla neutra por rol
    // --------------------------------------------------------------------------------------

    private function migracion(): string
    {
        return (string) file_get_contents(
            $this->root . '/database/migrations/20260920_000001_permisos_cobertura_total_y_semilla_por_rol.sql'
        );
    }

    public function testLaMigracionCreaLasClavesNuevas(): void
    {
        $sql = $this->migracion();
        foreach ([
            'configurar_notificaciones', 'ver_salud_sistema', 'atender_chat', 'vender_sin_inventario',
            'crear_categorias', 'asignar_categorias_masivo', 'declarar_liquidacion',
        ] as $clave) {
            $this->assertStringContainsString("'{$clave}'", $sql, "la migracion no siembra '{$clave}'");
            $this->assertContains($clave, PERMISOS_EN_USO, "'{$clave}' debe estar en PERMISOS_EN_USO");
        }
    }

    public function testLaClaveHeredadaVentaSeDesactivaSinBorrarlaYNadieLaUsa(): void
    {
        $sql = (string) file_get_contents(
            $this->root . '/database/migrations/20260920_000002_desactiva_permiso_venta_duplicado.sql'
        );
        $this->assertMatchesRegularExpression("/UPDATE\s+permisos\s+SET\s+estado\s*=\s*'inactivo'\s+WHERE\s+clave\s*=\s*'venta'/i", $sql);
        $this->assertStringNotContainsStringIgnoringCase('DELETE ', $sql, 'la clave se desactiva, no se borra');

        // Seguro solo si ningun guard la comprueba: si alguno la usara, desactivarla dejaria sin acceso.
        $this->assertNotContains('venta', PERMISOS_EN_USO);
        $usos = [];
        foreach ($this->archivosDeAcceso() as $rel) {
            if (preg_match("/(hasPermission|requirePermission)\(\s*'venta'\s*\)/", (string) file_get_contents($this->root . '/' . $rel))) {
                $usos[] = $rel;
            }
        }
        $this->assertSame([], $usos, "'venta' esta en uso en: " . implode(', ', $usos));
    }

    public function testLaSemillaConservaElAccesoQueDabaCadaRolYNoRegalaMas(): void
    {
        $sql = $this->migracion();

        // Idempotente y solo aditiva: nunca borra ni pisa.
        $this->assertStringNotContainsStringIgnoringCase('DELETE ', $sql);
        $this->assertStringNotContainsStringIgnoringCase('UPDATE ', $sql);
        $this->assertStringContainsString('NOT EXISTS', $sql);

        // El encargado NO recibe gestionar_productos (le abriria toda la ficha de productos; hoy
        // solo tenia la asignacion masiva de categorias, que ahora tiene clave propia).
        $this->assertDoesNotMatchRegularExpression(
            "/WHERE r\.nombre = 'encargado'.*?gestionar_productos/s",
            explode('-- vendedor:', $sql)[0] ?? '',
            'la semilla del encargado no debe incluir gestionar_productos'
        );
        $this->assertStringContainsString("'asignar_categorias_masivo'", $sql);

        // El vendedor no recibe nada de inventario / clientes / entregas.
        $bloqueVendedor = explode('-- repartidor:', explode('-- vendedor:', $sql)[1] ?? '')[0];
        foreach (['inventario', 'gestionar_clientes', 'asignar_entregas', 'vender_sin_inventario'] as $clave) {
            $this->assertStringNotContainsString("'{$clave}'", $bloqueVendedor, "el vendedor no debe recibir '{$clave}'");
        }
        $this->assertStringContainsString("'declarar_liquidacion'", $bloqueVendedor);

        // El cliente nunca recibe el chat de personal.
        $this->assertStringContainsString("NOT IN ('admin', 'cliente')", $sql);
    }
}
