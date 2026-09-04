<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
requirePermission('gestionar_usuarios', BASE_URL . 'views/dashboard.php');

$pageTitle = 'Roles y Permisos';
$pdo = getPDO();
$error = '';
$success = '';
$selRol = 0;

// Claves duplicadas conocidas (misma capacidad, dos claves). Se marcan en el catalogo.
$RP_DUPLICADOS = ['venta', 'realizar_ventas'];
$RP_ORDEN_CAT = ['Ventas', 'Inventario', 'Entregas', 'Catalogo', 'Metricas', 'Administracion', 'Otros'];

function rpGetRoles(PDO $pdo): array
{
    $sql = "SELECT r.id_rol, r.nombre, r.descripcion, r.estado,
                   COALESCE(r.es_sistema, 0) AS es_sistema,
                   (SELECT COUNT(*) FROM usuarios u WHERE u.id_rol = r.id_rol) AS num_usuarios
            FROM roles r
            ORDER BY COALESCE(r.es_sistema, 0) DESC, r.nombre";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function rpGetPermisos(PDO $pdo): array
{
    $sql = "SELECT p.id_permiso, p.clave, p.nombre, p.descripcion,
                   COALESCE(NULLIF(p.categoria, ''), 'Otros') AS categoria,
                   (SELECT COUNT(*) FROM rol_permisos rp WHERE rp.id_permiso = p.id_permiso) AS num_roles
            FROM permisos p
            WHERE p.estado = 'activo'
            ORDER BY p.clave";
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function rpRolePermisoIds(PDO $pdo, int $idRol): array
{
    $stmt = $pdo->prepare("SELECT id_permiso FROM rol_permisos WHERE id_rol = ?");
    $stmt->execute([$idRol]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * Arbol de acceso: por cada rol de staff (se excluye 'cliente', que no entra al panel),
 * su set de permisos "pre-aprobado" (rol_permisos) y sus usuarios con lo que se les
 * haya agregado/quitado encima (usuario_permisos vigentes). admin es especial: su
 * acceso es total por hasPermission()/isAdmin() y NO depende de rol_permisos, así que
 * listar sus filas ahí sería enganoso -- se marca aparte.
 *
 * @return array<int, array{id_rol:int, nombre:string, es_sistema:bool, es_admin:bool,
 *   permisos_base: array<int, array{clave:string, categoria:string}>,
 *   usuarios: array<int, array{id_usuario:int, nombre:string, estado:string,
 *     conceder: string[], denegar: string[]}>}>
 */
function rpBuildArbol(PDO $pdo): array
{
    $roles = $pdo->query(
        "SELECT id_rol, nombre, COALESCE(es_sistema, 0) AS es_sistema
         FROM roles WHERE estado = 'activo' AND nombre != 'cliente'"
    )->fetchAll(PDO::FETCH_ASSOC);

    $ordenRol = ['admin' => 0, 'encargado' => 1, 'vendedor' => 2, 'repartidor' => 3];
    usort($roles, static function ($a, $b) use ($ordenRol) {
        $ia = $ordenRol[$a['nombre']] ?? 99;
        $ib = $ordenRol[$b['nombre']] ?? 99;
        return $ia <=> $ib ?: strcmp($a['nombre'], $b['nombre']);
    });

    $basePorRol = [];
    foreach ($pdo->query(
        "SELECT rp.id_rol, p.clave, p.categoria FROM rol_permisos rp
         JOIN permisos p ON p.id_permiso = rp.id_permiso
         WHERE p.estado = 'activo' ORDER BY p.clave"
    ) as $row) {
        $basePorRol[(int) $row['id_rol']][] = $row;
    }

    $usuariosPorRol = [];
    foreach ($pdo->query(
        "SELECT u.id_usuario, u.id_rol, u.nombre, u.estado FROM usuarios u
         JOIN roles r ON r.id_rol = u.id_rol
         WHERE r.nombre != 'cliente' ORDER BY u.nombre"
    ) as $row) {
        $usuariosPorRol[(int) $row['id_rol']][] = $row;
    }

    $overridesPorUsuario = [];
    foreach ($pdo->query(
        "SELECT up.id_usuario, up.efecto, p.clave FROM usuario_permisos up
         JOIN permisos p ON p.id_permiso = up.id_permiso
         WHERE up.expira_en IS NULL OR up.expira_en > NOW()
         ORDER BY p.clave"
    ) as $row) {
        $overridesPorUsuario[(int) $row['id_usuario']][$row['efecto']][] = $row['clave'];
    }

    foreach ($roles as &$r) {
        $idRol = (int) $r['id_rol'];
        $r['id_rol'] = $idRol;
        $r['es_sistema'] = (bool) $r['es_sistema'];
        $r['es_admin'] = $r['nombre'] === 'admin';
        $r['permisos_base'] = $basePorRol[$idRol] ?? [];
        $r['usuarios'] = [];
        foreach (($usuariosPorRol[$idRol] ?? []) as $u) {
            $ov = $overridesPorUsuario[(int) $u['id_usuario']] ?? [];
            $r['usuarios'][] = [
                'id_usuario' => (int) $u['id_usuario'],
                'nombre' => $u['nombre'],
                'estado' => $u['estado'],
                'conceder' => $ov['conceder'] ?? [],
                'denegar' => $ov['denegar'] ?? [],
            ];
        }
    }
    unset($r);

    return $roles;
}

function rpCountOtrosAdminsActivos(PDO $pdo, int $exceptoIdUsuario = 0): int
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM usuarios u JOIN roles r ON r.id_rol = u.id_rol
         WHERE u.estado = 'activo' AND r.nombre = 'admin' AND u.id_usuario <> ?"
    );
    $stmt->execute([$exceptoIdUsuario]);
    return (int) $stmt->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF inválido. Recarga la página e inténtalo de nuevo.';
    } else {
        $accion = (string) $_POST['accion'];
        try {
            if ($accion === 'crear_rol') {
                $nombre = strtolower(trim((string) ($_POST['nombre'] ?? '')));
                $descripcion = trim((string) ($_POST['descripcion'] ?? ''));
                $copiarDe = (int) ($_POST['copiar_de'] ?? 0);

                if (!preg_match('/^[a-z0-9_]{2,50}$/', $nombre)) {
                    throw new Exception('El nombre del rol debe tener 2-50 caracteres: minúsculas, números y guion bajo.');
                }

                $pdo->beginTransaction();
                $pdo->prepare("INSERT INTO roles (nombre, descripcion, estado, es_sistema) VALUES (?, ?, 'activo', 0)")
                    ->execute([$nombre, $descripcion !== '' ? $descripcion : null]);
                $nuevoId = (int) $pdo->lastInsertId();
                if ($copiarDe > 0) {
                    $pdo->prepare("INSERT INTO rol_permisos (id_rol, id_permiso)
                                   SELECT ?, id_permiso FROM rol_permisos WHERE id_rol = ?")
                        ->execute([$nuevoId, $copiarDe]);
                }
                $pdo->commit();
                logAudit('ROL_CREADO', 'roles', $nuevoId, "Nombre: {$nombre}" . ($copiarDe ? " (copiado de rol #{$copiarDe})" : ''));
                $success = 'Rol creado correctamente.';
                $selRol = $nuevoId;
            } elseif ($accion === 'guardar_rol') {
                $idRol = (int) ($_POST['id_rol'] ?? 0);
                $descripcion = trim((string) ($_POST['descripcion'] ?? ''));
                $nombreNuevo = strtolower(trim((string) ($_POST['nombre'] ?? '')));
                $permisosIds = array_values(array_unique(array_map('intval', (array) ($_POST['permisos'] ?? []))));

                $stmt = $pdo->prepare("SELECT * FROM roles WHERE id_rol = ?");
                $stmt->execute([$idRol]);
                $rol = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$rol) {
                    throw new Exception('Rol no encontrado.');
                }
                $esSistema = (int) ($rol['es_sistema'] ?? 0) === 1;

                // Mejora 4: anti-autobloqueo.
                $idPermGestion = (int) $pdo->query("SELECT id_permiso FROM permisos WHERE clave = 'gestionar_usuarios'")->fetchColumn();
                $pierdeGestion = $idPermGestion > 0 && !in_array($idPermGestion, $permisosIds, true);
                if (
                    $pierdeGestion
                    && (int) ($_SESSION['usuario']['id_rol'] ?? 0) === $idRol
                    && !isAdmin()
                    && rpCountOtrosAdminsActivos($pdo, (int) ($_SESSION['usuario']['id_usuario'] ?? 0)) === 0
                ) {
                    throw new Exception('No puedes quitar "gestionar_usuarios" de tu propio rol: quedarías sin acceso a esta pantalla y no hay ningún administrador activo para restaurarlo.');
                }

                $pdo->beginTransaction();
                if (!$esSistema && $nombreNuevo !== '' && $nombreNuevo !== $rol['nombre']) {
                    if (!preg_match('/^[a-z0-9_]{2,50}$/', $nombreNuevo)) {
                        throw new Exception('Nombre de rol inválido.');
                    }
                    $pdo->prepare("UPDATE roles SET nombre = ? WHERE id_rol = ?")->execute([$nombreNuevo, $idRol]);
                }
                $pdo->prepare("UPDATE roles SET descripcion = ? WHERE id_rol = ?")
                    ->execute([$descripcion !== '' ? $descripcion : null, $idRol]);
                $pdo->prepare("DELETE FROM rol_permisos WHERE id_rol = ?")->execute([$idRol]);
                if ($permisosIds) {
                    $ins = $pdo->prepare("INSERT INTO rol_permisos (id_rol, id_permiso) VALUES (?, ?)");
                    foreach ($permisosIds as $pid) {
                        if ($pid > 0) {
                            $ins->execute([$idRol, $pid]);
                        }
                    }
                }
                $pdo->commit();
                logAudit('ROL_ACTUALIZADO', 'roles', $idRol, count($permisosIds) . ' permisos asignados');
                $success = 'Rol actualizado. Los usuarios verán el cambio en menos de 1 minuto.';
                $selRol = $idRol;
            } elseif ($accion === 'eliminar_rol') {
                $idRol = (int) ($_POST['id_rol'] ?? 0);
                $stmt = $pdo->prepare("SELECT * FROM roles WHERE id_rol = ?");
                $stmt->execute([$idRol]);
                $rol = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$rol) {
                    throw new Exception('Rol no encontrado.');
                }
                if ((int) ($rol['es_sistema'] ?? 0) === 1) {
                    throw new Exception('No se puede eliminar un rol del sistema.');
                }
                $num = (int) $pdo->query("SELECT COUNT(*) FROM usuarios WHERE id_rol = " . (int) $idRol)->fetchColumn();
                if ($num > 0) {
                    throw new Exception("No se puede eliminar: {$num} usuario(s) tienen este rol. Reasígnalos a otro rol primero.");
                }
                $pdo->prepare("DELETE FROM roles WHERE id_rol = ?")->execute([$idRol]);
                logAudit('ROL_ELIMINADO', 'roles', $idRol, "Nombre: {$rol['nombre']}");
                $success = 'Rol eliminado.';
            } elseif ($accion === 'crear_permiso' || $accion === 'guardar_permiso') {
                if (!isSuperAdmin()) {
                    throw new Exception('Solo un super admin puede editar el catálogo de permisos.');
                }
                $clave = strtolower(trim((string) ($_POST['clave'] ?? '')));
                $nombre = trim((string) ($_POST['nombre'] ?? ''));
                $descripcion = trim((string) ($_POST['descripcion'] ?? ''));
                $categoria = trim((string) ($_POST['categoria'] ?? 'Otros'));
                if ($categoria === '') {
                    $categoria = 'Otros';
                }
                if (!preg_match('/^[a-z0-9_]{2,100}$/', $clave)) {
                    throw new Exception('La clave debe ser minúsculas, números y guion bajo (2-100).');
                }
                if ($nombre === '') {
                    throw new Exception('El nombre visible es obligatorio.');
                }

                if ($accion === 'crear_permiso') {
                    $pdo->prepare("INSERT INTO permisos (clave, nombre, descripcion, categoria, estado) VALUES (?, ?, ?, ?, 'activo')")
                        ->execute([$clave, $nombre, $descripcion !== '' ? $descripcion : null, $categoria]);
                    logAudit('PERMISO_CREADO', 'permisos', (int) $pdo->lastInsertId(), "Clave: {$clave}");
                    $success = 'Permiso creado.';
                } else {
                    $idPermiso = (int) ($_POST['id_permiso'] ?? 0);
                    $pdo->prepare("UPDATE permisos SET nombre = ?, descripcion = ?, categoria = ? WHERE id_permiso = ?")
                        ->execute([$nombre, $descripcion !== '' ? $descripcion : null, $categoria, $idPermiso]);
                    logAudit('PERMISO_ACTUALIZADO', 'permisos', $idPermiso, "Clave: {$clave}");
                    $success = 'Permiso actualizado.';
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $code = $e instanceof PDOException ? (int) $e->getCode() : 0;
            $error = $code === 23000
                ? 'Error: ya existe un rol o permiso con ese nombre/clave.'
                : 'Error: ' . $e->getMessage();
        }
    }
}

$roles = rpGetRoles($pdo);
$arbol = rpBuildArbol($pdo);
$permisos = rpGetPermisos($pdo);

$permisosPorCategoria = [];
foreach ($permisos as $p) {
    $permisosPorCategoria[$p['categoria']][] = $p;
}
uksort($permisosPorCategoria, static function ($a, $b) use ($RP_ORDEN_CAT) {
    $ia = array_search($a, $RP_ORDEN_CAT, true);
    $ib = array_search($b, $RP_ORDEN_CAT, true);
    $ia = $ia === false ? 999 : $ia;
    $ib = $ib === false ? 999 : $ib;
    return $ia <=> $ib ?: strcmp($a, $b);
});

if ($selRol === 0) {
    $selRol = (int) ($_GET['rol'] ?? 0);
}
$rolSel = null;
foreach ($roles as $r) {
    if ((int) $r['id_rol'] === $selRol) {
        $rolSel = $r;
    }
}
if ($rolSel === null && $roles) {
    $rolSel = $roles[0];
    $selRol = (int) $rolSel['id_rol'];
}
$permisosDelRol = $rolSel ? rpRolePermisoIds($pdo, $selRol) : [];
$usuariosAfectados = $rolSel ? (int) $rolSel['num_usuarios'] : 0;
$rolSelEsSistema = $rolSel ? ((int) ($rolSel['es_sistema'] ?? 0) === 1) : false;

// Mejora 2: ¿quién puede...?
$quienClave = trim((string) ($_GET['quien'] ?? ''));
$quienRoles = [];
$quienIndiv = [];
if ($quienClave !== '') {
    $st = $pdo->prepare(
        "SELECT r.nombre FROM rol_permisos rp
         JOIN roles r ON r.id_rol = rp.id_rol
         JOIN permisos p ON p.id_permiso = rp.id_permiso
         WHERE p.clave = ? ORDER BY r.nombre"
    );
    $st->execute([$quienClave]);
    $quienRoles = $st->fetchAll(PDO::FETCH_COLUMN);

    $st = $pdo->prepare(
        "SELECT u.nombre, r.nombre AS rol, up.efecto, up.nota, up.expira_en
         FROM usuario_permisos up
         JOIN usuarios u ON u.id_usuario = up.id_usuario
         JOIN roles r ON r.id_rol = u.id_rol
         JOIN permisos p ON p.id_permiso = up.id_permiso
         WHERE p.clave = ? ORDER BY up.efecto DESC, u.nombre"
    );
    $st->execute([$quienClave]);
    $quienIndiv = $st->fetchAll(PDO::FETCH_ASSOC);
}

// Historial
$historial = $pdo->query(
    "SELECT l.accion, l.tabla_afectada, l.id_registro, l.detalles, l.fecha, u.nombre AS usuario
     FROM logs_auditoria l
     LEFT JOIN usuarios u ON u.id_usuario = l.id_usuario
     WHERE l.accion IN ('ROL_CREADO','ROL_ACTUALIZADO','ROL_ELIMINADO','PERMISO_CREADO','PERMISO_ACTUALIZADO','USUARIO_PERMISOS_ACTUALIZADOS')
     ORDER BY l.fecha DESC LIMIT 50"
)->fetchAll(PDO::FETCH_ASSOC);

$iconoCategoria = [
    'Ventas' => 'shopping_cart',
    'Inventario' => 'inventory_2',
    'Entregas' => 'local_shipping',
    'Catalogo' => 'storefront',
    'Metricas' => 'insights',
    'Administracion' => 'admin_panel_settings',
    'Otros' => 'label',
];
$iconoRol = [
    'admin' => 'shield',
    'encargado' => 'store',
    'vendedor' => 'point_of_sale',
    'repartidor' => 'local_shipping',
    'cliente' => 'person',
];
$permisosSinEfecto = 0;
foreach ($permisos as $p) {
    if (!in_array($p['clave'], PERMISOS_EN_USO, true)) {
        $permisosSinEfecto++;
    }
}

include __DIR__ . '/includes/header.php';
?>
<style>
    .rp-header { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-top: 20px; }
    .rp-header h4 { margin: 0; }
    .rp-stats .chip { margin: 4px 4px 4px 0; }

    /* Pestañas: el default de Materialize (texto/indicador rosa palido) casi no se ve
       sobre blanco. Barra indigo solida + iconos + subrayado ambar de alto contraste. */
    .card-tabs { background: #1a237e; border-radius: 2px 2px 0 0; }
    .card-tabs .tabs { background: transparent; }
    .card-tabs .tabs .tab { height: 56px; line-height: 56px; }
    .card-tabs .tabs .tab a {
        color: rgba(255,255,255,.72);
        font-weight: 700;
        font-size: 13.5px;
        letter-spacing: .03em;
        transition: color .15s ease;
    }
    .card-tabs .tabs .tab a i.material-icons {
        font-size: 19px;
        margin-right: 6px;
        vertical-align: -4px;
    }
    .card-tabs .tabs .tab a:hover { color: #fff; }
    .card-tabs .tabs .tab a.active { color: #fff; }
    .card-tabs .tabs .tab a:focus, .card-tabs .tabs .tab a:focus.active { background-color: rgba(255,255,255,.14); }
    .card-tabs .tabs .indicator { background-color: #ffab00; height: 3px; }
    .rp-grid { display: grid; grid-template-columns: 300px 1fr; gap: 0; }
    @media (max-width: 900px) { .rp-grid { grid-template-columns: 1fr; } }
    .rp-role-list { border-right: 1px solid #e0e0e0; }
    @media (max-width: 900px) { .rp-role-list { border-right: none; border-bottom: 1px solid #e0e0e0; } }
    .rp-role { display: flex; align-items: center; gap: 12px; padding: 12px 14px; border-bottom: 1px solid #f0f0f0; text-decoration: none; color: #37474f; }
    .rp-role:hover { background: #f5f6ff; }
    .rp-role.active { background: #e8eaf6; box-shadow: inset 4px 0 0 #1a237e; }
    .rp-role.active .rp-role-name { color: #1a237e; }
    .rp-role .material-icons { background: #5c6bc0; color: #fff; border-radius: 50%; padding: 8px; font-size: 20px; }
    .rp-role.active .material-icons { background: #1a237e; }
    .rp-role-name { font-weight: 600; }
    .rp-role-meta { font-size: 11px; color: #90a4ae; }
    .rp-editor { padding: 18px 22px; }
    .rp-cat { margin: 18px 0 6px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #1a237e; font-size: 12px; border-bottom: 1px dashed #c5cae9; padding-bottom: 4px; display: flex; align-items: center; gap: 8px; }
    .rp-perm { display: flex; align-items: center; gap: 12px; padding: 10px 2px; border-bottom: 1px solid #f2f3f9; flex-wrap: wrap; }
    .rp-perm .switch { flex: 0 0 auto; }
    .rp-perm .pk { font-weight: 700; font-family: 'Roboto Mono', monospace; font-size: 13px; }
    .rp-perm .pd { color: #90a4ae; font-size: 12px; display: block; }
    .rp-perm .tags { margin-left: auto; }
    .rp-perm .tags .chip { font-size: 10px; height: 20px; line-height: 20px; font-weight: 700; text-transform: uppercase; margin: 0 0 0 4px; }
    /* Tooltip "que hace este permiso": burbuja al pasar el mouse sobre el icono ⓘ. */
    .rp-info {
        display: inline-flex; align-items: center; justify-content: center;
        width: 16px; height: 16px; border-radius: 50%; margin-left: 5px; vertical-align: -3px;
        background: #e8eaf6; color: #3949ab; font-size: 11px; font-weight: 700; font-style: normal;
        cursor: default; position: relative;
    }
    .rp-info .rp-bubble {
        position: absolute; bottom: calc(100% + 8px); left: 50%; transform: translateX(-50%) translateY(4px);
        width: max-content; max-width: min(260px, 78vw); background: #1c2333; color: #fff; font-weight: 400;
        font-size: 12px; line-height: 1.45; padding: 8px 10px; border-radius: 8px;
        box-shadow: 0 8px 24px rgba(26,35,126,.25); opacity: 0; pointer-events: none;
        transition: opacity .14s ease, transform .14s ease; z-index: 20; text-align: left; white-space: normal;
    }
    .rp-info .rp-bubble::after {
        content: ""; position: absolute; top: 100%; left: 50%; transform: translateX(-50%);
        border: 6px solid transparent; border-top-color: #1c2333;
    }
    .rp-info:hover .rp-bubble, .rp-info:focus-visible .rp-bubble { opacity: 1; transform: translateX(-50%) translateY(0); }

    /* Tab Arbol: admin (todo) -> rol (set base) -> usuarios con sus overrides. */
    .mono { font-family: 'Roboto Mono', monospace; font-size: 12px; }
    .rp-tree-rol { background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; padding: 14px 18px; margin-bottom: 16px; }
    .rp-tree-rol-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .rp-tree-rol-head h6 { margin: 0; font-weight: 700; text-transform: capitalize; font-size: 16px; }
    .rp-tree-base { margin: 10px 0 2px; padding: 10px 12px; background: #f7f8fc; border-radius: 8px; font-size: 12.5px; line-height: 1.7; }
    .rp-tree-base-admin { background: #e8f5e9; color: #2e7d32; }
    .rp-tree-base .cat-tag { display: inline-block; font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .04em; color: #5c6bc0; margin-right: 8px; }
    .rp-tree-users { margin: 12px 0 0 4px; padding-left: 16px; border-left: 2px solid #e8eaf6; }
    .rp-tree-user { display: flex; align-items: center; gap: 8px; padding: 5px 0; font-size: 13px; flex-wrap: wrap; }
    .rp-tree-user .name { font-weight: 600; }
    .rp-tree-user-detail { font-size: 11.5px; color: #78909c; padding: 0 0 6px 22px; line-height: 1.5; }
    .chip.rp-dead { background: #ffebee; color: #c62828; }
    .chip.rp-dup { background: #fff8e1; color: #ff8f00; }
    .chip.rp-live { background: #e8f5e9; color: #2e7d32; }
    .chip.rp-add { background: #e8f5e9; color: #2e7d32; }
    .chip.rp-del { background: #fff3e0; color: #e65100; }
    .rp-actions { margin-top: 20px; display: flex; gap: 10px; flex-wrap: wrap; }
    .rp-diff-list { list-style: none; margin: 8px 0; padding: 0; }
    .rp-diff-list li { padding: 7px 10px; border-radius: 8px; margin-bottom: 6px; font-family: 'Roboto Mono', monospace; font-weight: 700; font-size: 13px; }
    .rp-diff-list li.add { background: #e8f5e9; color: #2e7d32; }
    .rp-diff-list li.del { background: #fff3e0; color: #e65100; }
</style>

<div class="container">
    <div class="rp-header">
        <h4>Roles y Permisos</h4>
        <a href="<?php echo BASE_URL; ?>views/users.php" class="btn blue darken-4 waves-effect waves-light">
            <i class="material-icons left">people</i> Usuarios
        </a>
    </div>

    <div class="rp-stats" style="margin: 10px 0;">
        <span class="chip"><?php echo count($roles); ?> roles</span>
        <span class="chip"><?php echo count($permisos); ?> permisos</span>
        <span class="chip <?php echo $permisosSinEfecto ? 'orange lighten-4 orange-text text-darken-4' : ''; ?>">
            <?php echo $permisosSinEfecto; ?> sin efecto
        </span>
    </div>

    <?php if ($error): ?>
        <div class="card red lighten-2"><div class="card-content white-text"><p><?php echo esc($error); ?></p></div></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="card green lighten-2"><div class="card-content white-text"><p><?php echo esc($success); ?></p></div></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-tabs">
            <ul class="tabs tabs-fixed-width">
                <li class="tab"><a class="active" href="#rp-tab-roles"><i class="material-icons left">groups</i>Roles</a></li>
                <li class="tab"><a href="#rp-tab-arbol"><i class="material-icons left">account_tree</i>Árbol</a></li>
                <li class="tab"><a href="#rp-tab-cat"><i class="material-icons left">fact_check</i>Catálogo de permisos</a></li>
                <li class="tab"><a href="#rp-tab-hist"><i class="material-icons left">history</i>Historial</a></li>
            </ul>
        </div>

        <div class="card-content" style="padding: 0;">
            <!-- ================= TAB ROLES ================= -->
            <div id="rp-tab-roles">
                <div class="rp-grid">
                    <div class="rp-role-list">
                        <?php foreach ($roles as $r): ?>
                            <a class="rp-role <?php echo (int) $r['id_rol'] === $selRol ? 'active' : ''; ?>"
                               href="?rol=<?php echo (int) $r['id_rol']; ?>#rp-tab-roles">
                                <i class="material-icons"><?php echo esc($iconoRol[$r['nombre']] ?? 'supervisor_account'); ?></i>
                                <span>
                                    <span class="rp-role-name"><?php echo esc($r['nombre']); ?></span><br>
                                    <span class="rp-role-meta">
                                        <?php echo (int) $r['es_sistema'] === 1 ? 'sistema · ' : ''; ?>
                                        <?php echo (int) $r['num_usuarios']; ?> usuario(s)
                                    </span>
                                </span>
                            </a>
                        <?php endforeach; ?>
                        <a class="rp-role modal-trigger" href="#rpModalNuevoRol" style="color:#1a237e;font-weight:700;">
                            <i class="material-icons" style="background:#00897b;">add</i>
                            <span>Nuevo rol</span>
                        </a>
                    </div>

                    <div class="rp-editor">
                        <?php if ($rolSel): ?>
                            <form method="POST" id="rpFormRol" data-afectados="<?php echo $usuariosAfectados; ?>">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="accion" value="guardar_rol">
                                <input type="hidden" name="id_rol" value="<?php echo (int) $rolSel['id_rol']; ?>">

                                <h5 style="font-weight:700;margin-top:0;">
                                    <?php echo esc($rolSel['nombre']); ?>
                                    <?php if ($rolSelEsSistema): ?><span class="chip">sistema</span><?php endif; ?>
                                </h5>
                                <p class="grey-text" style="margin-top:-6px;"><?php echo $usuariosAfectados; ?> usuario(s) con este rol</p>

                                <div class="row" style="margin-bottom:0;">
                                    <div class="input-field col s12 m5">
                                        <input id="rp_nombre" type="text" name="nombre"
                                               value="<?php echo esc($rolSel['nombre']); ?>"
                                               <?php echo $rolSelEsSistema ? 'disabled' : ''; ?>>
                                        <label for="rp_nombre" class="active">Nombre<?php echo $rolSelEsSistema ? ' (rol del sistema, no editable)' : ''; ?></label>
                                    </div>
                                    <div class="input-field col s12 m7">
                                        <input id="rp_desc" type="text" name="descripcion"
                                               value="<?php echo esc((string) ($rolSel['descripcion'] ?? '')); ?>">
                                        <label for="rp_desc" class="active">Descripción</label>
                                    </div>
                                </div>

                                <p class="grey-text" style="font-size:13px;margin:4px 0 0;">
                                    <i class="material-icons tiny" style="vertical-align:-3px;">toggle_on</i>
                                    Activa los permisos que este rol otorga por defecto a todos sus usuarios.
                                </p>

                                <?php foreach ($permisosPorCategoria as $cat => $lista): ?>
                                    <div class="rp-cat">
                                        <i class="material-icons tiny"><?php echo esc($iconoCategoria[$cat] ?? 'label'); ?></i>
                                        <?php echo esc($cat); ?>
                                    </div>
                                    <?php foreach ($lista as $p): ?>
                                        <?php
                                        $enRol = in_array((int) $p['id_permiso'], $permisosDelRol, true);
                                        $viva = in_array($p['clave'], PERMISOS_EN_USO, true);
                                        $dup = in_array($p['clave'], $RP_DUPLICADOS, true);
                                        ?>
                                        <div class="rp-perm">
                                            <div class="switch">
                                                <label>
                                                    <input type="checkbox" name="permisos[]"
                                                           value="<?php echo (int) $p['id_permiso']; ?>"
                                                           data-clave="<?php echo esc($p['clave']); ?>"
                                                           data-inrole="<?php echo $enRol ? '1' : '0'; ?>"
                                                           <?php echo $enRol ? 'checked' : ''; ?>>
                                                    <span class="lever"></span>
                                                </label>
                                            </div>
                                            <div>
                                                <span class="pk"><?php echo esc($p['clave']); ?></span>
                                                <?php $pDesc = trim((string) ($p['descripcion'] ?? '')); ?>
                                                <?php if ($pDesc !== ''): ?>
                                                    <span class="rp-info" tabindex="0">i<span class="rp-bubble"><?php echo esc($pDesc); ?></span></span>
                                                <?php endif; ?>
                                                <span class="pd"><?php echo esc((string) ($p['nombre'] ?? '')); ?></span>
                                            </div>
                                            <div class="tags">
                                                <?php if ($dup): ?><span class="chip rp-dup">duplicado</span><?php endif; ?>
                                                <span class="chip <?php echo $viva ? 'rp-live' : 'rp-dead'; ?>">
                                                    <?php echo $viva ? 'activo' : 'sin efecto'; ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>

                                <div class="rp-actions">
                                    <button type="button" id="rpBtnGuardar" class="btn green darken-1 waves-effect">
                                        <i class="material-icons left">save</i> Guardar cambios
                                    </button>
                                    <a href="?rol=<?php echo (int) $rolSel['id_rol']; ?>#rp-tab-roles" class="btn-flat">Cancelar</a>
                                    <?php if (!$rolSelEsSistema): ?>
                                        <button type="submit" form="rpFormEliminar" class="btn red darken-1 waves-effect" style="margin-left:auto;"
                                                onclick="return confirm('¿Eliminar el rol <?php echo esc($rolSel['nombre']); ?>? Esta acción no se puede deshacer.');">
                                            <i class="material-icons left">delete</i> Eliminar rol
                                        </button>
                                    <?php else: ?>
                                        <button type="button" class="btn grey lighten-1 disabled" style="margin-left:auto;">
                                            <i class="material-icons left">lock</i> Rol del sistema
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </form>

                            <?php if (!$rolSelEsSistema): ?>
                                <form method="POST" id="rpFormEliminar" style="display:none;">
                                    <?php echo csrfInput(); ?>
                                    <input type="hidden" name="accion" value="eliminar_rol">
                                    <input type="hidden" name="id_rol" value="<?php echo (int) $rolSel['id_rol']; ?>">
                                </form>
                            <?php endif; ?>
                        <?php else: ?>
                            <p class="grey-text">No hay roles. Crea uno con el botón «Nuevo rol».</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- ================= TAB ARBOL ================= -->
            <div id="rp-tab-arbol" style="padding: 22px;">
                <h5 style="font-weight:700;margin-top:0;">Árbol de acceso por rol</h5>
                <p class="grey-text" style="font-size:13px;max-width:70ch;">
                    De arriba hacia abajo: lo que trae cada rol de fábrica (el "set pre-aprobado"), y debajo
                    quién lo tiene hoy y qué le cambiaste encima. Para editar el set de un rol o los permisos
                    de una persona, usa la pestaña <b>Roles</b> o <a href="<?php echo BASE_URL; ?>views/users.php">Usuarios</a>
                    — esta vista es solo de consulta.
                </p>

                <?php foreach ($arbol as $rolArbol): ?>
                    <div class="rp-tree-rol">
                        <div class="rp-tree-rol-head">
                            <i class="material-icons" style="color:#3949ab;"><?php echo esc($iconoRol[$rolArbol['nombre']] ?? 'group'); ?></i>
                            <h6><?php echo esc($rolArbol['nombre']); ?></h6>
                            <?php if ($rolArbol['es_sistema']): ?>
                                <span class="chip grey lighten-3" style="font-size:10px;height:20px;line-height:20px;">sistema</span>
                            <?php endif; ?>
                            <span class="grey-text" style="font-size:12.5px;margin-left:auto;">
                                <?php echo count($rolArbol['usuarios']); ?> usuario<?php echo count($rolArbol['usuarios']) === 1 ? '' : 's'; ?>
                            </span>
                            <a href="?rol=<?php echo (int) $rolArbol['id_rol']; ?>#rp-tab-roles" class="btn-flat btn-small waves-effect" style="padding:0 10px;">Editar rol</a>
                        </div>

                        <?php if ($rolArbol['es_admin']): ?>
                            <div class="rp-tree-base rp-tree-base-admin">
                                <i class="material-icons tiny" style="vertical-align:-3px;">verified_user</i>
                                Acceso total a todo el sistema, por diseño: <span class="mono">isAdmin()</span> pasa
                                cualquier verificación de permiso, sin importar el catálogo.
                            </div>
                        <?php else: ?>
                            <div class="rp-tree-base">
                                <?php if (empty($rolArbol['permisos_base'])): ?>
                                    <span class="grey-text">Este rol no trae ningún permiso de fábrica todavía.</span>
                                <?php else: ?>
                                    <?php
                                    $porCat = [];
                                    foreach ($rolArbol['permisos_base'] as $pb) {
                                        $porCat[$pb['categoria']][] = $pb['clave'];
                                    }
                                    ?>
                                    <?php foreach ($porCat as $cat => $claves): ?>
                                        <div style="margin-bottom:4px;">
                                            <span class="cat-tag"><?php echo esc($cat); ?></span>
                                            <?php foreach ($claves as $cl): ?><span class="mono" style="margin-right:10px;"><?php echo esc($cl); ?></span><?php endforeach; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($rolArbol['usuarios'])): ?>
                            <?php
                            $conCambios = [];
                            $sinCambios = [];
                            foreach ($rolArbol['usuarios'] as $u) {
                                if (count($u['conceder']) > 0 || count($u['denegar']) > 0) {
                                    $conCambios[] = $u;
                                } else {
                                    $sinCambios[] = $u;
                                }
                            }
                            ?>
                            <div class="rp-tree-users">
                                <?php if (empty($conCambios)): ?>
                                    <p class="grey-text" style="font-size:12.5px;margin:2px 0;">
                                        Nadie tiene permisos individuales sobre este rol todavía —
                                        los <?php echo count($sinCambios); ?> usuarios tienen exactamente el set de arriba.
                                    </p>
                                <?php else: ?>
                                    <?php foreach ($conCambios as $u): ?>
                                        <?php $nCon = count($u['conceder']); $nDen = count($u['denegar']); ?>
                                        <div class="rp-tree-user">
                                            <i class="material-icons tiny grey-text"><?php echo $u['estado'] === 'activo' ? 'person' : 'person_off'; ?></i>
                                            <span class="name"><?php echo esc($u['nombre']); ?></span>
                                            <?php if ($u['estado'] !== 'activo'): ?>
                                                <span class="grey-text" style="font-size:11px;">(inactivo)</span>
                                            <?php endif; ?>
                                            <span class="chip green lighten-4 green-text text-darken-2" style="font-size:10px;height:20px;line-height:20px;">
                                                rol<?php echo $nCon > 0 ? ' +' . $nCon : ''; ?><?php echo $nDen > 0 ? ' −' . $nDen : ''; ?>
                                            </span>
                                        </div>
                                        <div class="rp-tree-user-detail">
                                            <?php if ($nCon > 0): ?>añadido: <span class="mono"><?php echo esc(implode(', ', $u['conceder'])); ?></span><?php endif; ?>
                                            <?php if ($nCon > 0 && $nDen > 0): ?> &middot; <?php endif; ?>
                                            <?php if ($nDen > 0): ?>quitado: <span class="mono"><?php echo esc(implode(', ', $u['denegar'])); ?></span><?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (!empty($sinCambios)): ?>
                                        <details style="margin-top:6px;">
                                            <summary style="cursor:pointer;font-size:12px;color:#78909c;">
                                                + <?php echo count($sinCambios); ?> más igual al rol, sin cambios
                                            </summary>
                                            <p style="font-size:12px;color:#78909c;margin:6px 0 0;">
                                                <?php echo esc(implode(', ', array_column($sinCambios, 'nombre'))); ?>
                                            </p>
                                        </details>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <p class="grey-text" style="font-size:12.5px;margin:10px 0 0;">Nadie tiene este rol todavía.</p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- ================= TAB CATALOGO ================= -->
            <div id="rp-tab-cat" style="padding: 22px;">
                <div class="row">
                    <div class="col s12 m7">
                        <h5 style="font-weight:700;margin-top:0;">Catálogo de permisos</h5>
                        <p class="grey-text" style="font-size:13px;">
                            <span class="chip rp-live">activo</span> alguna parte del código lo comprueba hoy ·
                            <span class="chip rp-dead">sin efecto</span> aún se decide por rol ·
                            <span class="chip rp-dup">duplicado</span> misma capacidad que otra clave
                        </p>
                        <table class="striped">
                            <thead><tr><th>Clave</th><th>Categoría</th><th>Roles</th><th>Estado</th></tr></thead>
                            <tbody>
                                <?php foreach ($permisos as $p): ?>
                                    <?php $viva = in_array($p['clave'], PERMISOS_EN_USO, true); $dup = in_array($p['clave'], $RP_DUPLICADOS, true); ?>
                                    <tr>
                                        <td><b><?php echo esc($p['clave']); ?></b><br><span class="grey-text" style="font-size:11px;"><?php echo esc((string) ($p['nombre'] ?? '')); ?></span></td>
                                        <td><?php echo esc($p['categoria']); ?></td>
                                        <td><?php echo (int) $p['num_roles']; ?></td>
                                        <td>
                                            <?php if ($dup): ?><span class="chip rp-dup">duplicado</span><br><?php endif; ?>
                                            <span class="chip <?php echo $viva ? 'rp-live' : 'rp-dead'; ?>"><?php echo $viva ? 'activo' : 'sin efecto'; ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <?php if (isSuperAdmin()): ?>
                            <a class="btn-small indigo waves-effect modal-trigger" href="#rpModalNuevoPermiso" style="margin-top:10px;">
                                <i class="material-icons left">add</i> Nueva clave de permiso
                            </a>
                        <?php endif; ?>
                    </div>

                    <div class="col s12 m5">
                        <div class="card-panel grey lighten-4" style="border-radius:10px;">
                            <h5 style="font-weight:700;margin-top:0;font-size:1.2rem;">¿Quién puede…?</h5>
                            <form method="GET">
                                <div class="input-field">
                                    <select name="quien" onchange="this.form.submit()">
                                        <option value="">-- Elige un permiso --</option>
                                        <?php foreach ($permisos as $p): ?>
                                            <option value="<?php echo esc($p['clave']); ?>" <?php echo $quienClave === $p['clave'] ? 'selected' : ''; ?>>
                                                <?php echo esc($p['clave']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <label>Permiso</label>
                                </div>
                            </form>
                            <?php if ($quienClave !== ''): ?>
                                <p style="font-weight:700;margin-bottom:2px;">Roles que lo otorgan</p>
                                <?php if ($quienRoles): ?>
                                    <?php foreach ($quienRoles as $rn): ?><span class="chip"><?php echo esc($rn); ?></span><?php endforeach; ?>
                                <?php else: ?>
                                    <p class="grey-text">Ningún rol.</p>
                                <?php endif; ?>

                                <p style="font-weight:700;margin:14px 0 2px;">Ajustes individuales</p>
                                <?php if ($quienIndiv): ?>
                                    <ul class="collection" style="border:none;">
                                        <?php foreach ($quienIndiv as $qi): ?>
                                            <li class="collection-item" style="padding:8px 4px;">
                                                <?php echo esc($qi['nombre']); ?>
                                                <span class="grey-text">(<?php echo esc($qi['rol']); ?>)</span>
                                                <span class="chip <?php echo $qi['efecto'] === 'conceder' ? 'rp-add' : 'rp-del'; ?>">
                                                    <?php echo $qi['efecto'] === 'conceder' ? 'añadido' : 'quitado'; ?>
                                                </span>
                                                <?php if (!empty($qi['expira_en'])): ?>
                                                    <span class="grey-text" style="font-size:11px;">· expira <?php echo esc(date('d/m/Y', strtotime((string) $qi['expira_en']))); ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($qi['nota'])): ?>
                                                    <br><span class="grey-text" style="font-size:11px;">nota: <?php echo esc((string) $qi['nota']); ?></span>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php else: ?>
                                    <p class="grey-text">Sin ajustes individuales.</p>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ================= TAB HISTORIAL ================= -->
            <div id="rp-tab-hist" style="padding: 22px;">
                <h5 style="font-weight:700;margin-top:0;">Últimos cambios de roles y permisos</h5>
                <table class="striped">
                    <thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Detalle</th></tr></thead>
                    <tbody>
                        <?php foreach ($historial as $h): ?>
                            <tr>
                                <td style="white-space:nowrap;"><?php echo esc(date('d/m/Y H:i', strtotime((string) $h['fecha']))); ?></td>
                                <td><?php echo esc((string) ($h['usuario'] ?? '—')); ?></td>
                                <td><span class="chip"><?php echo esc($h['accion']); ?></span></td>
                                <td><?php echo esc((string) ($h['detalles'] ?? '')); ?> <span class="grey-text" style="font-size:11px;">#<?php echo (int) $h['id_registro']; ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$historial): ?>
                            <tr><td colspan="4" class="grey-text">Sin registros todavía.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ===== MODAL NUEVO ROL ===== -->
<div id="rpModalNuevoRol" class="modal">
    <form method="POST">
        <?php echo csrfInput(); ?>
        <input type="hidden" name="accion" value="crear_rol">
        <div class="modal-content">
            <h5 style="font-weight:700;"><i class="material-icons left indigo-text">add_moderator</i> Nuevo rol</h5>
            <div class="row" style="margin-top:14px;">
                <div class="input-field col s12 m6">
                    <input id="nr_nombre" type="text" name="nombre" required>
                    <label for="nr_nombre">Nombre (minúsculas, sin espacios)</label>
                </div>
                <div class="input-field col s12 m6">
                    <input id="nr_desc" type="text" name="descripcion">
                    <label for="nr_desc">Descripción</label>
                </div>
                <div class="input-field col s12">
                    <select name="copiar_de">
                        <option value="0">Empezar vacío</option>
                        <?php foreach ($roles as $r): ?>
                            <option value="<?php echo (int) $r['id_rol']; ?>">Copiar permisos de: <?php echo esc($r['nombre']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label>Permisos por defecto</label>
                </div>
            </div>
            <p class="grey-text" style="font-size:12px;"><i class="material-icons tiny">info</i> Podrás ajustar los permisos justo después de crearlo.</p>
        </div>
        <div class="modal-footer">
            <a href="#!" class="modal-close btn-flat">Cancelar</a>
            <button type="submit" class="btn indigo waves-effect"><i class="material-icons left">check</i> Crear rol</button>
        </div>
    </form>
</div>

<?php if (isSuperAdmin()): ?>
<!-- ===== MODAL NUEVA CLAVE DE PERMISO ===== -->
<div id="rpModalNuevoPermiso" class="modal">
    <form method="POST">
        <?php echo csrfInput(); ?>
        <input type="hidden" name="accion" value="crear_permiso">
        <div class="modal-content">
            <h5 style="font-weight:700;"><i class="material-icons left indigo-text">vpn_key</i> Nueva clave de permiso</h5>
            <div class="row" style="margin-top:14px;">
                <div class="input-field col s12 m6"><input type="text" name="clave" required><label>clave (snake_case)</label></div>
                <div class="input-field col s12 m6"><input type="text" name="nombre" required><label>Nombre visible</label></div>
                <div class="input-field col s12 m6">
                    <select name="categoria">
                        <?php foreach (['Ventas', 'Inventario', 'Entregas', 'Catalogo', 'Administracion', 'Otros'] as $c): ?>
                            <option value="<?php echo $c; ?>"><?php echo $c; ?></option>
                        <?php endforeach; ?>
                    </select>
                    <label>Categoría</label>
                </div>
                <div class="input-field col s12"><input type="text" name="descripcion"><label>Descripción</label></div>
            </div>
            <p class="grey-text" style="font-size:12px;"><i class="material-icons tiny">info</i> Las claves no se borran, se desactivan. Crear una clave no la conecta con el código: eso es trabajo de la Fase 4.</p>
        </div>
        <div class="modal-footer">
            <a href="#!" class="modal-close btn-flat">Cancelar</a>
            <button type="submit" class="btn indigo waves-effect">Crear</button>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- ===== MODAL DIFF (mejora 3) ===== -->
<div id="rpModalDiff" class="modal">
    <div class="modal-content">
        <h5 style="font-weight:700;"><i class="material-icons left amber-text text-darken-2">rule</i> Confirmar cambios del rol</h5>
        <p class="grey-text">Afecta a <b id="rpDiffAfectados">0</b> usuario(s) con este rol. Verán el cambio en menos de 1 minuto.</p>
        <ul class="rp-diff-list" id="rpDiffLista"></ul>
        <p id="rpDiffSinCambios" class="grey-text" style="display:none;">No hay cambios en los permisos.</p>
    </div>
    <div class="modal-footer">
        <a href="#!" class="modal-close btn-flat">Volver a editar</a>
        <button type="button" id="rpDiffAplicar" class="btn green darken-1 waves-effect"><i class="material-icons left">check</i> Aplicar cambios</button>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        M.Tabs.init(document.querySelectorAll('.tabs'));
        M.Modal.init(document.querySelectorAll('.modal'));
        M.FormSelect.init(document.querySelectorAll('select'));
        M.updateTextFields();

        var form = document.getElementById('rpFormRol');
        var btn = document.getElementById('rpBtnGuardar');
        if (form && btn) {
            var diffModal = M.Modal.getInstance(document.getElementById('rpModalDiff'));
            btn.addEventListener('click', function () {
                var añadidos = [], quitados = [];
                form.querySelectorAll('input[name="permisos[]"]').forEach(function (cb) {
                    var enRol = cb.dataset.inrole === '1';
                    if (cb.checked && !enRol) añadidos.push(cb.dataset.clave);
                    if (!cb.checked && enRol) quitados.push(cb.dataset.clave);
                });
                var ul = document.getElementById('rpDiffLista');
                ul.innerHTML = '';
                añadidos.forEach(function (c) { ul.insertAdjacentHTML('beforeend', '<li class="add">+ ' + c + '</li>'); });
                quitados.forEach(function (c) { ul.insertAdjacentHTML('beforeend', '<li class="del">− ' + c + '</li>'); });
                document.getElementById('rpDiffSinCambios').style.display = (añadidos.length + quitados.length) ? 'none' : 'block';
                document.getElementById('rpDiffAfectados').textContent = form.dataset.afectados || '0';
                diffModal.open();
            });
            document.getElementById('rpDiffAplicar').addEventListener('click', function () { form.submit(); });
        }
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
