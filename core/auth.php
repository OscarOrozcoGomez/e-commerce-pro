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
                   GROUP_CONCAT(p.clave) as permisos,
                   c.id_cliente,
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

        // ÉXITO: Limpiamos los intentos fallidos y el bloqueo
        $pdo->prepare("UPDATE usuarios SET intentos_fallidos = 0, bloqueado_hasta = NULL WHERE id_usuario = ?")
            ->execute([$user['id_usuario']]);

        $user['permisos'] = $user['permisos'] ? explode(',', $user['permisos']) : [];
        $_SESSION['usuario'] = $user;
        session_regenerate_id(true); // SEGURIDAD: Evita ataques de fijación de sesión
        
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

    $pdo->prepare("UPDATE usuarios SET intentos_fallidos = ?, bloqueado_hasta = ? WHERE id_usuario = ?")
        ->execute([$nuevosIntentos, $nuevaFechaBloqueo, $user['id_usuario']]);

    return false;
}

/**
 * Cierra la sesión del usuario.
 *
 * @return void
 */
function logout(): void
{
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
    session_write_close(); // Liberar el bloqueo del archivo inmediatamente
    session_destroy();
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
    $subject = 'Código de recuperación de contraseña';
    $message = "Tu código de seguridad es: {$token}\n\n" .
               "Ingrésalo en la página para restablecer tu contraseña.\n" .
               "Si no solicitaste esto, ignora este mensaje.\n";

    // SIMULACIÓN PARA XAMPP / LOCALHOST
    // Guardamos el "email" en un archivo local para que puedas verlo sin configurar servidores
    $logPath = __DIR__ . '/../mail_log.txt';
    $logContent = "========================================\n" .
                  "FECHA: " . date('Y-m-d H:i:s') . "\n" .
                  "PARA: $email\n" .
                  "ASUNTO: $subject\n" .
                  "MENSAJE: $message\n" .
                  "========================================\n\n";
    
    file_put_contents($logPath, $logContent, FILE_APPEND);

    // En producción, aquí usarías mail() o PHPMailer. 
    // Por ahora retornamos true para que el flujo continúe en XAMPP.
    return true;
}

/**
 * Crea un pedido público (Checkout) encapsulando la lógica SQL.
 */
function dbCreatePublicOrder(array $data): array {
    $pdo = getPDO();
    try {
        $pdo->beginTransaction();
        
        // Definir el almacén de despacho (por defecto 1, o podrías obtenerlo de la configuración)
        $id_almacen_despacho = $data['id_almacen'] ?? 1;
        $id_usuario = $data['id_usuario'] ?? 1; // Asignar al Admin (ID 1) si no hay un vendedor físico
        $id_cliente = $data['id_cliente'] ?? null; // Vincular al perfil del cliente si está logueado

        $entrega = $data['tipo_entrega'] ?? 'No especificado';
        $infoCliente = "ENTREGA: {$entrega} | Cliente: {$data['cliente']['nombre']} | Tel: {$data['cliente']['telefono']} | Dir: {$data['cliente']['direccion']}";
        $subtotal = array_reduce($data['items'], fn($s, $i) => $s + ($i['precio'] * $i['quantity']), 0);
        $numero_pedido = 'WEB-' . strtoupper(uniqid());

        // Corregido: id_usuario no puede ser NULL según la estructura de la tabla pedidos
        $stmt = $pdo->prepare("INSERT INTO pedidos (numero_pedido, id_usuario, id_cliente, id_almacen, id_metodo_pago, estado, subtotal, total, observaciones) VALUES (?, ?, ?, ?, 1, 'pendiente_pago', ?, ?, ?)");
        $stmt->execute([$numero_pedido, $id_usuario, $id_cliente, $id_almacen_despacho, $subtotal, $subtotal, $infoCliente]);
        $id_pedido = $pdo->lastInsertId();

        $stmtDetalle = $pdo->prepare("INSERT INTO detalle_pedidos (id_pedido, id_producto, cantidad, precio_original, precio_unitario, subtotal) VALUES (?, ?, ?, ?, ?, ?)");
        $stmtStock = $pdo->prepare("UPDATE inventario_almacen SET cantidad_actual = cantidad_actual - ? WHERE id_producto = ? AND id_almacen = ?");

        foreach ($data['items'] as $item) {
            $lineTotal = $item['precio'] * $item['quantity'];
            $stmtDetalle->execute([$id_pedido, $item['id_producto'], $item['quantity'], $item['precio'], $item['precio'], $lineTotal]);
            $stmtStock->execute([$item['quantity'], $item['id_producto'], $id_almacen_despacho]);
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
function dbGetProductPresentations(int $id_producto_padre): array {
    try {
        $pdo = getPDO();
        $stmt = $pdo->prepare("SELECT * FROM productos WHERE (id_padre = ? OR id_producto = ?) AND estado = 'activo' ORDER BY precio_venta ASC");
        $stmt->execute([$id_producto_padre, $id_producto_padre]);
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
        return $pdo->query($sql)->fetchAll();
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
        $sql = "SELECT id_producto, nombre, sku, nombre_variante FROM productos WHERE id_padre IS NULL AND estado = 'activo' ORDER BY nombre ASC";
        return $pdo->query($sql)->fetchAll();
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

/**
 * Resuelve la URL de la imagen de un producto de forma robusta.
 */
function getProductImageUrl(?string $imgData): string {
    $default = BASE_URL . 'assets/img/no-product.png';
    if (empty($imgData)) return $default;

    // Si es una ruta de archivo (formato: carpeta-ID/archivo.jpg)
    if (strlen($imgData) < 255 && preg_match('/\.(jpg|jpeg|png|webp)$/i', $imgData)) {
        return BASE_URL . 'assets/img/products/' . $imgData;
    }

    // Si es Base64 (compatibilidad con datos antiguos)
    if (strpos($imgData, 'iVBORw') !== false || strpos($imgData, '/9j/') !== false || strpos($imgData, 'data:image') !== false) {
        // Si ya tiene el prefijo data:image, devolver tal cual
        if (strpos($imgData, 'data:image') === 0) return $imgData;
        
        $mime = (strpos($imgData, 'iVBORw') === 0) ? 'image/png' : 'image/jpeg';
        // Limpiar el contenido si trae el encabezado
        $clean = (strpos($imgData, ',') !== false) ? explode(',', $imgData)[1] : $imgData;
        return "data:$mime;base64," . trim($clean);
    }

    return $default;
}
