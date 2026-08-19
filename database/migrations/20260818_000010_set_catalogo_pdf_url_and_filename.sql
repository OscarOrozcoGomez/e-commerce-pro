-- Migracion: nombre de archivo con el que se presenta cada plantilla de tipo imagen/documento
-- (ej. para que WhatsApp muestre "Catalogo_Be_Life.pdf" en vez de un nombre generico), y la
-- URL real del PDF del catalogo.
ALTER TABLE `whatsapp_templates`
  ADD COLUMN `nombre_archivo` VARCHAR(150) DEFAULT NULL AFTER `url_archivo`;

UPDATE `whatsapp_templates`
SET `url_archivo` = 'https://bellezaybienestar.com.mx/assets/catalogo_be_life.pdf',
    `nombre_archivo` = 'Catalogo_Be_Life.pdf'
WHERE `codigo` = 'catalogo_pdf';
