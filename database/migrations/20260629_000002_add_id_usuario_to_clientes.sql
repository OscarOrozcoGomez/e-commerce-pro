-- clientes.id_usuario nunca tuvo una migracion propia ni esta en database.sql -- se agrego
-- en algun momento directo contra las bases de datos reales (local y produccion), fuera de
-- control de versiones. Toda la app ya depende de esta columna (vincula un cliente walk-in
-- con su cuenta de usuario una vez que "completa su cuenta" -- ver views/complete_account.php,
-- core/phone_utils.php::findClienteByPhone) y por eso el hueco nunca se noto: ningun ambiente
-- real parte de un esquema realmente vacio. Se descubrio al correr por primera vez
-- scripts/migrate.php contra una BD nueva de verdad (el job e2e-tests de CI), donde
-- 20260710_191500_seed_clientes_odoo_from_qa.sql (que si inserta id_usuario) fallo con
-- "Unknown column 'id_usuario' in 'field list'".
--
-- Idempotente: seguro correr de nuevo si la columna/FK ya existen (como en todo ambiente real).
SET @db := DATABASE();

SELECT COUNT(*) INTO @has_column
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'clientes'
  AND COLUMN_NAME = 'id_usuario';

SET @sql := IF(
    @has_column = 0,
    'ALTER TABLE clientes ADD COLUMN id_usuario INT UNSIGNED NULL AFTER fecha_creacion',
    'SELECT "skip: clientes.id_usuario ya existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @has_idx
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'clientes'
  AND INDEX_NAME = 'fk_cliente_usuario';

SET @sql := IF(
    @has_idx = 0,
    'ALTER TABLE clientes ADD INDEX fk_cliente_usuario (id_usuario)',
    'SELECT "skip: indice fk_cliente_usuario ya existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @has_fk
FROM information_schema.REFERENTIAL_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = @db
  AND TABLE_NAME = 'clientes'
  AND CONSTRAINT_NAME = 'fk_cliente_usuario';

SET @sql := IF(
    @has_fk = 0,
    'ALTER TABLE clientes ADD CONSTRAINT fk_cliente_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios (id_usuario) ON DELETE CASCADE',
    'SELECT "skip: FK fk_cliente_usuario ya existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
