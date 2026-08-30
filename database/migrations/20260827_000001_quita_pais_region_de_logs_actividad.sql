-- Migracion: quita `pais` y `region` de logs_actividad. Se decidio no perseguir la
-- cuenta/licencia de MaxMind GeoLite2 necesaria para poblar estas columnas: para un
-- negocio local, el nivel pais no aporta nada (feedback de marketing), y el codigo que
-- las llenaba (core/geo_lookup.php + core/lib/MaxMindDb/Reader.php) tambien se quito
-- en este mismo cambio. Las columnas nunca llegaron a poblarse en produccion (esta
-- funcionalidad aun no se habia desplegado), asi que no hay datos reales que perder.
ALTER TABLE `logs_actividad`
  DROP COLUMN `pais`,
  DROP COLUMN `region`;
