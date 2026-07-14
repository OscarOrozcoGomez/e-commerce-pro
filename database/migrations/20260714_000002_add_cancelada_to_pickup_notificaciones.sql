-- Agrega estado cancelada para notificaciones pickup y fecha de cancelacion
ALTER TABLE pickup_notificaciones
  MODIFY COLUMN estado ENUM('nueva','vista','apartada','atendida','cancelada') NOT NULL DEFAULT 'nueva';

ALTER TABLE pickup_notificaciones
  ADD COLUMN fecha_cancelada DATETIME DEFAULT NULL AFTER fecha_atendida;
