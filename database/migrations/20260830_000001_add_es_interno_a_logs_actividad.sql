-- Migracion: bandera es_interno en logs_actividad.
--
-- Problema: el personal (admin, encargado, vendedor, repartidor) navega la tienda y
-- el panel mientras trabaja, y cada carga dispara el beacon de tracking
-- (api/log_activity.php). Esa actividad propia infla los reportes de marketing
-- (Trafico y Campanas, Comportamiento en el Sitio) y da mediciones falsas de campana.
--
-- Solucion: NO se deja de registrar nada -- el log de auditoria sigue mostrando todo.
-- Se agrega una bandera que marca la fila como interna al momento de insertarla
-- (cualquier rol de sesion distinto de 'cliente'); los reportes de marketing filtran
-- es_interno = 0 para medir solo trafico real de visitantes y clientes.
--
-- Idempotente: se puede correr mas de una vez sin romper.

SET @db := DATABASE();

-- 1) Columna es_interno (si no existe).
SELECT COUNT(*) INTO @has_col
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'logs_actividad'
  AND COLUMN_NAME = 'es_interno';

SET @sql := IF(
    @has_col = 0,
    'ALTER TABLE logs_actividad ADD COLUMN es_interno TINYINT(1) NOT NULL DEFAULT 0 AFTER id_usuario',
    'SELECT "skip: logs_actividad.es_interno ya existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2) Indice compuesto para el patron de los reportes: es_interno = 0 + tipo_accion +
--    rango de fecha_creacion (si no existe).
SELECT COUNT(*) INTO @has_idx
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'logs_actividad'
  AND INDEX_NAME = 'idx_logs_actividad_es_interno';

SET @sql := IF(
    @has_idx = 0,
    'ALTER TABLE logs_actividad ADD INDEX idx_logs_actividad_es_interno (es_interno, tipo_accion, fecha_creacion)',
    'SELECT "skip: idx_logs_actividad_es_interno ya existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 3) Backfill del historico: marca como internas las filas ligadas a una cuenta de
--    staff (cualquier rol que no sea 'cliente'). Las filas sin id_usuario (visitantes
--    anonimos) y las de clientes logueados quedan en 0. La FK de id_usuario es
--    ON DELETE SET NULL, asi que una fila con id_usuario no nulo siempre tiene usuario.
UPDATE logs_actividad la
JOIN usuarios u ON u.id_usuario = la.id_usuario
LEFT JOIN roles r ON r.id_rol = u.id_rol
SET la.es_interno = 1
WHERE la.es_interno = 0
  AND COALESCE(r.nombre, '') <> 'cliente';
