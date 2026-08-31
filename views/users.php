<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

requireAuth();
requirePermission('gestionar_usuarios', BASE_URL . 'views/dashboard.php');

$pageTitle = 'Gestionar Usuarios';
$pdo = getPDO();
$error = '';
$success = '';

function generateTemporarySecurePassword(int $length = 12): string
{
    $length = max(10, $length);

    $upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    $lower = 'abcdefghijkmnpqrstuvwxyz';
    $digits = '23456789';
    $symbols = '!@#$%^&*()_+-=';

    $password = [
        $upper[random_int(0, strlen($upper) - 1)],
        $lower[random_int(0, strlen($lower) - 1)],
        $digits[random_int(0, strlen($digits) - 1)],
        $symbols[random_int(0, strlen($symbols) - 1)],
    ];

    $all = $upper . $lower . $digits . $symbols;
    while (count($password) < $length) {
        $password[] = $all[random_int(0, strlen($all) - 1)];
    }

    shuffle($password);
    return implode('', $password);
}

function getStaffUserById(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare("SELECT u.id_usuario, u.estado, COALESCE(u.es_superadmin, 0) AS es_superadmin, r.nombre AS rol
                           FROM usuarios u
                           JOIN roles r ON u.id_rol = r.id_rol
                           WHERE u.id_usuario = ?
                           LIMIT 1");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user !== false ? $user : null;
}

/**
 * Chip que resume cuánto se ha personalizado un usuario respecto a su rol (mejora 1).
 */
function upPersonalizacionChip(array $user): string
{
    $c = (int) ($user['ov_conceder'] ?? 0);
    $d = (int) ($user['ov_denegar'] ?? 0);
    if ($c === 0 && $d === 0) {
        return '<span class="chip grey lighten-3" style="font-size:11px;height:20px;line-height:20px;">rol</span>';
    }
    $txt = 'rol' . ($c > 0 ? ' +' . $c : '') . ($d > 0 ? ' −' . $d : '');
    return '<span class="chip green lighten-4 green-text text-darken-2" style="font-size:11px;height:20px;line-height:20px;"'
        . ' title="' . $c . ' permiso(s) añadido(s), ' . $d . ' quitado(s)">' . esc($txt) . '</span>';
}

// Procesar formulario
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = 'Token CSRF inválido. Por favor recarga la página e inténtalo de nuevo.';
    } else {
        $accion = htmlspecialchars($_POST['accion']);
        
        if ($accion === 'agregar') {
            try {
                $email = htmlspecialchars($_POST['email'] ?? '');
                $nombre = htmlspecialchars($_POST['nombre'] ?? '');
                $passwordRaw = $_POST['password'] ?? '';

                // Mejora 8: alta por invitación. En vez de teclear una contraseña temporal
                // y dictarla, se crea con una aleatoria que nadie ve y se le envía un código
                // para que la persona fije su propia contraseña.
                $invitar = ($_POST['invitar_password'] ?? '') === '1';
                if ($invitar) {
                    $passwordRaw = generateTemporarySecurePassword(20);
                }

                if (!isPasswordSecure($passwordRaw)) {
                    throw new Exception("La contraseña no cumple con los requisitos mínimos de seguridad (10 caracteres, mayúscula, número y símbolo).");
                }

                $password = password_hash($passwordRaw, PASSWORD_BCRYPT);
                $id_rol = intval($_POST['id_rol'] ?? 0);
                $id_almacen = intval($_POST['id_almacen'] ?? 0) ?: null;
                
                // Validar que Vendedores y Encargados tengan sucursal asignada
                $stmtRol = $pdo->prepare("SELECT nombre FROM roles WHERE id_rol = ?");
                $stmtRol->execute([$id_rol]);
                $rolNombre = $stmtRol->fetchColumn();

                if ($rolNombre === 'admin' && !isSuperAdmin()) {
                    throw new Exception('Solo un super admin puede crear otros administradores.');
                }

                if (in_array($rolNombre, ['vendedor', 'encargado']) && !$id_almacen) {
                    throw new Exception("Los vendedores y encargados deben tener una sucursal asignada obligatoriamente.");
                }

                $stmtExistingUser = $pdo->prepare("SELECT u.id_usuario, u.estado, u.id_rol, u.id_almacen, COALESCE(u.es_superadmin, 0) AS es_superadmin, r.nombre AS rol
                                                  FROM usuarios u
                                                  JOIN roles r ON u.id_rol = r.id_rol
                                                  WHERE LOWER(u.email) = LOWER(?)
                                                  LIMIT 1");
                $stmtExistingUser->execute([$email]);
                $existingUser = $stmtExistingUser->fetch(PDO::FETCH_ASSOC);

                if ($existingUser && !shouldReactivateExistingUser($existingUser)) {
                    throw new Exception('El correo ya está asociado a otra cuenta activa. Usa otro correo o reactiva la cuenta existente.');
                }

                if ($existingUser && shouldReactivateExistingUser($existingUser)) {
                    $targetUserId = (int)$existingUser['id_usuario'];
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare("UPDATE usuarios SET nombre = ?, email = ?, contrasena = ?, id_rol = ?, id_almacen = ?, estado = 'activo', intentos_fallidos = 0, bloqueado_hasta = NULL, es_superadmin = 0 WHERE id_usuario = ?")
                            ->execute([$nombre, $email, $password, $id_rol, $id_almacen, $targetUserId]);
                        $pdo->commit();
                        logAudit('USUARIO_REACTIVADO', 'usuarios', $targetUserId, "Email: $email" . ($invitar ? ' (invitación enviada)' : ''));
                        $success = 'Cuenta existente reactivada y actualizada correctamente.';
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        throw $e;
                    }
                } else {
                    $sql = "INSERT INTO usuarios (nombre, email, contrasena, id_rol, id_almacen, estado)
                            VALUES (:nombre, :email, :contrasena, :id_rol, :id_almacen, 'activo')";
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        ':nombre' => $nombre,
                        ':email' => $email,
                        ':contrasena' => $password,
                        ':id_rol' => $id_rol,
                        ':id_almacen' => $id_almacen,
                    ]);
                    logAudit('USUARIO_CREADO', 'usuarios', (int)$pdo->lastInsertId(), "Email: $email" . ($invitar ? ' (invitación enviada)' : ''));
                    $success = 'Usuario creado correctamente.';
                }

                if ($invitar) {
                    // La contraseña aleatoria no se comunica: se manda un código para que
                    // la persona fije la suya en la pantalla de recuperar contraseña.
                    $enviado = generatePasswordResetToken($email, true);
                    $success .= $enviado
                        ? ' Se envió un correo de invitación para que fije su contraseña.'
                        : ' Aviso: no se pudo enviar el correo de invitación; usa "Restablecer contraseña" para reintentar.';
                }
            } catch (Throwable $e) {
                $error = 'Error: ' . $e->getMessage();
            }
        } elseif ($accion === 'cambiar_estado') {
            $id = intval($_POST['id_usuario']);
            $nuevo_estado = $_POST['estado'] === 'activo' ? 'inactivo' : 'activo';

            $targetUser = getStaffUserById($pdo, $id);
            if (!$targetUser) {
                throw new Exception('Usuario no encontrado.');
            }

            if (isAdminAccount($targetUser) && !isSuperAdmin()) {
                throw new Exception('Solo un super admin puede modificar cuentas de administrador.');
            }

            if (($targetUser['es_superadmin'] ?? 0) && $nuevo_estado === 'inactivo') {
                $stmtSuper = $pdo->prepare("SELECT COUNT(*) FROM usuarios WHERE estado = 'activo' AND COALESCE(es_superadmin, 0) = 1");
                $stmtSuper->execute();
                if ((int)$stmtSuper->fetchColumn() <= 1) {
                    throw new Exception('No puedes desactivar el último super admin activo.');
                }
            }

            $stmt = $pdo->prepare("UPDATE usuarios SET estado = ? WHERE id_usuario = ?");
            $stmt->execute([$nuevo_estado, $id]);
            logAudit('USUARIO_ESTADO_CAMBIADO', 'usuarios', $id, "Nuevo estado: $nuevo_estado");
            $success = 'Estado de usuario actualizado.';
        } elseif ($accion === 'desbloquear') {
            $id = intval($_POST['id_usuario']);

            $targetUser = getStaffUserById($pdo, $id);
            if (!$targetUser) {
                throw new Exception('Usuario no encontrado.');
            }

            if (isAdminAccount($targetUser) && !isSuperAdmin()) {
                throw new Exception('Solo un super admin puede desbloquear cuentas de administrador.');
            }

            $pdo->prepare("UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id_usuario = ?")->execute([$id]);
            logAudit('USUARIO_DESBLOQUEADO', 'usuarios', $id, "Cuenta desbloqueada manualmente por admin");
            $success = 'Usuario desbloqueado correctamente.';
        } elseif ($accion === 'eliminar_usuario') {
            $id = intval($_POST['id_usuario'] ?? 0);
            if ($id <= 0) {
                throw new Exception('ID de usuario inválido para eliminar.');
            }

            $targetUser = getStaffUserById($pdo, $id);
            if (!$targetUser) {
                throw new Exception('Usuario no encontrado.');
            }

            $currentUserId = (int)($_SESSION['usuario']['id_usuario'] ?? 0);
            if (!canDeleteUserAccount($targetUser, $currentUserId)) {
                throw new Exception('No tienes permisos para eliminar esta cuenta de usuario.');
            }

            $pdo->beginTransaction();
            try {
                $pdo->prepare("DELETE FROM vendedor_liquidaciones WHERE id_vendedor = ?")->execute([$id]);
                $pdo->prepare("DELETE FROM usuarios WHERE id_usuario = ?")->execute([$id]);
                $pdo->commit();
                logAudit('USUARIO_ELIMINADO', 'usuarios', $id, 'Eliminación manual por admin');
                $success = 'Usuario eliminado correctamente.';
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        } elseif ($accion === 'reset_password_staff') {
            try {
                $id = intval($_POST['id_usuario'] ?? 0);
                $emailUsuario = trim((string)($_POST['email_usuario'] ?? ''));

                if ($id <= 0) {
                    throw new Exception('ID de usuario inválido para reset de contraseña.');
                }

                $targetUser = getStaffUserById($pdo, $id);
                if (!$targetUser) {
                    throw new Exception('Usuario no encontrado.');
                }

                if (isAdminAccount($targetUser) && !isSuperAdmin()) {
                    throw new Exception('Solo un super admin puede resetear la contraseña de cuentas de administrador.');
                }

                $tempPassword = generateTemporarySecurePassword(12);
                $tempHash = password_hash($tempPassword, PASSWORD_BCRYPT);

                $pdo->prepare("UPDATE usuarios SET contrasena = ?, intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id_usuario = ?")
                    ->execute([$tempHash, $id]);

                logAudit('USUARIO_PASSWORD_RESETEADA', 'usuarios', $id, 'Reset manual de contraseña para staff');
                $target = $emailUsuario !== '' ? $emailUsuario : ('usuario #' . $id);
                $success = 'Contraseña temporal generada para ' . $target . ': ' . $tempPassword;
            } catch (Throwable $e) {
                $error = 'No se pudo resetear la contraseña: ' . $e->getMessage();
            }
        } elseif ($accion === 'guardar_permisos_usuario') {
            try {
                $id = intval($_POST['id_usuario'] ?? 0);
                $nota = trim((string) ($_POST['nota'] ?? ''));
                $deseados = array_values(array_unique(array_map('intval', (array) ($_POST['permisos'] ?? []))));

                // Mejora 6: caducidad opcional por permiso concedido. expira[<id_permiso>] = 'YYYY-MM-DD'.
                $expiraInput = (array) ($_POST['expira'] ?? []);
                $expiraPorPermiso = [];
                foreach ($expiraInput as $pidRaw => $fechaRaw) {
                    $fechaRaw = trim((string) $fechaRaw);
                    if ($fechaRaw === '') {
                        continue;
                    }
                    $d = DateTime::createFromFormat('Y-m-d', $fechaRaw);
                    if (!$d || $d->format('Y-m-d') !== $fechaRaw) {
                        throw new Exception('La fecha de caducidad no es válida (formato AAAA-MM-DD).');
                    }
                    // Se guarda como fin del día para que el permiso dure toda la fecha elegida.
                    $expiraPorPermiso[(int) $pidRaw] = $d->format('Y-m-d') . ' 23:59:59';
                }

                if ($id <= 0) {
                    throw new Exception('ID de usuario inválido.');
                }

                // Mejora 7: el motivo del cambio es obligatorio y queda en la auditoría.
                if ($nota === '') {
                    throw new Exception('Escribe el motivo del cambio: queda registrado en la auditoría.');
                }
                if (mb_strlen($nota) > 255) {
                    $nota = mb_substr($nota, 0, 255);
                }

                $stmtT = $pdo->prepare("SELECT u.id_usuario, u.id_rol, u.estado, COALESCE(u.es_superadmin,0) AS es_superadmin, r.nombre AS rol
                                        FROM usuarios u JOIN roles r ON r.id_rol = u.id_rol
                                        WHERE u.id_usuario = ? LIMIT 1");
                $stmtT->execute([$id]);
                $targetUser = $stmtT->fetch(PDO::FETCH_ASSOC);
                if (!$targetUser) {
                    throw new Exception('Usuario no encontrado.');
                }
                if (isAdminAccount($targetUser) && !isSuperAdmin()) {
                    throw new Exception('Solo un super admin puede editar los permisos de una cuenta de administrador.');
                }

                // Permisos que otorga su rol.
                $stmtR = $pdo->prepare("SELECT id_permiso FROM rol_permisos WHERE id_rol = ?");
                $stmtR->execute([(int) $targetUser['id_rol']]);
                $idsRol = array_map('intval', $stmtR->fetchAll(PDO::FETCH_COLUMN));

                // Mejora 4: anti-autobloqueo. No puedes quitarte a ti mismo el permiso
                // que da acceso a esta pantalla; que lo haga otro administrador si hace falta.
                $idGestion = (int) $pdo->query("SELECT id_permiso FROM permisos WHERE clave = 'gestionar_usuarios'")->fetchColumn();
                if (
                    $idGestion > 0
                    && $id === (int) ($_SESSION['usuario']['id_usuario'] ?? 0)
                    && !in_array($idGestion, $deseados, true)
                ) {
                    throw new Exception('No puedes quitarte "gestionar_usuarios" a ti mismo. Pídele a otro administrador que lo haga si de verdad es necesario.');
                }

                $todos = $pdo->query("SELECT id_permiso FROM permisos WHERE estado = 'activo'")->fetchAll(PDO::FETCH_COLUMN);

                $pdo->beginTransaction();
                $del = $pdo->prepare("DELETE FROM usuario_permisos WHERE id_usuario = ? AND id_permiso = ?");
                $ins = $pdo->prepare("INSERT INTO usuario_permisos (id_usuario, id_permiso, efecto, nota, expira_en, asignado_por)
                                      VALUES (?, ?, ?, ?, ?, ?)
                                      ON DUPLICATE KEY UPDATE efecto = VALUES(efecto), nota = VALUES(nota), expira_en = VALUES(expira_en), asignado_por = VALUES(asignado_por)");
                $nConceder = 0;
                $nDenegar = 0;
                $nConCaducidad = 0;
                $asignadoPor = (int) ($_SESSION['usuario']['id_usuario'] ?? 0) ?: null;
                foreach ($todos as $pid) {
                    $pid = (int) $pid;
                    $enRol = in_array($pid, $idsRol, true);
                    $marcado = in_array($pid, $deseados, true);
                    if ($marcado === $enRol) {
                        $del->execute([$id, $pid]);
                    } elseif ($marcado && !$enRol) {
                        $expira = $expiraPorPermiso[$pid] ?? null;
                        $ins->execute([$id, $pid, 'conceder', $nota, $expira, $asignadoPor]);
                        $nConceder++;
                        if ($expira !== null) {
                            $nConCaducidad++;
                        }
                    } else {
                        // Un 'denegar' no caduca: quitarle algo a alguien no debería revertirse solo.
                        $ins->execute([$id, $pid, 'denegar', $nota, null, $asignadoPor]);
                        $nDenegar++;
                    }
                }
                $pdo->commit();

                logAudit('USUARIO_PERMISOS_ACTUALIZADOS', 'usuario_permisos', $id,
                    "conceder={$nConceder} denegar={$nDenegar} con_caducidad={$nConCaducidad} | nota: {$nota}");
                $success = 'Permisos del usuario actualizados. El cambio aplica en menos de 1 minuto.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $error = 'No se pudieron guardar los permisos: ' . $e->getMessage();
            }
        }
    }
}

// Obtener usuarios
try {
    $sql = "SELECT u.id_usuario, u.nombre, u.email, u.id_rol, r.nombre as rol, a.nombre as almacen, u.estado, u.es_superadmin, u.intentos_fallidos, u.bloqueado_hasta,
                   (SELECT COUNT(*) FROM usuario_permisos up WHERE up.id_usuario = u.id_usuario AND up.efecto = 'conceder' AND (up.expira_en IS NULL OR up.expira_en > NOW())) AS ov_conceder,
                   (SELECT COUNT(*) FROM usuario_permisos up WHERE up.id_usuario = u.id_usuario AND up.efecto = 'denegar' AND (up.expira_en IS NULL OR up.expira_en > NOW())) AS ov_denegar
            FROM usuarios u
            JOIN roles r ON u.id_rol = r.id_rol
            LEFT JOIN almacenes a ON u.id_almacen = a.id_almacen
            WHERE r.nombre != 'cliente'
            ORDER BY u.nombre";
    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $usuarios = $stmt->fetchAll();
} catch (PDOException $e) {
    $usuarios = [];
}

// Catalogo de permisos + mapa de permisos por usuario para el modal "Permisos".
try {
    $permisosCat = $pdo->query(
        "SELECT id_permiso, clave, nombre, COALESCE(NULLIF(categoria,''),'Otros') AS categoria
         FROM permisos WHERE estado = 'activo' ORDER BY clave"
    )->fetchAll(PDO::FETCH_ASSOC);

    $ordenCat = ['Ventas', 'Inventario', 'Entregas', 'Catalogo', 'Metricas', 'Administracion', 'Otros'];
    $permisosPorCat = [];
    foreach ($permisosCat as $p) {
        $permisosPorCat[$p['categoria']][] = $p;
    }
    uksort($permisosPorCat, static function ($a, $b) use ($ordenCat) {
        $ia = array_search($a, $ordenCat, true);
        $ib = array_search($b, $ordenCat, true);
        return ($ia === false ? 999 : $ia) <=> ($ib === false ? 999 : $ib) ?: strcmp($a, $b);
    });

    // rol -> [id_permiso]
    $rolPermMap = [];
    foreach ($pdo->query("SELECT id_rol, id_permiso FROM rol_permisos") as $row) {
        $rolPermMap[(int) $row['id_rol']][] = (int) $row['id_permiso'];
    }
    // id_usuario -> ['conceder'=>[ids], 'denegar'=>[ids], 'expira'=>[id_permiso=>'YYYY-MM-DD']]
    $userOverrideMap = [];
    foreach ($pdo->query(
        "SELECT id_usuario, id_permiso, efecto, expira_en FROM usuario_permisos
         WHERE expira_en IS NULL OR expira_en > NOW()"
    ) as $row) {
        $uidRow = (int) $row['id_usuario'];
        $pidRow = (int) $row['id_permiso'];
        $userOverrideMap[$uidRow][$row['efecto']][] = $pidRow;
        if ($row['efecto'] === 'conceder' && !empty($row['expira_en'])) {
            $userOverrideMap[$uidRow]['expira'][$pidRow] = substr((string) $row['expira_en'], 0, 10);
        }
    }
} catch (PDOException $e) {
    $permisosPorCat = [];
    $rolPermMap = [];
    $userOverrideMap = [];
}

$iconoCategoria = [
    'Ventas' => 'shopping_cart', 'Inventario' => 'inventory_2', 'Entregas' => 'local_shipping',
    'Catalogo' => 'storefront', 'Metricas' => 'insights',
    'Administracion' => 'admin_panel_settings', 'Otros' => 'label',
];

// Obtener roles y almacenes
try {
    $stmt = $pdo->prepare("SELECT * FROM roles WHERE estado = 'activo'");
    $stmt->execute();
    $roles = $stmt->fetchAll();
    
    $stmt = $pdo->prepare("SELECT * FROM almacenes WHERE estado = 'activo'");
    $stmt->execute();
    $almacenes = $stmt->fetchAll();
} catch (PDOException $e) {
    $roles = [];
    $almacenes = [];
}

include __DIR__ . '/includes/header.php';
?>
<style>
    .users-page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
        margin-top: 20px;
    }

    /* Tabla clasica: visible en escritorio/tablet, con scroll horizontal solo como respaldo */
    .users-table-wrap {
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
    }

    /* Tarjetas moviles: ocultas por defecto, se muestran solo en pantallas angostas */
    .users-cards {
        display: none;
    }

    @media (max-width: 992px) {
        .users-table-wrap {
            display: none;
        }

        .users-cards {
            display: block;
        }
    }

    .users-card {
        background: #fff;
        border: 1px solid #e0e0e0;
        border-radius: 8px;
        padding: 14px 16px;
        margin-bottom: 12px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
    }

    .users-card-header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 10px;
        margin-bottom: 8px;
    }

    .users-card-name {
        font-size: 1.05rem;
        font-weight: 600;
        color: #212121;
        word-break: break-word;
    }

    .users-card-field {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 12px;
        padding: 7px 0;
        border-top: 1px solid #f0f0f0;
        font-size: 0.9rem;
    }

    .users-card-field-label {
        color: #78909c;
        font-weight: 500;
        flex: 0 0 auto;
    }

    .users-card-field-value {
        text-align: right;
        color: #37474f;
        word-break: break-word;
        overflow-wrap: anywhere;
    }

    .users-card-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-top: 12px;
        padding-top: 12px;
        border-top: 1px solid #f0f0f0;
    }

    .users-card-actions form {
        display: flex;
        flex: 1 1 auto;
    }

    .users-card-actions .btn-small {
        width: 100%;
        min-width: 44px;
        min-height: 36px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
    }
</style>

<div class="container">
    <div class="row">
        <div class="col s12">
            <div class="users-page-header">
                <h4 style="margin: 0;">Gestionar Usuarios</h4>
                <a href="dashboard.php" class="btn blue darken-4 waves-effect waves-light"><i class="material-icons left">dashboard</i> Volver al Dashboard</a>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="row">
            <div class="col s12">
                <div class="card red lighten-2">
                    <div class="card-content white-text">
                        <p><?php echo esc($error); ?></p>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="row">
            <div class="col s12">
                <div class="card green lighten-2">
                    <div class="card-content white-text">
                        <p><?php echo esc($success); ?></p>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col s12 m6">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Crear Nuevo Usuario</span>
                    <form method="POST">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="accion" value="agregar">
                        
                        <div class="input-field">
                            <input type="text" id="nombre" name="nombre" required>
                            <label for="nombre">Nombre Completo</label>
                        </div>
                        
                        <div class="input-field">
                            <input type="email" id="email" name="email" required>
                            <label for="email">Email</label>
                        </div>
                        
                        <p style="margin:6px 0 0;">
                            <label>
                                <input type="checkbox" id="invitar_password" name="invitar_password" value="1" class="filled-in">
                                <span>Enviar invitación para que fije su propia contraseña</span>
                            </label>
                        </p>

                        <div class="input-field" id="password_field">
                            <input type="password" id="password" name="password" required>
                            <label for="password">Contraseña</label>
                            <span class="helper-text" id="password_help"></span>
                        </div>

                        <div class="input-field">
                            <select name="id_rol" required>
                                <option value="">-- Selecciona rol --</option>
                                <?php foreach ($roles as $rol): ?>
                                    <?php if (!isSuperAdmin() && $rol['nombre'] === 'admin') continue; ?>
                                    <option value="<?php echo $rol['id_rol']; ?>"><?php echo esc($rol['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label>Rol</label>
                        </div>
                        
                        <div class="input-field">
                            <select name="id_almacen">
                                <option value="">-- Sin almacén asignado --</option>
                                <?php foreach ($almacenes as $almacen): ?>
                                    <option value="<?php echo $almacen['id_almacen']; ?>"><?php echo esc($almacen['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <label>Almacén</label>
                        </div>
                        
                        <button type="submit" class="btn waves-effect waves-light blue">
                            Crear Usuario <i class="material-icons right">person_add</i>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col s12 m6">
            <div class="card">
                <div class="card-content">
                    <span class="card-title">Usuarios del Sistema</span>

                    <div class="users-table-wrap">
                        <table class="striped">
                            <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th>Email</th>
                                    <th>Rol</th>
                                    <th>Almacén</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($usuarios as $user): ?>
                                    <tr>
                                        <td><?php echo esc($user['nombre']); ?></td>
                                        <td><?php echo esc($user['email']); ?></td>
                                        <td><?php echo esc($user['rol']); ?><br><?php echo upPersonalizacionChip($user); ?></td>
                                        <td><?php echo esc($user['almacen'] ?? 'N/A'); ?></td>
                                        <td>
                                            <?php if (!isAdminAccount($user) || isSuperAdmin()): ?>
                                            <button type="button" class="btn-small indigo waves-effect up-perm-btn"
                                                    data-uid="<?php echo (int) $user['id_usuario']; ?>"
                                                    data-uname="<?php echo esc($user['nombre']); ?>"
                                                    data-urol="<?php echo esc($user['rol']); ?>"
                                                    data-uidrol="<?php echo (int) $user['id_rol']; ?>"
                                                    data-ualmacen="<?php echo esc($user['almacen'] ?? '—'); ?>"
                                                    title="Editar permisos individuales">
                                                <i class="material-icons">tune</i>
                                            </button>
                                            <?php endif; ?>

                                            <form method="POST" style="display:inline;">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="accion" value="cambiar_estado">
                                                <input type="hidden" name="id_usuario" value="<?php echo $user['id_usuario']; ?>">
                                                <input type="hidden" name="estado" value="<?php echo $user['estado']; ?>">
                                                <button type="submit" class="btn-small <?php echo $user['estado'] === 'activo' ? 'orange' : 'green'; ?>" title="<?php echo $user['estado'] === 'activo' ? 'Desactivar' : 'Activar'; ?>">
                                                    <i class="material-icons"><?php echo $user['estado'] === 'activo' ? 'block' : 'check'; ?></i>
                                                </button>
                                            </form>

                                            <?php if ((int)$user['intentos_fallidos'] >= 5 || ($user['bloqueado_hasta'] && strtotime($user['bloqueado_hasta']) > time())): ?>
                                            <form method="POST" style="display:inline;">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="accion" value="desbloquear">
                                                <input type="hidden" name="id_usuario" value="<?php echo $user['id_usuario']; ?>">
                                                <button type="submit" class="btn-small blue waves-effect waves-light" title="Desbloquear intentos">
                                                    <i class="material-icons">lock_open</i>
                                                </button>
                                            </form>
                                            <?php endif; ?>

                                            <?php if (canDeleteUserAccount($user, (int)($_SESSION['usuario']['id_usuario'] ?? 0))): ?>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar este usuario del sistema? Esta acción no se puede deshacer.');">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="accion" value="eliminar_usuario">
                                                <input type="hidden" name="id_usuario" value="<?php echo $user['id_usuario']; ?>">
                                                <button type="submit" class="btn-small red darken-2 waves-effect waves-light" title="Eliminar usuario">
                                                    <i class="material-icons">person_remove</i>
                                                </button>
                                            </form>
                                            <?php endif; ?>

                                            <form method="POST" style="display:inline;" onsubmit="return confirm('¿Resetear contraseña de este usuario staff?');">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="accion" value="reset_password_staff">
                                                <input type="hidden" name="id_usuario" value="<?php echo $user['id_usuario']; ?>">
                                                <input type="hidden" name="email_usuario" value="<?php echo esc($user['email']); ?>">
                                                <button type="submit" class="btn-small red darken-2 waves-effect waves-light" title="Resetear contraseña">
                                                    <i class="material-icons">vpn_key</i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="users-cards">
                        <?php foreach ($usuarios as $user): ?>
                            <div class="users-card">
                                <div class="users-card-header">
                                    <span class="users-card-name"><?php echo esc($user['nombre']); ?></span>
                                    <span class="badge <?php echo $user['estado'] === 'activo' ? 'green' : 'red'; ?> white-text" style="float:none; flex-shrink:0;">
                                        <?php echo strtoupper($user['estado']); ?>
                                    </span>
                                </div>
                                <div class="users-card-field">
                                    <span class="users-card-field-label">Email</span>
                                    <span class="users-card-field-value"><?php echo esc($user['email']); ?></span>
                                </div>
                                <div class="users-card-field">
                                    <span class="users-card-field-label">Rol</span>
                                    <span class="users-card-field-value"><?php echo esc($user['rol']); ?> <?php echo upPersonalizacionChip($user); ?></span>
                                </div>
                                <div class="users-card-field">
                                    <span class="users-card-field-label">Almacén</span>
                                    <span class="users-card-field-value"><?php echo esc($user['almacen'] ?? 'N/A'); ?></span>
                                </div>

                                <div class="users-card-actions">
                                    <?php if (!isAdminAccount($user) || isSuperAdmin()): ?>
                                    <button type="button" class="btn-small indigo waves-effect up-perm-btn"
                                            data-uid="<?php echo (int) $user['id_usuario']; ?>"
                                            data-uname="<?php echo esc($user['nombre']); ?>"
                                            data-urol="<?php echo esc($user['rol']); ?>"
                                            data-uidrol="<?php echo (int) $user['id_rol']; ?>"
                                            data-ualmacen="<?php echo esc($user['almacen'] ?? '—'); ?>"
                                            title="Editar permisos individuales">
                                        <i class="material-icons">tune</i>
                                    </button>
                                    <?php endif; ?>

                                    <form method="POST">
                                        <?php echo csrfInput(); ?>
                                        <input type="hidden" name="accion" value="cambiar_estado">
                                        <input type="hidden" name="id_usuario" value="<?php echo $user['id_usuario']; ?>">
                                        <input type="hidden" name="estado" value="<?php echo $user['estado']; ?>">
                                        <button type="submit" class="btn-small <?php echo $user['estado'] === 'activo' ? 'orange' : 'green'; ?>" title="<?php echo $user['estado'] === 'activo' ? 'Desactivar' : 'Activar'; ?>">
                                            <i class="material-icons"><?php echo $user['estado'] === 'activo' ? 'block' : 'check'; ?></i>
                                        </button>
                                    </form>

                                    <?php if ((int)$user['intentos_fallidos'] >= 5 || ($user['bloqueado_hasta'] && strtotime($user['bloqueado_hasta']) > time())): ?>
                                    <form method="POST">
                                        <?php echo csrfInput(); ?>
                                        <input type="hidden" name="accion" value="desbloquear">
                                        <input type="hidden" name="id_usuario" value="<?php echo $user['id_usuario']; ?>">
                                        <button type="submit" class="btn-small blue waves-effect waves-light" title="Desbloquear intentos">
                                            <i class="material-icons">lock_open</i>
                                        </button>
                                    </form>
                                    <?php endif; ?>

                                    <?php if (canDeleteUserAccount($user, (int)($_SESSION['usuario']['id_usuario'] ?? 0))): ?>
                                    <form method="POST" onsubmit="return confirm('¿Eliminar este usuario del sistema? Esta acción no se puede deshacer.');">
                                        <?php echo csrfInput(); ?>
                                        <input type="hidden" name="accion" value="eliminar_usuario">
                                        <input type="hidden" name="id_usuario" value="<?php echo $user['id_usuario']; ?>">
                                        <button type="submit" class="btn-small red darken-2 waves-effect waves-light" title="Eliminar usuario">
                                            <i class="material-icons">person_remove</i>
                                        </button>
                                    </form>
                                    <?php endif; ?>

                                    <form method="POST" onsubmit="return confirm('¿Resetear contraseña de este usuario staff?');">
                                        <?php echo csrfInput(); ?>
                                        <input type="hidden" name="accion" value="reset_password_staff">
                                        <input type="hidden" name="id_usuario" value="<?php echo $user['id_usuario']; ?>">
                                        <input type="hidden" name="email_usuario" value="<?php echo esc($user['email']); ?>">
                                        <button type="submit" class="btn-small red darken-2 waves-effect waves-light" title="Resetear contraseña">
                                            <i class="material-icons">vpn_key</i>
                                        </button>
                                    </form>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ===== MODAL: Permisos individuales del usuario (Fase 3) ===== -->
<div id="modalPermUser" class="modal modal-fixed-footer">
    <form method="POST" id="upPermForm">
        <?php echo csrfInput(); ?>
        <input type="hidden" name="accion" value="guardar_permisos_usuario">
        <input type="hidden" name="id_usuario" id="upUserId" value="">
        <div class="modal-content">
            <h5 style="font-weight:700;margin-top:0;">Permisos de <span id="upUserName"></span></h5>
            <p class="grey-text" id="upUserMeta" style="margin-top:-4px;"></p>
            <p class="grey-text" style="font-size:13px;">
                Activa lo que quieres que tenga <b>esta persona</b>. Lo que viene de su rol ya está activo;
                puedes quitar o añadir permisos individuales sin afectar a los demás usuarios con ese rol.
            </p>
            <div id="upPermBody"></div>
            <div class="input-field" style="margin-top:10px;">
                <input type="text" name="nota" id="upNota" maxlength="255" required>
                <label for="upNota">Motivo del cambio (obligatorio, queda en la auditoría)</label>
            </div>
            <p class="grey-text" style="font-size:12px;">
                <span class="chip grey lighten-3" style="font-size:10px;height:20px;line-height:20px;">por rol</span> viene del rol ·
                <span class="chip green lighten-4 green-text text-darken-2" style="font-size:10px;height:20px;line-height:20px;">añadido</span> concesión individual ·
                <span class="chip orange lighten-4 orange-text text-darken-4" style="font-size:10px;height:20px;line-height:20px;">quitado</span> revocado solo para esta persona
            </p>
            <p class="grey-text" style="font-size:12px;">
                En los permisos <b>añadidos</b> puedes fijar una fecha de «acceso hasta»: al pasar esa fecha el permiso
                se retira solo. Déjalo vacío para que no caduque.
            </p>
        </div>
        <div class="modal-footer">
            <a href="#!" class="modal-close btn-flat">Cancelar</a>
            <button type="submit" class="btn green darken-1 waves-effect"><i class="material-icons left">save</i> Guardar permisos</button>
        </div>
    </form>
</div>

<script>
    window.UP_PERMS_CAT = <?php echo json_encode($permisosPorCat, JSON_UNESCAPED_UNICODE); ?>;
    window.UP_ROLE_PERMS = <?php echo json_encode($rolPermMap); ?>;
    window.UP_USER_OVERRIDES = <?php echo json_encode($userOverrideMap); ?>;
    window.UP_LIVE = <?php echo json_encode(array_values(PERMISOS_EN_USO)); ?>;
    window.UP_CAT_ICON = <?php echo json_encode($iconoCategoria); ?>;

    document.addEventListener('DOMContentLoaded', function () {
        M.FormSelect.init(document.querySelectorAll('select'));
        M.updateTextFields();

        // Mejora 8: al marcar "enviar invitación", la contraseña la fija la persona,
        // así que se oculta y se deja de exigir en el formulario de alta.
        var invitarCb = document.getElementById('invitar_password');
        var passField = document.getElementById('password_field');
        var passInput = document.getElementById('password');
        var passHelp = document.getElementById('password_help');
        if (invitarCb && passField && passInput) {
            var syncInvitar = function () {
                if (invitarCb.checked) {
                    passField.style.display = 'none';
                    passInput.required = false;
                    passInput.value = '';
                    if (passHelp) passHelp.textContent = '';
                } else {
                    passField.style.display = '';
                    passInput.required = true;
                }
            };
            invitarCb.addEventListener('change', syncInvitar);
            syncInvitar();
        }

        var modalEl = document.getElementById('modalPermUser');
        var modal = modalEl ? M.Modal.init(modalEl) : null;

        function chip(kind) {
            if (kind === 'rol') return '<span class="chip grey lighten-3" style="font-size:10px;height:20px;line-height:20px;">por rol</span>';
            if (kind === 'add') return '<span class="chip green lighten-4 green-text text-darken-2" style="font-size:10px;height:20px;line-height:20px;">añadido</span>';
            if (kind === 'del') return '<span class="chip orange lighten-4 orange-text text-darken-4" style="font-size:10px;height:20px;line-height:20px;">quitado</span>';
            return '';
        }

        var hoyISO = new Date().toISOString().slice(0, 10);

        function buildBody(idRol, uid) {
            var rolIds = (window.UP_ROLE_PERMS[idRol] || []).map(Number);
            var ov = window.UP_USER_OVERRIDES[uid] || {};
            var conceder = (ov.conceder || []).map(Number);
            var denegar = (ov.denegar || []).map(Number);
            var expira = ov.expira || {};
            var html = '';
            Object.keys(window.UP_PERMS_CAT).forEach(function (cat) {
                var icon = window.UP_CAT_ICON[cat] || 'label';
                html += '<div style="margin:14px 0 4px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#1a237e;font-size:12px;border-bottom:1px dashed #c5cae9;padding-bottom:4px;">'
                     + '<i class="material-icons tiny" style="vertical-align:-3px;">' + icon + '</i> ' + cat + '</div>';
                window.UP_PERMS_CAT[cat].forEach(function (p) {
                    var pid = Number(p.id_permiso);
                    var enRol = rolIds.indexOf(pid) !== -1;
                    var checked = enRol;
                    if (conceder.indexOf(pid) !== -1) checked = true;
                    if (denegar.indexOf(pid) !== -1) checked = false;
                    var sinEfecto = window.UP_LIVE.indexOf(p.clave) === -1;
                    var expVal = expira[pid] || '';
                    html += '<div class="up-row" data-inrole="' + (enRol ? 1 : 0) + '" style="display:flex;align-items:center;gap:12px;padding:9px 2px;border-bottom:1px solid #f2f3f9;flex-wrap:wrap;">'
                         + '<div class="switch"><label><input type="checkbox" name="permisos[]" value="' + pid + '" ' + (checked ? 'checked' : '') + '><span class="lever"></span></label></div>'
                         + '<div style="flex:1;min-width:150px;"><span style="font-weight:700;font-family:monospace;font-size:13px;">' + p.clave + '</span>'
                         + '<span style="display:block;color:#90a4ae;font-size:11px;">' + (p.nombre || '') + (sinEfecto ? ' · <i>sin efecto aún</i>' : '') + '</span></div>'
                         + '<span class="up-tag"></span>'
                         + '<label class="up-exp" style="display:none;font-size:11px;color:#78909c;white-space:nowrap;">acceso hasta '
                         + '<input type="date" name="expira[' + pid + ']" min="' + hoyISO + '" value="' + expVal + '" style="height:1.6rem;font-size:12px;width:auto;margin:0 0 0 4px;padding:0 4px;border:1px solid #cfd8dc;border-radius:3px;"></label>'
                         + '</div>';
                });
            });
            return html;
        }

        function refreshTags() {
            document.querySelectorAll('#upPermBody .up-row').forEach(function (row) {
                var enRol = row.dataset.inrole === '1';
                var cb = row.querySelector('input[type=checkbox]');
                var tag = row.querySelector('.up-tag');
                var exp = row.querySelector('.up-exp');
                var esAnadido = cb.checked && !enRol;
                if (cb.checked && enRol) tag.innerHTML = chip('rol');
                else if (esAnadido) tag.innerHTML = chip('add');
                else if (!cb.checked && enRol) tag.innerHTML = chip('del');
                else tag.innerHTML = '';
                if (exp) {
                    exp.style.display = esAnadido ? 'inline-block' : 'none';
                    if (!esAnadido) exp.querySelector('input').value = '';
                }
            });
        }

        document.querySelectorAll('.up-perm-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var uid = btn.dataset.uid;
                document.getElementById('upUserId').value = uid;
                document.getElementById('upUserName').textContent = btn.dataset.uname;
                document.getElementById('upUserMeta').textContent = 'Rol: ' + btn.dataset.urol + ' · Sucursal: ' + btn.dataset.ualmacen;
                document.getElementById('upNota').value = '';
                document.getElementById('upPermBody').innerHTML = buildBody(btn.dataset.uidrol, uid);
                document.querySelectorAll('#upPermBody input[type=checkbox]').forEach(function (cb) {
                    cb.addEventListener('change', refreshTags);
                });
                refreshTags();
                if (modal) modal.open();
            });
        });
    });
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
