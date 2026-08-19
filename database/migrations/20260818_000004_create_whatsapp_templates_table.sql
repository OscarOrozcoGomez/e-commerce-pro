-- Migracion: plantillas de mensajes/multimedia que Alex puede enviar por WhatsApp
-- (catalogos, fotos de producto, notas de pedido) via la funcion enviar_plantilla.
CREATE TABLE IF NOT EXISTS `whatsapp_templates` (
  `id_plantilla` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `codigo` VARCHAR(60) NOT NULL COMMENT 'Identificador corto que usa el LLM para pedir la plantilla, ej: catalogo_be_life',
  `tipo` ENUM('texto','imagen','documento') NOT NULL DEFAULT 'texto',
  `texto` TEXT DEFAULT NULL COMMENT 'Cuerpo del mensaje (tipo=texto) o caption/pie de foto (tipo=imagen/documento)',
  `url_archivo` TEXT DEFAULT NULL COMMENT 'URL publica del archivo para tipo=imagen/documento',
  `activo` TINYINT(1) NOT NULL DEFAULT 1,
  `creado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actualizado_en` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_plantilla`),
  UNIQUE KEY `uq_whatsapp_templates_codigo` (`codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
