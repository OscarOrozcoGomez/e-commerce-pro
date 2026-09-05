-- Migracion: cliente_direcciones.alias pasa de VARCHAR a TEXT.
--
-- Problema: la columna `alias` guarda el valor CIFRADO con piiEncryptValue()
-- (core/pii_crypto.php, formato "ENCv1:" + base64 de 1 byte de algoritmo + nonce
-- de 24 bytes + ciphertext + tag de 16 bytes). Ese texto cifrado mide ~4/3 del
-- texto plano MAS ~40 bytes de overhead fijo, asi que:
--
--   alias plano        alias cifrado
--   ----------------   -------------
--   4 caracteres       ~50
--   50 ASCII           ~114
--   50 con acentos     ~178
--   50 emoji           ~314
--
-- La migracion 20260821_000006 ya lo habia ampliado de VARCHAR(50) a VARCHAR(255)
-- por este mismo motivo, pero 255 se queda corto: un alias de 50 caracteres con
-- acentos/emoji cifra por encima de 255 y el INSERT/UPDATE falla con
--   SQLSTATE[22001] 1406 Data too long for column 'alias'
-- (o, en una conexion sin SQL_MODE estricto, se trunca en silencio y rompe la
-- autenticacion AEAD: el descifrado de esa fila falla para siempre).
--
-- Solucion: alinear `alias` con sus columnas hermanas `direccion` y `maps_link`,
-- que ya son TEXT justamente porque tambien guardan PII cifrada. TEXT tiene de
-- sobra para cualquier alias de 50 caracteres cifrado.
--
-- Idempotente: solo altera la columna si todavia no es TEXT.

SET @db := DATABASE();

SELECT DATA_TYPE INTO @alias_type
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'cliente_direcciones'
  AND COLUMN_NAME = 'alias';

SET @sql := IF(
    @alias_type = 'text',
    'SELECT "skip: cliente_direcciones.alias ya es TEXT"',
    'ALTER TABLE `cliente_direcciones` MODIFY COLUMN `alias` TEXT NOT NULL COMMENT ''Ej: Casa, Oficina (se guarda cifrado)'''
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
