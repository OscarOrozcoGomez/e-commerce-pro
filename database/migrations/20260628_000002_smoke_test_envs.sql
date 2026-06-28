-- Smoke test para validar pipeline de migraciones en QA/Produccion.
-- Es idempotente y no afecta inventario ni logica del negocio.

CREATE TABLE IF NOT EXISTS migration_smoke_test (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    environment_label VARCHAR(32) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO migration_smoke_test (environment_label)
SELECT 'unknown'
WHERE NOT EXISTS (
    SELECT 1
    FROM migration_smoke_test
    WHERE environment_label = 'unknown'
);
