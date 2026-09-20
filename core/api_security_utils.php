<?php
declare(strict_types=1);

/**
 * Seguridad comun de los endpoints de api/: CSRF en escrituras con sesion, metodo HTTP
 * obligatorio, deteccion segura del "cron local" y limite de peticiones por IP en los
 * endpoints publicos.
 *
 * Modelo de autenticacion del proyecto: la app usa la cookie de sesion de PHP (HttpOnly,
 * SameSite=Lax, Secure bajo HTTPS), no tokens por peticion. Por eso toda escritura hecha con
 * sesion debe llevar ademas el token CSRF (getCsrfToken()), que un sitio ajeno no puede leer.
 * Los endpoints servidor-a-servidor (puente de WhatsApp, migraciones) usan un token compartido
 * en cabecera comparado con hash_equals() y no pasan por aqui.
 *
 * El token CSRF puede viajar en la cabecera `X-CSRF-Token`, en el campo `csrf_token` de un
 * formulario/FormData, o en el campo `csrf_token` del cuerpo JSON.
 */

/** ¿El metodo HTTP modifica estado? (todo lo que no sea GET/HEAD/OPTIONS). */
function apiMetodoEsEscritura(string $metodo): bool
{
    return !in_array(strtoupper(trim($metodo)), ['GET', 'HEAD', 'OPTIONS'], true);
}

/** ¿El metodo esta entre los permitidos? (sin distinguir mayusculas). */
function apiMetodoPermitido(string $metodo, array $permitidos): bool
{
    $metodo = strtoupper(trim($metodo));
    foreach ($permitidos as $p) {
        if ($metodo === strtoupper((string) $p)) {
            return true;
        }
    }

    return false;
}

/**
 * Token CSRF que mando el cliente: cabecera X-CSRF-Token, luego campo csrf_token del POST,
 * luego campo csrf_token del JSON. '' si no mando ninguno. Nunca lee $_GET: un token en la URL
 * queda en logs y en el historial.
 *
 * @param array<string, mixed>      $server
 * @param array<string, mixed>      $post
 * @param array<string, mixed>|null $json
 */
function apiTokenCsrfDePeticion(array $server, array $post, ?array $json): string
{
    $cabecera = trim((string) ($server['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($cabecera !== '') {
        return $cabecera;
    }

    $campo = $post['csrf_token'] ?? null;
    if (is_string($campo) && trim($campo) !== '') {
        return trim($campo);
    }

    $enJson = $json['csrf_token'] ?? null;

    return is_string($enJson) ? trim($enJson) : '';
}

/** Compara el token enviado con el de la sesion (tiempo constante). Vacio nunca es valido. */
function apiCsrfValido(string $enviado, ?string $tokenSesion): bool
{
    if ($enviado === '' || $tokenSesion === null || $tokenSesion === '') {
        return false;
    }

    return hash_equals($tokenSesion, $enviado);
}

/**
 * Corta la peticion con 403 JSON si no trae un token CSRF valido. Para llamar en endpoints
 * que modifican estado con sesion, DESPUES de comprobar la sesion/permisos.
 *
 * @param array<string, mixed>|null $json Cuerpo JSON ya decodificado (si el endpoint ya lo leyo);
 *                                        si es null se intenta leer php://input.
 */
function apiRequerirCsrf(?array $json = null): void
{
    if ($json === null && (($_SERVER['CONTENT_TYPE'] ?? '') !== '') && stripos((string) $_SERVER['CONTENT_TYPE'], 'json') !== false) {
        $decodificado = json_decode((string) file_get_contents('php://input'), true);
        $json = is_array($decodificado) ? $decodificado : null;
    }

    $enviado = apiTokenCsrfDePeticion($_SERVER, $_POST, $json);
    $deSesion = isset($_SESSION['csrf_token']) && is_string($_SESSION['csrf_token']) ? $_SESSION['csrf_token'] : null;

    if (apiCsrfValido($enviado, $deSesion)) {
        return;
    }

    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: application/json');
    }
    $mensaje = 'Token de seguridad invalido. Recarga la pagina e intenta de nuevo.';
    echo json_encode(['success' => false, 'error' => $mensaje, 'message' => $mensaje]);
    exit;
}

/** Corta con 405 JSON (y cabecera Allow) si el metodo de la peticion no es uno de los permitidos. */
function apiRequerirMetodo(string ...$permitidos): void
{
    $metodo = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (apiMetodoPermitido($metodo, $permitidos)) {
        return;
    }

    if (!headers_sent()) {
        http_response_code(405);
        header('Allow: ' . implode(', ', array_map('strtoupper', $permitidos)));
        header('Content-Type: application/json');
    }
    echo json_encode(['success' => false, 'error' => 'Metodo no permitido', 'message' => 'Metodo no permitido']);
    exit;
}

/**
 * Cabeceras que agregan los proxies (Cloudflare, nginx, Varnish, balanceadores): una peticion que
 * pasa por alguno trae al menos una; el curl directo del cron, ninguna.
 */
const API_CABECERAS_DE_PROXY = [
    'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_HOST', 'HTTP_FORWARDED', 'HTTP_VIA',
    'HTTP_CF_CONNECTING_IP', 'HTTP_CF_RAY', 'HTTP_CF_VISITOR', 'HTTP_TRUE_CLIENT_IP',
    'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'HTTP_X_CLIENT_IP', 'HTTP_X_VARNISH',
];

/**
 * ¿Es una llamada del cron local (curl a 127.0.0.1 sin sesion)? Solo si la conexion viene de
 * 127.0.0.1 Y no trae ninguna cabecera de proxy: una peticion que pasa por Cloudflare/nginx
 * SIEMPRE trae X-Forwarded-For / CF-Connecting-IP, y el cron (curl directo al backend) no.
 * Antes bastaba REMOTE_ADDR === '127.0.0.1', que depende de que el proxy no se pueda enganar.
 *
 * @param array<string, mixed> $server
 */
function apiEsCronLocal(array $server, bool $autenticado): bool
{
    if ($autenticado || ($server['REMOTE_ADDR'] ?? '') !== '127.0.0.1') {
        return false;
    }

    foreach (API_CABECERAS_DE_PROXY as $cabecera) {
        if (trim((string) ($server[$cabecera] ?? '')) !== '') {
            return false;
        }
    }

    return true;
}

// ---------------------------------------------------------------------------------------
// Limite de peticiones por IP (ventana deslizante, archivos con flock; sin BD ni extensiones)
// ---------------------------------------------------------------------------------------

/**
 * Directorio de los contadores: core/cache/ratelimit (ignorado por git y por el deploy) o, si no
 * se puede escribir, el directorio temporal del sistema.
 */
function rateLimitDirectorio(): string
{
    $preferido = __DIR__ . '/cache/ratelimit';
    if ((is_dir($preferido) || @mkdir($preferido, 0775, true)) && is_writable($preferido)) {
        return $preferido;
    }

    $respaldo = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'bb_ratelimit';
    if (!is_dir($respaldo)) {
        @mkdir($respaldo, 0775, true);
    }

    return $respaldo;
}

/**
 * Registra una peticion de $clave y dice si se permite: como maximo $maximo por ventana de
 * $ventanaSegundos. Si el almacenamiento falla, PERMITE (un fallo del limitador nunca debe
 * dejar sin servicio a un cliente).
 *
 * @return array{permitido:bool, restantes:int, reintentar_en:int}
 */
function rateLimitPermitir(string $clave, int $maximo, int $ventanaSegundos, ?string $directorio = null, ?int $ahora = null): array
{
    $ahora ??= time();
    $maximo = max(1, $maximo);
    $ventanaSegundos = max(1, $ventanaSegundos);
    $directorio ??= rateLimitDirectorio();

    $archivo = rtrim($directorio, '/\\') . DIRECTORY_SEPARATOR . hash('sha256', $clave) . '.json';
    $gestor = @fopen($archivo, 'c+');
    if ($gestor === false) {
        return ['permitido' => true, 'restantes' => $maximo, 'reintentar_en' => 0];
    }

    try {
        if (!flock($gestor, LOCK_EX)) {
            return ['permitido' => true, 'restantes' => $maximo, 'reintentar_en' => 0];
        }

        $contenido = stream_get_contents($gestor);
        $marcas = json_decode(is_string($contenido) && $contenido !== '' ? $contenido : '[]', true);
        $marcas = is_array($marcas) ? array_values(array_filter($marcas, static fn($t): bool => is_int($t) && $t > $ahora - $ventanaSegundos)) : [];

        if (count($marcas) >= $maximo) {
            $masAntigua = min($marcas);

            return ['permitido' => false, 'restantes' => 0, 'reintentar_en' => max(1, $masAntigua + $ventanaSegundos - $ahora)];
        }

        $marcas[] = $ahora;
        ftruncate($gestor, 0);
        rewind($gestor);
        fwrite($gestor, (string) json_encode($marcas));
        fflush($gestor);

        return ['permitido' => true, 'restantes' => $maximo - count($marcas), 'reintentar_en' => 0];
    } finally {
        flock($gestor, LOCK_UN);
        fclose($gestor);
    }
}

/** Borra contadores sin uso (mas viejos que $edadMaximaSegundos). Devuelve cuantos borro. */
function rateLimitLimpiar(?string $directorio = null, int $edadMaximaSegundos = 86400, ?int $ahora = null): int
{
    $ahora ??= time();
    $directorio ??= rateLimitDirectorio();
    $borrados = 0;

    foreach (glob(rtrim($directorio, '/\\') . DIRECTORY_SEPARATOR . '*.json') ?: [] as $archivo) {
        $modificado = @filemtime($archivo);
        if ($modificado !== false && $modificado < $ahora - $edadMaximaSegundos && @unlink($archivo)) {
            $borrados++;
        }
    }

    return $borrados;
}

/**
 * IP del cliente. En produccion nginx ya reemplaza REMOTE_ADDR por la IP real (real_ip de
 * Cloudflare), asi que NO se leen cabeceras X-Forwarded-For aqui: cualquiera podria falsificarlas.
 *
 * @param array<string, mixed> $server
 */
function apiIpCliente(array $server): string
{
    $ip = (string) ($server['REMOTE_ADDR'] ?? '');

    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
}

/**
 * Clave de limite para una IP: las IPv4 tal cual; las IPv6 agrupadas por /64 (un cliente recibe un
 * /64 completo y puede rotar de direccion a voluntad: por IP exacta esquivaria el limite y llenaria
 * el directorio de contadores con un archivo por direccion).
 */
function apiClaveLimiteIp(string $ip): string
{
    if (!str_contains($ip, ':')) {
        return $ip;
    }

    $binaria = @inet_pton($ip);
    if ($binaria === false || strlen($binaria) !== 16) {
        return $ip;
    }

    return bin2hex(substr($binaria, 0, 8)) . '::/64';
}

/**
 * Aplica el limite a la peticion actual: si la IP excede $maximo peticiones por $ventanaSegundos
 * en el endpoint $nombre, responde 429 con Retry-After y termina. Se puede apagar con la variable
 * de entorno API_RATE_LIMIT_DISABLED=1 (pruebas de carga o E2E).
 */
function apiLimitarPeticiones(string $nombre, int $maximo, int $ventanaSegundos = 60, ?string $directorio = null): void
{
    if (function_exists('getEnvVar') && in_array(strtolower((string) (getEnvVar('API_RATE_LIMIT_DISABLED', '0') ?? '0')), ['1', 'true', 'yes', 'on'], true)) {
        return;
    }

    try {
        $resultado = rateLimitPermitir($nombre . '|' . apiClaveLimiteIp(apiIpCliente($_SERVER)), $maximo, $ventanaSegundos, $directorio);
    } catch (Throwable $e) {
        error_log('WARNING: limitador de peticiones fallo, se permite la peticion: ' . $e->getMessage());
        return;
    }

    // Limpieza ocasional FUERA del try: si fallara no debe cambiar el veredicto de esta peticion.
    try {
        if (random_int(1, 200) === 1) {
            rateLimitLimpiar($directorio);
        }
    } catch (Throwable $e) {
        error_log('WARNING: limpieza del limitador de peticiones fallo: ' . $e->getMessage());
    }

    if ($resultado['permitido']) {
        return;
    }

    if (!headers_sent()) {
        http_response_code(429);
        header('Retry-After: ' . $resultado['reintentar_en']);
        header('Content-Type: application/json');
    }
    $mensaje = 'Demasiadas peticiones. Intenta de nuevo en ' . $resultado['reintentar_en'] . ' segundos.';
    echo json_encode(['success' => false, 'error' => $mensaje, 'message' => $mensaje]);
    exit;
}
