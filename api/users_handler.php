<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json');

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

if (!isAuthenticated() || !isAdmin()) {
    echo json_encode(['success' => false, 'message' => 'No autorizado']);
    exit;
}

$pdo = getPDO();
$data = $_POST;
$accion = $data['accion'] ?? '';

if (!validateCsrfToken($data['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => 'Token CSRF inválido']);
    exit;
}

try {
    if ($accion === 'agregar') {
        $email = htmlspecialchars(trim($data['email'] ?? ''));
        $nombre = htmlspecialchars(trim($data['nombre'] ?? ''));
        $passwordRaw = $data['password'] ?? '';
        $id_rol = (int)($data['id_rol'] ?? 0);
        $id_almacen = (int)($data['id_almacen'] ?? 0) ?: null;

        $stmtRol = $pdo->prepare("SELECT nombre FROM roles WHERE id_rol = ?");
        $stmtRol->execute([$id_rol]);
        $rolNombre = (string)$stmtRol->fetchColumn();

        if ($rolNombre === 'admin' && !isSuperAdmin()) {
            throw new Exception('Solo un super admin puede crear otros administradores.');
        }

        if (!isPasswordSecure($passwordRaw)) {
            throw new Exception("La contraseña es insegura");
        }

        $passwordHash = password_hash($passwordRaw, PASSWORD_BCRYPT);

        $stmtExistingUser = $pdo->prepare("SELECT u.id_usuario, u.estado, COALESCE(u.es_superadmin, 0) AS es_superadmin, r.nombre AS rol
                                           FROM usuarios u
                                           JOIN roles r ON u.id_rol = r.id_rol
                                           WHERE LOWER(u.email) = LOWER(?)
                                           LIMIT 1");
        $stmtExistingUser->execute([$email]);
        $existingUser = $stmtExistingUser->fetch(PDO::FETCH_ASSOC);

        if ($existingUser && !shouldReactivateExistingUser($existingUser)) {
            throw new Exception('El correo ya está asociado a otra cuenta activa.');
        }

        if ($existingUser && shouldReactivateExistingUser($existingUser)) {
            $targetUserId = (int)$existingUser['id_usuario'];
            $pdo->prepare("UPDATE usuarios SET nombre = ?, email = ?, contrasena = ?, id_rol = ?, id_almacen = ?, estado = 'activo', intentos_fallidos = 0, bloqueado_hasta = NULL, es_superadmin = 0 WHERE id_usuario = ?")
                ->execute([$nombre, $email, $passwordHash, $id_rol, $id_almacen, $targetUserId]);
            logAudit('USUARIO_REACTIVADO', 'usuarios', $targetUserId, "Email: $email");
            echo json_encode(['success' => true, 'message' => 'Cuenta existente reactivada y actualizada']);
        } else {
            $sql = "INSERT INTO usuarios (nombre, email, contrasena, id_rol, id_almacen, estado) 
                    VALUES (?, ?, ?, ?, ?, 'activo')";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$nombre, $email, $passwordHash, $id_rol, $id_almacen]);
            
            logAudit('USUARIO_CREADO', 'usuarios', (int)$pdo->lastInsertId(), "Email: $email");
            echo json_encode(['success' => true, 'message' => 'Usuario creado']);
        }
    } 
    elseif ($accion === 'cambiar_estado') {
        $id = (int)$data['id_usuario'];
        $estado_actual = $data['estado'] ?? 'activo';
        $nuevo_estado = $estado_actual === 'activo' ? 'inactivo' : 'activo';

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
        echo json_encode(['success' => true, 'message' => 'Estado actualizado']);
    }
    elseif ($accion === 'desbloquear') {
        $id = (int)$data['id_usuario'];

        $targetUser = getStaffUserById($pdo, $id);
        if (!$targetUser) {
            throw new Exception('Usuario no encontrado.');
        }

        if (isAdminAccount($targetUser) && !isSuperAdmin()) {
            throw new Exception('Solo un super admin puede desbloquear cuentas de administrador.');
        }

        $pdo->prepare("UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id_usuario = ?")
            ->execute([$id]);
            
        logAudit('USUARIO_DESBLOQUEADO', 'usuarios', $id, "Desbloqueo manual");
        echo json_encode(['success' => true, 'message' => 'Usuario desbloqueado']);
    }
    else {
        throw new Exception("Acción no reconocida");
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}