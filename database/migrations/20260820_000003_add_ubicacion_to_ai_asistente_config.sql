-- Migracion: ubicacion fisica del negocio (para que Alex conteste con datos reales cuando
-- preguntan por sucursales/donde se encuentran), editable despues desde el panel admin.
ALTER TABLE `ai_asistente_config`
  ADD COLUMN `ubicacion_texto` TEXT DEFAULT NULL AFTER `politica_pago_texto`;

UPDATE `ai_asistente_config`
SET `ubicacion_texto` = 'Tabachín 248, Bosques de Tonalá, 45400 Tonalá, Jal. Puedes ver el mapa aquí: https://maps.app.goo.gl/cBpMFXU27MXL4k9F6'
WHERE `id_config` = 1 AND (`ubicacion_texto` IS NULL OR `ubicacion_texto` = '');
