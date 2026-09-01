-- logs_actividad (igual que clientes.id_usuario en 20260629_000002) nunca tuvo una
-- migracion de creacion ni esta en database.sql -- se creo en algun momento directo
-- contra las BDs reales, fuera de control de versiones. views/activity_logs.php y el
-- registro de visitas/clicks dependen de ella desde antes de que existiera ninguna
-- migracion en este repo, y las migraciones posteriores que le agregan columnas
-- (20260825_000006_add_marketing_attribution_a_logs_actividad.sql,
-- 20260826_000001_add_comportamiento_sitio_a_logs_actividad.sql) asumen que ya existe
-- con este set base de columnas -- por eso este archivo va fechado ANTES de esas (y
-- antes de 20260629_000001_allow_null_id_usuario_in_logs_actividad.sql, que ya sabe
-- agregar el indice/FK de id_usuario de forma idempotente si la tabla existe).
--
-- Descubierto igual que clientes.id_usuario: al correr scripts/migrate.php por primera
-- vez contra una BD realmente vacia (job e2e-tests de CI), donde
-- 20260825_000006_add_marketing_attribution_a_logs_actividad.sql tronaba con
-- "Table 'logs_actividad' doesn't exist".
CREATE TABLE IF NOT EXISTS `logs_actividad` (
  `id_log` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `id_usuario` INT UNSIGNED NULL,
  `tipo_accion` ENUM('visit','click') NOT NULL,
  `url` VARCHAR(255) DEFAULT NULL,
  `elemento_id` VARCHAR(100) DEFAULT NULL,
  `elemento_texto` VARCHAR(255) DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `fecha_creacion` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_log`),
  KEY `idx_logs_actividad_tipo_fecha` (`tipo_accion`, `fecha_creacion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
