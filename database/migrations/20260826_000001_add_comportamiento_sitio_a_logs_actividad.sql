-- Migracion: columnas para "Comportamiento en el Sitio" (que ve la gente y cuanto
-- tiempo, no solo de donde vino). pageview_id enlaza el evento 'visit' con su
-- evento 'duration' posterior (el navegador manda la duracion al salir de la pagina,
-- como UPDATE sobre la fila 'visit' ya insertada, identificada por este id).
-- id_producto permite agregar "productos mas vistos" sin tener que parsear la URL
-- en cada consulta del reporte.
ALTER TABLE `logs_actividad`
  ADD COLUMN `pageview_id` CHAR(32) DEFAULT NULL,
  ADD COLUMN `duracion_segundos` SMALLINT UNSIGNED DEFAULT NULL,
  ADD COLUMN `id_producto` INT UNSIGNED DEFAULT NULL,
  ADD KEY `idx_logs_actividad_pageview_id` (`pageview_id`),
  ADD KEY `idx_logs_actividad_id_producto` (`id_producto`);
