-- Migracion: permisos individuales por usuario (override sobre los del rol)
-- Objetivo:
-- 1) Permitir conceder o revocar un permiso a UNA persona concreta sin tocar
--    rol_permisos (que afecta a todos los usuarios de ese rol).
-- 2) `efecto` = 'conceder' suma un permiso que el rol no da; 'denegar' quita uno
--    que el rol si da.
-- 3) `nota` guarda el motivo del cambio (se refleja en logs_auditoria).
-- 4) `expira_en` permite accesos temporales; getEffectivePermissions() ignora las
--    filas vencidas y un cron las limpia.
-- CREATE TABLE IF NOT EXISTS + claves foraneas ON DELETE CASCADE lo hacen idempotente
-- y auto-limpiable cuando se borra el usuario o el permiso.
CREATE TABLE IF NOT EXISTS usuario_permisos (
  id_usuario INT UNSIGNED NOT NULL,
  id_permiso INT UNSIGNED NOT NULL,
  efecto ENUM('conceder','denegar') NOT NULL DEFAULT 'conceder',
  nota VARCHAR(255) NULL,
  expira_en DATETIME NULL,
  asignado_por INT UNSIGNED NULL,
  fecha_asignacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id_usuario, id_permiso),
  KEY idx_usuario_permisos_expira (expira_en),
  CONSTRAINT fk_usuario_permisos_usuario FOREIGN KEY (id_usuario) REFERENCES usuarios (id_usuario) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT fk_usuario_permisos_permiso FOREIGN KEY (id_permiso) REFERENCES permisos (id_permiso) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
