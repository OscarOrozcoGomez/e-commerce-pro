CREATE TABLE IF NOT EXISTS sucursal_incentivos (
  id_regla TINYINT UNSIGNED NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  descuento_porcentaje DECIMAL(6,2) NOT NULL DEFAULT 5.00,
  descuento_fijo DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  subtotal_minimo DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  piezas_minimas INT UNSIGNED NOT NULL DEFAULT 1,
  tope_descuento DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  mensaje_publico VARCHAR(255) DEFAULT NULL,
  fecha_actualizacion TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id_regla)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO sucursal_incentivos (id_regla, activo, descuento_porcentaje, descuento_fijo, subtotal_minimo, piezas_minimas, tope_descuento, mensaje_publico)
VALUES (1, 0, 0.00, 0.00, 0.00, 1, 0.00, NULL)
ON DUPLICATE KEY UPDATE id_regla = id_regla;
