<?php
declare(strict_types=1);

/**
 * Interruptores on/off para las iniciativas nuevas de ventas/marketing
 * (atribucion, feed de catalogo, referidos, stock+campanas). Backed por la
 * tabla ventas_features_config -- mismo patron que ai_asistente_config: se
 * puede prender/apagar desde un panel admin sin necesitar un deploy.
 */

const VENTAS_FEATURE_KEYS = [
    'atribucion_ventas',
    'catalogo_feed',
    'programa_referidos',
    'stock_calendario_campanas',
    'comportamiento_sitio',
];

function ventasFeaturesGetAll(PDO $pdo): array
{
    // Sin cache estatico a proposito: una request tipica llama esto una sola vez, y un
    // cache aqui podria devolver datos obsoletos si se llama de nuevo despues de un
    // ventasFeatureSave() en la misma request (o resultados de la conexion PDO
    // equivocada si en algun momento coexisten dos conexiones).
    $rows = $pdo->query('SELECT feature_key, activo, config_json FROM ventas_features_config')->fetchAll();
    $result = [];
    foreach ($rows as $row) {
        $config = [];
        if (!empty($row['config_json'])) {
            $decoded = json_decode((string) $row['config_json'], true);
            if (is_array($decoded)) {
                $config = $decoded;
            }
        }
        $result[(string) $row['feature_key']] = [
            'activo' => !empty($row['activo']),
            'config' => $config,
        ];
    }

    return $result;
}

function ventasFeatureIsActive(PDO $pdo, string $featureKey): bool
{
    $all = ventasFeaturesGetAll($pdo);
    return !empty($all[$featureKey]['activo']);
}

function ventasFeatureConfig(PDO $pdo, string $featureKey): array
{
    $all = ventasFeaturesGetAll($pdo);
    return $all[$featureKey]['config'] ?? [];
}

function ventasFeatureSave(PDO $pdo, string $featureKey, bool $activo, array $config): void
{
    if (!in_array($featureKey, VENTAS_FEATURE_KEYS, true)) {
        throw new InvalidArgumentException('Feature de ventas desconocida: ' . $featureKey);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO ventas_features_config (feature_key, activo, config_json)
         VALUES (:feature_key, :activo, :config_json)
         ON DUPLICATE KEY UPDATE activo = VALUES(activo), config_json = VALUES(config_json)'
    );
    $stmt->execute([
        ':feature_key' => $featureKey,
        ':activo' => $activo ? 1 : 0,
        ':config_json' => empty($config) ? null : json_encode($config),
    ]);
}
