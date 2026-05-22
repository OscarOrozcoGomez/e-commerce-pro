<?php
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

try {
    $pdo = getPDO();
    
    echo "<h3>Sincronizando Base de Datos...</h3>";

    // 1. Crear tabla password_resets (Para recuperación de contraseña)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `password_resets` (
      `id_password_reset` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `email` VARCHAR(150) NOT NULL,
      `token_hash` VARCHAR(255) NOT NULL,
      `expires_at` DATETIME NOT NULL,
      `usado` TINYINT(1) NOT NULL DEFAULT 0,
      `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id_password_reset`),
      INDEX `idx_password_resets_email` (`email`),
      INDEX `idx_password_resets_token_hash` (`token_hash`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;");
    echo "✅ Tabla 'password_resets' verificada.<br>";

    // 2. Crear tabla categorias (Maestra)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `categorias` (
      `id_categoria` INT AUTO_INCREMENT PRIMARY KEY,
      `nombre` VARCHAR(100) NOT NULL UNIQUE,
      `estado` ENUM('activo', 'inactivo') DEFAULT 'activo'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;");
    echo "✅ Tabla 'categorias' verificada.<br>";

    // 3. Crear tabla producto_categorias (Relación muchos a muchos)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `producto_categorias` (
      `id_producto` INT NOT NULL,
      `id_categoria` INT NOT NULL,
      PRIMARY KEY (`id_producto`, `id_categoria`),
      INDEX (`id_producto`),
      INDEX (`id_categoria`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;");
    echo "✅ Tabla 'producto_categorias' verificada.<br>";

    // 4. Crear tabla logs_auditoria (Para registro de acciones)
    $pdo->exec("CREATE TABLE IF NOT EXISTS `logs_auditoria` (
      `id_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `id_usuario` INT UNSIGNED NULL,
      `accion` VARCHAR(50) NOT NULL,
      `tabla_afectada` VARCHAR(50) NOT NULL,
      `id_registro` INT NULL,
      `detalles` TEXT,
      `ip_address` VARCHAR(45),
      `fecha` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (`id_log`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
    echo "✅ Tabla 'logs_auditoria' verificada.<br>";

    echo "<br><p><strong>Todo listo.</strong> Ya puedes intentar enviar el código de recuperación nuevamente.</p>";
    echo "<a href='../views/forgot_password.php'>Volver a Recuperar Contraseña</a>";

} catch (Exception $e) {
    echo "<p style='color:red;'>Error: " . $e->getMessage() . "</p>";
}