<?php
declare(strict_types=1);

require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';

try {
    $pdo = getPDO();
    echo "Conexión a la base de datos establecida con éxito.\n";

    // 1. Crear tabla blogs
    $sqlTable = "CREATE TABLE IF NOT EXISTS `blogs` (
      `id_blog` INT UNSIGNED NOT NULL AUTO_INCREMENT,
      `titulo` VARCHAR(255) NOT NULL,
      `slug` VARCHAR(255) NOT NULL,
      `extracto` VARCHAR(500) DEFAULT NULL,
      `contenido` LONGTEXT NOT NULL,
      `imagen` LONGTEXT DEFAULT NULL,
      `id_usuario` INT UNSIGNED NOT NULL,
      `estado` ENUM('publicado','borrador') NOT NULL DEFAULT 'publicado',
      `fecha_creacion` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `fecha_actualizacion` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id_blog`),
      UNIQUE KEY `uq_blogs_slug` (`slug`),
      CONSTRAINT `fk_blogs_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;";

    $pdo->exec($sqlTable);
    echo "Tabla 'blogs' creada o verificada correctamente.\n";

    // 2. Insertar el permiso gestionar_blogs
    $sqlPerm = "INSERT IGNORE INTO `permisos` (`clave`, `nombre`, `descripcion`) VALUES 
    ('gestionar_blogs', 'Gestionar Blogs', 'Permite crear, editar y eliminar publicaciones en el blog');";
    $pdo->exec($sqlPerm);
    echo "Permiso 'gestionar_blogs' insertado o verificado correctamente.\n";

    // 3. Asignar el permiso a los roles
    // Obtener id del permiso gestionar_blogs
    $stmt = $pdo->prepare("SELECT id_permiso FROM permisos WHERE clave = 'gestionar_blogs'");
    $stmt->execute();
    $permId = $stmt->fetchColumn();

    if ($permId) {
        // Rol 1: Admin, Rol 2: Encargado
        $sqlAssign = "INSERT IGNORE INTO `rol_permisos` (`id_rol`, `id_permiso`) VALUES (1, :perm_id1), (2, :perm_id2);";
        $stmtAssign = $pdo->prepare($sqlAssign);
        $stmtAssign->execute([':perm_id1' => $permId, ':perm_id2' => $permId]);
        echo "Permiso asignado correctamente a Administradores y Encargados.\n";
    } else {
        echo "ERROR: No se encontró el permiso 'gestionar_blogs'.\n";
    }

    echo "Migración completada con éxito.\n";

} catch (Throwable $e) {
    echo "ERROR en la migración: " . $e->getMessage() . "\n";
}
