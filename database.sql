-- database.sql
-- POS ligero multi-almacén para PHP y MariaDB/MySQL

SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_SQL_MODE=@@SQL_MODE;
SET SQL_MODE='STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

CREATE DATABASE IF NOT EXISTS `beautyandwell_prod` CHARACTER SET utf8mb4 COLLATE utf8mb4_spanish_ci;
USE `beautyandwell_prod`;

-- Roles y permisos
CREATE TABLE IF NOT EXISTS `roles` (
  `id_rol` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(50) NOT NULL,
  `descripcion` VARCHAR(200) DEFAULT NULL,
  `estado` ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
  `fecha_creacion` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_rol`),
  UNIQUE KEY `uq_roles_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE IF NOT EXISTS `permisos` (
  `id_permiso` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `clave` VARCHAR(100) NOT NULL,
  `nombre` VARCHAR(100) NOT NULL,
  `descripcion` VARCHAR(255) DEFAULT NULL,
  `estado` ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
  `fecha_creacion` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_permiso`),
  UNIQUE KEY `uq_permisos_clave` (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE IF NOT EXISTS `rol_permisos` (
  `id_rol` INT UNSIGNED NOT NULL,
  `id_permiso` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id_rol`,`id_permiso`),
  CONSTRAINT `fk_rolperm_rol` FOREIGN KEY (`id_rol`) REFERENCES `roles` (`id_rol`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_rolperm_permiso` FOREIGN KEY (`id_permiso`) REFERENCES `permisos` (`id_permiso`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Almacenes
CREATE TABLE IF NOT EXISTS `almacenes` (
  `id_almacen` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(120) NOT NULL,
  `ubicacion` VARCHAR(255) DEFAULT NULL,
  `estado` ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
  `fecha_creacion` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_almacen`),
  UNIQUE KEY `uq_almacenes_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Usuarios
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id_usuario` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(120) NOT NULL,
  `email` VARCHAR(150) NOT NULL,
  `contrasena` VARCHAR(255) NOT NULL,
  `id_rol` INT UNSIGNED NOT NULL,
  `id_almacen` INT UNSIGNED DEFAULT NULL,
  `estado` ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
  `fecha_creacion` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ultimo_tecleo` TIMESTAMP NULL DEFAULT NULL,
  `tecleando_para` INT UNSIGNED DEFAULT NULL COMMENT 'ID del usuario al que se le escribe',
  `soporte_activo` TINYINT(1) NOT NULL DEFAULT 0,
  `asignado_a` INT UNSIGNED DEFAULT NULL COMMENT 'ID del staff que atiende a este cliente',
  PRIMARY KEY (`id_usuario`),
  UNIQUE KEY `uq_usuarios_email` (`email`),
  INDEX `idx_usuarios_rol` (`id_rol`),
  INDEX `idx_usuarios_almacen` (`id_almacen`),
  CONSTRAINT `fk_usuarios_rol` FOREIGN KEY (`id_rol`) REFERENCES `roles` (`id_rol`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_usuarios_almacen` FOREIGN KEY (`id_almacen`) REFERENCES `almacenes` (`id_almacen`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Restablecimiento de contraseñas
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id_password_reset` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(150) NOT NULL,
  `token_hash` VARCHAR(255) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `usado` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_password_reset`),
  INDEX `idx_password_resets_email` (`email`),
  INDEX `idx_password_resets_token_hash` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Clientes
CREATE TABLE IF NOT EXISTS `clientes` (
  `id_cliente` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(180) NOT NULL,
  `email` VARCHAR(150) DEFAULT NULL,
  `telefono` VARCHAR(60) DEFAULT NULL,
  `direccion` VARCHAR(255) DEFAULT NULL,
  `estado` ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
  `fecha_creacion` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_cliente`),
  INDEX `idx_clientes_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE IF NOT EXISTS `cliente_direcciones` (
  `id_direccion` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_cliente` INT UNSIGNED NOT NULL,
  `alias` VARCHAR(50) NOT NULL COMMENT 'Ej: Casa, Oficina',
  `direccion` TEXT NOT NULL,
  `maps_link` TEXT NULL,
  `es_default` TINYINT(1) NOT NULL DEFAULT 0,
  `fecha_creacion` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_direccion`),
  CONSTRAINT `fk_direccion_cliente_rel` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Productos
CREATE TABLE IF NOT EXISTS `productos` (
  `id_producto` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_padre` INT UNSIGNED NULL,
  `nombre` VARCHAR(255) NOT NULL,
  `codigo_barras` VARCHAR(120) NOT NULL,
  `descripcion` TEXT DEFAULT NULL,
  `unidad` VARCHAR(80) DEFAULT NULL,
  `nombre_variante` VARCHAR(255) NULL,
  `precio_costo` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `precio_venta` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `categoria` VARCHAR(120) DEFAULT NULL,
  `estado` ENUM('activo','inactivo','archivado') NOT NULL DEFAULT 'activo',
  `fecha_creacion` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_producto`),
  CONSTRAINT `fk_productos_padre` FOREIGN KEY (`id_padre`) REFERENCES `productos` (`id_producto`) ON DELETE SET NULL,
  UNIQUE KEY `uq_productos_codigo_barras` (`codigo_barras`),
  INDEX `idx_productos_categoria` (`categoria`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Favoritos por usuario
CREATE TABLE IF NOT EXISTS `favoritos_usuarios` (
  `id_favorito` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_usuario` INT UNSIGNED NOT NULL,
  `id_producto` INT UNSIGNED NOT NULL,
  `fecha_creacion` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_favorito`),
  UNIQUE KEY `uq_favoritos_usuario_producto` (`id_usuario`, `id_producto`),
  INDEX `idx_favoritos_usuario` (`id_usuario`),
  INDEX `idx_favoritos_producto` (`id_producto`),
  CONSTRAINT `fk_favoritos_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_favoritos_producto` FOREIGN KEY (`id_producto`) REFERENCES `productos` (`id_producto`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Inventario por almacén
CREATE TABLE IF NOT EXISTS `inventario_almacen` (
  `id_inventario` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_producto` INT UNSIGNED NOT NULL,
  `id_almacen` INT UNSIGNED NOT NULL,
  `cantidad_actual` INT NOT NULL DEFAULT 0,
  `cantidad_reservada` INT NOT NULL DEFAULT 0,
  `stock_minimo` INT NOT NULL DEFAULT 2,
  `stock_maximo` INT NOT NULL DEFAULT 5,
  `fecha_ultimo_movimiento` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_inventario`),
  UNIQUE KEY `uq_inventario_producto_almacen` (`id_producto`,`id_almacen`),
  INDEX `idx_inventario_almacen` (`id_almacen`),
  INDEX `idx_inventario_producto` (`id_producto`),
  CONSTRAINT `fk_inventario_producto` FOREIGN KEY (`id_producto`) REFERENCES `productos` (`id_producto`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_inventario_almacen` FOREIGN KEY (`id_almacen`) REFERENCES `almacenes` (`id_almacen`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Métodos de pago
CREATE TABLE IF NOT EXISTS `metodos_pago` (
  `id_metodo` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(120) NOT NULL,
  `descripcion` VARCHAR(255) DEFAULT NULL,
  `estado` ENUM('activo','inactivo') NOT NULL DEFAULT 'activo',
  `fecha_creacion` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_metodo`),
  UNIQUE KEY `uq_metodos_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Pedidos
CREATE TABLE IF NOT EXISTS `pedidos` (
  `id_pedido` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `numero_pedido` VARCHAR(80) NOT NULL,
  `id_cliente` INT UNSIGNED DEFAULT NULL,
  `id_usuario` INT UNSIGNED NOT NULL,
  `id_almacen` INT UNSIGNED NOT NULL,
  `id_metodo_pago` INT UNSIGNED DEFAULT NULL,
  `estado` ENUM('pendiente_pago','pagado','entregado','cancelado') NOT NULL DEFAULT 'pendiente_pago',
  `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `descuento_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `total` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `observaciones` TEXT DEFAULT NULL,
  `fecha_creacion` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `fecha_pago` DATETIME DEFAULT NULL,
  `fecha_entrega` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id_pedido`),
  UNIQUE KEY `uq_pedidos_numero` (`numero_pedido`),
  INDEX `idx_pedidos_almacen` (`id_almacen`),
  INDEX `idx_pedidos_usuario` (`id_usuario`),
  INDEX `idx_pedidos_fecha` (`fecha_creacion`),
  CONSTRAINT `fk_pedidos_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_pedidos_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_pedidos_almacen` FOREIGN KEY (`id_almacen`) REFERENCES `almacenes` (`id_almacen`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_pedidos_metodo` FOREIGN KEY (`id_metodo_pago`) REFERENCES `metodos_pago` (`id_metodo`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Detalle de pedidos
CREATE TABLE IF NOT EXISTS `detalle_pedidos` (
  `id_detalle` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_pedido` INT UNSIGNED NOT NULL,
  `id_producto` INT UNSIGNED NOT NULL,
  `cantidad` INT NOT NULL DEFAULT 1,
  `precio_original` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `precio_unitario` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `costo_unitario` DECIMAL(12,2) DEFAULT NULL,
  `porcentaje_descuento` DECIMAL(5,2) DEFAULT NULL,
  `monto_descuento` DECIMAL(12,2) DEFAULT NULL,
  `subtotal` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id_detalle`),
  INDEX `idx_detalle_pedido` (`id_pedido`),
  INDEX `idx_detalle_producto` (`id_producto`),
  CONSTRAINT `fk_detalle_pedidos_pedido` FOREIGN KEY (`id_pedido`) REFERENCES `pedidos` (`id_pedido`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_detalle_pedidos_producto` FOREIGN KEY (`id_producto`) REFERENCES `productos` (`id_producto`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Movimientos de inventario
CREATE TABLE IF NOT EXISTS `movimientos_inventario` (
  `id_movimiento` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_producto` INT UNSIGNED NOT NULL,
  `tipo_movimiento` ENUM('entrada','salida','ajuste','transferencia') NOT NULL,
  `id_almacen_origen` INT UNSIGNED DEFAULT NULL,
  `id_almacen_destino` INT UNSIGNED DEFAULT NULL,
  `cantidad` INT NOT NULL DEFAULT 0,
  `id_usuario` INT UNSIGNED DEFAULT NULL,
  `observacion` TEXT DEFAULT NULL,
  `fecha` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_movimiento`),
  INDEX `idx_movimientos_producto` (`id_producto`),
  INDEX `idx_movimientos_origen` (`id_almacen_origen`),
  INDEX `idx_movimientos_destino` (`id_almacen_destino`),
  CONSTRAINT `fk_movimientos_producto` FOREIGN KEY (`id_producto`) REFERENCES `productos` (`id_producto`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_movimientos_origen` FOREIGN KEY (`id_almacen_origen`) REFERENCES `almacenes` (`id_almacen`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_movimientos_destino` FOREIGN KEY (`id_almacen_destino`) REFERENCES `almacenes` (`id_almacen`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Chat de Soporte Interno
CREATE TABLE IF NOT EXISTS `mensajes_soporte` (
  `id_mensaje` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_cliente` INT UNSIGNED NOT NULL,
  `id_staff` INT UNSIGNED DEFAULT NULL COMMENT 'ID del admin/encargado que responde',
  `enviado_por` ENUM('cliente','staff') NOT NULL,
  `tipo_mensaje` ENUM('texto','producto','sistema') NOT NULL DEFAULT 'texto',
  `mensaje` TEXT NOT NULL,
  `fecha_envio` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `leido_cliente` TINYINT(1) NOT NULL DEFAULT 0,
  `leido_staff` TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id_mensaje`),
  KEY `idx_chat_cliente` (`id_cliente`),
  CONSTRAINT `fk_chat_usu_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Artículos de Blog
CREATE TABLE IF NOT EXISTS `blogs` (
  `id_blog` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_usuario` INT UNSIGNED NOT NULL COMMENT 'Autor del artículo',
  `titulo` VARCHAR(255) NOT NULL,
  `slug` VARCHAR(255) NOT NULL,
  `extracto` TEXT DEFAULT NULL,
  `contenido` LONGTEXT NOT NULL,
  `imagen_portada` LONGTEXT DEFAULT NULL,
  `fecha_creacion` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `estado` ENUM('publicado','borrador') NOT NULL DEFAULT 'publicado',
  PRIMARY KEY (`id_blog`),
  UNIQUE KEY `uq_blogs_slug` (`slug`),
  CONSTRAINT `fk_blogs_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Respuestas rápidas configurables por usuario (Staff)
CREATE TABLE IF NOT EXISTS `respuestas_rapidas` (
  `id_respuesta` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_usuario` INT UNSIGNED NOT NULL,
  `titulo` VARCHAR(50) NOT NULL,
  `mensaje` TEXT NOT NULL,
  `fecha_creacion` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_respuesta`),
  CONSTRAINT `fk_respuestas_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Insertar datos iniciales
INSERT INTO `roles` (`nombre`,`descripcion`) VALUES
  ('admin','Administrador con permisos totales'),
  ('encargado','Encargado de sucursal: ventas y gestión de inventario'),
  ('vendedor','Vendedor con acceso solo a punto de venta');

INSERT INTO `permisos` (`clave`,`nombre`,`descripcion`) VALUES
  ('venta','Venta','Puede crear y gestionar pedidos'),
  ('inventario','Inventario','Puede hacer entradas, ajustes y transferencias de inventario'),
  ('configurar_usuarios','Configurar usuarios','Puede crear y editar usuarios y roles'),
  ('ver_reportes','Ver reportes','Puede ver reportes y dashboards'),
  ('transferir_stock','Transferir stock','Puede mover inventario entre almacenes'),
  ('gestionar_clientes','Gestionar clientes','Puede crear y editar clientes'),
  ('gestionar_blogs','Gestionar Blogs','Puede crear y editar artículos del blog');

INSERT INTO `rol_permisos` (`id_rol`,`id_permiso`) VALUES
  (1,1),(1,2),(1,3),(1,4),(1,5),(1,6),(1,7),
  (2,1),(2,2),(2,4),(2,5),(2,6),
  (3,1);

INSERT INTO `almacenes` (`nombre`,`ubicacion`) VALUES
  ('Almacén Central','Ubicación principal'),
  ('Sucursal 1','Sucursal primaria');

INSERT INTO `metodos_pago` (`nombre`) VALUES
  ('Efectivo'),
  ('Transferencia Bancaria'),
  ('Tarjeta'),
  ('Cheque');

INSERT INTO `usuarios` (`nombre`,`email`,`contrasena`,`id_rol`,`id_almacen`,`estado`) VALUES
  ('Administrador','admin@system.local','$2y$10$PhPsKkdX3Tz9qh6.CtebBum33IdHlLjrJ..NOWt8ObFkvOTikSBce',1,NULL,'activo'),
  ('Encargado Sucursal','encargado@system.local','$2y$10$PhPsKkdX3Tz9qh6.CtebBum33IdHlLjrJ..NOWt8ObFkvOTikSBce',2,2,'activo'),
  ('Vendedor Demo','vendedor@system.local','$2y$10$PhPsKkdX3Tz9qh6.CtebBum33IdHlLjrJ..NOWt8ObFkvOTikSBce',3,2,'activo');

SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;
SET SQL_MODE=@OLD_SQL_MODE;
