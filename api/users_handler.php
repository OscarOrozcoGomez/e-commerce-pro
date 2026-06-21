<?php
declare(strict_types=1);
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

header('Content-Type: application/json');

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

        if (!isPasswordSecure($passwordRaw)) {
            throw new Exception("La contraseña es insegura");
        }

        $passwordHash = password_hash($passwordRaw, PASSWORD_BCRYPT);

        $sql = "INSERT INTO usuarios (nombre, email, contrasena, id_rol, id_almacen, estado) 
                VALUES (?, ?, ?, ?, ?, 'activo')";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$nombre, $email, $passwordHash, $id_rol, $id_almacen]);
        
        logAudit('USUARIO_CREADO', 'usuarios', (int)$pdo->lastInsertId(), "Email: $email");
        echo json_encode(['success' => true, 'message' => 'Usuario creado']);
    } 
    elseif ($accion === 'cambiar_estado') {
        $id = (int)$data['id_usuario'];
        $estado_actual = $data['estado'] ?? 'activo';
        $nuevo_estado = $estado_actual === 'activo' ? 'inactivo' : 'activo';

        $stmt = $pdo->prepare("UPDATE usuarios SET estado = ? WHERE id_usuario = ?");
        $stmt->execute([$nuevo_estado, $id]);
        
        logAudit('USUARIO_ESTADO_CAMBIADO', 'usuarios', $id, "Nuevo estado: $nuevo_estado");
        echo json_encode(['success' => true, 'message' => 'Estado actualizado']);
    }
    elseif ($accion === 'desbloquear') {
        $id = (int)$data['id_usuario'];
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