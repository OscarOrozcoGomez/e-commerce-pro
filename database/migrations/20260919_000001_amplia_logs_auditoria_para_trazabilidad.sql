-- Migracion: amplia logs_auditoria para poder responder "quien hizo que, cuando y desde donde".
--
-- Problema: logs_auditoria solo guardaba (usuario, accion, tabla, id_registro, detalles en
-- texto libre, IP). No guardaba el valor anterior/nuevo, ni el nombre y rol del usuario al
-- momento del cambio, ni el dispositivo, ni la sesion. Cuando un encargado dice "yo no fui",
-- no habia forma de comprobarlo.
--
-- Solucion (todo aditivo, nada se borra ni se renombra):
--   * datos_antes / datos_despues : JSON con SOLO los campos que cambiaron (PII enmascarada
--     por la app antes de guardar, nunca contrasenas ni tokens).
--   * usuario_nombre / usuario_rol / id_almacen : foto del actor al momento del cambio
--     (si despues renombran o borran al usuario, el log sigue diciendo quien fue).
--   * user_agent / sesion_hash / url / metodo / origen : desde que dispositivo, sesion y
--     endpoint (dos personas con la misma cuenta se distinguen por IP + dispositivo + sesion).
--   * severidad : info | aviso | alerta, para filtrar lo delicado.
--   * Indices para consultar por usuario, accion y registro sin escanear toda la tabla.
--
-- Idempotente (patron information_schema + PREPARE): en prod es Percona 8.4 y
-- la variante "IF NOT EXISTS" de ADD COLUMN es MariaDB-only. El codigo de la app (logAudit) tolera que
-- estas columnas aun no existan mientras el deploy corre la migracion.

SET @db := DATABASE();

-- Columna usuario_nombre
SELECT COUNT(*) INTO @has_col
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'logs_auditoria' AND COLUMN_NAME = 'usuario_nombre';
SET @sql := IF(@has_col = 0,
    'ALTER TABLE logs_auditoria ADD COLUMN usuario_nombre VARCHAR(150) NULL DEFAULT NULL',
    'SELECT "skip: logs_auditoria.usuario_nombre ya existe"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Columna usuario_rol
SELECT COUNT(*) INTO @has_col
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'logs_auditoria' AND COLUMN_NAME = 'usuario_rol';
SET @sql := IF(@has_col = 0,
    'ALTER TABLE logs_auditoria ADD COLUMN usuario_rol VARCHAR(50) NULL DEFAULT NULL',
    'SELECT "skip: logs_auditoria.usuario_rol ya existe"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Columna id_almacen
SELECT COUNT(*) INTO @has_col
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'logs_auditoria' AND COLUMN_NAME = 'id_almacen';
SET @sql := IF(@has_col = 0,
    'ALTER TABLE logs_auditoria ADD COLUMN id_almacen INT UNSIGNED NULL DEFAULT NULL',
    'SELECT "skip: logs_auditoria.id_almacen ya existe"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Columna sesion_hash
SELECT COUNT(*) INTO @has_col
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'logs_auditoria' AND COLUMN_NAME = 'sesion_hash';
SET @sql := IF(@has_col = 0,
    'ALTER TABLE logs_auditoria ADD COLUMN sesion_hash VARCHAR(16) NULL DEFAULT NULL',
    'SELECT "skip: logs_auditoria.sesion_hash ya existe"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Columna user_agent
SELECT COUNT(*) INTO @has_col
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'logs_auditoria' AND COLUMN_NAME = 'user_agent';
SET @sql := IF(@has_col = 0,
    'ALTER TABLE logs_auditoria ADD COLUMN user_agent VARCHAR(255) NULL DEFAULT NULL',
    'SELECT "skip: logs_auditoria.user_agent ya existe"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Columna url
SELECT COUNT(*) INTO @has_col
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'logs_auditoria' AND COLUMN_NAME = 'url';
SET @sql := IF(@has_col = 0,
    'ALTER TABLE logs_auditoria ADD COLUMN url VARCHAR(255) NULL DEFAULT NULL',
    'SELECT "skip: logs_auditoria.url ya existe"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Columna metodo
SELECT COUNT(*) INTO @has_col
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'logs_auditoria' AND COLUMN_NAME = 'metodo';
SET @sql := IF(@has_col = 0,
    'ALTER TABLE logs_auditoria ADD COLUMN metodo VARCHAR(10) NULL DEFAULT NULL',
    'SELECT "skip: logs_auditoria.metodo ya existe"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Columna origen
SELECT COUNT(*) INTO @has_col
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'logs_auditoria' AND COLUMN_NAME = 'origen';
SET @sql := IF(@has_col = 0,
    'ALTER TABLE logs_auditoria ADD COLUMN origen VARCHAR(12) NOT NULL DEFAULT "web"',
    'SELECT "skip: logs_auditoria.origen ya existe"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Columna severidad
SELECT COUNT(*) INTO @has_col
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'logs_auditoria' AND COLUMN_NAME = 'severidad';
SET @sql := IF(@has_col = 0,
    'ALTER TABLE logs_auditoria ADD COLUMN severidad VARCHAR(10) NOT NULL DEFAULT "info"',
    'SELECT "skip: logs_auditoria.severidad ya existe"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Columna datos_antes
SELECT COUNT(*) INTO @has_col
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'logs_auditoria' AND COLUMN_NAME = 'datos_antes';
SET @sql := IF(@has_col = 0,
    'ALTER TABLE logs_auditoria ADD COLUMN datos_antes MEDIUMTEXT NULL',
    'SELECT "skip: logs_auditoria.datos_antes ya existe"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Columna datos_despues
SELECT COUNT(*) INTO @has_col
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'logs_auditoria' AND COLUMN_NAME = 'datos_despues';
SET @sql := IF(@has_col = 0,
    'ALTER TABLE logs_auditoria ADD COLUMN datos_despues MEDIUMTEXT NULL',
    'SELECT "skip: logs_auditoria.datos_despues ya existe"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Indice idx_logs_auditoria_usuario_fecha
SELECT COUNT(*) INTO @has_idx
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'logs_auditoria' AND INDEX_NAME = 'idx_logs_auditoria_usuario_fecha';
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE logs_auditoria ADD INDEX idx_logs_auditoria_usuario_fecha (id_usuario, fecha)',
    'SELECT "skip: idx_logs_auditoria_usuario_fecha ya existe"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Indice idx_logs_auditoria_accion_fecha
SELECT COUNT(*) INTO @has_idx
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'logs_auditoria' AND INDEX_NAME = 'idx_logs_auditoria_accion_fecha';
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE logs_auditoria ADD INDEX idx_logs_auditoria_accion_fecha (accion, fecha)',
    'SELECT "skip: idx_logs_auditoria_accion_fecha ya existe"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Indice idx_logs_auditoria_registro
SELECT COUNT(*) INTO @has_idx
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'logs_auditoria' AND INDEX_NAME = 'idx_logs_auditoria_registro';
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE logs_auditoria ADD INDEX idx_logs_auditoria_registro (tabla_afectada, id_registro)',
    'SELECT "skip: idx_logs_auditoria_registro ya existe"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Indice idx_logs_auditoria_fecha
SELECT COUNT(*) INTO @has_idx
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'logs_auditoria' AND INDEX_NAME = 'idx_logs_auditoria_fecha';
SET @sql := IF(@has_idx = 0,
    'ALTER TABLE logs_auditoria ADD INDEX idx_logs_auditoria_fecha (fecha)',
    'SELECT "skip: idx_logs_auditoria_fecha ya existe"');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Relleno de severidad para el historico: las filas anteriores a esta migracion quedaron con el
-- default 'info'. Se marcan por nombre de accion (mismas reglas que auditSeveridadPorDefecto() en
-- core/audit_utils.php) para que el filtro "Solo alertas" tambien sirva sobre lo ya registrado.
-- Idempotente: solo toca filas que siguen en 'info'.
UPDATE logs_auditoria SET severidad = 'alerta'
WHERE severidad = 'info' AND accion IN (
    'BLOQUEO_CUENTA', 'USUARIO_ELIMINADO', 'USUARIO_PASSWORD_RESETEADA', 'USUARIO_ESTADO_CAMBIADO',
    'USUARIO_PERMISOS_ACTUALIZADOS', 'USUARIO_ELEVADO_SUPERADMIN', 'ROL_ELIMINADO', 'ROL_ACTUALIZADO',
    'PERMISO_DESACTIVADO', 'PERMISO_EXPIRADO', 'LOTE_ELIMINADO', 'PRODUCTO_EN_OFERTA',
    'PEDIDO_CANCELADO_CLIENTE', 'PEDIDO_ENTREGADO_SIN_EVIDENCIA', 'PEDIDO_SIN_AFECTAR_INVENTARIO',
    'PRODUCTO_NO_ENTREGADO', 'PEDIDO_ENTREGA_CANCELADA'
);

UPDATE logs_auditoria SET severidad = 'aviso'
WHERE severidad = 'info' AND accion IN (
    'CATEGORIA_ASIGNADA_MASIVA', 'PEDIDO_ASIGNADO', 'PEDIDO_FECHA_ENTREGA_CAMBIADA', 'PEDIDO_LIBERADO',
    'PRODUCTO_LIBERADO', 'LOTE_GUARDADO', 'PEDIDO_CONVERTIDO_A_SUCURSAL', 'PEDIDO_CONVERTIDO_A_DOMICILIO',
    'PRODUCTO_AGREGADO_A_PEDIDO', 'USUARIO_CREADO', 'USUARIO_REACTIVADO', 'USUARIO_DESBLOQUEADO',
    'ROL_CREADO', 'PERMISO_CREADO', 'PERMISO_ACTUALIZADO'
);
