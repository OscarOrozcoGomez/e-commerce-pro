-- Migracion: ofertas caducidad gestion y eventos alex
--
-- 1) oferta_caducidad_gestion: productos que el SISTEMA puso en la categoria Ofertas desde
--    Caducidades (boton "Poner en oferta"). Solo esos se ajustan/retiran solos (cron de
--    caducidades, ofertaCadReconciliar()): baja el precio al siguiente escalon conforme se
--    acerca la fecha y sale de Ofertas cuando ya no le queda ningun lote en riesgo. Los
--    productos que el equipo metio a la categoria a mano no aparecen aqui y nunca se tocan.
--    Sin llaves foraneas a proposito (mismo criterio que el resto de tablas de apoyo).
-- 2) alex_oferta_eventos: bitacora de lo que Alex hace con las ofertas (consulta, venta,
--    recompra, seguimiento con oferta) para medir si la estrategia de caducidades vende
--    (panel "Alex y las ofertas" en views/caducidades.php) y para topar el envio de recompras.
CREATE TABLE IF NOT EXISTS `oferta_caducidad_gestion` (
  `id_producto` INT NOT NULL,
  `severidad` VARCHAR(20) NOT NULL DEFAULT '',
  `gestiona_precio` TINYINT(1) NOT NULL DEFAULT 1,
  `precio_aplicado` DECIMAL(10,2) NULL DEFAULT NULL,
  `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_producto`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;

CREATE TABLE IF NOT EXISTS `alex_oferta_eventos` (
  `id_evento` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tipo` VARCHAR(30) NOT NULL,
  `id_conversacion` INT NULL DEFAULT NULL,
  `id_cliente` INT NULL DEFAULT NULL,
  `id_producto` INT NOT NULL,
  `id_pedido` INT NULL DEFAULT NULL,
  `cantidad` INT NULL DEFAULT NULL,
  `precio_unitario` DECIMAL(10,2) NULL DEFAULT NULL,
  `precio_normal` DECIMAL(10,2) NULL DEFAULT NULL,
  `severidad` VARCHAR(20) NULL DEFAULT NULL,
  `paquete` TINYINT(1) NOT NULL DEFAULT 0,
  `creado_en` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_evento`),
  KEY `idx_alex_oferta_tipo_fecha` (`tipo`, `creado_en`),
  KEY `idx_alex_oferta_conversacion` (`id_conversacion`, `tipo`),
  KEY `idx_alex_oferta_cliente_producto` (`id_cliente`, `id_producto`, `tipo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
