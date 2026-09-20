-- Migracion: agrega telefono_resuelto a whatsapp_conversaciones
--
-- La mayoria de las conversaciones (170 de 171 en produccion, probablemente por venir de
-- anuncios de Facebook/Instagram) tienen un wa_id tipo LID: WhatsApp oculta el numero real
-- y aiWaIdToMxDigits()/aiWaIdToDisplayPhone() no pueden sacar nada de ahi. Baileys (el
-- puente) SI guarda internamente el mapeo LID->telefono real cada vez que decodifica un
-- mensaje de ese contacto (protocolo WhatsApp incluye el numero real en el "sobre" del
-- mensaje) -- ver scripts/resolver_lids_whatsapp.php, que lo consulta (lectura 100% local,
-- nunca manda nada a WhatsApp) y guarda aqui el que sí logre resolver.
--
-- Digitos nacionales de 10 (mismo formato que produce aiWaIdToMxDigits()), NULL cuando
-- Baileys no tiene ese mapeo (la mayoria seguira sin resolverse, ver el script).
--
-- Patron idempotente (information_schema + ALTER dinamico via PREPARE/EXECUTE):
-- "ADD COLUMN IF NOT EXISTS" es MariaDB-only y revienta el runner en Percona 8.4.

SET @db := DATABASE();

SET @needs := (SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'whatsapp_conversaciones' AND COLUMN_NAME = 'telefono_resuelto');
SET @sql := IF(@needs,
    'ALTER TABLE whatsapp_conversaciones ADD COLUMN telefono_resuelto VARCHAR(10) DEFAULT NULL AFTER wa_id',
    'SELECT "skip: whatsapp_conversaciones.telefono_resuelto ya existe"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
