CREATE TABLE IF NOT EXISTS purchase_order_postponed_items (
    id_postergacion INT UNSIGNED NOT NULL AUTO_INCREMENT,
    id_producto INT UNSIGNED NOT NULL,
    id_almacen INT UNSIGNED NOT NULL,
    estado ENUM('pendiente','reactivado') NOT NULL DEFAULT 'pendiente',
    motivo VARCHAR(255) DEFAULT NULL,
    pospuesto_por INT UNSIGNED DEFAULT NULL,
    pospuesto_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reactivado_en DATETIME DEFAULT NULL,
    PRIMARY KEY (id_postergacion),
    UNIQUE KEY uq_po_postergado_producto_almacen (id_producto, id_almacen),
    KEY idx_po_postergado_estado (estado),
    KEY idx_po_postergado_almacen (id_almacen),
    CONSTRAINT fk_po_postergado_producto FOREIGN KEY (id_producto) REFERENCES productos (id_producto) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_po_postergado_almacen FOREIGN KEY (id_almacen) REFERENCES almacenes (id_almacen) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_po_postergado_usuario FOREIGN KEY (pospuesto_por) REFERENCES usuarios (id_usuario) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_spanish_ci;
