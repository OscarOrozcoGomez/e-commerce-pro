-- Normaliza pedidos para flujo de domicilio: reparto, direccion exacta y estado en_reparto.
-- Idempotente para despliegues repetidos.

SET @db := DATABASE();

SELECT COUNT(*) INTO @has_pedidos
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'pedidos';

SET @sql := IF(
    @has_pedidos = 1,
    "ALTER TABLE pedidos MODIFY COLUMN estado ENUM('pendiente_pago','pagado','en_reparto','entregado','cancelado') NOT NULL DEFAULT 'pendiente_pago'",
    'SELECT "skip: tabla pedidos no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @has_tipo_entrega
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'pedidos'
  AND COLUMN_NAME = 'tipo_entrega';

SET @sql := IF(
    @has_pedidos = 1 AND @has_tipo_entrega = 0,
    "ALTER TABLE pedidos ADD COLUMN tipo_entrega VARCHAR(30) DEFAULT NULL AFTER estado",
    'SELECT "skip: pedidos.tipo_entrega ya existe o tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @has_id_repartidor
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'pedidos'
  AND COLUMN_NAME = 'id_repartidor';

SET @sql := IF(
    @has_pedidos = 1 AND @has_id_repartidor = 0,
    "ALTER TABLE pedidos ADD COLUMN id_repartidor INT UNSIGNED DEFAULT NULL AFTER id_almacen",
    'SELECT "skip: pedidos.id_repartidor ya existe o tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @has_direccion_entrega
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'pedidos'
  AND COLUMN_NAME = 'direccion_entrega';

SET @sql := IF(
    @has_pedidos = 1 AND @has_direccion_entrega = 0,
    "ALTER TABLE pedidos ADD COLUMN direccion_entrega TEXT DEFAULT NULL AFTER total",
    'SELECT "skip: pedidos.direccion_entrega ya existe o tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @has_telefono_entrega
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'pedidos'
  AND COLUMN_NAME = 'telefono_entrega';

SET @sql := IF(
    @has_pedidos = 1 AND @has_telefono_entrega = 0,
    "ALTER TABLE pedidos ADD COLUMN telefono_entrega VARCHAR(30) DEFAULT NULL AFTER direccion_entrega",
    'SELECT "skip: pedidos.telefono_entrega ya existe o tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @has_fecha_entrega_programada
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'pedidos'
  AND COLUMN_NAME = 'fecha_entrega_programada';

SET @sql := IF(
    @has_pedidos = 1 AND @has_fecha_entrega_programada = 0,
    "ALTER TABLE pedidos ADD COLUMN fecha_entrega_programada DATETIME DEFAULT NULL AFTER fecha_pago",
    'SELECT "skip: pedidos.fecha_entrega_programada ya existe o tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @has_idx_pedidos_repartidor
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'pedidos'
  AND INDEX_NAME = 'idx_pedidos_repartidor';

SET @sql := IF(
    @has_pedidos = 1 AND @has_idx_pedidos_repartidor = 0,
    'ALTER TABLE pedidos ADD INDEX idx_pedidos_repartidor (id_repartidor)',
    'SELECT "skip: indice idx_pedidos_repartidor ya existe o tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SELECT COUNT(*) INTO @has_fk_pedidos_repartidor
FROM information_schema.TABLE_CONSTRAINTS
WHERE TABLE_SCHEMA = @db
  AND TABLE_NAME = 'pedidos'
  AND CONSTRAINT_NAME = 'fk_pedidos_repartidor';

SET @sql := IF(
    @has_pedidos = 1 AND @has_fk_pedidos_repartidor = 0,
    'ALTER TABLE pedidos ADD CONSTRAINT fk_pedidos_repartidor FOREIGN KEY (id_repartidor) REFERENCES usuarios(id_usuario) ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT "skip: fk_pedidos_repartidor ya existe o tabla no existe"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;