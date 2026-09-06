-- Migracion: tabla de codigos OTP para el login de staff (mejora 9).
-- Objetivo:
-- 1) Guardar el codigo de 6 digitos (hasheado) que se envia por correo a admin/encargado
--    tras validar la contrasena, cuando STAFF_OTP_ENABLED esta activo.
-- 2) Contar intentos fallidos para bloquear la fuerza bruta, igual que el reset de contrasena.
-- 3) Ser idempotente.

SET @db := DATABASE();

SELECT COUNT(*) INTO @has_table
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'staff_login_otp';

SET @sql := IF(
    @has_table = 0,
    'CREATE TABLE staff_login_otp (
        id_otp INT UNSIGNED NOT NULL AUTO_INCREMENT,
        id_usuario INT UNSIGNED NOT NULL,
        codigo_hash CHAR(64) NOT NULL,
        expira_en DATETIME NOT NULL,
        intentos TINYINT UNSIGNED NOT NULL DEFAULT 0,
        usado TINYINT(1) NOT NULL DEFAULT 0,
        creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id_otp),
        KEY idx_slo_usuario_usado (id_usuario, usado),
        KEY idx_slo_expira (expira_en),
        CONSTRAINT fk_slo_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios(id_usuario) ON DELETE CASCADE ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci',
    'SELECT "skip: staff_login_otp ya existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
