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
