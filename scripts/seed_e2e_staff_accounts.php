<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php'; // isPublicPickupWarehouseName()

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    http_response_code(403);
    echo "Este script solo se puede ejecutar por CLI o phpdbg.\n";
    exit(1);
}

// Cuentas fijas que los specs de tests/e2e/*.staff.spec.ts referencian por email.
// Idempotente: correr varias veces (local o CI) solo actualiza el hash de password,
// no duplica filas ni acumula cuentas basura como las de cliente (que sí son
// desechables por diseño, ver tests/e2e/helpers.ts::registerAndLogin).
const E2E_STAFF_PASSWORD = 'E2eStaff!2026';

$staffAccounts = [
    ['rol' => 'admin', 'nombre' => 'Playwright E2E Admin', 'email' => 'e2e-admin@playwright.test', 'almacen' => 'ninguno'],
    ['rol' => 'encargado', 'nombre' => 'Playwright E2E Encargado', 'email' => 'e2e-encargado@playwright.test', 'almacen' => 'default'],
    ['rol' => 'vendedor', 'nombre' => 'Playwright E2E Vendedor', 'email' => 'e2e-vendedor@playwright.test', 'almacen' => 'default'],
    ['rol' => 'repartidor', 'nombre' => 'Playwright E2E Repartidor', 'email' => 'e2e-repartidor@playwright.test', 'almacen' => 'default'],
    // Encargado dedicado a la sucursal de pickup (distinta de "default"): views/pickup_notifications.php
    // filtra por el id_almacen del encargado, y las notificaciones de pickup se crean con el id_almacen
    // resuelto por resolvePickupWarehouseId() (core/auth.php), no con el almacen "default" de arriba.
    ['rol' => 'encargado', 'nombre' => 'Playwright E2E Encargado Pickup', 'email' => 'e2e-encargado-pickup@playwright.test', 'almacen' => 'pickup'],
    // isSuperAdmin() = isAdmin() + usuarios.es_superadmin -- un admin normal NO puede tocar el
    // catalogo de permisos (crear/editar/desactivar) en views/roles_permisos.php, solo super admin.
    // Cuenta aparte del admin de arriba para poder probar ambos casos (admin normal bloqueado,
    // superadmin permitido) sin pisarse.
    ['rol' => 'admin', 'nombre' => 'Playwright E2E Superadmin', 'email' => 'e2e-superadmin@playwright.test', 'almacen' => 'ninguno', 'es_superadmin' => 1],
    // Encargado (NO admin) al que se le concede SOLO el permiso 'ver_auditoria' por override individual (ver
    // abajo): prueba que la vista de Movimientos abre por permiso y no solo por ser admin.
    ['rol' => 'encargado', 'nombre' => 'Playwright E2E Auditor', 'email' => 'e2e-auditor@playwright.test', 'almacen' => 'default'],
    // Vendedor EXCLUSIVO de permisos-en-vivo.staff.spec.ts: esa prueba le concede/quita permisos a media
    // sesion (scripts/e2e_permiso_override.php). No se comparte con otros specs porque corren en paralelo y
    // se contaminarian entre si; abajo se le borran los overrides que haya dejado una corrida interrumpida.
    ['rol' => 'vendedor', 'nombre' => 'Playwright E2E Permisos En Vivo', 'email' => 'e2e-permisos-vivo@playwright.test', 'almacen' => 'default'],
    // Encargados con UN permiso de menos (override 'denegar', abajo) para probar lo que depende de ese permiso en
    // Administrar Clientes: sin realizar_ventas no se ofrece "agendar venta" tras crear un cliente; sin asignar_entregas
    // la oferta dice "registrar" en vez de "agendar" (puede_agendar).
    ['rol' => 'encargado', 'nombre' => 'Playwright E2E Encargado Sin Ventas', 'email' => 'e2e-encargado-sin-ventas@playwright.test', 'almacen' => 'default'],
    ['rol' => 'encargado', 'nombre' => 'Playwright E2E Encargado Sin Agendar', 'email' => 'e2e-encargado-sin-agendar@playwright.test', 'almacen' => 'default'],
    // WhatsApp (encargado + override): el lector solo ve Contactos/Seguimientos (ver_conversaciones_whatsapp); el de feedback
    // ademas puede convertir una respuesta de Alex en regla de aprendizaje (dar_feedback_asistente_ia).
    ['rol' => 'encargado', 'nombre' => 'Playwright E2E WhatsApp Lector', 'email' => 'e2e-whatsapp-lector@playwright.test', 'almacen' => 'default'],
    ['rol' => 'encargado', 'nombre' => 'Playwright E2E WhatsApp Feedback', 'email' => 'e2e-whatsapp-feedback@playwright.test', 'almacen' => 'default'],
];

$pdo = getPDO();

try {
    $pdo->beginTransaction();

    $idAlmacen = (int) $pdo->query(
        "SELECT id_almacen FROM almacenes WHERE estado = 'activo' ORDER BY id_almacen ASC LIMIT 1"
    )->fetchColumn();

    if ($idAlmacen <= 0) {
        throw new RuntimeException('No se pudo resolver id_almacen. ¿Se importó database.sql?');
    }

    // Mismo criterio que resolvePickupWarehouseId() (core/auth.php): requiere que
    // scripts/seed_e2e_test_data.php ya haya corrido (siembra ese almacen si hace falta).
    $idAlmacenPickup = 0;
    $stmtPickup = $pdo->query("SELECT id_almacen, nombre FROM almacenes WHERE estado = 'activo' ORDER BY id_almacen ASC");
    foreach ($stmtPickup->fetchAll(PDO::FETCH_ASSOC) as $almacen) {
        if (isPublicPickupWarehouseName((string) $almacen['nombre'])) {
            $idAlmacenPickup = (int) $almacen['id_almacen'];
            break;
        }
    }
    if ($idAlmacenPickup <= 0) {
        throw new RuntimeException('No se encontró un almacén de pickup válido. ¿Corriste scripts/seed_e2e_test_data.php antes que este script?');
    }

    $hash = password_hash(E2E_STAFF_PASSWORD, PASSWORD_BCRYPT);

    foreach ($staffAccounts as $cuenta) {
        $stmtRol = $pdo->prepare('SELECT id_rol FROM roles WHERE nombre = :nombre');
        $stmtRol->execute(['nombre' => $cuenta['rol']]);
        $idRol = (int) $stmtRol->fetchColumn();

        if ($idRol <= 0) {
            throw new RuntimeException("No existe el rol '{$cuenta['rol']}' en la tabla roles. ¿Corriste scripts/migrate.php (incluye 20260821_000005_seed_missing_roles.sql)?");
        }

        $idAlmacenCuenta = match ($cuenta['almacen']) {
            'default' => $idAlmacen,
            'pickup' => $idAlmacenPickup,
            default => null,
        };

        $esSuperadmin = (int) ($cuenta['es_superadmin'] ?? 0);

        $stmt = $pdo->prepare(
            'INSERT INTO usuarios (nombre, email, contrasena, id_rol, id_almacen, estado, es_superadmin)
             VALUES (:nombre, :email, :contrasena, :id_rol, :id_almacen, "activo", :es_superadmin)
             ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), contrasena = VALUES(contrasena),
                 id_rol = VALUES(id_rol), id_almacen = VALUES(id_almacen), estado = "activo",
                 es_superadmin = VALUES(es_superadmin)'
        );
        $stmt->execute([
            'nombre' => $cuenta['nombre'],
            'email' => $cuenta['email'],
            'contrasena' => $hash,
            'id_rol' => $idRol,
            'id_almacen' => $idAlmacenCuenta,
            'es_superadmin' => $esSuperadmin,
        ]);

        echo "Seed OK: {$cuenta['rol']} -> {$cuenta['email']} (id_rol={$idRol}, id_almacen=" . ($idAlmacenCuenta ?? 'NULL') . ($esSuperadmin ? ', superadmin' : '') . ")\n";
    }

    // Permisos que las cuentas de prueba NO deben tener, sin importar como este configurado el rol
    // real en la BD donde se corre (p.ej. una BD local donde alguien le dio "transferir_stock" al
    // rol encargado desde Roles y Permisos). Un override individual 'denegar' (usuario_permisos, gana
    // sobre el rol: ver mergeEffectivePermissions en core/auth.php) fija el caso sin tocar el rol.
    // transfer-stock.staff.spec.ts prueba que un encargado NORMAL no puede transferir entre almacenes
    // (las migraciones no le dan ese permiso a proposito, ver 20260907_130000).
    $denegaciones = [
        ['e2e-encargado@playwright.test', 'transferir_stock', 'denegar'],
        ['e2e-encargado-pickup@playwright.test', 'transferir_stock', 'denegar'],
        // ...y el caso inverso: una cuenta que SI debe tenerlo aunque su rol no lo traiga.
        ['e2e-auditor@playwright.test', 'ver_auditoria', 'conceder'],
        ['e2e-encargado-sin-ventas@playwright.test', 'realizar_ventas', 'denegar'],
        ['e2e-encargado-sin-agendar@playwright.test', 'asignar_entregas', 'denegar'],
        ['e2e-whatsapp-lector@playwright.test', 'ver_conversaciones_whatsapp', 'conceder'],
        ['e2e-whatsapp-feedback@playwright.test', 'ver_conversaciones_whatsapp', 'conceder'],
        ['e2e-whatsapp-feedback@playwright.test', 'dar_feedback_asistente_ia', 'conceder'],
        // El encargado normal NO debe abrir Movimientos (el rol encargado no lo trae; se fija por si alguien se lo dio).
        ['e2e-encargado@playwright.test', 'ver_auditoria', 'denegar'],
    ];
    foreach ($denegaciones as [$emailDeny, $claveDeny, $efectoDeny]) {
        $stmtIds = $pdo->prepare(
            'SELECT (SELECT id_usuario FROM usuarios WHERE email = :email) AS id_usuario,
                    (SELECT id_permiso FROM permisos WHERE clave = :clave) AS id_permiso'
        );
        $stmtIds->execute(['email' => $emailDeny, 'clave' => $claveDeny]);
        $ids = $stmtIds->fetch(PDO::FETCH_ASSOC) ?: [];
        if (empty($ids['id_usuario']) || empty($ids['id_permiso'])) {
            throw new RuntimeException("No se pudo fijar la denegacion de '{$claveDeny}' para {$emailDeny} (falta el usuario o el permiso).");
        }
        $pdo->prepare('DELETE FROM usuario_permisos WHERE id_usuario = :u AND id_permiso = :p')
            ->execute(['u' => $ids['id_usuario'], 'p' => $ids['id_permiso']]);
        $pdo->prepare(
            'INSERT INTO usuario_permisos (id_usuario, id_permiso, efecto, nota) VALUES (:u, :p, :efecto, "E2E Playwright: caso fijo, ver seed_e2e_staff_accounts.php")'
        )->execute(['u' => $ids['id_usuario'], 'p' => $ids['id_permiso'], 'efecto' => $efectoDeny]);
        echo "Seed OK: {$emailDeny} -> {$efectoDeny} {$claveDeny} (override individual)\n";
    }

    // La cuenta de permisos-en-vivo arranca SIEMPRE sin overrides (una corrida interrumpida pudo dejarle alguno).
    $pdo->prepare('DELETE FROM usuario_permisos WHERE id_usuario = (SELECT id_usuario FROM usuarios WHERE email = :email)')
        ->execute(['email' => 'e2e-permisos-vivo@playwright.test']);

    $pdo->commit();
    exit(0);
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Seed error: ' . $e->getMessage() . "\n");
    exit(1);
}
