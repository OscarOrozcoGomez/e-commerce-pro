<?php
require_once __DIR__ . '/../core/config.php';
require_once __DIR__ . '/../core/auth.php';
try {
    $pdo = getPDO();
    
    // Create producto_categorias
    $pdo->exec('CREATE TABLE IF NOT EXISTS `producto_categorias` (
      `id_producto` INT UNSIGNED NOT NULL,
      `id_categoria` INT NOT NULL,
      PRIMARY KEY (`id_producto`, `id_categoria`),
      CONSTRAINT `fk_pc_producto` FOREIGN KEY (`id_producto`) REFERENCES `productos` (`id_producto`) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT `fk_pc_categoria` FOREIGN KEY (`id_categoria`) REFERENCES `categorias` (`id_categoria`) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;');
    
    echo "Tabla producto_categorias creada con exito.";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
