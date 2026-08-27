<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
require_once __DIR__ . '/../core/geo_lookup.php';
require_once __DIR__ . '/../core/site_behavior.php';

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
if (!in_array($tipo, ['visit', 'click', 'duration'], true)) {
    respondNoContent();
    exit;
}

// 'duration' no es una visita nueva: es el navegador reportando, al salir de la
// pagina, cuanto tiempo estuvo visible una visita 'visit' ya insertada -- se resuelve
// con un UPDATE angostado por pageview_id, sin tocar el resto del pipeline (atribucion,
// geo, etc. ya se guardaron en el evento 'visit' original).
if ($tipo === 'duration') {
    $pageviewId = sanitizePageviewId($data['pageview_id'] ?? null);
    $duracion = clampDurationSeconds($data['segundos'] ?? null);

    if ($pageviewId !== null && $duracion !== null) {
        $storeDuration = static function () use ($pageviewId, $duracion): void {
            try {
                $pdo = getPDO();
                $pdo->exec('SET SESSION innodb_lock_wait_timeout = 2');
                $stmt = $pdo->prepare(
                    "UPDATE logs_actividad SET duracion_segundos = :duracion
                     WHERE pageview_id = :pageview_id AND tipo_accion = 'visit'
                     LIMIT 1"
                );
                $stmt->execute([':duracion' => $duracion, ':pageview_id' => $pageviewId]);
            } catch (Throwable $e) {
                error_log('Error registrando duracion de visita: ' . $e->getMessage());
            }
        };

        if (function_exists('fastcgi_finish_request')) {
            respondNoContent();
            if (ob_get_level() > 0) {
                @ob_end_flush();
            }
            @flush();
            fastcgi_finish_request();
            $storeDuration();
            exit;
        }

        $storeDuration();
    }

    respondNoContent();
    exit;
}

$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');

$payload = [
    ':id_usuario' => $idUsuario,
    ':tipo' => $tipo,
    ':url' => (string)($data['url'] ?? $_SERVER['HTTP_REFERER'] ?? ''),
    ':elemento_id' => $data['id'] ?? null,
    ':elemento_texto' => mb_substr((string)($data['texto'] ?? ''), 0, 255),
    ':ip' => $ip,
    ':ua' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
    ':utm_source' => null,
    ':utm_medium' => null,
    ':utm_campaign' => null,
    ':utm_term' => null,
    ':utm_content' => null,
    ':gclid' => null,
    ':wbraid' => null,
    ':gbraid' => null,
    ':referrer' => null,
    ':landing_page' => null,
    ':plataforma' => null,
    ':visitor_id' => getVisitorId(),
    ':pais' => null,
    ':region' => null,
    ':pageview_id' => null,
    ':id_producto' => sanitizeExplicitProductId($data['id_producto'] ?? null),
];

// La atribucion de campana (UTM/gclid/referrer) y la geolocalizacion solo aplican
// a visitas -- un click hereda la atribucion de su visita via visitor_id, no necesita
// repetirla ni pagar el costo del lookup de geo en cada click.
if ($tipo === 'visit') {
    $attributionFields = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'wbraid', 'gbraid', 'referrer', 'landing_page'];
    foreach ($attributionFields as $field) {
        $value = trim((string) ($data[$field] ?? ''));
        $payload[':' . $field] = $value !== '' ? $value : null;
    }

    $payload[':plataforma'] = classifyPlatform($data);

    $geo = lookupGeo($ip);
    $payload[':pais'] = $geo['pais'];
    $payload[':region'] = $geo['region'];

    $payload[':pageview_id'] = sanitizePageviewId($data['pageview_id'] ?? null);
    // La URL (product_detail.php?id=X) es la fuente por defecto de id_producto en una
    // visita; un id_producto explicito en el payload (no aplica a 'visit' hoy, pero
    // deja la puerta abierta) tendria prioridad si algun dia se manda.
    if ($payload[':id_producto'] === null) {
        $payload[':id_producto'] = extractProductIdFromUrl($payload[':url']);
    }
}

$storeLog = static function () use ($payload): void {
    try {
        $pdo = getPDO();
        // Evita que el logger espere demasiado en caso de bloqueo concurrente.
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 2');

        $sql = "INSERT INTO logs_actividad
                (id_usuario, tipo_accion, url, elemento_id, elemento_texto, ip_address, user_agent,
                 utm_source, utm_medium, utm_campaign, utm_term, utm_content, gclid, wbraid, gbraid,
                 referrer, landing_page, plataforma, visitor_id, pais, region, pageview_id, id_producto)
                VALUES (:id_usuario, :tipo, :url, :elemento_id, :elemento_texto, :ip, :ua,
                        :utm_source, :utm_medium, :utm_campaign, :utm_term, :utm_content, :gclid, :wbraid, :gbraid,
                        :referrer, :landing_page, :plataforma, :visitor_id, :pais, :region, :pageview_id, :id_producto)";

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
