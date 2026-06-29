-- Plantilla inicial del sistema de migraciones.
-- Esta migracion es opcional porque el runner crea la tabla automaticamente.
-- Se mantiene para validar que el flujo de deploy incluya archivos SQL versionados.

CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(191) NOT NULL,
    filename VARCHAR(255) NOT NULL,
    checksum CHAR(64) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
