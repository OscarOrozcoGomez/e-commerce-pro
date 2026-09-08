-- Migracion: un par de columnas que guardan valores cifrados con
-- piiEncryptValue() (core/pii_crypto.php, formato "ENCv1:" + base64 de 1 byte de
-- algoritmo + nonce de 24 bytes + ciphertext+tag) se quedaron dimensionadas para
-- el texto PLANO original, no para el texto cifrado -- que siempre es mas largo
-- por el overhead del nonce/tag/base64. Este es el MISMO problema que ya se
-- corrigio para clientes.telefono en 20260818_000001_ampliar_telefono_de_clientes_para_cifrado.sql
-- (buen diagnostico de esa migracion, aplica igual aqui) -- confirmado
-- empiricamente ahora para estas otras dos columnas:
--
--   cliente_direcciones.alias   VARCHAR(50)  <- cifrar "Casa Playwright" (15
--                                                caracteres) da 66; cualquier
--                                                alias de mas de ~4 caracteres
--                                                ya se trunca al cifrarse
--   clientes.alias_perfil       VARCHAR(80)  <- mismo riesgo para alias de
--                                                perfil largos (mi_perfil.php)
--
-- MySQL/MariaDB en esta conexion no aborta el INSERT por exceder el VARCHAR (el
-- SQL_MODE estricto de database.sql solo aplica durante esa importacion, no a las
-- conexiones normales de la app) -- trunca en silencio, lo que rompe la
-- autenticacion AEAD y el descifrado falla para siempre en esa fila. No depende
-- de carga concurrente: pasa siempre que el valor cifrado supera el limite.
--
-- Se amplian a VARCHAR(255) -- mismo tamaño usado para clientes.telefono en la
-- migracion de referencia y para clientes.direccion. Ampliar un VARCHAR no
-- afecta los datos ya guardados.
ALTER TABLE `cliente_direcciones`
    MODIFY COLUMN `alias` VARCHAR(255) NOT NULL COMMENT 'Ej: Casa, Oficina';

ALTER TABLE `clientes`
    MODIFY COLUMN `alias_perfil` VARCHAR(255) NULL;
