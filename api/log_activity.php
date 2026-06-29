<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

$pdo = getPDO();
$idUsuario = null;
if (isAuthenticated()) {
    $idUsuario = (int)($_SESSION['usuario']['id_usuario'] ?? 0);
    if ($idUsuario <= 0) {
        $idUsuario = null;
    }
}

// Obtener datos del cuerpo de la solicitud (JSON)
$data = json_decode(file_get_contents('php://input'), true);

if ($data && isset($data['tipo'])) {
    try {
        $tipo = (string)$data['tipo'];
        if (!in_array($tipo, ['visit', 'click'], true)) {
            exit;
        }

        $sql = "INSERT INTO logs_actividad 
                (id_usuario, tipo_accion, url, elemento_id, elemento_texto, ip_address, user_agent) 
                VALUES (:id_usuario, :tipo, :url, :elemento_id, :elemento_texto, :ip, :ua)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':id_usuario' => $idUsuario,
            ':tipo' => $tipo, // 'click' o 'visit'
            ':url' => $data['url'] ?? $_SERVER['HTTP_REFERER'] ?? '',
            ':elemento_id' => $data['id'] ?? null,
            ':elemento_texto' => mb_substr($data['texto'] ?? '', 0, 255),
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            ':ua' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    } catch (Exception $e) {
        // Silenciar errores de log para no interrumpir al usuario
        error_log("Error registrando actividad: " . $e->getMessage());
    }
}
?>
