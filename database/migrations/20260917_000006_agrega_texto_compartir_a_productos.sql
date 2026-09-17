-- Migracion: agrega columna texto_compartir a productos, para el boton "Compartir"
-- (WhatsApp/correo/Facebook) que usa el admin/encargado. A diferencia de beneficios y
-- perfil_recomendado (referencia INTERNA, nunca se le muestra al cliente), texto_compartir
-- SI esta redactado para que el cliente lo lea tal cual -- lenguaje de venta natural, sin
-- tags cortos, sin advertencias internas de embarazo/lactancia, sin afirmaciones medicas.
-- Idempotente para despliegues repetidos.

SET @db := DATABASE();

SELECT COUNT(*) INTO @has_productos
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'productos';

SELECT COUNT(*) INTO @has_texto_compartir
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'productos'
  AND COLUMN_NAME = 'texto_compartir';

SET @sql := IF(
    @has_productos = 1 AND @has_texto_compartir = 0,
    "ALTER TABLE productos ADD COLUMN texto_compartir TEXT DEFAULT NULL AFTER perfil_recomendado",
    'SELECT "skip: productos.texto_compartir ya existe o tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
