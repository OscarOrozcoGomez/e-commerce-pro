-- Migracion: `database.sql` solo siembra los roles admin/encargado/vendedor
-- (database.sql:362, INSERT INTO roles sin id_rol explicito -> auto_increment 1,2,3).
-- Los roles 'cliente' y 'repartidor' nunca quedaron sembrados en ninguna migracion,
-- pese a que el codigo los da por hechos: views/register.php inserta usuarios con
-- id_rol=4 (comentario "id_rol = 4 es 'cliente' segun vimos anteriormente", asumido
-- de un ambiente ya poblado a mano) y core/auth.php tiene isRepartidor()/entregas.php
-- para el rol 'repartidor'. En una base de datos nueva (CI, o una recuperacion de
-- desastre real) el registro de clientes y el login de repartidores fallarian porque
-- el JOIN roles nunca encuentra esas filas.
--
-- INSERT IGNORE + la UNIQUE KEY uq_roles_nombre (database.sql:20) hacen esto
-- idempotente: en ambientes que ya tienen estos roles (agregados a mano en algun
-- momento) no pasa nada; en un ambiente nuevo los crea.
INSERT IGNORE INTO `roles` (`nombre`, `descripcion`) VALUES
  ('cliente', 'Cliente publico que compra en el catalogo'),
  ('repartidor', 'Encargado de entregar pedidos a domicilio');
