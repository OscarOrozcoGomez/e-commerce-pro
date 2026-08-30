<?php
declare(strict_types=1);

require_once __DIR__ . '/pickup_offer_utils.php';
require_once __DIR__ . '/phone_utils.php';
require_once __DIR__ . '/delivery_route_utils.php';
require_once __DIR__ . '/order_cancel_utils.php';

/**
 * Verifica si el usuario está autenticado.
 *
 * @return bool
 */
function isAuthenticated(): bool
{
    return isset($_SESSION['usuario']) && !empty($_SESSION['usuario']);
}

/**
 * Modo degradado para login cuando hay incidentes de latencia/timeout en el host.
 */
function isLoginDegradedModeEnabled(): bool
{
    $raw = getenv('LOGIN_DEGRADED_MODE');
    if ($raw === false) {
        $raw = $_SERVER['LOGIN_DEGRADED_MODE'] ?? $_ENV['LOGIN_DEGRADED_MODE'] ?? '';
    }

    $value = strtolower(trim((string)$raw));
    return in_array($value, ['1', 'true', 'yes', 'on'], true);
}

/**
 * Cola de auditoria de contingencia (JSONL) cuando la BD esta lenta/no disponible.
 */
function logAuditFallback(string $accion, string $tabla, ?int $id_registro, string $detalles): void
{
    try {
        $path = __DIR__ . '/../audit_fallback.log';
        $entry = [
            'ts' => date('c'),
            'accion' => $accion,
            'tabla' => $tabla,
            'id_registro' => $id_registro,
            'detalles' => $detalles,
            'id_usuario' => $_SESSION['usuario']['id_usuario'] ?? null,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ];
        @file_put_contents($path, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    } catch (Throwable $e) {
        // Nunca romper flujo de negocio por auditoria.
    }
}

/**
 * Registra una acción en el log de auditoría.
 */
function logAudit(string $accion, string $tabla, ?int $id_registro, string $detalles): void
{
    if (isLoginDegradedModeEnabled()) {
        logAuditFallback($accion, $tabla, $id_registro, $detalles);
        return;
    }

    try {
        $pdo = getPDO();
        try {
            $pdo->exec('SET SESSION innodb_lock_wait_timeout = 2');
        } catch (Throwable $e) {
            // Seguir aunque no se pueda ajustar timeout.
        }
        $stmt = $pdo->prepare("INSERT INTO logs_auditoria (id_usuario, accion, tabla_afectada, id_registro, detalles, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $_SESSION['usuario']['id_usuario'] ?? null,
            $accion,
            $tabla,
            $id_registro,
            $detalles,
            $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
        ]);
    } catch (Throwable $e) {
        // En producción, podrías loguear esto a un archivo para no detener el flujo
        error_log("Error en auditoría: " . $e->getMessage());
    }
}

function getCsrfToken(): string
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfInput(): string
{
    return '<input type="hidden" name="csrf_token" value="' . esc(getCsrfToken()) . '">';
}

function validateCsrfToken(string $token): bool
{
    if (empty($token) || !isset($_SESSION['csrf_token'])) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Verifica si el usuario tiene un permiso específico.
 *
 * @param string $permiso
 * @return bool
 */
function hasPermission(string $permiso): bool
{
    if (!isAuthenticated()) {
        return false;
    }

    // Si es admin, tiene todos los permisos por defecto
    if (isAdmin()) {
        return true;
    }

    $usuario = $_SESSION['usuario'];
    if (!isset($usuario['permisos']) || !is_array($usuario['permisos'])) {
        return false;
    }

    return in_array($permiso, $usuario['permisos'], true);
}

/**
 * Verifica si el usuario es admin.
 *
 * @return bool
 */
function isAdmin(): bool
{
    return isAuthenticated() && ($_SESSION['usuario']['rol'] ?? '') === 'admin';
}

/**
 * Determina si el usuario autenticado puede eliminar una cuenta de usuario.
 *
 * @param array<string, mixed> $usuarioObjetivo
 * @param int|null $idUsuarioActual
 * @return bool
 */
function canDeleteUserAccount(array $usuarioObjetivo, ?int $idUsuarioActual = null): bool
{
    if (!isAuthenticated() || !isAdmin()) {
        return false;
    }

    $idUsuarioObjetivo = (int)($usuarioObjetivo['id_usuario'] ?? 0);
    if ($idUsuarioActual !== null && $idUsuarioActual > 0 && $idUsuarioObjetivo === $idUsuarioActual) {
        return false;
    }

    $rolObjetivo = (string)($usuarioObjetivo['rol'] ?? '');
    $esAdminObjetivo = $rolObjetivo === 'admin' || !empty($usuarioObjetivo['es_superadmin']);
    if ($esAdminObjetivo && !isSuperAdmin()) {
        return false;
    }

    return true;
}

/**
 * Verifica si el usuario autenticado es super admin.
 *
 * @return bool
 */
function isSuperAdmin(): bool
{
    return isAdmin() && !empty($_SESSION['usuario']['es_superadmin']);
}

/**
 * Verifica si un registro de usuario corresponde a una cuenta de admin.
 *
 * @param array<string, mixed> $usuario
 * @return bool
 */
function isAdminAccount(array $usuario): bool
{
    return (($usuario['rol'] ?? '') === 'admin') || !empty($usuario['es_superadmin']);
}

/**
 * Determina si una cuenta existente con el mismo correo puede reutilizarse.
 *
 * @param array<string, mixed>|null $usuario
 * @return bool
 */
function shouldReactivateExistingUser(?array $usuario): bool
{
    if ($usuario === null) {
        return false;
    }

    return (string)($usuario['estado'] ?? '') === 'inactivo';
}

/**
 * Verifica si el usuario es encargado.
 *
 * @return bool
 */
function isEncargado(): bool
{
    return isAuthenticated() && ($_SESSION['usuario']['rol'] ?? '') === 'encargado';
}

/**
 * Verifica si el usuario es vendedor.
 *
 * @return bool
 */
function isVendedor(): bool
{
    return isAuthenticated() && ($_SESSION['usuario']['rol'] ?? '') === 'vendedor';
}

/**
 * Verifica si el usuario es repartidor.
 *
 * @return bool
 */
function isRepartidor(): bool
{
    return isAuthenticated() && ($_SESSION['usuario']['rol'] ?? '') === 'repartidor';
}

/**
 * Verifica si el usuario es cliente.
 *
 * @return bool
 */
function isCliente(): bool
{
    return isAuthenticated() && ($_SESSION['usuario']['rol'] ?? '') === 'cliente';
}

/**
 * Verifica si el usuario puede agendar pedidos a domicilio y asignar repartidores.
 */
function canManageDeliveryOrders(): bool
{
    return isAuthenticated() && (isAdmin() || isEncargado());
}

/**
 * Verifica si el usuario puede agendar pedidos a domicilio.
 */
function canScheduleSalesOrders(): bool
{
    return isAuthenticated() && (isAdmin() || isEncargado() || isVendedor());
}

/**
 * Verifica si el usuario puede asignar una categoria a muchos productos a la vez.
 */
function canBulkAssignCategories(): bool
{
    return isAuthenticated() && (isAdmin() || isEncargado());
}

/**
 * Obtiene el ID del almacén del usuario actual.
 *
 * @return int|null
 */
function getCurrentAlmacenId(): ?int
{
    $almacen = $_SESSION['usuario']['id_almacen'] ?? null;
    if ($almacen === null || $almacen === '') {
        return null;
    }

    if (is_numeric($almacen)) {
        return (int)$almacen;
    }

    return null;
}

/**
 * Resuelve la sucursal desde la cual debe operar una venta.
 *
 * - Vendedores/encargados usan siempre su sucursal asignada en sesión.
 * - Si un admin no tiene sucursal asignada, se toma la primera sucursal activa.
 */
function resolveSalesWarehouseId(PDO $pdo): int
{
    $almacenId = getCurrentAlmacenId();
    if ($almacenId !== null && $almacenId > 0) {
        return $almacenId;
    }

    if (isAdmin()) {
        $stmt = $pdo->query("SELECT id_almacen FROM almacenes WHERE estado = 'activo' ORDER BY id_almacen ASC LIMIT 1");
        return (int)($stmt->fetchColumn() ?: 0);
    }

    return 0;
}

/**
 * Redirige si no está autenticado.
 *
 * @param string $redirectUrl
 * @return void
 */
function requireAuth(string $redirectUrl = ''): void
{
    if (!isAuthenticated()) {
        if ($redirectUrl === '') {
            $redirectUrl = BASE_URL . 'views/login.php';
        }
        header("Location: {$redirectUrl}");
        exit;
    }
}

/**
 * Redirige si no tiene permiso.
 *
 * @param string $permiso
 * @param string $redirectUrl
 * @return void
 */
function requirePermission(string $permiso, string $redirectUrl = ''): void
{
    if (!hasPermission($permiso)) {
        if ($redirectUrl === '') {
            $redirectUrl = BASE_URL . 'index.php';
        }
        header("Location: {$redirectUrl}");
        exit;
    }
}

/**
 * Intenta autenticar al usuario.
 *
 * Acepta email o telefono como identificador de acceso.
 *
 * @param string $loginIdentifier
 * @param string $password
 * @return bool
 */
function authenticate(string $loginIdentifier, string $password): bool
{
    $pdo = getPDO();
    $loginIdentifier = trim($loginIdentifier);
    $loginDigits = normalizePhoneDigitsMx($loginIdentifier);

    // Evita esperas largas por bloqueos InnoDB durante login bajo carga.
    try {
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 3');
    } catch (Throwable $e) {
        // Continuar si el motor/usuario no permite cambiar esta variable.
    }

    $userId = null;
    if ($loginDigits !== null && $loginDigits !== '') {
        try {
            $clienteMatch = findClienteByPhone($pdo, $loginDigits);
            if (is_array($clienteMatch) && !empty($clienteMatch['id_usuario'])) {
                $userId = (int)$clienteMatch['id_usuario'];
            }
        } catch (Throwable $e) {
            error_log('DEBUG LOGIN: No se pudo resolver usuario por teléfono: ' . $e->getMessage());
        }
    }

    // Se añadió u.contrasena a la lista de columnas seleccionadas
    $sql = "SELECT u.id_usuario, u.nombre, u.email, u.contrasena, u.id_rol, u.id_almacen, u.es_superadmin, r.nombre as rol,
                   GROUP_CONCAT(p.clave) as permisos,
                   c.id_cliente,
                   c.telefono as telefono_cliente,
                   u.intentos_fallidos, u.bloqueado_hasta
            FROM usuarios u
            JOIN roles r ON u.id_rol = r.id_rol
            LEFT JOIN rol_permisos rp ON r.id_rol = rp.id_rol
            LEFT JOIN permisos p ON rp.id_permiso = p.id_permiso
            LEFT JOIN clientes c ON u.id_usuario = c.id_usuario
            WHERE " . ($userId !== null ? 'u.id_usuario = :login_id' : 'u.email = :login') . " AND u.estado = 'activo'
            GROUP BY u.id_usuario";

    try {
        error_log("DEBUG LOGIN: Intentando autenticar a: " . $loginIdentifier);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($userId !== null ? [':login_id' => $userId] : [':login' => $loginIdentifier]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("DEBUG LOGIN ERROR SQL: " . $e->getMessage());
        return false;
    }

    if (!$user) {
        error_log("DEBUG LOGIN: Usuario no encontrado o inactivo en la BD para: " . $loginIdentifier);
        return false;
    }

    if (isset($user['telefono_cliente']) && is_string($user['telefono_cliente'])
        && function_exists('piiIsEncryptedValue')
        && function_exists('piiDecryptValue')
        && piiIsEncryptedValue($user['telefono_cliente'])) {
        $user['telefono_cliente'] = (string)piiDecryptValue($user['telefono_cliente']);
    }

    error_log("DEBUG LOGIN: Usuario encontrado. ID: " . $user['id_usuario'] . " | Rol: " . $user['rol']);

    // Verificar si la cuenta está bloqueada temporalmente
    if ($user['intentos_fallidos'] >= 5 && $user['bloqueado_hasta'] && strtotime($user['bloqueado_hasta']) > time()) {
        $minutosRestantes = ceil((strtotime($user['bloqueado_hasta']) - time()) / 60);
        error_log("DEBUG LOGIN: Cuenta bloqueada para el ID: " . $user['id_usuario']);
        throw new Exception("Cuenta bloqueada temporalmente por seguridad debido a demasiados intentos fallidos. Inténtalo de nuevo en $minutosRestantes minuto(s).");
    }

    if (password_verify($password, $user['contrasena'])) {
        error_log("DEBUG LOGIN: Contraseña CORRECTA para ID " . $user['id_usuario']);
        
        if (empty($user['rol'])) {
            error_log("DEBUG LOGIN ADVERTENCIA: El usuario no tiene un rol asignado.");
        }

        $user['permisos'] = $user['permisos'] ? explode(',', $user['permisos']) : [];
        $_SESSION['usuario'] = $user;
        session_regenerate_id(true); // SEGURIDAD: Evita ataques de fijación de sesión
        $_SESSION['_session_id_rotated_at'] = time();

        // ÉXITO: limpiar intentos es importante, pero no debe bloquear el login.
        try {
            $pdo->prepare("UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id_usuario = ?")
                ->execute([$user['id_usuario']]);
        } catch (Throwable $e) {
            error_log("LOGIN_WARN: No se pudo limpiar intentos_fallidos: " . $e->getMessage());
        }
        
        logAudit('LOGIN_EXITOSO', 'usuarios', (int)$user['id_usuario'], "Usuario inició sesión");
        return true;
    }

    error_log("DEBUG LOGIN: Contraseña INCORRECTA para ID " . $user['id_usuario']);

    // FALLO: Incrementamos el contador de intentos
    $nuevosIntentos = (int)$user['intentos_fallidos'] + 1;
    $nuevaFechaBloqueo = null;

    if ($nuevosIntentos >= 5) {
        // Bloqueamos la cuenta por 15 minutos
        $nuevaFechaBloqueo = date('Y-m-d H:i:s', time() + (15 * 60));
        logAudit('BLOQUEO_CUENTA', 'usuarios', (int)$user['id_usuario'], "Cuenta bloqueada por 5 intentos fallidos");

        // Enviar alerta al Centro de Mensajes (Soporte) para el Admin
        if (!isLoginDegradedModeEnabled()) {
            try {
                $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $msgAlerta = "⚠️ ALERTA DE SEGURIDAD: La cuenta vinculada a este chat ha sido bloqueada temporalmente tras 5 intentos fallidos de inicio de sesión. Origen IP: $ip";
                
                // Insertar mensaje de alerta
                // Marcamos leido_cliente = 1 para que el usuario no vea esta alerta técnica en su chat
                $stmtMsg = $pdo->prepare("INSERT INTO mensajes_soporte (id_cliente, enviado_por, tipo_mensaje, mensaje, leido_staff, leido_cliente) 
                                         VALUES (?, 'staff', 'seguridad', ?, 0, 1)");
                $stmtMsg->execute([
                    $user['id_usuario'], 
                    $msgAlerta
                ]);
                
                // Asegurar que el Admin vea la notificación en la lista de chats
                $pdo->prepare("UPDATE usuarios SET soporte_activo = 1 WHERE id_usuario = ?")->execute([$user['id_usuario']]);
            } catch (Throwable $e) {
                error_log("Error al enviar alerta de bloqueo al chat: " . $e->getMessage());
            }
        }
    }

    try {
        $pdo->prepare("UPDATE usuarios SET intentos_fallidos = ?, bloqueado_hasta = ? WHERE id_usuario = ?")
            ->execute([$nuevosIntentos, $nuevaFechaBloqueo, $user['id_usuario']]);
    } catch (Throwable $e) {
        error_log("LOGIN_WARN: No se pudo actualizar intentos_fallidos: " . $e->getMessage());
    }

    return false;
}

/**
 * Cierra la sesión del usuario.
 *
 * @return void
 */
function logout(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        header('Location: ' . BASE_URL . 'views/login.php?logout=1');
        exit;
    }

    // Limpiar los datos de sesión en memoria
    $_SESSION = [];
    // Destruir la cookie de sesión en el navegador
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    session_start();
    $_SESSION['session_notice'] = 'Tu sesión se cerró correctamente. Por tu seguridad, te invitamos a iniciar sesión de nuevo.';
    header('Location: ' . BASE_URL . 'views/login.php?logout=1');
    exit;
}

function generatePasswordResetToken(string $email): bool
{
    $pdo = getPDO();
    $sql = "SELECT id_usuario FROM usuarios WHERE email = :email AND estado = 'activo' LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        return false;
    }

    // Evita reenviar/renovar el código en ráfaga (spam y ventana extra para adivinarlo).
    $stmtExisting = $pdo->prepare('SELECT created_at FROM password_resets WHERE email = :email AND usado = 0 AND expires_at >= NOW() ORDER BY id_password_reset DESC LIMIT 1');
    $stmtExisting->execute([':email' => $email]);
    $existing = $stmtExisting->fetch(PDO::FETCH_ASSOC);
    if ($existing && (time() - strtotime((string)$existing['created_at'])) < 60) {
        return true;
    }

    // Generar un código de 6 dígitos para mejor UX sin links
    $code = (string)random_int(100000, 999999);
    $tokenHash = hash('sha256', $code);
    $expiresAt = date('Y-m-d H:i:s', time() + 3600);

    $pdo->prepare('DELETE FROM password_resets WHERE email = :email')->execute([':email' => $email]);
    $stmt = $pdo->prepare('INSERT INTO password_resets (email, token_hash, expires_at, usado, intentos_fallidos, created_at) VALUES (:email, :token_hash, :expires_at, 0, 0, NOW())');
    $stmt->execute([
        ':email' => $email,
        ':token_hash' => $tokenHash,
        ':expires_at' => $expiresAt,
    ]);

    return sendPasswordResetEmail($email, $code);
}

/**
 * Busca el código de recuperación vigente para un email especifico.
 * El código SIEMPRE se valida contra el email indicado por el usuario
 * (nunca contra cualquier código vigente en el sistema), para que adivinar
 * un código al azar no permita tomar una cuenta distinta a la solicitada.
 */
function getPasswordResetRecordByEmail(string $email): ?array
{
    $pdo = getPDO();
    $stmt = $pdo->prepare('SELECT * FROM password_resets WHERE email = :email AND usado = 0 AND expires_at >= NOW() ORDER BY id_password_reset DESC LIMIT 1');
    $stmt->execute([':email' => $email]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    return $record !== false ? $record : null;
}

function resetPasswordWithToken(string $email, string $token, string $newPassword, ?string &$errorMessage = null): bool
{
    $errorMessage = null;
    $email = trim($email);
    $token = trim($token);

    if (!isPasswordSecure($newPassword)) {
        $errorMessage = 'La nueva contraseña debe tener al menos 10 caracteres, incluir mayúsculas, minúsculas, números y un símbolo.';
        return false;
    }

    if ($email === '' || $token === '') {
        $errorMessage = 'El código es inválido o ha expirado.';
        return false;
    }

    $pdo = getPDO();

    $record = getPasswordResetRecordByEmail($email);
    if (!$record) {
        $errorMessage = 'El código es inválido o ha expirado.';
        return false;
    }

    // Bloquea el código tras 5 intentos fallidos, igual que el bloqueo de login,
    // para que no pueda adivinarse por fuerza bruta (1,000,000 combinaciones).
    if ((int)$record['intentos_fallidos'] >= 5) {
        $pdo->prepare('UPDATE password_resets SET usado = 1 WHERE id_password_reset = :id')
            ->execute([':id' => $record['id_password_reset']]);
        $errorMessage = 'Demasiados intentos fallidos. Solicita un nuevo código.';
        return false;
    }

    if (!hash_equals((string)$record['token_hash'], hash('sha256', $token))) {
        $nuevosIntentos = (int)$record['intentos_fallidos'] + 1;
        if ($nuevosIntentos >= 5) {
            $pdo->prepare('UPDATE password_resets SET usado = 1, intentos_fallidos = ? WHERE id_password_reset = ?')
                ->execute([$nuevosIntentos, $record['id_password_reset']]);
            $errorMessage = 'Demasiados intentos fallidos. Solicita un nuevo código.';
        } else {
            $pdo->prepare('UPDATE password_resets SET intentos_fallidos = ? WHERE id_password_reset = ?')
                ->execute([$nuevosIntentos, $record['id_password_reset']]);
            $errorMessage = 'El código es inválido o ha expirado.';
        }
        return false;
    }

    $stmtCurrent = $pdo->prepare('SELECT contrasena FROM usuarios WHERE email = :email LIMIT 1');
    $stmtCurrent->execute([':email' => $record['email']]);
    $currentHash = $stmtCurrent->fetchColumn();

    if (!is_string($currentHash) || $currentHash === '') {
        $errorMessage = 'No se encontró una cuenta activa para actualizar la contraseña.';
        return false;
    }

    if (password_verify($newPassword, $currentHash)) {
        $errorMessage = 'La nueva contraseña no puede ser igual a la contraseña anterior.';
        return false;
    }

    $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare('UPDATE usuarios SET contrasena = :contrasena, intentos_fallidos = 0, bloqueado_hasta = NULL WHERE email = :email');
        $stmt->execute([
            ':contrasena' => $passwordHash,
            ':email' => $record['email'],
        ]);

        $stmt = $pdo->prepare('UPDATE password_resets SET usado = 1 WHERE id_password_reset = :id');
        $stmt->execute([':id' => $record['id_password_reset']]);

        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        $errorMessage = 'No fue posible actualizar la contraseña en este momento.';
        return false;
    }
}

function sendPasswordResetEmail(string $email, string $token): bool
{
    $subject = 'Código de recuperación de contraseña';
    $message = "Tu código de seguridad es: {$token}\n\n" .
               "Ingrésalo en la página para restablecer tu contraseña.\n" .
               "Si no solicitaste esto, ignora este mensaje.\n";

    return appSendPlainTextEmail($email, $subject, $message);
}

/**
 * Envía un correo de texto plano reutilizando la misma lógica para todo el sistema:
 * en localhost queda registrado en mail_log.txt (para pruebas en XAMPP) y en el
 * host real se envía con mail() usando un remitente del propio dominio.
 */
function appSendPlainTextEmail(string $email, string $subject, string $message): bool
{
    // Detectar si estamos en localhost o en el host real
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $isLocal = (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false);

    if ($isLocal) {
        // Seguimos guardando en log para pruebas locales en XAMPP
        $logPath = __DIR__ . '/../mail_log.txt';
        $logContent = "========================================\n" .
                      "FECHA: " . date('Y-m-d H:i:s') . "\n" .
                      "PARA: $email\n" .
                      "ASUNTO: $subject\n" .
                      "MENSAJE: $message\n" .
                      "========================================\n\n";
        file_put_contents($logPath, $logContent, FILE_APPEND);
        return true;
    }

    // LÓGICA PARA EL HOST REAL
    // Es vital que el remitente (From) sea un correo de tu dominio para evitar el SPAM
    $domain = str_replace('www.', '', $host);
    $fromEmail = "no-reply@" . $domain;
    $fromName = "Belleza y Bienestar";

    $headers = [
        "From: $fromName <$fromEmail>",
        "Reply-To: $fromEmail",
        "Return-Path: $fromEmail",
        "X-Mailer: PHP/" . phpversion(),
        "MIME-Version: 1.0",
        "Content-Type: text/plain; charset=UTF-8"
    ];

    // El quinto parámetro "-f" es fundamental en muchos hostings para validar el remitente real
    $extraParams = "-f" . $fromEmail;

    return mail($email, '=?UTF-8?B?'.base64_encode($subject).'?=', $message, implode("\r\n", $headers), $extraParams);
}

/**
 * Envía un correo en HTML. Misma lógica de entorno que appSendPlainTextEmail:
 * en localhost queda en mail_log.txt y además se vuelca a mail_preview_last_order.html
 * para poder abrirlo en el navegador y revisar cómo se ve.
 */
function appSendHtmlEmail(string $email, string $subject, string $htmlBody): bool
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $isLocal = (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false);

    if ($isLocal) {
        $logPath = __DIR__ . '/../mail_log.txt';
        $logContent = "========================================\n" .
                      "FECHA: " . date('Y-m-d H:i:s') . "\n" .
                      "PARA: $email\n" .
                      "ASUNTO: $subject\n" .
                      "MENSAJE: (HTML, ver mail_preview_last_order.html para vista previa)\n" .
                      "========================================\n\n";
        file_put_contents($logPath, $logContent, FILE_APPEND);
        file_put_contents(__DIR__ . '/../mail_preview_last_order.html', $htmlBody);
        return true;
    }

    $domain = str_replace('www.', '', $host);
    $fromEmail = "no-reply@" . $domain;
    $fromName = "Belleza y Bienestar";

    $headers = [
        "From: $fromName <$fromEmail>",
        "Reply-To: $fromEmail",
        "Return-Path: $fromEmail",
        "X-Mailer: PHP/" . phpversion(),
        "MIME-Version: 1.0",
        "Content-Type: text/html; charset=UTF-8"
    ];

    $extraParams = "-f" . $fromEmail;

    return mail($email, '=?UTF-8?B?'.base64_encode($subject).'?=', $htmlBody, implode("\r\n", $headers), $extraParams);
}

/**
 * Convierte una ruta relativa del sitio (ej. assets/img/logo.png) en una URL absoluta
 * con esquema y host, necesaria para que las imágenes se vean dentro del correo.
 */
function appAbsoluteAssetUrl(string $relativePath): string
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $base = defined('BASE_URL') ? BASE_URL : '/';
    if ($host === '') {
        return $base . ltrim($relativePath, '/');
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $host . $base . ltrim($relativePath, '/');
}

/**
 * Resuelve la imagen de un producto (columna producto_imagenes/productos.imagen) a una
 * URL absoluta usable en un correo. Si no hay imagen real, cae al logo de la tienda.
 */
function appResolveProductImageUrlForEmail(?string $imagen): string
{
    $imagen = trim((string) $imagen);
    if ($imagen === '' || stripos($imagen, 'default-product') !== false) {
        return appAbsoluteAssetUrl('assets/img/logo.png');
    }
    if (preg_match('#^https?://#i', $imagen)) {
        return $imagen;
    }
    return appAbsoluteAssetUrl('assets/img/products/' . ltrim($imagen, '/'));
}

/**
 * Obtiene nombre, cantidad, precio e imagen de cada producto de un pedido ya creado,
 * para armar el resumen visual del correo de aviso de pedido nuevo.
 */
function dbGetOrderItemsForEmail(PDO $pdo, int $idPedido): array
{
    if ($idPedido <= 0) {
        return [];
    }

    $sql = "SELECT dp.cantidad, dp.precio_unitario, dp.subtotal,
                   p.nombre, p.nombre_variante,
                   COALESCE(
                       NULLIF((SELECT pi.ruta_archivo FROM producto_imagenes pi WHERE pi.id_producto = p.id_producto ORDER BY pi.orden ASC LIMIT 1), ''),
                       NULLIF(TRIM(p.imagen), ''),
                       NULLIF(TRIM(p.imagen_url), '')
                   ) AS imagen
            FROM detalle_pedidos dp
            INNER JOIN productos p ON p.id_producto = dp.id_producto
            WHERE dp.id_pedido = :id_pedido
            ORDER BY dp.id_detalle ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id_pedido' => $idPedido]);
    return $stmt->fetchAll();
}

/**
 * Arma el HTML del correo de aviso de pedido nuevo: encabezado, datos del cliente
 * y una tabla con imagen, nombre y cantidad de cada producto comprado.
 */
function buildNewOrderNotificationHtml(array $order, array $items): string
{
    $numeroPedido = esc((string) ($order['numero_pedido'] ?? ''));
    $clienteNombre = esc((string) ($order['cliente_nombre'] ?? 'Cliente sin nombre'));
    $entrega = esc((string) ($order['tipo_entrega'] ?? 'No especificado'));
    $telefono = esc((string) ($order['telefono'] ?? ''));
    $direccion = esc((string) ($order['direccion'] ?? ''));
    $total = (float) ($order['total'] ?? 0.0);

    $filas = '';
    foreach ($items as $item) {
        $nombre = trim((string) ($item['nombre'] ?? 'Producto'));
        $variante = trim((string) ($item['nombre_variante'] ?? ''));
        $nombreCompleto = esc($variante !== '' ? "{$nombre} - {$variante}" : $nombre);
        $cantidad = (int) ($item['cantidad'] ?? 0);
        $precioUnitario = (float) ($item['precio_unitario'] ?? 0.0);
        $subtotalLinea = (float) ($item['subtotal'] ?? 0.0);
        $imagenUrl = esc(appResolveProductImageUrlForEmail($item['imagen'] ?? null));

        $filas .= '
            <tr>
                <td style="padding:10px 8px;border-bottom:1px solid #eee;width:64px;">
                    <img src="' . $imagenUrl . '" alt="' . $nombreCompleto . '" width="56" height="56" style="width:56px;height:56px;object-fit:cover;border-radius:8px;border:1px solid #eee;display:block;">
                </td>
                <td style="padding:10px 8px;border-bottom:1px solid #eee;">
                    <div style="font-weight:600;color:#263238;font-size:14px;">' . $nombreCompleto . '</div>
                    <div style="color:#78909c;font-size:12px;margin-top:2px;">$' . number_format($precioUnitario, 2) . ' c/u</div>
                </td>
                <td style="padding:10px 8px;border-bottom:1px solid #eee;text-align:center;color:#263238;font-size:14px;white-space:nowrap;">
                    x' . $cantidad . '
                </td>
                <td style="padding:10px 8px;border-bottom:1px solid #eee;text-align:right;color:#263238;font-size:14px;white-space:nowrap;">
                    $' . number_format($subtotalLinea, 2) . '
                </td>
            </tr>';
    }

    if ($filas === '') {
        $filas = '<tr><td colspan="4" style="padding:14px 8px;color:#78909c;">Sin productos.</td></tr>';
    }

    $direccionHtml = $direccion !== ''
        ? '<div style="margin-top:4px;"><strong>Dirección:</strong> ' . $direccion . '</div>'
        : '';

    return '
    <div style="background:#f4f6f7;padding:24px 12px;font-family:Arial,Helvetica,sans-serif;">
        <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,0.06);">
            <div style="background:#1a237e;padding:20px 24px;">
                <div style="color:#ffffff;font-size:18px;font-weight:700;">Belleza y Bienestar</div>
                <div style="color:#c5cae9;font-size:13px;margin-top:2px;">¡Nuevo pedido en la tienda en línea!</div>
            </div>
            <div style="padding:20px 24px;">
                <div style="background:#e8eaf6;border-radius:8px;padding:14px 16px;font-size:14px;color:#283593;margin-bottom:18px;">
                    <div style="font-size:16px;font-weight:700;margin-bottom:6px;">Pedido ' . $numeroPedido . '</div>
                    <div><strong>Cliente:</strong> ' . $clienteNombre . '</div>
                    <div><strong>Teléfono:</strong> ' . $telefono . '</div>
                    <div><strong>Entrega:</strong> ' . $entrega . '</div>
                    ' . $direccionHtml . '
                </div>

                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;">
                    <thead>
                        <tr>
                            <th colspan="2" style="text-align:left;padding:0 8px 8px;font-size:12px;color:#90a4ae;text-transform:uppercase;letter-spacing:.03em;">Producto</th>
                            <th style="text-align:center;padding:0 8px 8px;font-size:12px;color:#90a4ae;text-transform:uppercase;letter-spacing:.03em;">Cant.</th>
                            <th style="text-align:right;padding:0 8px 8px;font-size:12px;color:#90a4ae;text-transform:uppercase;letter-spacing:.03em;">Subtotal</th>
                        </tr>
                    </thead>
                    <tbody>' . $filas . '</tbody>
                </table>

                <div style="text-align:right;margin-top:16px;padding-top:12px;border-top:2px solid #1a237e;">
                    <span style="font-size:13px;color:#78909c;">Total del pedido</span><br>
                    <span style="font-size:22px;font-weight:800;color:#1a237e;">$' . number_format($total, 2) . '</span>
                </div>

                <div style="margin-top:24px;text-align:center;">
                    <a href="' . esc(appAbsoluteAssetUrl('views/dashboard.php')) . '" style="display:inline-block;background:#1a237e;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:8px;font-size:14px;font-weight:600;">Ver en el panel de administración</a>
                </div>
            </div>
        </div>
    </div>';
}

/**
 * Notifica por correo a la lista de destinatarios administrada en
 * views/notificaciones_pedidos.php cuando se crea un nuevo pedido web,
 * con un resumen visual (imagen, nombre y cantidad de cada producto) para que no pase desapercibido.
 * No lanza excepción si falla: un error de correo nunca debe tumbar la creación del pedido.
 */
function sendNewOrderNotificationEmails(array $order): void
{
    try {
        $recipients = dbGetOrderNotificationEmails(true);
        if (empty($recipients)) {
            return;
        }

        $numeroPedido = (string) ($order['numero_pedido'] ?? '');
        $items = is_array($order['items'] ?? null) ? $order['items'] : [];

        $subject = "Nuevo pedido web #{$numeroPedido}";
        $htmlBody = buildNewOrderNotificationHtml($order, $items);

        foreach ($recipients as $recipient) {
            if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            appSendHtmlEmail($recipient, $subject, $htmlBody);
        }
    } catch (Throwable $e) {
        error_log('WARNING: No fue posible enviar notificaciones de nuevo pedido por correo: ' . $e->getMessage());
    }
}

/**
 * Lista los correos configurados para recibir aviso de pedidos nuevos.
 * $soloActivos = true regresa solo los correos activos (usado al enviar el aviso real).
 */
function dbGetOrderNotificationEmails(bool $soloActivos = false): array
{
    $pdo = getPDO();
    $sql = 'SELECT id_correo, correo, activo, creado_en FROM pedido_notificacion_correos';
    if ($soloActivos) {
        $sql .= ' WHERE activo = 1';
    }
    $sql .= ' ORDER BY creado_en ASC';

    $stmt = $pdo->query($sql);
    $rows = $stmt ? $stmt->fetchAll() : [];

    if ($soloActivos) {
        return array_values(array_map(static fn($row) => (string) $row['correo'], $rows));
    }

    return $rows;
}

/**
 * Agrega un correo a la lista de notificación de pedidos nuevos (solo admin).
 */
function dbAddOrderNotificationEmail(string $correo): array
{
    if (!isAdmin()) {
        return ['success' => false, 'message' => 'No autorizado.'];
    }

    $correo = trim($correo);
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'message' => 'El correo no es válido.'];
    }

    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare('INSERT INTO pedido_notificacion_correos (correo) VALUES (:correo)');
        $stmt->execute([':correo' => $correo]);
        return ['success' => true, 'message' => 'Correo agregado correctamente.'];
    } catch (PDOException $e) {
        if ((int) $e->getCode() === 23000) {
            return ['success' => false, 'message' => 'Ese correo ya está en la lista.'];
        }
        error_log('Error en dbAddOrderNotificationEmail: ' . $e->getMessage());
        return ['success' => false, 'message' => 'No fue posible agregar el correo.'];
    }
}

/**
 * Activa/desactiva un correo de la lista de notificación sin borrar su historial (solo admin).
 */
function dbSetOrderNotificationEmailActive(int $idCorreo, bool $activo): bool
{
    if (!isAdmin() || $idCorreo <= 0) {
        return false;
    }

    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare('UPDATE pedido_notificacion_correos SET activo = :activo WHERE id_correo = :id');
        $stmt->execute([':activo' => $activo ? 1 : 0, ':id' => $idCorreo]);
        return true;
    } catch (PDOException $e) {
        error_log('Error en dbSetOrderNotificationEmailActive: ' . $e->getMessage());
        return false;
    }
}

/**
 * Elimina un correo de la lista de notificación de pedidos nuevos (solo admin).
 */
function dbDeleteOrderNotificationEmail(int $idCorreo): bool
{
    if (!isAdmin() || $idCorreo <= 0) {
        return false;
    }

    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare('DELETE FROM pedido_notificacion_correos WHERE id_correo = :id');
        $stmt->execute([':id' => $idCorreo]);
        return true;
    } catch (PDOException $e) {
        error_log('Error en dbDeleteOrderNotificationEmail: ' . $e->getMessage());
        return false;
    }
}

/**
 * Crea un pedido público (Checkout) encapsulando la lógica SQL.
 */
function dbCreatePublicOrder(array $data): array {
    $pdo = getPDO();
    try {
        $pdo->beginTransaction();

        $columnExists = static function (PDO $pdo, string $table, string $column): bool {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
            );
            $stmt->execute([$table, $column]);
            return ((int)$stmt->fetchColumn()) > 0;
        };

        $hasPedidosTipoEntrega = $columnExists($pdo, 'pedidos', 'tipo_entrega');
        $hasPedidosDireccionEntrega = $columnExists($pdo, 'pedidos', 'direccion_entrega');
        $hasPedidosTelefonoEntrega = $columnExists($pdo, 'pedidos', 'telefono_entrega');
        $hasPedidosMapsLinkEntrega = $columnExists($pdo, 'pedidos', 'maps_link_entrega');
        $hasPedidosLatitud = $columnExists($pdo, 'pedidos', 'latitud');
        $hasPedidosLongitud = $columnExists($pdo, 'pedidos', 'longitud');

        $entrega = $data['tipo_entrega'] ?? 'No especificado';
        $esPickupSucursal = strcasecmp((string)$entrega, 'Sucursal') === 0;
        $pickupWarehouseId = $esPickupSucursal ? resolvePickupWarehouseId($pdo) : null;
        if ($esPickupSucursal && (int)$pickupWarehouseId <= 0) {
            throw new Exception('No hay una sucursal pickup publica configurada para pedidos web.');
        }

        $pickupStockSnapshot = null;
        $allowSupportTransferPickup = false;
        if ($esPickupSucursal) {
            $pickupStockSnapshot = dbBuildPickupStockHint($pdo, $data['items'] ?? []);
            if (!($pickupStockSnapshot['success'] ?? false)) {
                throw new Exception((string)($pickupStockSnapshot['message'] ?? 'No se pudo validar stock pickup.'));
            }

            $statusPickup = (string)($pickupStockSnapshot['status'] ?? 'ok');
            if ($statusPickup === 'sin_stock') {
                $faltantes = is_array($pickupStockSnapshot['faltantes'] ?? null) ? $pickupStockSnapshot['faltantes'] : [];
                $sinStock = array_filter($faltantes, static fn($row) => isset($row['transferible']) && $row['transferible'] === false);
                $source = !empty($sinStock) ? $sinStock : $faltantes;

                $nombres = [];
                foreach ($source as $row) {
                    $nombre = trim((string)($row['nombre'] ?? ''));
                    if ($nombre !== '') {
                        $nombres[] = $nombre;
                    }
                }

                $detalle = empty($nombres) ? '' : implode(', ', array_values(array_unique($nombres)));
                throw new Exception('pickup_sin_stock::' . $detalle);
            }

            $allowSupportTransferPickup = $statusPickup === 'transferible';
        }
        
        // Para pickup, el pedido siempre queda asignado a la sucursal pickup.
        // Si hay faltantes transferibles, permitimos surtir desde almacenes de apoyo.
        $id_almacen_pedido = $esPickupSucursal
            ? (int)$pickupWarehouseId
            : resolveCheckoutWarehouse($data['id_almacen'] ?? null);

        // Almacén principal de salida para escenarios que no son pickup transferible.
        $id_almacen_despacho = $esPickupSucursal
            ? (int)$pickupWarehouseId
            : $id_almacen_pedido;
        $id_usuario = $data['id_usuario'] ?? 1; // Asignar al Admin (ID 1) si no hay un vendedor físico
        $id_cliente = $data['id_cliente'] ?? null; // Vincular al perfil del cliente si está logueado

        $direccionEntrega = trim((string)($data['cliente']['direccion'] ?? ''));
        $telefonoEntrega = trim((string)($data['cliente']['telefono'] ?? ''));
        $mapsLinkEntrega = trim((string)($data['maps_link'] ?? ''));

        if ($mapsLinkEntrega !== ''
            && function_exists('piiIsEncryptedValue')
            && function_exists('piiDecryptValue')
            && piiIsEncryptedValue($mapsLinkEntrega)) {
            $mapsLinkEntrega = trim((string)piiDecryptValue($mapsLinkEntrega));
        }

        $coordsEntrega = null;
        if (strcasecmp((string)$entrega, 'Domicilio') === 0) {
            $coordsEntrega = deliveryResolveCoordinates(
                $mapsLinkEntrega,
                $direccionEntrega,
                getMapsApiKey(false)
            );
        }

        $infoCliente = "ENTREGA: {$entrega} | Cliente: {$data['cliente']['nombre']} | Tel: {$telefonoEntrega} | Dir: {$direccionEntrega}";
        $subtotal = array_reduce($data['items'], fn($s, $i) => $s + ((float)($i['precio'] ?? 0) * (int)($i['quantity'] ?? 0)), 0.0);
        $subtotal = round(max(0.0, (float)$subtotal), 2);
        $totalPiezas = (int)array_reduce($data['items'], fn($s, $i) => $s + max(0, (int)($i['quantity'] ?? 0)), 0);

        $pickupOffer = calculatePickupOffer($subtotal, $totalPiezas, getPickupOfferSettings($pdo));
        $aplicarIncentivoSucursal = strcasecmp((string)$entrega, 'Sucursal') === 0
            && !empty($pickupOffer['elegible'])
            && ((float)($pickupOffer['ahorro'] ?? 0.0) > 0.0);

        $descuentoTotal = $aplicarIncentivoSucursal ? round((float)$pickupOffer['ahorro'], 2) : 0.0;
        $totalPedido = round(max(0.0, $subtotal - $descuentoTotal), 2);

        if ($aplicarIncentivoSucursal) {
            $infoCliente .= " | INCENTIVO_SUCURSAL: -$" . number_format($descuentoTotal, 2, '.', '');
        }

        if ($esPickupSucursal && is_array($pickupStockSnapshot) && (($pickupStockSnapshot['status'] ?? '') === 'transferible')) {
            $supportWarehouse = trim((string)($pickupStockSnapshot['almacen_apoyo_nombre'] ?? 'almacen de apoyo'));
            $faltantesRaw = is_array($pickupStockSnapshot['faltantes'] ?? null) ? $pickupStockSnapshot['faltantes'] : [];
            $faltantesTxt = [];
            foreach ($faltantesRaw as $row) {
                $nombre = trim((string)($row['nombre'] ?? ''));
                $faltan = max(0, (int)($row['faltan'] ?? 0));
                if ($nombre !== '' && $faltan > 0) {
                    $faltantesTxt[] = $nombre . ' (faltan ' . $faltan . ')';
                }
            }
            $detalleFaltantes = empty($faltantesTxt) ? 'productos pendientes por surtir' : implode(', ', $faltantesTxt);
            $infoCliente .= ' | TRASLADO_INTERNO_2_3H: Requiere traslado desde ' . $supportWarehouse . ' para pickup. ' . $detalleFaltantes;
        }

        $numero_pedido = 'WEB-' . strtoupper(uniqid());

        $pedidoColumns = [
            'numero_pedido',
            'id_usuario',
            'id_cliente',
            'id_almacen',
            'id_metodo_pago',
            'estado',
            'subtotal',
            'descuento_total',
            'total',
            'observaciones',
        ];
        $pedidoPlaceholders = [
            ':numero_pedido',
            ':id_usuario',
            ':id_cliente',
            ':id_almacen',
            ':id_metodo_pago',
            ':estado',
            ':subtotal',
            ':descuento_total',
            ':total',
            ':observaciones',
        ];
        $pedidoParams = [
            ':numero_pedido' => $numero_pedido,
            ':id_usuario' => $id_usuario,
            ':id_cliente' => $id_cliente,
            ':id_almacen' => $id_almacen_pedido,
            ':id_metodo_pago' => 1,
            ':estado' => 'pendiente_pago',
            ':subtotal' => $subtotal,
            ':descuento_total' => $descuentoTotal,
            ':total' => $totalPedido,
            ':observaciones' => $infoCliente,
        ];

        if ($hasPedidosTipoEntrega) {
            $pedidoColumns[] = 'tipo_entrega';
            $pedidoPlaceholders[] = ':tipo_entrega';
            $pedidoParams[':tipo_entrega'] = (string)$entrega;
        }

        if ($hasPedidosDireccionEntrega && $direccionEntrega !== '') {
            $pedidoColumns[] = 'direccion_entrega';
            $pedidoPlaceholders[] = ':direccion_entrega';
            $pedidoParams[':direccion_entrega'] = $direccionEntrega;
        }

        if ($hasPedidosTelefonoEntrega && $telefonoEntrega !== '') {
            $pedidoColumns[] = 'telefono_entrega';
            $pedidoPlaceholders[] = ':telefono_entrega';
            $pedidoParams[':telefono_entrega'] = $telefonoEntrega;
        }

        if ($hasPedidosMapsLinkEntrega && $mapsLinkEntrega !== '') {
            $pedidoColumns[] = 'maps_link_entrega';
            $pedidoPlaceholders[] = ':maps_link_entrega';
            $pedidoParams[':maps_link_entrega'] = $mapsLinkEntrega;
        }

        if ($hasPedidosLatitud && $hasPedidosLongitud && is_array($coordsEntrega)) {
            $pedidoColumns[] = 'latitud';
            $pedidoColumns[] = 'longitud';
            $pedidoPlaceholders[] = ':latitud';
            $pedidoPlaceholders[] = ':longitud';
            $pedidoParams[':latitud'] = $coordsEntrega['lat'];
            $pedidoParams[':longitud'] = $coordsEntrega['lng'];
        }

        $sqlPedido = sprintf(
            'INSERT INTO pedidos (%s) VALUES (%s)',
            implode(', ', $pedidoColumns),
            implode(', ', $pedidoPlaceholders)
        );
        $stmt = $pdo->prepare($sqlPedido);
        $stmt->execute($pedidoParams);
        $id_pedido = $pdo->lastInsertId();

        if (strcasecmp((string)$entrega, 'Sucursal') === 0) {
            dbCreatePickupNotification($pdo, [
                'id_pedido' => (int)$id_pedido,
            'id_almacen' => (int)$pickupWarehouseId,
                'id_cliente' => $id_cliente !== null ? (int)$id_cliente : null,
                'numero_pedido' => (string)$numero_pedido,
                'cliente_nombre' => (string)($data['cliente']['nombre'] ?? 'Cliente sin nombre'),
                'cliente_telefono' => (string)($data['cliente']['telefono'] ?? ''),
                'direccion' => (string)($data['cliente']['direccion'] ?? ''),
                'observaciones' => (string)$infoCliente,
            ]);
        }

        $stmtDetalle = $pdo->prepare("INSERT INTO detalle_pedidos (id_pedido, id_producto, cantidad, precio_original, precio_unitario, costo_unitario, monto_descuento, subtotal) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $stmtStock = $pdo->prepare("UPDATE inventario_almacen SET cantidad_actual = cantidad_actual - ? WHERE id_producto = ? AND id_almacen = ?");
        $stmtStockCheck = $pdo->prepare("SELECT COALESCE(cantidad_actual, 0) FROM inventario_almacen WHERE id_producto = ? AND id_almacen = ? FOR UPDATE");
                $stmtSupportStocks = $pdo->prepare("SELECT ia.id_almacen, COALESCE(ia.cantidad_actual, 0) AS cantidad_actual
                                                                                        FROM inventario_almacen ia
                                                                                        INNER JOIN almacenes a ON a.id_almacen = ia.id_almacen
                                                                                        WHERE ia.id_producto = ?
                                                                                            AND ia.id_almacen <> ?
                                                                                            AND a.estado = 'activo'
                                                                                            AND ia.cantidad_actual > 0
                                                                                        ORDER BY ia.cantidad_actual DESC, ia.id_almacen ASC
                                                                                        FOR UPDATE");
        $stmtCosto = $pdo->prepare("SELECT COALESCE(precio_costo, 0) FROM productos WHERE id_producto = ?");

        $remainingDiscountCents = (int)round($descuentoTotal * 100);
        $remainingPiecesForDiscount = max(0, $totalPiezas);

        foreach ($data['items'] as $item) {
            $idProducto = (int)($item['id_producto'] ?? 0);
            $cantidad = max(0, (int)($item['quantity'] ?? 0));
            $precio = (float)($item['precio'] ?? 0);
            $nombreProducto = trim((string)($item['nombre'] ?? ''));

            if ($idProducto <= 0 || $cantidad <= 0 || $precio <= 0) {
                throw new Exception('Producto o cantidad inválidos en el pedido.');
            }

            $consumos = [];

            if ($esPickupSucursal) {
                // Consumir primero el stock de la sucursal pickup.
                $stmtStockCheck->execute([$idProducto, (int)$pickupWarehouseId]);
                $stockPickup = (int)$stmtStockCheck->fetchColumn();
                $usarPickup = min($cantidad, max(0, $stockPickup));
                $faltante = $cantidad - $usarPickup;

                if ($usarPickup > 0) {
                    $consumos[] = ['id_almacen' => (int)$pickupWarehouseId, 'cantidad' => $usarPickup];
                }

                if ($faltante > 0) {
                    if (!$allowSupportTransferPickup) {
                        throw new Exception('stock_insuficiente::' . $idProducto . '::' . $nombreProducto);
                    }

                    // Repartir faltantes entre almacenes de apoyo disponibles.
                    $stmtSupportStocks->execute([$idProducto, (int)$pickupWarehouseId]);
                    $supportRows = $stmtSupportStocks->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($supportRows as $row) {
                        if ($faltante <= 0) {
                            break;
                        }

                        $idAlmacenApoyo = (int)($row['id_almacen'] ?? 0);
                        $stockApoyo = max(0, (int)($row['cantidad_actual'] ?? 0));
                        if ($idAlmacenApoyo <= 0 || $stockApoyo <= 0) {
                            continue;
                        }

                        $usarApoyo = min($faltante, $stockApoyo);
                        if ($usarApoyo > 0) {
                            $consumos[] = ['id_almacen' => $idAlmacenApoyo, 'cantidad' => $usarApoyo];
                            $faltante -= $usarApoyo;
                        }
                    }

                    if ($faltante > 0) {
                        throw new Exception('stock_insuficiente::' . $idProducto . '::' . $nombreProducto);
                    }
                }
            } else {
                $stmtStockCheck->execute([$idProducto, $id_almacen_despacho]);
                $stockActual = (int)$stmtStockCheck->fetchColumn();
                if ($stockActual < $cantidad) {
                    throw new Exception('stock_insuficiente::' . $idProducto . '::' . $nombreProducto);
                }

                $consumos[] = ['id_almacen' => (int)$id_almacen_despacho, 'cantidad' => $cantidad];
            }

            $stmtCosto->execute([$idProducto]);
            $costoUnitario = (float)($stmtCosto->fetchColumn() ?: 0);

            $lineGross = round($precio * $cantidad, 2);
            $lineDiscountCents = 0;

            if ($remainingDiscountCents > 0 && $remainingPiecesForDiscount > 0) {
                $basePerPieceCents = intdiv($remainingDiscountCents, $remainingPiecesForDiscount);
                $remainderCents = $remainingDiscountCents % $remainingPiecesForDiscount;
                $extraForLine = min($cantidad, $remainderCents);
                $lineDiscountCents = ($cantidad * $basePerPieceCents) + $extraForLine;

                $lineGrossCents = (int)round($lineGross * 100);
                $lineDiscountCents = min($lineDiscountCents, $lineGrossCents);

                $remainingDiscountCents -= $lineDiscountCents;
                $remainingPiecesForDiscount -= $cantidad;
            }

            $lineDiscount = round($lineDiscountCents / 100, 2);
            $lineNet = round(max(0.0, $lineGross - $lineDiscount), 2);
            $netUnitPrice = $cantidad > 0 ? round($lineNet / $cantidad, 2) : $precio;

            $stmtDetalle->execute([$id_pedido, $idProducto, $cantidad, $precio, $netUnitPrice, $costoUnitario, $lineDiscount, $lineNet]);
            foreach ($consumos as $consumo) {
                $stmtStock->execute([(int)$consumo['cantidad'], $idProducto, (int)$consumo['id_almacen']]);
            }
        }

        $pdo->commit();

        sendNewOrderNotificationEmails([
            'numero_pedido' => $numero_pedido,
            'cliente_nombre' => $data['cliente']['nombre'] ?? 'Cliente sin nombre',
            'tipo_entrega' => $entrega,
            'telefono' => $telefonoEntrega,
            'direccion' => $direccionEntrega,
            'total' => $totalPedido,
            'items' => dbGetOrderItemsForEmail($pdo, (int)$id_pedido),
        ]);

        return [
            'success' => true,
            'pedido' => $numero_pedido,
            'id_pedido' => (int)$id_pedido,
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Error en dbCreatePublicOrder: " . $e->getMessage());
        $msg = $e->getMessage();
        if (stripos($msg, 'pickup_sin_stock::') === 0) {
            $detalle = trim((string)substr($msg, strlen('pickup_sin_stock::')));
            if ($detalle !== '') {
                return ['success' => false, 'message' => 'No podemos completar el pedido: no hay existencia ni en sucursal ni en bodega para: ' . $detalle . '. Elimina esos productos del carrito para continuar.'];
            }
            return ['success' => false, 'message' => 'No podemos completar el pedido porque no hay existencia ni en sucursal ni en bodega para uno o más productos. Elimina esos productos del carrito para continuar.'];
        }
        if (stripos($msg, 'stock_insuficiente::') === 0) {
            $partes = explode('::', $msg, 3);
            $idProductoFaltante = (int)($partes[1] ?? 0);
            $nombreFaltante = trim((string)($partes[2] ?? ''));
            $mensaje = $nombreFaltante !== ''
                ? 'No hay stock suficiente de "' . $nombreFaltante . '". Elimínalo o ajusta la cantidad en tu carrito e intenta de nuevo.'
                : 'No hay stock suficiente para uno o más productos del carrito. Actualiza tu carrito e intenta de nuevo.';
            return [
                'success' => false,
                'message' => $mensaje,
                'productos_sin_stock' => $idProductoFaltante > 0 ? [$idProductoFaltante] : [],
            ];
        }
        if (stripos($msg, 'stock insuficiente') !== false) {
            return ['success' => false, 'message' => 'No hay stock suficiente para uno o más productos del carrito. Actualiza tu carrito e intenta de nuevo.'];
        }
        if (stripos($msg, 'producto o cantidad inválidos') !== false) {
            return ['success' => false, 'message' => 'Hay productos inválidos en tu carrito. Actualiza la página e intenta de nuevo.'];
        }
        return ['success' => false, 'message' => 'Error interno al procesar pedido'];
    }
}

/**
 * Evalua stock para pedidos pickup (sucursal) y determina si es:
 * - ok: todo surtible en sucursal
 * - transferible: faltantes en sucursal pero hay en almacenes de apoyo
 * - sin_stock: no hay suficiente ni en sucursal ni en apoyo
 */
function dbBuildPickupStockHint(PDO $pdo, array $items): array
{
    $required = [];
    foreach ($items as $item) {
        $idProducto = (int)($item['id_producto'] ?? $item['id'] ?? 0);
        $cantidad = max(0, (int)($item['quantity'] ?? 0));
        if ($idProducto <= 0 || $cantidad <= 0) {
            continue;
        }
        $required[$idProducto] = ($required[$idProducto] ?? 0) + $cantidad;
    }

    if (empty($required)) {
        return ['success' => false, 'message' => 'Items invalidos'];
    }

    $pickupWarehouseId = resolvePickupWarehouseId($pdo);
    if ($pickupWarehouseId <= 0) {
        return ['success' => false, 'message' => 'No se pudo resolver sucursal pickup'];
    }

    $stmtPickupName = $pdo->prepare("SELECT nombre FROM almacenes WHERE id_almacen = ? LIMIT 1");
    $stmtPickupName->execute([$pickupWarehouseId]);
    $pickupWarehouseName = (string)($stmtPickupName->fetchColumn() ?: ('Sucursal #' . $pickupWarehouseId));

    $selects = [];
    $paramsRequired = [];
    foreach ($required as $idProducto => $cantidad) {
        $selects[] = 'SELECT ? AS id_producto, ? AS cantidad_requerida';
        $paramsRequired[] = $idProducto;
        $paramsRequired[] = $cantidad;
    }
    $requiredSql = implode(' UNION ALL ', $selects);

    $sql = "SELECT
                req.id_producto,
                req.cantidad_requerida,
                COALESCE(pr.nombre, CONCAT('Producto #', req.id_producto)) AS nombre,
                COALESCE(MAX(CASE WHEN ia.id_almacen = ? AND a.estado = 'activo' THEN ia.cantidad_actual ELSE 0 END), 0) AS stock_pickup,
                COALESCE(SUM(CASE WHEN ia.id_almacen <> ? AND a.estado = 'activo' THEN ia.cantidad_actual ELSE 0 END), 0) AS stock_otro,
                MAX(CASE WHEN ia.id_almacen <> ? AND a.estado = 'activo' THEN a.nombre ELSE NULL END) AS almacen_apoyo_nombre
            FROM ({$requiredSql}) req
            LEFT JOIN inventario_almacen ia ON ia.id_producto = req.id_producto
            LEFT JOIN almacenes a ON a.id_almacen = ia.id_almacen
            LEFT JOIN productos pr ON pr.id_producto = req.id_producto
            GROUP BY req.id_producto, req.cantidad_requerida, pr.nombre";

    $stmt = $pdo->prepare($sql);
    $params = array_merge([$pickupWarehouseId, $pickupWarehouseId, $pickupWarehouseId], $paramsRequired);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $faltantes = [];
    $transferible = true;
    $supportWarehouseName = '';

    foreach ($rows as $row) {
        $idProducto = (int)($row['id_producto'] ?? 0);
        $requerido = (int)($row['cantidad_requerida'] ?? 0);
        $stockPickup = (int)($row['stock_pickup'] ?? 0);
        $stockOtro = (int)($row['stock_otro'] ?? 0);
        $faltan = max(0, $requerido - $stockPickup);

        if ($faltan > 0) {
            $puedeTransfer = $stockOtro >= $faltan;
            $transferible = $transferible && $puedeTransfer;
            if ($supportWarehouseName === '' && !empty($row['almacen_apoyo_nombre'])) {
                $supportWarehouseName = (string)$row['almacen_apoyo_nombre'];
            }
            $faltantes[] = [
                'id_producto' => $idProducto,
                'nombre' => (string)($row['nombre'] ?? ('Producto #' . $idProducto)),
                'requerido' => $requerido,
                'stock_pickup' => $stockPickup,
                'stock_otro' => $stockOtro,
                'faltan' => $faltan,
                'transferible' => $puedeTransfer,
            ];
        }
    }

    $status = 'ok';
    if (!empty($faltantes)) {
        $status = $transferible ? 'transferible' : 'sin_stock';
    }

    return [
        'success' => true,
        'status' => $status,
        'pickup_almacen_id' => $pickupWarehouseId,
        'pickup_almacen_nombre' => $pickupWarehouseName,
        'almacen_apoyo_nombre' => $supportWarehouseName !== '' ? $supportWarehouseName : 'almacen de apoyo',
        'faltantes' => $faltantes,
    ];
}

/**
 * Resuelve la sucursal de pickup web.
 *
 * Prioridad:
 * 1) Env var CHECKOUT_PICKUP_WAREHOUSE_ID o PICKUP_WAREHOUSE_ID si es valida y activa.
 * 2) Primera sucursal activa por id_almacen.
 */
function resolvePickupWarehouseId(PDO $pdo): int
{
    $fromEnv = (int)(getEnvVar('CHECKOUT_PUBLIC_PICKUP_WAREHOUSE_ID', getEnvVar('CHECKOUT_PICKUP_WAREHOUSE_ID', getEnvVar('PICKUP_WAREHOUSE_ID', '0'))) ?: 0);
    if ($fromEnv > 0) {
        $stmt = $pdo->prepare("SELECT id_almacen, nombre FROM almacenes WHERE id_almacen = ? AND estado = 'activo' LIMIT 1");
        $stmt->execute([$fromEnv]);
        $validatedRow = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $validated = (int)($validatedRow['id_almacen'] ?? 0);
        $validatedName = (string)($validatedRow['nombre'] ?? '');
        if ($validated > 0 && isPublicPickupWarehouseName($validatedName)) {
            return $validated;
        }
    }

    $stmt = $pdo->query("SELECT id_almacen, nombre FROM almacenes WHERE estado = 'activo' ORDER BY id_almacen ASC");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $idAlmacen = (int)($row['id_almacen'] ?? 0);
        $nombre = (string)($row['nombre'] ?? '');
        if ($idAlmacen > 0 && isPublicPickupWarehouseName($nombre)) {
            return $idAlmacen;
        }
    }

    return 0;
}

/**
 * Determina si una sucursal es apta para pickup de cliente web (punto de venta publico).
 */
function isPublicPickupWarehouseName(string $warehouseName): bool
{
    $name = strtolower(trim($warehouseName));
    if ($name === '') {
        return false;
    }

    if (strpos($name, 'papeler') !== false || strpos($name, 'liz') !== false) {
        return true;
    }

    if (strpos($name, 'central') !== false || strpos($name, 'almacen') !== false || strpos($name, 'luisa') !== false) {
        return false;
    }

    return false;
}

/**
 * Verifica existencia de la tabla de notificaciones de pickup.
 */
function dbPickupNotificationsTableExists(PDO $pdo): bool
{
    static $existsCache = null;
    if ($existsCache !== null) {
        return $existsCache;
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pickup_notificaciones'");
    $stmt->execute();
    $existsCache = ((int)$stmt->fetchColumn()) > 0;
    return $existsCache;
}

/**
 * Crea alerta formal para sucursal cuando un pedido es pickup.
 */
function dbCreatePickupNotification(PDO $pdo, array $data): void
{
    try {
        if (!dbPickupNotificationsTableExists($pdo)) {
            return;
        }

        $idPedido = (int)($data['id_pedido'] ?? 0);
        $idAlmacen = (int)($data['id_almacen'] ?? 0);
        if ($idPedido <= 0 || $idAlmacen <= 0) {
            return;
        }

        $numeroPedido = trim((string)($data['numero_pedido'] ?? ''));
        $clienteNombre = trim((string)($data['cliente_nombre'] ?? 'Cliente'));
        $telefono = trim((string)($data['cliente_telefono'] ?? ''));
        $direccion = trim((string)($data['direccion'] ?? ''));

        $mensaje = 'Pickup en sucursal: Pedido ' . $numeroPedido . ' a nombre de ' . $clienteNombre;
        if ($telefono !== '') {
            $mensaje .= ' | Tel: ' . $telefono;
        }
        if ($direccion !== '') {
            $mensaje .= ' | Ref: ' . $direccion;
        }

        $stmt = $pdo->prepare("INSERT INTO pickup_notificaciones
            (id_pedido, id_almacen, id_cliente, estado, mensaje, notas_seguimiento, creado_en, actualizado_en)
            VALUES
            (:id_pedido, :id_almacen, :id_cliente, 'nueva', :mensaje, :notas, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                estado = 'nueva',
                mensaje = VALUES(mensaje),
                notas_seguimiento = VALUES(notas_seguimiento),
                actualizado_en = NOW()");

        $stmt->execute([
            ':id_pedido' => $idPedido,
            ':id_almacen' => $idAlmacen,
            ':id_cliente' => isset($data['id_cliente']) ? (int)$data['id_cliente'] : null,
            ':mensaje' => $mensaje,
            ':notas' => (string)($data['observaciones'] ?? ''),
        ]);
    } catch (Throwable $e) {
        error_log('Error creando notificacion pickup: ' . $e->getMessage());
    }
}

/**
 * Resuelve el almacén para surtir checkout web (pedidos a domicilio).
 *
 * Los pedidos web SIEMPRE se surten desde el Almacén Central (id 1, "Ubicacion principal" en
 * la tabla almacenes): las demás sucursales manejan su propio inventario para venta en piso y
 * no se debe drenar su stock por ventas en línea, ni tampoco conviene "repartir" el pedido entre
 * varias sucursales solo porque tengan existencia, ya que eso rompe el filtro por almacén que usan
 * los encargados para ver sus propios pedidos. Si el Almacén Central no tiene existencia
 * suficiente, el checkout ya valida el stock producto por producto y regresa un error claro
 * (ver dbCreatePublicOrder) en vez de tomar stock de otra sucursal silenciosamente.
 */
function resolveCheckoutWarehouse(mixed $requestedWarehouseId = null): int
{
    $requestedId = (int)($requestedWarehouseId ?? 0);
    return $requestedId > 0 ? $requestedId : 1;
}

/**
 * IDs de los unicos almacenes que realmente pueden surtir un pedido del sitio publico:
 * el Almacen Central (id 1, destino por default de resolveCheckoutWarehouse() para
 * domicilio) mas cualquier sucursal de pickup publica (ver isPublicPickupWarehouseName()).
 * Existen otros almacenes activos en la tabla `almacenes` que NO son de este negocio
 * (ej. "Papelería Liz" via nombre matchea como pickup y si cuenta, pero "Luisa" y
 * cualquier otro almacen interno/de otro giro no deben contar como stock vendible online
 * -- sin este filtro, un producto agotado en Almacen Central/pickup podia mostrarse como
 * "Disponible" en el catalogo/ficha solo porque tenia existencia en un almacen que el
 * checkout jamas va a usar para surtir esa venta).
 *
 * @return int[]
 */
function getPublicSellableWarehouseIds(PDO $pdo): array
{
    // Sin cache estatico a proposito: es una tabla chica (una decena de filas) y esta
    // funcion puede llamarse con distintas conexiones PDO en pruebas -- un cache aqui
    // devolveria resultados de la primera conexion para todas las siguientes.
    $ids = [1 => true];

    try {
        $stmt = $pdo->query("SELECT id_almacen, nombre FROM almacenes WHERE estado = 'activo'");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $idAlmacen = (int)($row['id_almacen'] ?? 0);
            $nombre = (string)($row['nombre'] ?? '');
            if ($idAlmacen > 0 && isPublicPickupWarehouseName($nombre)) {
                $ids[$idAlmacen] = true;
            }
        }
    } catch (PDOException $e) {
        error_log('getPublicSellableWarehouseIds: no se pudo consultar almacenes: ' . $e->getMessage());
    }

    return array_keys($ids);
}

/**
 * Obtiene la lista de productos para gestión (Admin/Encargado).
 */
function dbGetProductsManaged(): array {
    try {
        $pdo = getPDO();
        $sql = "SELECT p.*, (SELECT nombre FROM productos p2 WHERE p2.id_producto = p.id_padre) as producto_base 
                FROM productos p WHERE estado = 'activo' 
                ORDER BY COALESCE(p.id_padre, p.id_producto), p.id_padre IS NOT NULL, p.nombre";
        return $pdo->query($sql)->fetchAll();
    } catch (PDOException $e) {
        error_log("Error en dbGetProductsManaged: " . $e->getMessage());
        return [];
    }
}

/**
 * Obtiene productos para el catálogo público.
 * Filtra para mostrar solo productos principales (sin padre) o variantes únicas.
 */
function dbGetCatalogProducts(?int $id_categoria = null): array {
    try {
        $pdo = getPDO();
        $sql = "SELECT p.*, 
                (SELECT COUNT(*) FROM productos p2 WHERE p2.id_padre = p.id_producto) as total_presentaciones,
                (SELECT MIN(precio_venta) FROM productos p3 WHERE p3.id_padre = p.id_producto OR p3.id_producto = p.id_producto) as precio_desde
                FROM productos p 
                WHERE p.estado = 'activo' AND p.id_padre IS NULL";
        
        $params = [];
        if ($id_categoria) {
            $sql .= " AND p.id_producto IN (SELECT id_producto FROM producto_categorias WHERE id_categoria = ?)";
            $params[] = $id_categoria;
        }

        $sql .= " ORDER BY p.nombre ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error en dbGetCatalogProducts: " . $e->getMessage());
        return [];
    }
}

/**
 * Obtiene todas las presentaciones (variantes) de un producto específico.
 */
function dbGetProductPresentations(int $id_producto): array {
    try {
        $pdo = getPDO();
        
        $stmtInfo = $pdo->prepare("SELECT nombre FROM productos WHERE id_producto = ?");
        $stmtInfo->execute([$id_producto]);
        $nombre_base = $stmtInfo->fetchColumn();

        if (!$nombre_base) return [];

        // Agrupamos por nombre exacto para corregir errores de jerarquía en DB
        $sql = "SELECT * FROM productos 
                WHERE estado = 'activo' 
                AND TRIM(nombre) = ?
                ORDER BY precio_venta ASC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([trim($nombre_base)]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error en dbGetProductPresentations: " . $e->getMessage());
        return [];
    }
}

/**
 * CENTRALIZACIÓN: Obtiene logs con filtros aplicados (Protección contra Inyección SQL).
 */
function dbGetActivityLogs(array $filters): array {
    $pdo = getPDO();
    $query = "SELECT l.*, u.nombre as usuario_nombre, u.email as usuario_email 
              FROM logs_actividad l 
              JOIN usuarios u ON l.id_usuario = u.id_usuario 
              WHERE 1=1";
    $params = [];

    if (($filters['usuario'] ?? 0) > 0) {
        $query .= " AND l.id_usuario = :id_u";
        $params[':id_u'] = $filters['usuario'];
    }
    if (!empty($filters['tipo'])) {
        $query .= " AND l.tipo_accion = :t";
        $params[':t'] = $filters['tipo'];
    }
    if (!empty($filters['inicio'])) {
        $query .= " AND DATE(l.fecha_creacion) >= :ini";
        $params[':ini'] = $filters['inicio'];
    }
    if (!empty($filters['fin'])) {
        $query .= " AND DATE(l.fecha_creacion) <= :fin";
        $params[':fin'] = $filters['fin'];
    }

    $query .= " ORDER BY l.fecha_creacion DESC LIMIT 500";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * CENTRALIZACIÓN: Gestión de Blogs (CRUD Seguro).
 */
function dbGetBlogs(bool $publishedOnly = true): array {
    $pdo = getPDO();
    $sql = "SELECT * FROM blogs " . ($publishedOnly ? "WHERE estado = 'publicado'" : "") . " ORDER BY fecha_creacion DESC";
    return $pdo->query($sql)->fetchAll();
}

function dbGetBlogBySlug(string $slug): ?array {
    $pdo = getPDO();
    $stmt = $pdo->prepare("SELECT * FROM blogs WHERE slug = ? AND estado = 'publicado'");
    $stmt->execute([$slug]);
    $res = $stmt->fetch();
    return $res ?: null;
}

function dbSaveBlog(array $data): bool {
    $pdo = getPDO();
    if ((int)($data['id'] ?? 0) > 0) {
        $stmt = $pdo->prepare("UPDATE blogs SET titulo = ?, slug = ?, extracto = ?, contenido = ?, estado = ? WHERE id_blog = ?");
        return $stmt->execute([$data['titulo'], $data['slug'], $data['extracto'], $data['contenido'], $data['estado'], $data['id']]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO blogs (id_usuario, titulo, slug, extracto, contenido, estado) VALUES (?, ?, ?, ?, ?, ?)");
        return $stmt->execute([$data['id_usuario'], $data['titulo'], $data['slug'], $data['extracto'], $data['contenido'], $data['estado']]);
    }
}

/**
 * CENTRALIZACIÓN: Lógica de presencia del Chat.
 */
function dbUpdateChatStatus(int $id_cliente, string $action, ?int $id_staff = null): bool {
    $pdo = getPDO();
    $nuevo_estado = ($action === 'start') ? 1 : 0;
    $sql = "UPDATE usuarios SET soporte_activo = ?";
    $params = [$nuevo_estado];

    if ($action === 'close') {
        $sql .= ", asignado_a = NULL";
    }
    if ($id_staff !== null) {
        $sql .= ", asignado_a = ?";
        $params[] = $id_staff;
    }

    $sql .= " WHERE id_usuario = ?";
    $params[] = $id_cliente;

    return $pdo->prepare($sql)->execute($params);
}

/**
 * Asocia categorías a un producto (Relación Muchos a Muchos).
 */
function dbSetProductCategories(int $id_producto, array $ids_categorias): bool {
    try {
        $pdo = getPDO();
        $pdo->beginTransaction();
        
        // Limpiar asociaciones previas
        $stmt = $pdo->prepare("DELETE FROM producto_categorias WHERE id_producto = ?");
        $stmt->execute([$id_producto]);
        
        // Insertar nuevas
        if (!empty($ids_categorias)) {
            $stmtInsert = $pdo->prepare("INSERT INTO producto_categorias (id_producto, id_categoria) VALUES (?, ?)");
            foreach ($ids_categorias as $id_cat) {
                $stmtInsert->execute([$id_producto, (int)$id_cat]);
            }
        }
        
        $pdo->commit();
        return true;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Error en dbSetProductCategories: " . $e->getMessage());
        return false;
    }
}

/**
 * Crea una nueva categoría maestra (Solo Admin).
 */
function dbCreateCategory(string $nombre): bool {
    if (!isAdmin()) return false;
    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare("INSERT INTO categorias (nombre) VALUES (?) ON DUPLICATE KEY UPDATE estado = 'activo'");
        return $stmt->execute([trim($nombre)]);
    } catch (PDOException $e) {
        error_log("Error en dbCreateCategory: " . $e->getMessage());
        return false;
    }
}

/**
 * Obtiene todas las categorías activas de la tabla maestra.
 */
function dbGetCategories(): array {
    try {
        $pdo = getPDO();
        $sql = "SELECT * FROM categorias WHERE estado = 'activo' ORDER BY nombre ASC";
        $stmt = $pdo->query($sql);
        return $stmt ? $stmt->fetchAll() : [];
    } catch (PDOException $e) {
        error_log("Error en dbGetCategories: " . $e->getMessage());
        return [];
    }
}

/**
 * Obtiene todos los tipos de presentación (variantes) de la tabla maestra.
 */
function dbGetPresentationTypes(): array {
    try {
        $pdo = getPDO();
        $sql = "SELECT * FROM tipos_presentacion ORDER BY nombre ASC";
        return $pdo->query($sql)->fetchAll();
    } catch (PDOException $e) {
        error_log("Error en dbGetPresentationTypes: " . $e->getMessage());
        return [];
    }
}

/**
 * Obtiene los productos base (que no son variantes de otros) para ser usados como padres.
 */
function dbGetParentProducts(): array {
    try {
        $pdo = getPDO();
        // Permitimos que cualquier producto activo o archivado aparezca en la lista de padres.
        // Esto permite rescatar productos que fueron mal asociados anteriormente.
        $sql = "SELECT id_producto, nombre, sku, nombre_variante FROM productos WHERE estado != 'inactivo' ORDER BY nombre ASC";
        $stmt = $pdo->query($sql);
        return $stmt ? $stmt->fetchAll() : [];
    } catch (PDOException $e) {
        error_log("Error en dbGetParentProducts: " . $e->getMessage());
        return [];
    }
}

/**
 * Verifica si una contraseña cumple con los estándares de seguridad.
 * Mínimo 10 caracteres, una mayúscula, una minúscula, un número y un símbolo.
 *
 * @param string $password
 * @return bool
 */
function isPasswordSecure(string $password): bool {
    return strlen($password) >= 10 &&
           preg_match('/[A-Z]/', $password) &&
           preg_match('/[a-z]/', $password) &&
           preg_match('/[0-9]/', $password) &&
           preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password);
}

/**
 * Genera un nombre de carpeta amigable (slug) para el producto
 */
function slugify(string $text): string {
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '-', $text), '-'));
    return empty($slug) ? 'producto' : $slug;
}

function getDefaultProductImageUrl(): string {
    static $dataUri = null;

    if ($dataUri === null) {
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 600" role="img" aria-labelledby="title desc"><title>Producto sin imagen</title><desc>Placeholder para productos sin imagen disponible</desc><rect width="600" height="600" rx="32" fill="#f4f4f4"/><rect x="90" y="120" width="420" height="300" rx="24" fill="#e0e0e0"/><circle cx="205" cy="225" r="42" fill="#c7c7c7"/><path d="M140 380 240 280l70 70 60-55 90 85H140z" fill="#b5b5b5"/><path d="M210 470h180" stroke="#b0b0b0" stroke-width="18" stroke-linecap="round"/><text x="300" y="525" text-anchor="middle" font-family="Arial, Helvetica, sans-serif" font-size="30" fill="#8a8a8a">Sin imagen</text></svg>';
        $dataUri = 'data:image/svg+xml;utf8,' . rawurlencode($svg);
    }

    return $dataUri;
}

/**
 * Devuelve el mapa id_producto => [nombres de carpeta] de assets/img/products,
 * respaldado en disco para no repetir un scandir de todas las carpetas de
 * producto en cada request. Se invalida comparando el filemtime del directorio
 * base (crear una carpeta nueva, p. ej. al subir una imagen, lo actualiza de
 * forma confiable) y ademas por antiguedad maxima (15 min): en algunos
 * filesystems (NTFS en particular) borrar una subcarpeta no siempre actualiza
 * el mtime del padre, asi que el TTL evita que el indice quede obsoleto para
 * siempre.
 *
 * $cacheFile es opcional (por defecto core/cache/product_image_folder_index.json)
 * unicamente para permitir pruebas unitarias con un directorio y archivo de
 * cache temporales; el codigo de la app nunca lo pasa explicitamente.
 */
function productImageFolderIndex(string $baseDir, ?string $cacheFile = null): array {
    static $cached = [];

    $cacheFile = $cacheFile ?? (__DIR__ . '/cache/product_image_folder_index.json');
    // Se indexa por $baseDir+$cacheFile (no solo un valor unico) para que la
    // memoria de una llamada nunca se filtre a otra con un directorio distinto.
    $cacheKey = $baseDir . '|' . $cacheFile;
    if (isset($cached[$cacheKey])) {
        return $cached[$cacheKey];
    }

    $ttlSeconds = 900;

    $dirMtime = @filemtime($baseDir);
    if ($dirMtime === false) {
        return $cached[$cacheKey] = [];
    }

    $cacheRaw = @file_get_contents($cacheFile);
    if ($cacheRaw !== false) {
        $decoded = json_decode($cacheRaw, true);
        $isFresh = is_array($decoded)
            && ($decoded['dir_mtime'] ?? null) === $dirMtime
            && is_array($decoded['map'] ?? null)
            && (time() - (int)($decoded['built_at'] ?? 0)) < $ttlSeconds;
        if ($isFresh) {
            return $cached[$cacheKey] = $decoded['map'];
        }
    }

    $map = [];
    $entries = @scandir($baseDir);
    if (is_array($entries)) {
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $fullPath = $baseDir . DIRECTORY_SEPARATOR . $entry;
            if (!is_dir($fullPath)) {
                continue;
            }
            if (preg_match('/-(\d+)$/', $entry, $m)) {
                $id = (int)$m[1];
                if (!isset($map[$id])) {
                    $map[$id] = [];
                }
                $map[$id][] = $entry;
            }
        }
    }

    $cacheDir = dirname($cacheFile);
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }
    @file_put_contents($cacheFile, json_encode(['dir_mtime' => $dirMtime, 'built_at' => time(), 'map' => $map]), LOCK_EX);

    return $cached[$cacheKey] = $map;
}

/**
 * Resuelve la URL de la imagen de un producto de forma robusta.
 */
function findProductImageById(int $productId, string $preferredFileName = ''): ?string {
    if ($productId <= 0) {
        return null;
    }

    $baseDir = __DIR__ . '/../assets/img/products';
    if (!is_dir($baseDir)) {
        return null;
    }

    $foldersById = productImageFolderIndex($baseDir);

    $candidateFolders = $foldersById[$productId] ?? [];
    if (empty($candidateFolders)) {
        return null;
    }

    $preferredFileName = trim($preferredFileName);
    $preferredStem = pathinfo($preferredFileName, PATHINFO_FILENAME);

    foreach ($candidateFolders as $folder) {
        $folderPath = $baseDir . DIRECTORY_SEPARATOR . $folder;
        if ($preferredFileName !== '') {
            $exactPath = $folderPath . DIRECTORY_SEPARATOR . $preferredFileName;
            if (is_file($exactPath)) {
                return $folder . '/' . $preferredFileName;
            }
        }

        if ($preferredStem !== '') {
            $stemMatches = glob($folderPath . DIRECTORY_SEPARATOR . $preferredStem . '.*');
            if (is_array($stemMatches)) {
                foreach ($stemMatches as $match) {
                    if (is_file($match)) {
                        return $folder . '/' . basename($match);
                    }
                }
            }
        }

        $principalMatches = glob($folderPath . DIRECTORY_SEPARATOR . 'principal.*');
        if (is_array($principalMatches)) {
            foreach ($principalMatches as $match) {
                if (is_file($match)) {
                    return $folder . '/' . basename($match);
                }
            }
        }

        $files = glob($folderPath . DIRECTORY_SEPARATOR . '*.{jpg,jpeg,png,webp,gif,svg}', GLOB_BRACE);
        if (is_array($files)) {
            foreach ($files as $match) {
                if (is_file($match)) {
                    return $folder . '/' . basename($match);
                }
            }
        }
    }

    return null;
}

function resolveLocalProductImagePath(string $imgData): ?string {
    $baseDir = __DIR__ . '/../assets/img/products';
    if (!is_dir($baseDir)) {
        return null;
    }

    $normalized = str_replace('\\', '/', trim($imgData));
    if ($normalized === '') {
        return null;
    }

    $normalized = strtok($normalized, '?#') ?: '';
    $normalized = ltrim($normalized, '/');

    $prefix = 'assets/img/products/';
    if (stripos($normalized, $prefix) === 0) {
        $normalized = substr($normalized, strlen($prefix));
    }

    if ($normalized === '') {
        return null;
    }

    $fullPath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
    if (is_file($fullPath)) {
        return $normalized;
    }

    $fileName = basename($normalized);
    $dirPart = trim(dirname($normalized), '.\/');
    $fileStem = pathinfo($fileName, PATHINFO_FILENAME);

    if ($dirPart !== '') {
        $dirPath = $baseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dirPart);
        if (is_dir($dirPath) && $fileStem !== '') {
            $stemMatches = glob($dirPath . DIRECTORY_SEPARATOR . $fileStem . '.*');
            if (is_array($stemMatches)) {
                foreach ($stemMatches as $match) {
                    if (is_file($match)) {
                        return $dirPart . '/' . basename($match);
                    }
                }
            }
        }
    }

    if (preg_match('/-(\d+)(?:\/|$)/', $normalized, $m)) {
        $fallback = findProductImageById((int)$m[1], $fileName);
        if ($fallback !== null) {
            return $fallback;
        }
    }

    if ($fileName !== '') {
        $rootPath = $baseDir . DIRECTORY_SEPARATOR . $fileName;
        if (is_file($rootPath)) {
            return $fileName;
        }
    }

    return null;
}

function getProductImageUrl(?string $imgData, ?int $productId = null): string {
    $imgData = trim((string)$imgData);
    $normalizedProductId = $productId !== null ? (int)$productId : 0;

    $resolveByProductId = static function (int $id): ?string {
        if ($id <= 0) {
            return null;
        }
        $fallbackPath = findProductImageById($id, 'principal.webp');
        if ($fallbackPath === null) {
            return null;
        }
        return rtrim(BASE_URL, '/') . '/assets/img/products/' . ltrim(str_replace('\\', '/', $fallbackPath), '/');
    };

    if (empty($imgData) || in_array($imgData, ['NULL', 'undefined', '[object Object]', 'null', ''])) {
        $byIdUrl = $resolveByProductId($normalizedProductId);
        if ($byIdUrl !== null) {
            return $byIdUrl;
        }
        return getDefaultProductImageUrl();
    }

    // Normaliza referencias antiguas al placeholder en PNG/JPG.
    if (preg_match('#(^|[\\/])default-product\.(png|jpe?g)$#i', $imgData)) {
        $byIdUrl = $resolveByProductId($normalizedProductId);
        if ($byIdUrl !== null) {
            return $byIdUrl;
        }
        return getDefaultProductImageUrl();
    }

    // Si ya es una URL completa (http o https), devolverla tal cual
    if (strpos($imgData, 'http') === 0) return $imgData;

    // Detección robusta de Base64 (PNG, JPG, WebP o data-uri)
    // UklGR = WebP | iVBORw = PNG | /9j/ = JPG
    if (preg_match('/^(data:image|iVBORw|\/9j\/|UklGR)/', $imgData)) {
        if (strpos($imgData, 'data:image') === 0) return $imgData;
        $mime = 'image/jpeg';
        if (strpos($imgData, 'iVBORw') === 0) $mime = 'image/png';
        if (strpos($imgData, 'UklGR') === 0) $mime = 'image/webp';
        return "data:$mime;base64," . $imgData;
    }

    // Si no es ninguna de las anteriores, intentar resolver una ruta local robusta
    if (strpos($imgData, '/') !== false || strpos($imgData, '\\') !== false || preg_match('/\.(jpg|jpeg|png|webp|gif|svg)$/i', $imgData)) {
        $base = rtrim(BASE_URL, '/') . '/';
        $resolvedLocalPath = resolveLocalProductImagePath($imgData);
        if ($resolvedLocalPath !== null) {
            return $base . 'assets/img/products/' . ltrim(str_replace('\\', '/', $resolvedLocalPath), '/');
        }
        $byIdUrl = $resolveByProductId($normalizedProductId);
        if ($byIdUrl !== null) {
            return $byIdUrl;
        }
        return $base . 'assets/img/products/' . ltrim(str_replace('\\', '/', $imgData), '/');
    }

    $byIdUrl = $resolveByProductId($normalizedProductId);
    if ($byIdUrl !== null) {
        return $byIdUrl;
    }

    return '';
}

/**
 * Obtiene los productos para el catálogo con filtros de seguridad aplicados.
 * Migrado desde views/catalogo.php para mayor seguridad.
 */
function dbGetCatalogFiltered(string $categoria = '', string $busqueda = ''): array {
    $pdo = getPDO();
    
    $sql = "SELECT p.*, 
        COALESCE(
            (SELECT pi_sub.ruta_archivo FROM producto_imagenes pi_sub INNER JOIN productos p_img_sub ON pi_sub.id_producto = p_img_sub.id_producto WHERE (p_img_sub.id_producto = p.id_producto OR p_img_sub.id_padre = p.id_producto) ORDER BY (p_img_sub.id_producto = p.id_producto) DESC, pi_sub.orden ASC LIMIT 1),
            p.imagen, p.imagen_url
        ) as calculated_imagen,
        (SELECT MIN(p3.precio_venta) FROM productos p3 WHERE (p3.id_producto = p.id_producto OR p3.id_padre = p.id_producto) AND p3.estado = 'activo') as precio_desde,
        (SELECT COUNT(*) FROM productos p2 WHERE (p2.id_producto = p.id_producto OR p2.id_padre = p.id_producto) AND p2.estado = 'activo') as total_variantes,
        (SELECT COALESCE(SUM(ia_sub.cantidad_actual), 0) FROM inventario_almacen ia_sub JOIN productos p_all ON ia_sub.id_producto = p_all.id_producto WHERE p_all.id_producto = p.id_producto OR p_all.id_padre = p.id_producto) as total_stock
        FROM productos p ";
    
    $params = []; 
    $whereClauses = ["p.estado = 'activo'", "(p.id_padre IS NULL OR p.id_padre = 0)"];

    if (!empty($categoria)) {
        $sql .= " JOIN producto_categorias pc ON p.id_producto = pc.id_producto 
                  JOIN categorias c ON pc.id_categoria = c.id_categoria ";
        $whereClauses[] = "c.nombre = :cat";
        $params[':cat'] = $categoria;
    }

    if (!empty($busqueda)) {
        $whereClauses[] = "(p.nombre LIKE :search OR p.sku LIKE :search OR p.descripcion LIKE :search OR EXISTS (
            SELECT 1 FROM productos p_v 
            WHERE p_v.id_padre = p.id_producto AND (p_v.nombre_variante LIKE :search OR p_v.sku LIKE :search)
        ))";
        $params[':search'] = '%' . $busqueda . '%';
    }

    $sql .= " WHERE " . implode(" AND ", $whereClauses);
    $sql .= " GROUP BY p.id_producto ORDER BY p.nombre ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Obtiene el reporte de ventas con filtros de seguridad.
 */
function dbGetSalesReport(string $inicio, string $fin, ?int $idAlmacen = null, ?int $idUsuario = null, bool $isAdmin = false): array {
    $pdo = getPDO();
    $sql = "SELECT p.id_pedido, p.numero_pedido, p.total, p.fecha_creacion, u.nombre as vendedor, a.nombre as almacen, mp.nombre as metodo,
                   COALESCE((
                       SELECT GROUP_CONCAT(
                           CONCAT(
                               pr.nombre,
                               CASE
                                   WHEN COALESCE(pr.nombre_variante, '') <> '' THEN CONCAT(' - ', pr.nombre_variante)
                                   ELSE ''
                               END,
                               ' x', dp.cantidad
                           )
                           ORDER BY dp.id_detalle SEPARATOR ' | '
                       )
                       FROM detalle_pedidos dp
                       INNER JOIN productos pr ON dp.id_producto = pr.id_producto
                       WHERE dp.id_pedido = p.id_pedido
                   ), 'Sin detalle') as productos_vendidos
            FROM pedidos p
            JOIN usuarios u ON p.id_usuario = u.id_usuario
            JOIN almacenes a ON p.id_almacen = a.id_almacen
            LEFT JOIN metodos_pago mp ON p.id_metodo_pago = mp.id_metodo
            WHERE DATE(p.fecha_creacion) BETWEEN :inicio AND :fin
            AND p.estado != 'cancelado'";

    $params = [':inicio' => $inicio, ':fin' => $fin];

    if (!$isAdmin) {
        $sql .= " AND (p.id_usuario = :usuario OR p.id_almacen = :almacen)";
        $params[':usuario'] = $idUsuario;
        $params[':almacen'] = $idAlmacen;
    }

    $sql .= " ORDER BY p.fecha_creacion DESC LIMIT 1000";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Obtiene productos con stock para una sucursal específica.
 */
function dbGetInventoryProducts(int $idAlmacen): array {
    $pdo = getPDO();
    $sql = "SELECT p.id_producto, p.nombre, p.sku, ia.cantidad_actual, ia.stock_minimo, ia.stock_maximo 
            FROM productos p 
            JOIN inventario_almacen ia ON p.id_producto = ia.id_producto 
            WHERE ia.id_almacen = :almacen AND p.estado = 'activo'
            ORDER BY p.nombre ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':almacen' => $idAlmacen]);
    return $stmt->fetchAll();
}
