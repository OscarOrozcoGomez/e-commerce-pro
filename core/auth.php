<?php
declare(strict_types=1);

require_once __DIR__ . '/pickup_offer_utils.php';

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
 * @param string $email
 * @param string $password
 * @return bool
 */
function authenticate(string $email, string $password): bool
{
    $pdo = getPDO();

    // Evita esperas largas por bloqueos InnoDB durante login bajo carga.
    try {
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 3');
    } catch (Throwable $e) {
        // Continuar si el motor/usuario no permite cambiar esta variable.
    }

    // Se añadió u.contrasena a la lista de columnas seleccionadas
    $sql = "SELECT u.id_usuario, u.nombre, u.email, u.contrasena, u.id_rol, u.id_almacen, r.nombre as rol, 
                   GROUP_CONCAT(p.clave) as permisos,
                   c.id_cliente,
                 c.telefono as telefono_cliente,
                 u.intentos_fallidos, u.bloqueado_hasta
            FROM usuarios u
            JOIN roles r ON u.id_rol = r.id_rol
            LEFT JOIN rol_permisos rp ON r.id_rol = rp.id_rol
            LEFT JOIN permisos p ON rp.id_permiso = p.id_permiso
            LEFT JOIN clientes c ON u.id_usuario = c.id_usuario
            WHERE u.email = :email AND u.estado = 'activo'
            GROUP BY u.id_usuario";

    try {
        error_log("DEBUG LOGIN: Intentando autenticar a: " . $email);
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':email' => $email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("DEBUG LOGIN ERROR SQL: " . $e->getMessage());
        return false;
    }

    if (!$user) {
        error_log("DEBUG LOGIN: Usuario no encontrado o inactivo en la BD para: " . $email);
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

    // Generar un código de 6 dígitos para mejor UX sin links
    $code = (string)random_int(100000, 999999);
    $tokenHash = hash('sha256', $code);
    $expiresAt = date('Y-m-d H:i:s', time() + 3600);

    $pdo->prepare('DELETE FROM password_resets WHERE email = :email')->execute([':email' => $email]);
    $stmt = $pdo->prepare('INSERT INTO password_resets (email, token_hash, expires_at, usado, created_at) VALUES (:email, :token_hash, :expires_at, 0, NOW())');
    $stmt->execute([
        ':email' => $email,
        ':token_hash' => $tokenHash,
        ':expires_at' => $expiresAt,
    ]);

    return sendPasswordResetEmail($email, $code);
}

function getPasswordResetRecord(string $token): ?array
{
    $pdo = getPDO();
    $tokenHash = hash('sha256', $token);
    $stmt = $pdo->prepare('SELECT * FROM password_resets WHERE token_hash = :token_hash AND usado = 0 AND expires_at >= NOW() LIMIT 1');
    $stmt->execute([':token_hash' => $tokenHash]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    return $record !== false ? $record : null;
}

function resetPasswordWithToken(string $token, string $newPassword, ?string &$errorMessage = null): bool
{
    $errorMessage = null;

    if (!isPasswordSecure($newPassword)) {
        $errorMessage = 'La nueva contraseña debe tener al menos 10 caracteres, incluir mayúsculas, minúsculas, números y un símbolo.';
        return false;
    }

    $record = getPasswordResetRecord($token);
    if (!$record) {
        $errorMessage = 'El código es inválido o ha expirado.';
        return false;
    }

    $pdo = getPDO();
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
 * Crea un pedido público (Checkout) encapsulando la lógica SQL.
 */
function dbCreatePublicOrder(array $data): array {
    $pdo = getPDO();
    try {
        $pdo->beginTransaction();
        
        // Definir el almacén de despacho: si no llega explícito, resolver automáticamente
        // un almacén que pueda surtir todos los productos del carrito.
        $id_almacen_despacho = resolveCheckoutWarehouse($pdo, $data['items'] ?? [], $data['id_almacen'] ?? null);
        $id_usuario = $data['id_usuario'] ?? 1; // Asignar al Admin (ID 1) si no hay un vendedor físico
        $id_cliente = $data['id_cliente'] ?? null; // Vincular al perfil del cliente si está logueado

        $entrega = $data['tipo_entrega'] ?? 'No especificado';
        $infoCliente = "ENTREGA: {$entrega} | Cliente: {$data['cliente']['nombre']} | Tel: {$data['cliente']['telefono']} | Dir: {$data['cliente']['direccion']}";
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

        $numero_pedido = 'WEB-' . strtoupper(uniqid());

        // Corregido: id_usuario no puede ser NULL según la estructura de la tabla pedidos
        $stmt = $pdo->prepare("INSERT INTO pedidos (numero_pedido, id_usuario, id_cliente, id_almacen, id_metodo_pago, estado, subtotal, descuento_total, total, observaciones) VALUES (?, ?, ?, ?, 1, 'pendiente_pago', ?, ?, ?, ?)");
        $stmt->execute([$numero_pedido, $id_usuario, $id_cliente, $id_almacen_despacho, $subtotal, $descuentoTotal, $totalPedido, $infoCliente]);
        $id_pedido = $pdo->lastInsertId();

        if (strcasecmp((string)$entrega, 'Sucursal') === 0) {
            dbCreatePickupNotification($pdo, [
                'id_pedido' => (int)$id_pedido,
                'id_almacen' => (int)$id_almacen_despacho,
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
        $stmtCosto = $pdo->prepare("SELECT COALESCE(precio_costo, 0) FROM productos WHERE id_producto = ?");

        $remainingDiscountCents = (int)round($descuentoTotal * 100);
        $remainingPiecesForDiscount = max(0, $totalPiezas);

        foreach ($data['items'] as $item) {
            $idProducto = (int)($item['id_producto'] ?? 0);
            $cantidad = max(0, (int)($item['quantity'] ?? 0));
            $precio = (float)($item['precio'] ?? 0);

            if ($idProducto <= 0 || $cantidad <= 0 || $precio <= 0) {
                throw new Exception('Producto o cantidad inválidos en el pedido.');
            }

            $stmtStockCheck->execute([$idProducto, $id_almacen_despacho]);
            $stockActual = (int)$stmtStockCheck->fetchColumn();
            if ($stockActual < $cantidad) {
                throw new Exception('Stock insuficiente para uno o más productos.');
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
            $stmtStock->execute([$cantidad, $idProducto, $id_almacen_despacho]);
        }

        $pdo->commit();
        return [
            'success' => true,
            'pedido' => $numero_pedido,
            'id_pedido' => (int)$id_pedido,
        ];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Error en dbCreatePublicOrder: " . $e->getMessage());
        $msg = $e->getMessage();
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
 * Resuelve el almacén para surtir checkout web.
 *
 * Prioridad:
 * 1) Si el frontend envía id_almacen válido, se respeta.
 * 2) Buscar un almacén que cubra todos los productos requeridos.
 * 3) Si no existe uno que cubra todo, devolver 1 y dejar que la validación de stock responda error.
 */
function resolveCheckoutWarehouse(PDO $pdo, array $items, mixed $requestedWarehouseId = null): int
{
    $requestedId = (int)($requestedWarehouseId ?? 0);
    if ($requestedId > 0) {
        return $requestedId;
    }

    $required = [];
    foreach ($items as $item) {
        $idProducto = (int)($item['id_producto'] ?? 0);
        $cantidad = max(0, (int)($item['quantity'] ?? 0));
        if ($idProducto <= 0 || $cantidad <= 0) {
            continue;
        }
        $required[$idProducto] = ($required[$idProducto] ?? 0) + $cantidad;
    }

    if (empty($required)) {
        return 1;
    }

    $selects = [];
    $params = [];
    foreach ($required as $idProducto => $cantidadRequerida) {
        $selects[] = 'SELECT ? AS id_producto, ? AS cantidad_requerida';
        $params[] = $idProducto;
        $params[] = $cantidadRequerida;
    }

    $requiredSql = implode(' UNION ALL ', $selects);
    $requiredCount = count($required);

    $sql = "
        SELECT ia.id_almacen,
               SUM(CASE WHEN ia.cantidad_actual >= req.cantidad_requerida THEN 1 ELSE 0 END) AS cubiertos,
               SUM(ia.cantidad_actual) AS stock_total
        FROM inventario_almacen ia
        INNER JOIN ({$requiredSql}) req ON req.id_producto = ia.id_producto
        GROUP BY ia.id_almacen
        HAVING cubiertos = ?
        ORDER BY stock_total DESC, ia.id_almacen ASC
        LIMIT 1";

    $params[] = $requiredCount;
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $warehouseId = (int)($stmt->fetchColumn() ?: 0);

    return $warehouseId > 0 ? $warehouseId : 1;
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

    static $foldersById = null;
    if ($foldersById === null) {
        $foldersById = [];
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
                    if (!isset($foldersById[$id])) {
                        $foldersById[$id] = [];
                    }
                    $foldersById[$id][] = $entry;
                }
            }
        }
    }

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
