<?php
declare(strict_types=1);

/**
 * Resuelve la atribucion "last-touch" (ultima visita conocida con plataforma
 * clasificada) para un visitor_id, para congelarla en el pedido al momento
 * de crearse. Silencioso ante cualquier error: la atribucion es informativa,
 * nunca debe impedir que se cree un pedido.
 *
 * @return array{visitor_id: ?string, plataforma: ?string, utm_source: ?string, utm_campaign: ?string}
 */
function getLastTouchAttribution(PDO $pdo, ?string $visitorId): array
{
    $empty = ['visitor_id' => null, 'plataforma' => null, 'utm_source' => null, 'utm_campaign' => null];

    if ($visitorId === null || !preg_match('/^[a-f0-9]{32}$/', $visitorId)) {
        return $empty;
    }

    try {
        $stmt = $pdo->prepare(
            "SELECT plataforma, utm_source, utm_campaign
             FROM logs_actividad
             WHERE visitor_id = :visitor_id
               AND tipo_accion = 'visit'
               AND plataforma IS NOT NULL
             ORDER BY fecha_creacion DESC
             LIMIT 1"
        );
        $stmt->execute([':visitor_id' => $visitorId]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('getLastTouchAttribution: error al resolver visitor_id ' . $visitorId . ': ' . $e->getMessage());
        return $empty;
    }

    if (!$row) {
        return $empty;
    }

    return [
        'visitor_id' => $visitorId,
        'plataforma' => $row['plataforma'] ?: null,
        'utm_source' => $row['utm_source'] ?: null,
        'utm_campaign' => $row['utm_campaign'] ?: null,
    ];
}

/**
 * Extrae un campo del body JSON como string, rechazando cualquier tipo no
 * escalar (arrays/objetos) en vez de castearlo a ciegas -- evita el warning
 * "Array to string conversion" y, mas importante, evita que un payload con
 * "utm_source": {...} se cuele con un valor inesperado.
 */
function attributionExtractScalarString(array $data, string $key): string
{
    $value = $data[$key] ?? null;
    if (!is_scalar($value)) {
        return '';
    }
    return trim((string) $value);
}

/**
 * Clasifica la plataforma de origen de una visita a partir de sus parametros
 * de atribucion (UTM/gclid/etc.) y del referrer. Reglas simples basadas en
 * los valores que efectivamente mandan las campanas de Google y Facebook Ads;
 * sin llamadas externas ni dependencias nuevas. Entrada no confiable (viene
 * directo del body JSON de una request publica): nunca debe lanzar.
 */
function classifyPlatform(array $data): string
{
    $utmSource = strtolower(attributionExtractScalarString($data, 'utm_source'));
    $utmMedium = strtolower(attributionExtractScalarString($data, 'utm_medium'));
    $hasGoogleClickId = attributionExtractScalarString($data, 'gclid') !== ''
        || attributionExtractScalarString($data, 'wbraid') !== ''
        || attributionExtractScalarString($data, 'gbraid') !== '';

    if ($hasGoogleClickId || in_array($utmSource, ['google', 'google_ads', 'adwords'], true)) {
        return 'google_ads';
    }

    if (in_array($utmSource, ['facebook', 'fb', 'instagram', 'ig', 'meta'], true) || $utmMedium === 'paid_social') {
        return 'facebook_ads';
    }

    if ($utmSource !== '') {
        return $utmSource;
    }

    $referrer = attributionExtractScalarString($data, 'referrer');
    if ($referrer === '') {
        return 'direct';
    }

    $referrerHost = strtolower((string) (parse_url($referrer, PHP_URL_HOST) ?? ''));
    if ($referrerHost === '') {
        return 'direct';
    }

    $ownHost = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($referrerHost === $ownHost) {
        return 'direct';
    }

    $searchEngines = ['google.', 'bing.', 'yahoo.', 'duckduckgo.'];
    foreach ($searchEngines as $engine) {
        if (str_contains($referrerHost, $engine)) {
            return 'organic';
        }
    }

    return 'referral';
}
