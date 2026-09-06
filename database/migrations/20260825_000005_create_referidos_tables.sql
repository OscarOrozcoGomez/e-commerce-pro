-- Migracion: programa de referidos. Cada cliente tiene un codigo unico
-- (generado bajo demanda, ver core/referrals.php); registrar_uso queda en
-- referidos_usos para poder limitar el abuso (un mismo telefono no puede
-- redimir mas de un codigo de referido en su vida) y para reportar cuantas
-- ventas trajo cada referidor.
CREATE TABLE IF NOT EXISTS `codigos_referido` (
  `id_cliente` INT UNSIGNED NOT NULL,
  `codigo` VARCHAR(20) NOT NULL,
  `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_cliente`),
  UNIQUE KEY `uq_codigos_referido_codigo` (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE IF NOT EXISTS `referidos_usos` (
  `id_uso` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_pedido` INT UNSIGNED NOT NULL,
  `codigo` VARCHAR(20) NOT NULL,
  `id_cliente_referidor` INT UNSIGNED NOT NULL,
  `id_cliente_referido` INT UNSIGNED DEFAULT NULL,
  `telefono_referido_digits` VARCHAR(20) DEFAULT NULL,
  `descuento_aplicado` DECIMAL(10,2) NOT NULL DEFAULT 0,
  `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_uso`),
  KEY `idx_referidos_usos_pedido` (`id_pedido`),
  KEY `idx_referidos_usos_referidor` (`id_cliente_referidor`),
  KEY `idx_referidos_usos_telefono` (`telefono_referido_digits`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
