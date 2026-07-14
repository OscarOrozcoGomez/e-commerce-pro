-- Agrega estado intermedio de apartado en sucursal para flujo pickup web
ALTER TABLE pickup_notificaciones
  MODIFY COLUMN estado ENUM('nueva','vista','apartada','atendida') NOT NULL DEFAULT 'nueva';

-- Guarda hora exacta cuando sucursal aparta fisicamente el pedido
ALTER TABLE pickup_notificaciones
  ADD COLUMN fecha_apartada DATETIME DEFAULT NULL AFTER fecha_vista;
