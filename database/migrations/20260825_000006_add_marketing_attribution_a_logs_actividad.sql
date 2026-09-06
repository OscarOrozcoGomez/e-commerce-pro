-- Migracion: columnas de atribucion de marketing (UTM, gclid/wbraid/gbraid, referrer,
-- plataforma clasificada, visitor_id y geolocalizacion) en logs_actividad, para poder
-- reportar de donde viene el trafico (Google Ads, Facebook Ads, organico, directo) sin
-- crear una tabla separada -- una fila 'visit' ya es exactamente el evento donde aplican
-- estos datos.
ALTER TABLE `logs_actividad`
  ADD COLUMN `utm_source` VARCHAR(100) DEFAULT NULL,
  ADD COLUMN `utm_medium` VARCHAR(100) DEFAULT NULL,
  ADD COLUMN `utm_campaign` VARCHAR(150) DEFAULT NULL,
  ADD COLUMN `utm_term` VARCHAR(150) DEFAULT NULL,
  ADD COLUMN `utm_content` VARCHAR(150) DEFAULT NULL,
  ADD COLUMN `gclid` VARCHAR(255) DEFAULT NULL,
  ADD COLUMN `wbraid` VARCHAR(255) DEFAULT NULL,
  ADD COLUMN `gbraid` VARCHAR(255) DEFAULT NULL,
  ADD COLUMN `referrer` VARCHAR(500) DEFAULT NULL,
  ADD COLUMN `landing_page` VARCHAR(500) DEFAULT NULL,
  ADD COLUMN `plataforma` VARCHAR(30) DEFAULT NULL,
  ADD COLUMN `visitor_id` CHAR(32) DEFAULT NULL,
  ADD COLUMN `pais` VARCHAR(2) DEFAULT NULL,
  ADD COLUMN `region` VARCHAR(100) DEFAULT NULL,
  ADD KEY `idx_logs_actividad_plataforma` (`plataforma`),
  ADD KEY `idx_logs_actividad_visitor_id` (`visitor_id`);
