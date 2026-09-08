-- Mismo patron que 20260628_000006 (logs_actividad) y 20260629_000002 (clientes.id_usuario):
-- estas 7 tablas nunca tuvieron una migracion de creacion ni estan en database.sql -- se
-- crearon en algun momento directo contra las BDs reales, fuera de control de version. El
-- codigo de la app (views/products.php, views/purchase_orders.php, api/*, etc.) las da por
-- existentes desde siempre. Se agrupan aqui porque las descubrio la misma corrida de
-- verificacion (reconstruir el schema completo desde cero para el job e2e-tests de CI) y
-- porque tienen FKs entre si (categorias -> producto_categorias, ordenes_compra ->
-- detalle_orden_compra), asi que van en un solo archivo para no pelear con el orden entre
-- multiples migraciones nuevas.
--
-- NO se incluyen aqui otras tablas que tambien salieron "faltantes" en el mismo barrido
-- (calendario_campanas, carrito_temporal, codigos_referido, referidos_usos, staff_login_otp,
-- usuario_permisos, ventas_features_config): ninguna la referencia el codigo actual de esta
-- rama (automation) -- pertenecen a features de otras ramas aun no fusionadas.
--
-- Fechada 20260629_000003 (justo despues de clientes.id_usuario) para que corra antes de
-- 20260705_000001_add_catalog_activity_indexes.sql, que ya agrega indices a categorias/
-- producto_categorias/producto_imagenes de forma idempotente si la tabla existe (aunque en
-- la practica no cambia nada: los indices que esa migracion agregaria ya vienen incluidos
-- aqui, tal como estan hoy en las bases de datos reales).

CREATE TABLE IF NOT EXISTS `categorias` (
  `id_categoria` INT NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(100) NOT NULL,
  `estado` ENUM('activo','inactivo') DEFAULT 'activo',
  PRIMARY KEY (`id_categoria`),
  UNIQUE KEY `nombre` (`nombre`),
  KEY `idx_categorias_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE IF NOT EXISTS `producto_categorias` (
  `id_producto` INT UNSIGNED NOT NULL,
  `id_categoria` INT NOT NULL,
  PRIMARY KEY (`id_producto`, `id_categoria`),
  KEY `idx_pc_categoria_producto` (`id_categoria`, `id_producto`),
  KEY `idx_pc_producto_categoria` (`id_producto`, `id_categoria`),
  CONSTRAINT `fk_pc_categoria` FOREIGN KEY (`id_categoria`) REFERENCES `categorias` (`id_categoria`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pc_producto` FOREIGN KEY (`id_producto`) REFERENCES `productos` (`id_producto`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE IF NOT EXISTS `producto_imagenes` (
  `id_imagen` INT NOT NULL AUTO_INCREMENT,
  `id_producto` INT UNSIGNED NOT NULL,
  `ruta_archivo` VARCHAR(255) DEFAULT NULL,
  `orden` INT DEFAULT 0,
  PRIMARY KEY (`id_imagen`),
  KEY `idx_producto_imagenes_producto_orden` (`id_producto`, `orden`),
  CONSTRAINT `producto_imagenes_ibfk_1` FOREIGN KEY (`id_producto`) REFERENCES `productos` (`id_producto`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `tipos_presentacion` (
  `id_tipo` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nombre` VARCHAR(50) NOT NULL,
  PRIMARY KEY (`id_tipo`),
  UNIQUE KEY `uq_tipo_nombre` (`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `ordenes_compra` (
  `id_orden_compra` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_usuario` INT UNSIGNED NOT NULL,
  `id_almacen` INT UNSIGNED NOT NULL,
  `referencia` VARCHAR(50) NOT NULL,
  `fecha_creacion` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `estado` ENUM('borrador','enviada','recibida','cancelada') DEFAULT 'borrador',
  `total_estimado` DECIMAL(15,2) DEFAULT 0.00,
  `observaciones` TEXT DEFAULT NULL,
  PRIMARY KEY (`id_orden_compra`),
  KEY `id_usuario` (`id_usuario`),
  KEY `id_almacen` (`id_almacen`),
  CONSTRAINT `ordenes_compra_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`),
  CONSTRAINT `ordenes_compra_ibfk_2` FOREIGN KEY (`id_almacen`) REFERENCES `almacenes` (`id_almacen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `detalle_orden_compra` (
  `id_detalle` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_orden_compra` INT UNSIGNED NOT NULL,
  `id_producto` INT UNSIGNED NOT NULL,
  `cantidad_solicitada` INT NOT NULL,
  `cantidad_recibida` INT DEFAULT 0,
  `costo_unitario` DECIMAL(15,2) DEFAULT 0.00,
  PRIMARY KEY (`id_detalle`),
  KEY `id_orden_compra` (`id_orden_compra`),
  KEY `id_producto` (`id_producto`),
  CONSTRAINT `detalle_orden_compra_ibfk_1` FOREIGN KEY (`id_orden_compra`) REFERENCES `ordenes_compra` (`id_orden_compra`),
  CONSTRAINT `detalle_orden_compra_ibfk_2` FOREIGN KEY (`id_producto`) REFERENCES `productos` (`id_producto`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `logs_auditoria` (
  `id_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_usuario` INT UNSIGNED DEFAULT NULL,
  `accion` VARCHAR(50) NOT NULL,
  `tabla_afectada` VARCHAR(50) NOT NULL,
  `id_registro` INT DEFAULT NULL,
  `detalles` TEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `fecha` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_log`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
