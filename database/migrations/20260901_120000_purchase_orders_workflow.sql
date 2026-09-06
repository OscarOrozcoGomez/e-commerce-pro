-- Flujo de órdenes de compra: crea (si faltan) las tablas de cabecera/detalle y
-- deja el enum de estado con 'parcial'. Idempotente para despliegues repetidos.

CREATE TABLE IF NOT EXISTS `ordenes_compra` (
  `id_orden_compra` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_usuario` INT UNSIGNED NOT NULL,
  `id_almacen` INT UNSIGNED NOT NULL,
  `referencia` VARCHAR(50) NOT NULL,
  `fecha_creacion` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `estado` ENUM('borrador','enviada','parcial','recibida','cancelada') NOT NULL DEFAULT 'borrador',
  `total_estimado` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  `observaciones` TEXT DEFAULT NULL,
  PRIMARY KEY (`id_orden_compra`),
  KEY `idx_oc_almacen` (`id_almacen`),
  KEY `idx_oc_usuario` (`id_usuario`),
  KEY `idx_oc_estado` (`estado`),
  CONSTRAINT `fk_oc_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id_usuario`),
  CONSTRAINT `fk_oc_almacen` FOREIGN KEY (`id_almacen`) REFERENCES `almacenes` (`id_almacen`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE IF NOT EXISTS `detalle_orden_compra` (
  `id_detalle` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_orden_compra` INT UNSIGNED NOT NULL,
  `id_producto` INT UNSIGNED NOT NULL,
  `cantidad_solicitada` INT NOT NULL,
  `cantidad_recibida` INT NOT NULL DEFAULT 0,
  `costo_unitario` DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id_detalle`),
  KEY `idx_doc_orden` (`id_orden_compra`),
  KEY `idx_doc_producto` (`id_producto`),
  CONSTRAINT `fk_doc_orden` FOREIGN KEY (`id_orden_compra`) REFERENCES `ordenes_compra` (`id_orden_compra`) ON DELETE CASCADE,
  CONSTRAINT `fk_doc_producto` FOREIGN KEY (`id_producto`) REFERENCES `productos` (`id_producto`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

-- Si la tabla ya existía con el enum de 4 valores, agrega 'parcial'.
SET @db := DATABASE();

SELECT COUNT(*) INTO @needs_parcial
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'ordenes_compra'
  AND COLUMN_NAME = 'estado'
  AND COLUMN_TYPE NOT LIKE '%''parcial''%';

SET @sql := IF(
    @needs_parcial = 1,
    "ALTER TABLE ordenes_compra MODIFY COLUMN estado ENUM('borrador','enviada','parcial','recibida','cancelada') NOT NULL DEFAULT 'borrador'",
    'SELECT "skip: ordenes_compra.estado ya incluye parcial"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Índice por estado si la tabla es preexistente y no lo trae.
SELECT COUNT(*) INTO @has_idx_estado
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'ordenes_compra'
  AND INDEX_NAME = 'idx_oc_estado';

SET @sql := IF(
    @has_idx_estado = 0,
    'ALTER TABLE ordenes_compra ADD INDEX idx_oc_estado (estado)',
    'SELECT "skip: idx_oc_estado ya existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
