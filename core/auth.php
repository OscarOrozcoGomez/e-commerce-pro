<?php
declare(strict_types=1);

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
 * Registra una acción en el log de auditoría.
 */
function logAudit(string $accion, string $tabla, ?int $id_registro, string $detalles): void
{
    try {
        $pdo = getPDO();
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

/**
 * Configura cabeceras de seguridad HTTP esenciales.
 */
function setSecurityHeaders(): void
{
    header("X-Frame-Options: DENY");
    header("X-Content-Type-Options: nosniff");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Content-Security-Policy: default-src 'self'; script-src 'self' https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://cdnjs.cloudflare.com https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com;");
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
 * Obtiene el ID del almacén del usuario actual.
 *
 * @return int|null
 */
function getCurrentAlmacenId(): ?int
{
    return $_SESSION['usuario']['id_almacen'] ?? null;
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

    // Se añadió u.contrasena a la lista de columnas seleccionadas
    $sql = "SELECT u.id_usuario, u.nombre, u.email, u.contrasena, u.id_rol, u.id_almacen, r.nombre as rol,
                   GROUP_CONCAT(p.clave) as permisos
            FROM usuarios u
            JOIN roles r ON u.id_rol = r.id_rol
            LEFT JOIN rol_permisos rp ON r.id_rol = rp.id_rol
            LEFT JOIN permisos p ON rp.id_permiso = p.id_permiso
            WHERE u.email = :email AND u.estado = 'activo'
            GROUP BY u.id_usuario";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':email' => $email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    // Ahora $user['contrasena'] sí tendrá el hash que vimos en image_d12dc4.png
    if ($user && password_verify($password, $user['contrasena'])) {
        $user['permisos'] = $user['permisos'] ? explode(',', $user['permisos']) : [];
        $_SESSION['usuario'] = $user;
        
        logAudit('LOGIN_EXITOSO', 'usuarios', (int)$user['id_usuario'], "Usuario inició sesión");
        return true;
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
    session_destroy();
    header('Location: ' . BASE_URL . 'views/login.php');
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

    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $expiresAt = date('Y-m-d H:i:s', time() + 3600);

    $pdo->prepare('DELETE FROM password_resets WHERE email = :email')->execute([':email' => $email]);
    $stmt = $pdo->prepare('INSERT INTO password_resets (email, token_hash, expires_at, usado, created_at) VALUES (:email, :token_hash, :expires_at, 0, NOW())');
    $stmt->execute([
        ':email' => $email,
        ':token_hash' => $tokenHash,
        ':expires_at' => $expiresAt,
    ]);

    return sendPasswordResetEmail($email, $token);
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

function resetPasswordWithToken(string $token, string $newPassword): bool
{
    $record = getPasswordResetRecord($token);
    if (!$record) {
        return false;
    }

    $pdo = getPDO();
    $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare('UPDATE usuarios SET contrasena = :contrasena WHERE email = :email');
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
        return false;
    }
}

function sendPasswordResetEmail(string $email, string $token): bool
{
    $resetUrl = BASE_URL . 'views/reset_password.php?token=' . urlencode($token);
    $subject = 'Restablecimiento de contraseña';
    $message = "Se ha solicitado el restablecimiento de su contraseña.\n\n" .
               "Por favor, abra el siguiente enlace para establecer una nueva contraseña:\n" .
               "{$resetUrl}\n\n" .
               "Si usted no solicitó este cambio, ignore este mensaje.\n";
    $headers = 'From: no-reply@' . ($_SERVER['SERVER_NAME'] ?? 'localhost') . "\r\n" .
               'Content-Type: text/plain; charset=UTF-8';
    return mail($email, $subject, $message, $headers);
}

/**
 * Crea un pedido público (Checkout) encapsulando la lógica SQL.
 */
function dbCreatePublicOrder(array $data): array {
    $pdo = getPDO();
    try {
        $pdo->beginTransaction();
        
        $infoCliente = "Cliente: {$data['cliente']['nombre']} | Tel: {$data['cliente']['telefono']} | WA: {$data['cliente']['whatsapp']} | Dir: {$data['cliente']['direccion']}";
        $subtotal = array_reduce($data['items'], fn($s, $i) => $s + ($i['precio'] * $i['quantity']), 0);
        $numero_pedido = 'WEB-' . strtoupper(uniqid());

        $stmt = $pdo->prepare("INSERT INTO pedidos (numero_pedido, id_usuario, id_almacen, id_metodo_pago, estado, subtotal, total, observaciones) VALUES (?, NULL, 1, 1, 'pendiente_pago', ?, ?, ?)");
        $stmt->execute([$numero_pedido, $subtotal, $subtotal, $infoCliente]);
        $id_pedido = $pdo->lastInsertId();

        $stmtDetalle = $pdo->prepare("INSERT INTO detalle_pedidos (id_pedido, id_producto, cantidad, precio_unitario, subtotal) VALUES (?, ?, ?, ?, ?)");
        $stmtStock = $pdo->prepare("UPDATE inventario_almacen SET cantidad_actual = cantidad_actual - ? WHERE id_producto = ? AND id_almacen = 1");

        foreach ($data['items'] as $item) {
            $lineTotal = $item['precio'] * $item['quantity'];
            $stmtDetalle->execute([$id_pedido, $item['id_producto'], $item['quantity'], $item['precio'], $lineTotal]);
            $stmtStock->execute([$item['quantity'], $item['id_producto']]);
        }

        $pdo->commit();
        return ['success' => true, 'pedido' => $numero_pedido];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log("Error en dbCreatePublicOrder: " . $e->getMessage());
        return ['success' => false, 'message' => 'Error interno al procesar pedido'];
    }
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
 * Guarda o actualiza un producto.
 */
function dbSaveProduct(array $data): bool {
    try {
        $pdo = getPDO();
        $sql = "INSERT INTO productos (id_padre, nombre, nombre_variante, sku, codigo_barras, descripcion, unidad, precio_costo, precio_venta, categoria, estado) 
                VALUES (:id_padre, :nombre, :nombre_variante, :sku, :codigo_barras, :descripcion, :unidad, :precio_costo, :precio_venta, :categoria, 'activo')";
        return $pdo->prepare($sql)->execute($data);
    } catch (PDOException $e) {
        error_log("Error en dbSaveProduct: " . $e->getMessage());
        return false;
    }
}
