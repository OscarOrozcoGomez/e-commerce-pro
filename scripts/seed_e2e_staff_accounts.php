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

        $stmt = $pdo->prepare(
            'INSERT INTO usuarios (nombre, email, contrasena, id_rol, id_almacen, estado)
             VALUES (:nombre, :email, :contrasena, :id_rol, :id_almacen, "activo")
             ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), contrasena = VALUES(contrasena),
                 id_rol = VALUES(id_rol), id_almacen = VALUES(id_almacen), estado = "activo"'
        );
        $stmt->execute([
            'nombre' => $cuenta['nombre'],
            'email' => $cuenta['email'],
            'contrasena' => $hash,
            'id_rol' => $idRol,
            'id_almacen' => $idAlmacenCuenta,
        ]);

        echo "Seed OK: {$cuenta['rol']} -> {$cuenta['email']} (id_rol={$idRol}, id_almacen=" . ($idAlmacenCuenta ?? 'NULL') . ")\n";
    }

    $pdo->commit();
    exit(0);
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Seed error: ' . $e->getMessage() . "\n");
    exit(1);
}
