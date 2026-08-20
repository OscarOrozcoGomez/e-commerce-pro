-- Migracion: agregar confirmacion de cliente a cliente_direcciones
-- Permite marcar que el cliente confirmo por WhatsApp que una direccion es correcta,
-- ya que a veces ni el mapa encuentra bien el lugar. Nueva direccion o edicion arrancan
-- sin confirmar; se confirma manualmente cuando el cliente responde por WhatsApp.
-- Idempotente para despliegues repetidos.

SET @db := DATABASE();

SELECT COUNT(*) INTO @has_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'cliente_direcciones';

SELECT COUNT(*) INTO @has_confirmada
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'cliente_direcciones'
  AND COLUMN_NAME = 'confirmada_cliente';

SET @sql := IF(
    @has_table = 1 AND @has_confirmada = 0,
    'ALTER TABLE cliente_direcciones ADD COLUMN confirmada_cliente TINYINT(1) NOT NULL DEFAULT 0',
    'SELECT "skip: cliente_direcciones.confirmada_cliente ya existe o tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @has_confirmada_en
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'cliente_direcciones'
  AND COLUMN_NAME = 'confirmada_en';

SET @sql := IF(
    @has_table = 1 AND @has_confirmada_en = 0,
    'ALTER TABLE cliente_direcciones ADD COLUMN confirmada_en DATETIME NULL AFTER confirmada_cliente',
    'SELECT "skip: cliente_direcciones.confirmada_en ya existe o tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @has_confirmada_por
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'cliente_direcciones'
  AND COLUMN_NAME = 'confirmada_por';

SET @sql := IF(
    @has_table = 1 AND @has_confirmada_por = 0,
    'ALTER TABLE cliente_direcciones ADD COLUMN confirmada_por INT UNSIGNED NULL AFTER confirmada_en',
    'SELECT "skip: cliente_direcciones.confirmada_por ya existe o tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
