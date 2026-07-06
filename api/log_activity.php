<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

ignore_user_abort(true);

function respondNoContent(): void
{
    if (!headers_sent()) {
        http_response_code(204);
        header('Content-Type: text/plain; charset=UTF-8');
        header('Content-Length: 0');
        header('Connection: close');
    }
}

$idUsuario = null;
if (isAuthenticated()) {
    $idUsuario = (int)($_SESSION['usuario']['id_usuario'] ?? 0);
    if ($idUsuario <= 0) {
        $idUsuario = null;
    }
}

if (session_status() === PHP_SESSION_ACTIVE) {
    // Libera el lock de sesion para no bloquear requests paralelos del mismo usuario.
    session_write_close();
}

// Obtener datos del cuerpo de la solicitud (JSON)
$data = json_decode(file_get_contents('php://input'), true);

if (!$data || !isset($data['tipo'])) {
    respondNoContent();
    exit;
}

$tipo = (string)$data['tipo'];
if (!in_array($tipo, ['visit', 'click'], true)) {
    respondNoContent();
    exit;
}

$payload = [
    ':id_usuario' => $idUsuario,
    ':tipo' => $tipo,
    ':url' => (string)($data['url'] ?? $_SERVER['HTTP_REFERER'] ?? ''),
    ':elemento_id' => $data['id'] ?? null,
    ':elemento_texto' => mb_substr((string)($data['texto'] ?? ''), 0, 255),
    ':ip' => (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
    ':ua' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
];

$storeLog = static function () use ($payload): void {
    try {
        $pdo = getPDO();
        // Evita que el logger espere demasiado en caso de bloqueo concurrente.
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 2');

        $sql = "INSERT INTO logs_actividad
                (id_usuario, tipo_accion, url, elemento_id, elemento_texto, ip_address, user_agent)
                VALUES (:id_usuario, :tipo, :url, :elemento_id, :elemento_texto, :ip, :ua)";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($payload);
    } catch (Throwable $e) {
        // Silenciar errores de log para no interrumpir al usuario.
        error_log('Error registrando actividad: ' . $e->getMessage());
    }
};

if (function_exists('fastcgi_finish_request')) {
    respondNoContent();
    if (ob_get_level() > 0) {
        @ob_end_flush();
    }
    @flush();
    fastcgi_finish_request();
    $storeLog();
    exit;
}

$storeLog();
respondNoContent();
?>
