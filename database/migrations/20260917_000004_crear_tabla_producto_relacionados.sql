-- Migracion: crear tabla producto_relacionados
--
-- Venta cruzada: por cada producto, que otro(s) producto(s) sugerirle al vendedor/
-- Alex para subir el ticket (ej. quien compra Magnesio Glicinato, ofrecerle tambien
-- Melatonina). Relacion dirigida (id_producto -> id_producto_relacionado); para pares
-- que aplican en ambos sentidos se insertan las dos filas. `nota` es opcional, breve,
-- para recordar por que se sugiere el combo (ej. "mismo ritual antes de dormir").
--
-- Al igual que beneficios y perfil_recomendado, es un insumo interno -- el vendedor/
-- Alex lo usan para decidir que ofrecer, no se le enumera tal cual al cliente.
-- Idempotente para despliegues repetidos.
--
-- Sin "ON UPDATE CASCADE" en las FK a proposito: MySQL 8 rechaza un CHECK sobre una
-- columna que a la vez tiene una accion referencial (CASCADE/SET NULL) en ON UPDATE
-- ("Column ... cannot be used in a check constraint ... needed in a foreign key
-- constraint referential action"). id_producto es AUTO_INCREMENT y nunca se actualiza
-- en la practica, asi que no se pierde nada con solo dejar ON DELETE CASCADE.
CREATE TABLE IF NOT EXISTS producto_relacionados (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  id_producto INT UNSIGNED NOT NULL,
  id_producto_relacionado INT UNSIGNED NOT NULL,
  nota VARCHAR(255) DEFAULT NULL,
  creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_producto_relacionados_par (id_producto, id_producto_relacionado),
  KEY idx_producto_relacionados_producto (id_producto),
  KEY idx_producto_relacionados_relacionado (id_producto_relacionado),
  CONSTRAINT fk_producto_relacionados_producto FOREIGN KEY (id_producto) REFERENCES productos(id_producto) ON DELETE CASCADE,
  CONSTRAINT fk_producto_relacionados_relacionado FOREIGN KEY (id_producto_relacionado) REFERENCES productos(id_producto) ON DELETE CASCADE,
  CONSTRAINT chk_producto_relacionados_no_self CHECK (id_producto <> id_producto_relacionado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
