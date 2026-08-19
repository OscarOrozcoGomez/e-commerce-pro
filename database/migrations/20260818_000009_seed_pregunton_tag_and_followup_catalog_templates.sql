-- Migracion: etiqueta "Pregunton" (clientes que no responden al seguimiento de 24h) y
-- las plantillas base para el seguimiento automatico y el catalogo en PDF.
INSERT INTO `whatsapp_etiquetas` (`nombre`, `color`) VALUES
  ('Preguntón', 'red')
ON DUPLICATE KEY UPDATE `nombre` = VALUES(`nombre`);

INSERT INTO `whatsapp_templates` (`codigo`, `tipo`, `texto`, `url_archivo`, `activo`) VALUES
  ('seguimiento_24h', 'texto', 'Hola de nuevo! Solo quería saber si te quedó alguna duda o si te ayudo a encontrar algo más. Aquí sigo al pendiente.', NULL, 1),
  ('catalogo_pdf', 'documento', 'Catálogo Be Life', NULL, 1)
ON DUPLICATE KEY UPDATE `codigo` = VALUES(`codigo`);
