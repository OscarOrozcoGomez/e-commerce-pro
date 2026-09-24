<?php
declare(strict_types=1);

// Solo lectura. Lo consume tests/e2e/permisos-*.staff.spec.ts: imprime en JSON, por cada cuenta E2E,
// sus permisos EFECTIVOS reales (rol + overrides individuales), calculados con SQL propio y NO con
// getEffectivePermissions()/hasPermission() de la app. Asi la prueba compara "lo que la BD dice que
// tiene" contra "lo que la aplicacion realmente le deja hacer": si usara el mismo helper que la app,
// un error en el helper pasaria desapercibido.
//
// Reglas (mismas que core/auth.php, reescritas aqui a proposito):
//   - admin: todo (hasPermission() devuelve true por isAdmin()).
//   - resto: permisos del rol activos + overrides 'conceder' vigentes - overrides 'denegar' vigentes.
//   - un permiso con estado 'inactivo' no cuenta para nadie (salvo admin, que pasa por isAdmin()).

require_once __DIR__ . '/../core/config.php';

if (!in_array(PHP_SAPI, ['cli', 'phpdbg'], true)) {
    http_response_code(403);
    echo "Este script solo se puede ejecutar por CLI o phpdbg.\n";
    exit(1);
}

$pdo = getPDO();

$catalogo = $pdo->query("SELECT clave FROM permisos WHERE estado = 'activo' ORDER BY clave")->fetchAll(PDO::FETCH_COLUMN);

$porRol = [];
$stmtRol = $pdo->query(
    "SELECT r.nombre AS rol, p.clave
     FROM rol_permisos rp
     JOIN roles r ON r.id_rol = rp.id_rol
     JOIN permisos p ON p.id_permiso = rp.id_permiso
     WHERE p.estado = 'activo'
     ORDER BY r.nombre, p.clave"
);
foreach ($stmtRol->fetchAll(PDO::FETCH_ASSOC) as $fila) {
    $porRol[$fila['rol']][] = $fila['clave'];
}

$cuentas = [];
$stmtUsuarios = $pdo->query(
    "SELECT u.id_usuario, u.email, u.nombre, u.es_superadmin, r.nombre AS rol
     FROM usuarios u JOIN roles r ON r.id_rol = u.id_rol
     WHERE u.email LIKE 'e2e-%@playwright.test' AND u.estado = 'activo'
     ORDER BY u.email"
);
foreach ($stmtUsuarios->fetchAll(PDO::FETCH_ASSOC) as $u) {
    $overrides = $pdo->prepare(
        "SELECT p.clave, up.efecto
         FROM usuario_permisos up JOIN permisos p ON p.id_permiso = up.id_permiso
         WHERE up.id_usuario = ? AND p.estado = 'activo' AND (up.expira_en IS NULL OR up.expira_en > NOW())"
    );
    $overrides->execute([(int) $u['id_usuario']]);
    $ov = $overrides->fetchAll(PDO::FETCH_ASSOC);

    if ($u['rol'] === 'admin') {
        $efectivos = $catalogo;
    } else {
        $set = array_fill_keys($porRol[$u['rol']] ?? [], true);
        foreach ($ov as $o) {
            if ($o['efecto'] === 'denegar') {
                unset($set[$o['clave']]);
            } else {
                $set[$o['clave']] = true;
            }
        }
        $efectivos = array_keys($set);
        sort($efectivos);
    }

    $cuentas[$u['email']] = [
        'rol' => $u['rol'],
        'es_admin' => $u['rol'] === 'admin',
        'es_superadmin' => (int) $u['es_superadmin'] === 1,
        'efectivos' => array_values($efectivos),
        'overrides' => array_map(static fn(array $o): string => $o['efecto'] . ':' . $o['clave'], $ov),
    ];
}

echo json_encode(
    ['catalogo' => $catalogo, 'por_rol' => $porRol, 'cuentas' => $cuentas],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
), "\n";
